<?php
declare(strict_types=1);

require_once __DIR__ . '/Container.php';
require_once __DIR__ . '/Exif.php';
require_once __DIR__ . '/Cbor.php';
require_once __DIR__ . '/C2pa.php';
require_once __DIR__ . '/Iptc.php';

/**
 * Detector: rule-based provenance check. No trained model, no pixel decoding.
 *
 * Every verdict points at a concrete piece of evidence that was parsed out of
 * the file's structure (not a keyword search over the raw bytes):
 *   - C2PA manifest (JUMBF + CBOR): declared digital source type + software
 *   - XMP: IPTC DigitalSourceType, CreatorTool, history software
 *   - EXIF: camera make/model/exposure, Software, A1111-style UserComment
 *   - PNG text chunks written by Stable Diffusion UIs (A1111, ComfyUI, ...)
 *   - Typical generator output sizes (weak hint only)
 *
 * Limits, said out loud: metadata can be stripped or forged, and C2PA
 * signatures are not verified, so "nothing found" never proves an image is
 * real. SynthID is a pixel watermark and still needs Google's detector.
 */
final class Detector
{
    public const ALLOWED = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    // "AI likelihood" shown for each level. A fixed rule-based estimate, not a measured probability:
    // null means the file says nothing either way.
    // When the pixel check ran, its measured score replaces these (see analyze()).
    public const LEVEL_SCORES = ['ai' => 95, 'likely' => 75, 'hint' => 55, 'none' => null, 'camera' => 15, 'real' => 10];

    // Strength order, strongest first. Also the display order of the evidence list.
    private const STRENGTHS = ['strong', 'medium', 'weak', 'camera', 'real', 'info'];

    // IPTC digital source types (last path part of http://cv.iptc.org/newscodes/digitalsourcetype/...)
    private const SOURCE_TYPES = [
        'trainedalgorithmicmedia'              => ['strong', 'Declared fully AI-generated'],
        'compositewithtrainedalgorithmicmedia' => ['strong', 'Declared edited/composited with AI'],
        'compositesynthetic'                   => ['medium', 'Declared composite with synthetic parts'],
        'algorithmicmedia'                     => ['medium', 'Declared algorithmically generated'],
        'digitalcapture'                       => ['camera', 'Declared a camera capture'],
        'negativefilm'                         => ['camera', 'Declared scanned from film'],
        'positivefilm'                         => ['camera', 'Declared scanned from film'],
    ];

    // Matched only against software/creator fields, never against the whole file
    private const GENERATOR_RE = '/midjourney|dall[\s·\-]?e|stable[\s\-]?diffusion|\bsdxl\b|comfyui|automatic1111'
        . '|invokeai|novelai|fooocus|firefly|google imagen|imagen \d|chatgpt|gpt-4o|gpt-image|openai|\bflux\b'
        . '|leonardo\.?ai|ideogram|gemini|nano banana|made with google ai|bing image creator|dreamstudio/i';

    private const EDITOR_RE = '/photoshop|lightroom|gimp|snapseed|affinity|capture one|darktable|pixelmator|canva|paint\.net/i';

    // Default output sizes of popular generators. Plenty of real images share them: weak hint only.
    private const GENERATOR_SIZES = [
        '512x512', '768x768', '1024x1024', '2048x2048',
        '1024x1792', '1792x1024',                           // DALL-E 3
        '1024x1536', '1536x1024',                           // gpt-image
        '832x1216', '1216x832', '896x1152', '1152x896',     // SDXL buckets
        '768x1344', '1344x768', '640x1536', '1536x640',
        '1456x816', '816x1456', '1232x928', '928x1232',     // Midjourney
    ];

    /** @return array{ok:bool, error?:string, sha256?:string, mime?:string, bytes?:int, width?:int, height?:int, level?:string, score?:?int, score_source?:string, verdict?:string, evidence?:list<array{strength:string, source:string, label:string, detail:string}>, signals?:string[], synthid?:string} */
    /**
     * @param ?callable(string $path, string $mime): array $pixelCheck optional pixel model
     *        (Sightengine::check in production, a stub in tests)
     */
    public static function analyze(string $tmpPath, ?callable $pixelCheck = null): array
    {
        $bytes = filesize($tmpPath);
        if ($bytes === false || $bytes === 0 || $bytes > 10 * 1024 * 1024) {
            return ['ok' => false, 'error' => 'File must be between 1 byte and 10 MB.'];
        }

        // Real MIME from content, never from the client-supplied name/type
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = (string) $finfo->file($tmpPath);
        if (!isset(self::ALLOWED[$mime])) {
            return ['ok' => false, 'error' => 'Only JPEG, PNG or WebP images are accepted.'];
        }

        // Reads the header only; pixels are never decoded
        $info = @getimagesize($tmpPath);
        if ($info === false) {
            return ['ok' => false, 'error' => 'File is not a valid image.'];
        }

        $raw  = (string) file_get_contents($tmpPath);
        $meta = Container::parse($raw, $mime);
        $exif = [];
        foreach ($meta['exif'] as $blob) {
            $exif += Exif::parse($blob);
        }
        $iptc = [];
        foreach ($meta['iptc'] as $blob) {
            $iptc += Iptc::fromPhotoshop($blob);
        }
        $xmp  = implode("\n", $meta['xmp']);
        $xmpF = self::xmpFields($xmp);
        $c2pa = $meta['jumbf'] === null ? null : C2pa::read($meta['jumbf']);
        [$w, $h] = [(int) $info[0], (int) $info[1]];

        $ev = [];
        if ($c2pa !== null) {
            self::c2paRules($c2pa, $ev);
        }
        self::xmpRules($xmpF, $ev);
        self::generatorParamRules($meta['text'], $exif, $iptc, $ev);

        $software = [];
        foreach (['CreatorTool', 'softwareAgent'] as $f) {
            foreach ($xmpF[$f] ?? [] as $v) {
                $software[] = ['XMP', $v];
            }
        }
        if (is_string($exif['Software'] ?? null)) {
            $software[] = ['EXIF', $exif['Software']];
        }
        if (isset($meta['text']['Software'])) {
            $software[] = ['PNG text', $meta['text']['Software']];
        }
        self::softwareRules($software, $ev);

        // [source, field, value, free text?] for author/credit and description fields
        $named = [];
        foreach (['Artist' => false, 'Copyright' => false, 'XPAuthor' => false,
                  'ImageDescription' => true, 'XPTitle' => true, 'XPComment' => true, 'XPKeywords' => true] as $t => $free) {
            if (is_string($exif[$t] ?? null)) {
                $named[] = ['EXIF', $t, $exif[$t], $free];
            }
        }
        foreach ($iptc as $k => $v) {
            $named[] = ['IPTC', $k, $v, in_array($k, ['Caption', 'Headline', 'ObjectName', 'Keywords'], true)];
        }
        foreach (['Credit' => false, 'creator' => false, 'description' => true, 'title' => true] as $f => $free) {
            foreach ($xmpF[$f] ?? [] as $v) {
                $named[] = ['XMP', $f, $v, $free];
            }
        }
        self::namedRules($named, $ev);

        $camera = self::cameraRule($exif, $ev);
        self::consistencyRules($exif, $w, $h, $ev);
        if (!$camera && in_array($w . 'x' . $h, self::GENERATOR_SIZES, true)) {
            $ev[] = self::ev('weak', 'Image size', 'Common AI output size', "{$w}×{$h} is a default size of popular generators.");
        }
        if (!$exif && !$iptc && $xmp === '' && !$meta['text'] && $meta['jumbf'] === null) {
            $ev[] = self::ev('info', 'Metadata', 'No metadata at all', 'Typical of screenshots and re-uploads, and of AI images with metadata removed.');
        }

        $pixel = $pixelCheck === null ? null : $pixelCheck($tmpPath, $mime);
        if ($pixel !== null) {
            self::pixelRule($pixel, $ev);
        }

        // the same finding can come from several fields
        $ev = array_values(array_unique($ev, SORT_REGULAR));
        usort($ev, fn($a, $b) => array_search($a['strength'], self::STRENGTHS, true)
            <=> array_search($b['strength'], self::STRENGTHS, true));
        [$level, $verdict] = self::verdict($ev);

        // A measured pixel score beats the fixed per-level table, except that
        // declared AI metadata never scores below 95.
        $score  = self::LEVEL_SCORES[$level];
        $source = 'rules';
        if ($pixel !== null && $pixel['ok']) {
            $measured = (int) round($pixel['score'] * 100);
            $score  = $level === 'ai' ? max(95, $measured) : $measured;
            $source = $level === 'ai' && $measured < 95 ? 'rules' : 'pixel';
        }

        return [
            'ok'       => true,
            'sha256'   => hash('sha256', $raw),
            'mime'     => $mime,
            'bytes'    => (int) $bytes,
            'width'    => (int) $info[0],
            'height'   => (int) $info[1],
            'level'    => $level,
            'score'    => $score,
            'score_source' => $source,
            'pixel'    => $pixel,
            'verdict'  => $verdict,
            'evidence' => $ev,
            'signals'  => array_map(fn($e) => $e['source'] . ': ' . $e['label'], $ev),
            'metadata' => self::report($mime, $w, $h, (int) $bytes, $exif, $iptc, $xmpF, $meta['text'], $c2pa),
            'synthid'  => self::synthIdCheck($tmpPath),
        ];
    }

    private static function c2paRules(array $c, array &$ev): void
    {
        if (!$c['ok']) {
            $ev[] = self::ev('info', 'C2PA', 'Unreadable Content Credentials', 'A C2PA block is present but could not be parsed.');
            return;
        }
        $by = $c['agents'] ? implode(', ', array_slice($c['agents'], 0, 2)) : 'unknown software';

        foreach ($c['sourceTypes'] as $uri) {
            [$strength, $label] = self::sourceType($uri);
            $ev[] = self::ev($strength, 'C2PA', $label, "Written by $by. Signature not verified.");
        }
        foreach ($c['agents'] as $agent) {
            if (preg_match(self::GENERATOR_RE, $agent)) {
                $ev[] = self::ev('medium', 'C2PA', 'AI tool named in Content Credentials', $agent);
                break;
            }
        }
        if (!$c['sourceTypes']) {
            $actions = $c['actions'] ? ' Actions: ' . implode(', ', array_slice($c['actions'], 0, 4)) . '.' : '';
            $ev[] = self::ev('info', 'C2PA', 'Content Credentials, no AI declared', "Written by $by.$actions Signature not verified.");
        }
    }

    // XMP properties read by local name, whatever namespace prefix the writer used
    private const XMP_NAMES = [
        'DigitalSourceType', 'AISystemUsed', 'AISystemVersionUsed', 'AIPromptInformation', 'AIPromptWriterName',
        'CreatorTool', 'softwareAgent', 'Credit', 'creator', 'title', 'description', 'CreateDate', 'ModifyDate',
    ];

    /** @return array<string, list<string>> */
    private static function xmpFields(string $xmp): array
    {
        $out = [];
        if ($xmp === '') {
            return $out;
        }
        foreach (self::XMP_NAMES as $name) {
            if ($v = self::xmpValues($xmp, $name)) {
                $out[$name] = $v;
            }
        }
        return $out;
    }

    /**
     * All values of one XMP property: attribute form (ns:Name="v"), element form
     * (<ns:Name>v</ns:Name>, including rdf:Alt/Seq/Bag lists) and rdf:resource.
     *
     * @return list<string>
     */
    private static function xmpValues(string $xmp, string $name): array
    {
        $n = preg_quote($name, '/');
        $out = [];
        if (preg_match_all('/[\s<][\w-]+:' . $n . '\s*=\s*(["\'])(.*?)\1/s', $xmp, $m)) {
            array_push($out, ...$m[2]);
        }
        if (preg_match_all('/<([\w-]+:' . $n . ')\b[^>]*(?<!\/)>(.*?)<\/\1\s*>/s', $xmp, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                if (preg_match_all('/<rdf:li\b[^>]*>(.*?)<\/rdf:li>/s', $hit[2], $li)) {
                    array_push($out, ...$li[1]);
                } else {
                    $out[] = $hit[2];
                }
            }
        }
        if (preg_match_all('/<[\w-]+:' . $n . '\b[^>]*?rdf:resource\s*=\s*(["\'])(.*?)\1/s', $xmp, $m)) {
            array_push($out, ...$m[2]);
        }
        $clean = [];
        foreach ($out as $v) {
            $v = trim(html_entity_decode(strip_tags($v), ENT_QUOTES | ENT_XML1, 'UTF-8'));
            if ($v !== '' && count($clean) < 20) {
                $clean[$v] = true;
            }
        }
        return array_keys($clean);
    }

    /** @param array<string, list<string>> $f */
    private static function xmpRules(array $f, array &$ev): void
    {
        foreach ($f['DigitalSourceType'] ?? [] as $uri) {
            [$strength, $label] = self::sourceType($uri);
            $ev[] = self::ev($strength, 'XMP (IPTC)', $label, $uri);
        }
        // IPTC 2025 AI properties
        if (isset($f['AISystemUsed'])) {
            $version = isset($f['AISystemVersionUsed']) ? ' ' . $f['AISystemVersionUsed'][0] : '';
            $ev[] = self::ev('strong', 'XMP (IPTC)', 'Declared AI system', implode(', ', $f['AISystemUsed']) . $version);
        }
        if (isset($f['AIPromptInformation'])) {
            $ev[] = self::ev('strong', 'XMP (IPTC)', 'AI prompt recorded', $f['AIPromptInformation'][0]);
        }
    }

    /** Generation settings written by Stable Diffusion front-ends and other generators */
    private static function generatorParamRules(array $text, array $exif, array $iptc, array &$ev): void
    {
        $a1111 = [$text['parameters'] ?? '', $text['Description'] ?? '', $exif['UserComment'] ?? '',
            $exif['ImageDescription'] ?? '', $exif['XPComment'] ?? '', $iptc['Caption'] ?? ''];
        foreach ($a1111 as $v) {
            if (is_string($v) && preg_match('/\bSteps:\s*\d+.*\bSampler:/s', $v)) {
                $model = preg_match('/\bModel:\s*([^,\n]+)/', $v, $mm) ? ' Model: ' . $mm[1] . '.' : '';
                $ev[] = self::ev('strong', 'Generator settings', 'Stable Diffusion WebUI parameters',
                    'Prompt, steps and sampler are embedded.' . $model);
                break;
            }
        }

        $prompt   = json_decode($text['prompt'] ?? '', true, 64);
        $workflow = json_decode($text['workflow'] ?? '', true, 64);
        if ((is_array($workflow) && isset($workflow['nodes']))
            || (is_array($prompt) && array_filter($prompt, fn($n) => is_array($n) && isset($n['class_type'])))) {
            $ev[] = self::ev('strong', 'Generator settings', 'ComfyUI workflow embedded', 'Node graph stored in the PNG.');
        }

        foreach (['invokeai_metadata', 'invokeai_graph', 'sd-metadata', 'Dream'] as $key) {
            if (isset($text[$key])) {
                $ev[] = self::ev('strong', 'Generator settings', 'InvokeAI metadata embedded', "PNG text chunk \"$key\".");
                break;
            }
        }

        $params = json_decode($text['parameters'] ?? '', true, 16);
        if (isset($text['fooocus_scheme'])
            || (is_array($params) && preg_grep('/fooocus/i', array_map('strval', array_merge(array_keys($params), array_filter($params, 'is_scalar')))))) {
            $ev[] = self::ev('strong', 'Generator settings', 'Fooocus metadata embedded', 'Generation settings stored in the PNG.');
        }
        if (is_array($params) && isset($params['sui_image_params'])) {
            $ev[] = self::ev('strong', 'Generator settings', 'SwarmUI metadata embedded', 'Generation settings stored in the PNG.');
        }

        $comment = json_decode($text['Comment'] ?? '', true, 16);
        if (($text['Software'] ?? '') === 'NovelAI'
            || (is_array($comment) && isset($comment['steps'], $comment['sampler'], $comment['uc']))) {
            $ev[] = self::ev('strong', 'Generator settings', 'NovelAI metadata embedded', 'Generation settings stored in the PNG.');
        }

        foreach ([$text['Description'] ?? '', $exif['ImageDescription'] ?? '', $iptc['Caption'] ?? ''] as $v) {
            if (is_string($v) && preg_match('/\bJob ID:\s*[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $v)) {
                $ev[] = self::ev('medium', 'Generator settings', 'Midjourney job ID', 'A Midjourney job reference is stored in the description.');
                break;
            }
        }
    }

    /** @param list<array{0:string,1:string}> $software [source, value] */
    private static function softwareRules(array $software, array &$ev): void
    {
        $seen = [];
        foreach ($software as [$source, $name]) {
            $key = strtolower($name);
            if ($name === '' || isset($seen[$key]) || count($seen) >= 6) {
                continue;
            }
            $seen[$key] = true;
            if (preg_match(self::GENERATOR_RE, $name)) {
                $ev[] = self::ev('medium', $source, 'AI tool named as software', $name);
            } elseif (preg_match(self::EDITOR_RE, $name)) {
                $ev[] = self::ev('info', $source, 'Edited with an image editor', $name);
            } else {
                $ev[] = self::ev('info', $source, 'Software', $name);
            }
        }
    }

    /**
     * Author, credit and description fields. Generator names count only in
     * author/credit fields: a caption saying "in the style of Midjourney" must
     * not flag a photo. The "Made with Google AI" label counts anywhere.
     *
     * @param list<array{0:string,1:string,2:string,3:bool}> $named [source, field, value, free text]
     */
    private static function namedRules(array $named, array &$ev): void
    {
        foreach ($named as [$source, $field, $value, $free]) {
            if (preg_match('/made with google ai/i', $value)) {
                $ev[] = self::ev('medium', $source, 'Labelled "Made with Google AI"', "$field: $value");
            } elseif (!$free && preg_match(self::GENERATOR_RE, $value)) {
                $ev[] = self::ev('medium', $source, "AI tool named in $field", $value);
            }
        }
    }

    private static function cameraRule(array $exif, array &$ev): bool
    {
        $make  = is_string($exif['Make'] ?? null) ? trim($exif['Make']) : '';
        $model = is_string($exif['Model'] ?? null) ? trim($exif['Model']) : '';
        if ($make === '' && $model === '') {
            return false;
        }
        $name = str_starts_with(strtolower($model), strtolower($make)) ? $model : trim("$make $model");

        $shot = [];
        $t = $exif['ExposureTime'] ?? null;
        if (is_float($t) && $t > 0) {
            $shot[] = $t < 1 ? '1/' . round(1 / $t) . ' s' : round($t, 1) . ' s';
        }
        if (is_float($exif['FNumber'] ?? null) && $exif['FNumber'] > 0) {
            $shot[] = 'f/' . round($exif['FNumber'], 1);
        }
        if (is_int($exif['ISO'] ?? null)) {
            $shot[] = 'ISO ' . $exif['ISO'];
        }

        if (!$shot) {
            $ev[] = self::ev('info', 'EXIF', 'Camera named, no exposure data', "$name. Easy to copy into any file.");
            return false;
        }
        $extra = [];
        if (is_string($exif['LensModel'] ?? null) && $exif['LensModel'] !== '') {
            $extra[] = $exif['LensModel'];
        }
        if (is_string($exif['DateTimeOriginal'] ?? null)) {
            $extra[] = 'taken ' . $exif['DateTimeOriginal'];
        }
        if (isset($exif['GPS'])) {
            $extra[] = 'GPS recorded';
        }
        $ev[] = self::ev('camera', 'EXIF', 'Camera capture data',
            "$name · " . implode(' · ', array_merge($shot, $extra)) . '. Can be faked.');
        return true;
    }

    /** Recorded vs actual size: a camera file that was later resized or re-encoded */
    private static function consistencyRules(array $exif, int $w, int $h, array &$ev): void
    {
        $rw = $exif['PixelXDimension'] ?? null;
        $rh = $exif['PixelYDimension'] ?? null;
        if (is_int($rw) && is_int($rh) && $rw > 0 && $rh > 0 && !([$rw, $rh] === [$w, $h] || [$rh, $rw] === [$w, $h])) {
            $ev[] = self::ev('info', 'EXIF', 'Resized since capture', "EXIF records {$rw}×{$rh}, the file is {$w}×{$h}.");
        }
    }

    /**
     * Everything that was read, grouped for the Metadata panel.
     *
     * @return list<array{group:string, rows:list<array{0:string,1:string}>}>
     */
    private static function report(string $mime, int $w, int $h, int $bytes, array $exif, array $iptc,
        array $xmpF, array $text, ?array $c2pa): array
    {
        $groups = [];
        $add = function (string $group, array $rows) use (&$groups): void {
            $rows = array_values(array_filter($rows, fn($r) => $r[1] !== ''));
            if ($rows) {
                $groups[] = ['group' => $group, 'rows' => array_map(
                    fn($r) => [self::clip($r[0], 60), self::clip($r[1], 240)], array_slice($rows, 0, 40))];
            }
        };

        $add('File', [['Type', $mime], ['Dimensions', "$w × $h"], ['Size', number_format($bytes) . ' bytes']]);

        $rows = [];
        foreach ($exif as $k => $v) {
            $rows[] = [$k, match (true) {
                $k === 'ExposureTime' && is_float($v) && $v > 0 => ($v < 1 ? '1/' . round(1 / $v) : round($v, 2)) . ' s',
                $k === 'FNumber' && is_float($v)                => 'f/' . round($v, 1),
                $k === 'FocalLength' && is_float($v)            => round($v, 1) . ' mm',
                is_float($v)                                    => (string) round($v, 3),
                default                                         => (string) $v,
            }];
        }
        $add('EXIF', $rows);

        $add('IPTC', array_map(fn($k, $v) => [$k, $v], array_keys($iptc), $iptc));
        $add('XMP', array_map(fn($k, $v) => [$k, implode(' | ', array_slice($v, 0, 5))], array_keys($xmpF), $xmpF));

        $rows = [];
        foreach ($text as $k => $v) {
            $json = json_decode($v, true, 64);
            $rows[] = [$k, is_array($json) ? 'JSON, ' . number_format(strlen($v)) . ' bytes' : $v];
        }
        $add('PNG text', $rows);

        if ($c2pa !== null) {
            $add('C2PA', $c2pa['ok'] ? [
                ['Status', 'Manifest read, signature not verified'],
                ['Written by', implode(', ', $c2pa['agents'])],
                ['Actions', implode(', ', $c2pa['actions'])],
                ['Source type', implode(', ', array_map(fn($u) => (string) preg_replace('~^.*/~', '', $u), $c2pa['sourceTypes']))],
            ] : [['Status', 'Present but unreadable']]);
        }
        return $groups;
    }

    private static function clip(string $s, int $max): string
    {
        return substr(trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $s)), 0, $max);
    }

    /** @return array{0:string, 1:string} [level, verdict]; verdict fits the 64-char DB column */
    private static function verdict(array $ev): array
    {
        $has = array_flip(array_column($ev, 'strength'));
        return match (true) {
            isset($has['strong']) => ['ai', 'AI-generated (declared in the file\'s metadata)'],
            isset($has['medium']) => ['likely', 'Likely AI-generated (metadata points to it)'],
            isset($has['camera']) => ['camera', 'Camera metadata found, no AI markers'],
            isset($has['real'])   => ['real', 'Likely a real photo (pixel analysis)'],
            isset($has['weak'])   => ['hint', 'Weak hints only (inconclusive)'],
            default               => ['none', 'No evidence either way (inconclusive)'],
        };
    }

    /** Sightengine's pixel model: a measured probability, so it can push either way */
    private static function pixelRule(array $pixel, array &$ev): void
    {
        if (!$pixel['ok']) {
            $ev[] = self::ev('info', 'Sightengine', 'Pixel analysis unavailable', $pixel['error'] ?? 'Unknown error.');
            return;
        }
        $pct = (int) round($pixel['score'] * 100);
        $names = array_map(fn($g) => $g['name'] . ' ' . round($g['score'] * 100) . '%', $pixel['generators'] ?? []);
        $detail = "Model score $pct% that the pixels were made or edited by AI."
            . ($names ? ' Closest match: ' . implode(', ', $names) . '.' : '');

        $ev[] = match (true) {
            $pixel['score'] >= 0.9  => self::ev('medium', 'Sightengine', 'Pixel analysis: very likely AI', $detail),
            $pixel['score'] >= 0.5  => self::ev('weak', 'Sightengine', 'Pixel analysis: possibly AI', $detail),
            $pixel['score'] <= 0.2  => self::ev('real', 'Sightengine', 'Pixel analysis: looks like a real photo', $detail),
            default                 => self::ev('info', 'Sightengine', 'Pixel analysis: unclear', $detail),
        };
    }

    /** @return array{0:string, 1:string} [strength, label] */
    private static function sourceType(string $uri): array
    {
        $code = strtolower((string) preg_replace('~^.*/~', '', trim($uri)));
        return self::SOURCE_TYPES[$code] ?? ['info', 'Declared source: ' . $code];
    }

    /** One evidence item. Values come from the upload: strip control chars and cap length. */
    private static function ev(string $strength, string $source, string $label, string $detail): array
    {
        return [
            'strength' => $strength,
            'source'   => $source,
            'label'    => self::clip($label, 80),
            'detail'   => self::clip($detail, 200),
        ];
    }

    /**
     * Hook for a real SynthID backend. Google offers SynthID checks only in the
     * Gemini app and the SynthID Detector portal (no developer API as of
     * October 2026), so this reports "not checked" instead of pretending.
     */
    private static function synthIdCheck(string $tmpPath): string
    {
        return 'Not checked automatically: Google has no SynthID API. Check it in the Gemini app.';
    }
}
