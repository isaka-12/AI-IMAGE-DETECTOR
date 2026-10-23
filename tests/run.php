<?php
declare(strict_types=1);

/**
 * Plain-PHP test runner (no Composer needed):  php tests/run.php
 * Real C2PA samples in tests/fixtures come from the c2pa-rs project (MIT / Apache-2.0).
 * Everything else is built here byte by byte, so each rule is tested in isolation.
 */

require __DIR__ . '/../app/Detector.php';
require __DIR__ . '/../app/Sightengine.php';

// Any warning or notice inside the parsers is a bug: turn it into a failure.
// Deliberately @-silenced calls (the capped gzuncompress) are left alone.
set_error_handler(function (int $no, string $msg, string $file, int $line): bool {
    if (!(error_reporting() & $no)) {
        return false;
    }
    throw new ErrorException($msg, 0, $no, $file, $line);
});

const FIX = __DIR__ . '/fixtures/';
const JPEG_8 = '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDABQODxIPDRQSEBIXFRQYHjIhHhwcHj0sLiQySUBMS0dARkVQWnNiUFVtVkVGZIhlbXd7gYKBTmCNl4x9lnN+gXz/2wBDARUXFx4aHjshITt8U0ZTfHx8fHx8fHx8fHx8fHx8fHx8fHx8fHx8fHx8fHx8fHx8fHx8fHx8fHx8fHx8fHx8fHz/wAARCAAIAAgDASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/8QAHwEAAwEBAQEBAQEBAQAAAAAAAAECAwQFBgcICQoL/8QAtREAAgECBAQDBAcFBAQAAQJ3AAECAxEEBSExBhJBUQdhcRMiMoEIFEKRobHBCSMzUvAVYnLRChYkNOEl8RcYGRomJygpKjU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6goOEhYaHiImKkpOUlZaXmJmaoqOkpaanqKmqsrO0tba3uLm6wsPExcbHyMnK0tPU1dbX2Nna4uPk5ebn6Onq8vP09fb3+Pn6/9oADAMBAAIRAxEAPwB9FFFeWekf/9k=';
const WEBP_8 = 'UklGRjQAAABXRUJQVlA4ICgAAACwAQCdASoIAAgAA4BaJaACdLoABdQAAPuO/8BdO4dnP0fmXJznAAAA';

// ---------- builders ----------

function pngChunk(string $type, string $data): string
{
    return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
}

/** @param list<string> $extra extra chunks placed before IDAT */
function png(array $extra = [], int $w = 1, int $h = 1): string
{
    $rows = str_repeat("\0" . str_repeat("\x80\x40\x20", $w), $h);
    return "\x89PNG\r\n\x1a\n"
        . pngChunk('IHDR', pack('NNCCCCC', $w, $h, 8, 2, 0, 0, 0))
        . implode('', $extra)
        . pngChunk('IDAT', gzcompress($rows))
        . pngChunk('IEND', '');
}

/** Insert APPn segments right after SOI */
function jpeg(array $segments): string
{
    $base = base64_decode(JPEG_8);
    $app = '';
    foreach ($segments as [$marker, $payload]) {
        $app .= "\xFF" . chr($marker) . pack('n', strlen($payload) + 2) . $payload;
    }
    return substr($base, 0, 2) . $app . substr($base, 2);
}

function webp(array $chunks): string
{
    $body = substr(base64_decode(WEBP_8), 12);
    foreach ($chunks as [$type, $data]) {
        $body .= $type . pack('V', strlen($data)) . $data . (strlen($data) & 1 ? "\0" : '');
    }
    return 'RIFF' . pack('V', 4 + strlen($body)) . 'WEBP' . $body;
}

/** Little-endian TIFF with ASCII tags in IFD0 */
function tiff(array $asciiTags): string
{
    ksort($asciiTags);
    $count = count($asciiTags);
    $dataAt = 8 + 2 + $count * 12 + 4;
    $entries = $data = '';
    foreach ($asciiTags as $tag => $value) {
        $value .= "\0";
        $len = strlen($value);
        if ($len <= 4) {
            $entries .= pack('vvV', $tag, 2, $len) . str_pad($value, 4, "\0");
        } else {
            $entries .= pack('vvVV', $tag, 2, $len, $dataAt + strlen($data));
            $data .= $value;
        }
    }
    return "II*\0" . pack('V', 8) . pack('v', $count) . $entries . pack('V', 0) . $data;
}

function xmp(string $inner): string
{
    return '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
        . '<rdf:Description xmlns:Iptc4xmpExt="http://iptc.org/std/Iptc4xmpExt/2008-02-29/" xmlns:xmp="http://ns.adobe.com/xap/1.0/" '
        . $inner . '</rdf:Description></rdf:RDF></x:xmpmeta>';
}

function scan(string $bytes, ?callable $pixel = null): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'scan');
    file_put_contents($tmp, $bytes);
    try {
        return Detector::analyze($tmp, $pixel);
    } finally {
        unlink($tmp);
    }
}


/** JPEG APP13 payload with IPTC-IIM record 2 fields: [[dataset, value], ...] */
function iptcSeg(array $fields): string
{
    $iim = '';
    foreach ($fields as [$ds, $v]) {
        $iim .= "\x1C\x02" . chr($ds) . pack('n', strlen($v)) . $v;
    }
    return "Photoshop 3.0\0" . '8BIM' . pack('n', 0x0404) . "\0\0" . pack('N', strlen($iim)) . $iim . (strlen($iim) & 1 ? "\0" : '');
}

/** Little-endian TIFF with typed entries in IFD0 and an optional Exif sub-IFD: [[tag, type, bytes], ...] */
function tiffIfds(array $ifd0, array $sub = []): string
{
    $size = [1 => 1, 2 => 1, 3 => 2, 4 => 4, 7 => 1];
    if ($sub) {
        $ifd0[] = [0x8769, 4, ''];
    }
    usort($ifd0, fn($a, $b) => $a[0] <=> $b[0]);
    usort($sub, fn($a, $b) => $a[0] <=> $b[0]);
    $offSub = 8 + 2 + 12 * count($ifd0) + 4;
    $dataAt = $offSub + ($sub ? 2 + 12 * count($sub) + 4 : 0);
    $data = '';
    $build = function (array $entries) use (&$data, $dataAt, $size, $offSub): string {
        $out = pack('v', count($entries));
        foreach ($entries as [$tag, $type, $bytes]) {
            if ($tag === 0x8769) {
                $bytes = pack('V', $offSub);
            }
            $count = intdiv(strlen($bytes), $size[$type]);
            if (strlen($bytes) <= 4) {
                $out .= pack('vvV', $tag, $type, $count) . str_pad($bytes, 4, "\0");
            } else {
                $out .= pack('vvVV', $tag, $type, $count, $dataAt + strlen($data)) . '';
                $data .= $bytes;
            }
        }
        return $out . pack('V', 0);
    };
    $head = "II*\0" . pack('V', 8) . $build($ifd0);
    $tail = $sub ? $build($sub) : '';
    return $head . $tail . $data;
}

function rowsOf(array $r, string $group): array
{
    foreach ($r['metadata'] as $g) {
        if ($g['group'] === $group) {
            return array_column($g['rows'], 1, 0);
        }
    }
    return [];
}

// ---------- tiny assertion helpers ----------

$failures = 0;
$passes = 0;

function check(string $name, bool $ok, string $why = ''): void
{
    global $failures, $passes;
    if ($ok) {
        $passes++;
        echo "  ok   $name\n";
    } else {
        $failures++;
        echo "  FAIL $name" . ($why !== '' ? "  -- $why" : '') . "\n";
    }
}

function expect(string $name, array $r, string $level, array $labels = []): void
{
    $got = array_column($r['evidence'] ?? [], 'label');
    $missing = array_diff($labels, $got);
    check($name, ($r['level'] ?? null) === $level && !$missing,
        'level=' . ($r['level'] ?? 'n/a') . ' evidence=' . json_encode($got));
}

$ds = 'http://cv.iptc.org/newscodes/digitalsourcetype/';

echo "Real files\n";
expect('C2PA manifest declaring algorithmicMedia', scan(file_get_contents(FIX . 'c2pa-algorithmic.jpg')),
    'likely', ['Declared algorithmically generated']);
expect('C2PA manifest without AI declaration', scan(file_get_contents(FIX . 'c2pa-no-ai.jpg')),
    'none', ['Content Credentials, no AI declared']);
expect('Canon photo with full EXIF', scan(file_get_contents(FIX . 'camera.png')),
    'camera', ['Camera capture data', 'Edited with an image editor']);

echo "Generator metadata\n";
expect('A1111 parameters in PNG', scan(png([pngChunk('tEXt',
    "parameters\0a cat in a hat\nSteps: 20, Sampler: Euler a, CFG scale: 7, Seed: 1, Model: sdxl_base")])),
    'ai', ['Stable Diffusion WebUI parameters']);
expect('ComfyUI prompt graph in PNG', scan(png([pngChunk('tEXt',
    "prompt\0" . json_encode(['3' => ['class_type' => 'KSampler', 'inputs' => []]]))])),
    'ai', ['ComfyUI workflow embedded']);
expect('zTXt-compressed A1111 parameters', scan(png([pngChunk('zTXt',
    "parameters\0\0" . gzcompress("x\nSteps: 30, Sampler: DPM++ 2M"))])),
    'ai', ['Stable Diffusion WebUI parameters']);
expect('A1111 parameters in JPEG EXIF UserComment', scan(jpeg([[0xE1, "Exif\0\0" . (function () {
    // IFD0 -> ExifIFD -> UserComment (UNDEFINED, UTF-16BE like piexif writes)
    $uc = "UNICODE\0" . iconv('UTF-8', 'UTF-16BE', "dog\nSteps: 25, Sampler: Euler");
    return "II*\0" . pack('V', 8)
        . pack('v', 1) . pack('vvVV', 0x8769, 4, 1, 26) . pack('V', 0)
        . pack('v', 1) . pack('vvVV', 0x9286, 7, strlen($uc), 44) . pack('V', 0) . $uc;
})()]])), 'ai', ['Stable Diffusion WebUI parameters']);

echo "XMP / IPTC\n";
expect('XMP attribute: trainedAlgorithmicMedia', scan(jpeg([[0xE1, "http://ns.adobe.com/xap/1.0/\0"
    . xmp('Iptc4xmpExt:DigitalSourceType="' . $ds . 'trainedAlgorithmicMedia">')]])),
    'ai', ['Declared fully AI-generated']);
expect('XMP element: compositeWithTrainedAlgorithmicMedia', scan(jpeg([[0xE1, "http://ns.adobe.com/xap/1.0/\0"
    . xmp('><Iptc4xmpExt:DigitalSourceType>' . $ds . 'compositeWithTrainedAlgorithmicMedia</Iptc4xmpExt:DigitalSourceType>')]])),
    'ai', ['Declared edited/composited with AI']);
$r = scan(png([pngChunk('iTXt', "XML:com.adobe.xmp\0\0\0\0\0"
    . xmp('Iptc4xmpExt:DigitalSourceType="' . $ds . 'trainedAlgorithmicMedia">'))]));
expect('XMP in PNG iTXt', $r, 'ai', ['Declared fully AI-generated']);
check('...reported once, not twice (old bug)', count(array_filter($r['evidence'], fn($e) => $e['strength'] === 'strong')) === 1);
expect('XMP CreatorTool names a generator', scan(jpeg([[0xE1, "http://ns.adobe.com/xap/1.0/\0"
    . xmp('xmp:CreatorTool="Adobe Firefly">')]])), 'likely', ['AI tool named as software']);

echo "EXIF software\n";
expect('WebP EXIF Software = Midjourney', scan(webp([['EXIF', tiff([0x0131 => 'Midjourney v6'])]])),
    'likely', ['AI tool named as software']);
expect('Camera make without exposure stays inconclusive', scan(jpeg([[0xE1, "Exif\0\0" . tiff([0x010F => 'Canon'])]])),
    'none', ['Camera named, no exposure data']);

echo "False positives fixed\n";
expect('Spanish "imagen" in a description', scan(png([pngChunk('tEXt', "Description\0Imagen de la playa al atardecer")])),
    'none');
expect('"openai" text in an unrelated chunk', scan(png([pngChunk('tEXt', "Comment\0see openai.com and c2pa.org")])),
    'none');
expect('1024x1024 with no metadata is only a weak hint', scan(png([], 1024, 1024)),
    'hint', ['Common AI output size', 'No metadata at all']);

echo "Deeper metadata inspection\n";
$xmpHead = "http://ns.adobe.com/xap/1.0/\0";
expect('IPTC-IIM credit "Made with Google AI"', scan(jpeg([[0xED, iptcSeg([[110, 'Made with Google AI']])]])),
    'likely', ['Labelled "Made with Google AI"']);
expect('IPTC-IIM by-line names a generator', scan(jpeg([[0xED, iptcSeg([[80, 'Midjourney']])]])),
    'likely', ['AI tool named in By-line']);
expect('caption mentioning a generator is not flagged', scan(jpeg([[0xED, iptcSeg([[120, 'Beach photo, in the style of midjourney']])]])),
    'none');
expect('IPTC 2025 AISystemUsed', scan(jpeg([[0xE1, $xmpHead . xmp('Iptc4xmpExt:AISystemUsed="Imagen 4" Iptc4xmpExt:AISystemVersionUsed="4.0">')]])),
    'ai', ['Declared AI system']);
expect('IPTC 2025 AI prompt', scan(jpeg([[0xE1, $xmpHead . xmp('><Iptc4xmpExt:AIPromptInformation>a red fox, 35mm</Iptc4xmpExt:AIPromptInformation>')]])),
    'ai', ['AI prompt recorded']);
expect('XMP credit in rdf:Alt with entities', scan(jpeg([[0xE1, $xmpHead . xmp('xmlns:photoshop="http://ns.adobe.com/photoshop/1.0/"><photoshop:Credit><rdf:Alt><rdf:li xml:lang="x-default">Made with Google AI &amp; edited</rdf:li></rdf:Alt></photoshop:Credit>')]])),
    'likely', ['Labelled "Made with Google AI"']);
expect('XMP DigitalSourceType as rdf:resource', scan(jpeg([[0xE1, $xmpHead . xmp('><Iptc4xmpExt:DigitalSourceType rdf:resource="' . $ds . 'trainedAlgorithmicMedia"/>')]])),
    'ai', ['Declared fully AI-generated']);
expect('A1111 settings in Windows XPComment', scan(jpeg([[0xE1, "Exif\0\0" . tiffIfds([[0x9C9C, 1, iconv('UTF-8', 'UTF-16LE', "cat\nSteps: 20, Sampler: Euler") . "\0\0"]])]])),
    'ai', ['Stable Diffusion WebUI parameters']);
expect('EXIF size differs from the file (resized)', scan(jpeg([[0xE1, "Exif\0\0" . tiffIfds([], [[0xA002, 4, pack('V', 4000)], [0xA003, 4, pack('V', 3000)]])]])),
    'none', ['Resized since capture']);
expect('Fooocus parameters', scan(png([pngChunk('tEXt', "parameters\0" . json_encode(['Prompt' => 'x', 'Version' => 'Fooocus v2.5.0'])), pngChunk('tEXt', "fooocus_scheme\0fooocus")])),
    'ai', ['Fooocus metadata embedded']);
expect('SwarmUI parameters', scan(png([pngChunk('tEXt', "parameters\0" . json_encode(['sui_image_params' => ['prompt' => 'x']]))])),
    'ai', ['SwarmUI metadata embedded']);
expect('Midjourney job ID', scan(png([pngChunk('tEXt', "Description\0a castle --ar 3:2 Job ID: 0f8c9a3e-1b2c-4d5e-8f90-123456789abc")])),
    'likely', ['Midjourney job ID']);

$r = scan(file_get_contents(FIX . 'camera.png'));
$e = rowsOf($r, 'EXIF');
check('metadata panel lists camera EXIF', ($e['Make'] ?? '') === 'Canon' && ($e['FNumber'] ?? '') === 'f/5.6' && isset($e['LensModel']),
    json_encode(array_slice($e, 0, 6)));
$c = rowsOf(scan(file_get_contents(FIX . 'c2pa-algorithmic.jpg')), 'C2PA');
check('metadata panel lists C2PA actions', str_contains($c['Actions'] ?? '', 'c2pa.created') && ($c['Source type'] ?? '') === 'algorithmicMedia',
    json_encode($c));
$i = rowsOf(scan(jpeg([[0xED, iptcSeg([[25, 'beach'], [25, 'sunset'], [110, 'Jane Doe']])]])), 'IPTC');
check('metadata panel lists IPTC, repeated keywords joined', ($i['Keywords'] ?? '') === 'beach, sunset' && ($i['Credit'] ?? '') === 'Jane Doe',
    json_encode($i));

echo "Sightengine pixel check (stubbed, no network)\n";
$ok = Sightengine::parse('{"status":"success","type":{"ai_generated":0.97,"ai_generators":{"midjourney":0.85,"flux":0.1,"other":0.6}}}', 200);
check('parses score and the likely generator', $ok['ok'] && $ok['score'] === 0.97
    && array_column($ok['generators'], 'name') === ['midjourney'], json_encode($ok));
$bad = Sightengine::parse('{"status":"failure","error":{"type":"credentials_error","message":"Incorrect API user or secret"}}', 401);
check('reports API errors', !$bad['ok'] && str_contains($bad['error'], 'Incorrect API user'), json_encode($bad));
check('rejects a non-JSON answer', !Sightengine::parse('<html>502</html>', 502)['ok']);

$stub = fn(float $s) => fn() => ['ok' => true, 'score' => $s, 'generators' => [['name' => 'flux', 'score' => $s]]];
$plain = png([pngChunk('tEXt', "Description\0beach")]);
$r = scan($plain, $stub(0.97));
check('high pixel score, no metadata => likely AI, measured 97', $r['level'] === 'likely' && $r['score'] === 97 && $r['score_source'] === 'pixel',
    json_encode([$r['level'], $r['score'], $r['score_source']]));
$r = scan($plain, $stub(0.05));
check('low pixel score, no metadata => likely real, measured 5', $r['level'] === 'real' && $r['score'] === 5,
    json_encode([$r['level'], $r['score']]));
$r = scan(png([pngChunk('tEXt', "parameters\0x\nSteps: 20, Sampler: Euler")]), $stub(0.05));
check('declared AI metadata wins over a low pixel score', $r['level'] === 'ai' && $r['score'] === 95 && $r['score_source'] === 'rules',
    json_encode([$r['level'], $r['score'], $r['score_source']]));
$r = scan($plain, fn() => ['ok' => false, 'error' => 'Could not reach Sightengine.']);
check('API failure keeps the metadata result', $r['level'] === 'none' && $r['score'] === null
    && in_array('Pixel analysis unavailable', array_column($r['evidence'], 'label'), true));

echo "Hostile input\n";
// zip bomb: ~50 MB of zeros compressed into a tiny zTXt chunk
$bomb = png([pngChunk('zTXt', "parameters\0\0" . gzcompress(str_repeat("\0", 50 * 1048576), 9))]);
printf("       bomb file is %d KB\n", strlen($bomb) / 1024);
memory_reset_peak_usage();
$m0 = memory_get_usage();
$t = microtime(true);
$r = scan($bomb);
check('zTXt bomb is capped', $r['ok'] && memory_get_peak_usage() - $m0 < 20 * 1048576 && microtime(true) - $t < 2,
    sprintf('%.1f MB, %.2f s', (memory_get_peak_usage() - $m0) / 1048576, microtime(true) - $t));

$cborFails = function (string $bytes): bool {
    try {
        Cbor::decode($bytes);
        return false;
    } catch (RuntimeException) {
        return true;
    }
};
check('CBOR nested 10,000 deep is refused', $cborFails(str_repeat("\x81", 10000) . "\x00"));
check('CBOR claiming a 2^62-item array is refused', $cborFails("\x9B" . pack('J', 1 << 62)));
check('CBOR truncated string is refused', $cborFails("\x78\xFF" . 'abc'));
check('CBOR unterminated indefinite map is refused', $cborFails("\xBF\x61a\x01"));
check('JUMBF with a lying box length is reported unreadable', !C2pa::read(pack('N', 999999) . 'jumb' . 'xxxx')['ok']);

// Mutation fuzz: flip random bytes in every fixture. Must never warn, throw, or hang.
mt_srand(1234);
$seeds = [
    file_get_contents(FIX . 'c2pa-algorithmic.jpg'),
    png([pngChunk('tEXt', "parameters\0Steps: 1, Sampler: x"), pngChunk('iTXt', "XML:com.adobe.xmp\0\0\0\0\0" . xmp('a="b">'))]),
    webp([['EXIF', tiff([0x0131 => 'Midjourney', 0x010F => 'Canon'])]]),
    jpeg([[0xED, iptcSeg([[110, 'Made with Google AI'], [25, 'a'], [120, 'caption']])],
          [0xE1, "Exif\0\0" . tiffIfds([[0x9C9C, 1, "x\0y\0"]], [[0xA002, 4, pack('V', 9)]])]]),
];
$crashes = 0;
$slowest = 0.0;
for ($i = 0; $i < 300; $i++) {
    $b = $seeds[$i % count($seeds)];
    for ($k = 0, $flips = mt_rand(1, 20); $k < $flips; $k++) {
        $b[mt_rand(0, strlen($b) - 1)] = chr(mt_rand(0, 255));
    }
    $t = microtime(true);
    try {
        $r = scan($b);
        if (!isset($r['ok'])) {
            $crashes++;
        }
    } catch (Throwable $e) {
        $crashes++;
        echo '       ' . get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ")\n";
    }
    $slowest = max($slowest, microtime(true) - $t);
}
check('300 mutated files: no warnings or exceptions', $crashes === 0, "$crashes crashed");
check('300 mutated files: each under 1 s', $slowest < 1, sprintf('slowest %.2f s', $slowest));

echo "\n$passes passed, $failures failed\n";
exit($failures ? 1 : 0);
