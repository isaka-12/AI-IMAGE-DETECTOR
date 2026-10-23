<?php
declare(strict_types=1);

/**
 * Sightengine: pixel-based AI-image detection ("genai" model) over HTTPS.
 *
 * Unlike the metadata checks this looks at the image content itself, so it
 * still works on screenshots and re-saved files. It sends the image to a
 * third party, which is why the UI makes it opt-in per scan.
 *
 * Nothing about the service is hardcoded: the endpoint, user and secret all
 * come from the .env file mounted into the container (see docker/entrypoint.sh)
 * as SIGHTENGINE_ENDPOINT, SIGHTENGINE_API_USER and SIGHTENGINE_API_SECRET.
 */
final class Sightengine
{
    private const TIMEOUT = 15;

    public static function configured(): bool
    {
        return self::env('SIGHTENGINE_ENDPOINT') !== ''
            && self::env('SIGHTENGINE_API_USER') !== ''
            && self::env('SIGHTENGINE_API_SECRET') !== '';
    }

    /** @return array{ok:bool, score?:float, generators?:list<array{name:string, score:float}>, error?:string} */
    public static function check(string $path, string $mime): array
    {
        if (!self::configured()) {
            return ['ok' => false, 'error' => 'Sightengine is not configured on this server.'];
        }

        $ch = curl_init(self::env('SIGHTENGINE_ENDPOINT'));
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => [
                'media'      => new CURLFile($path, $mime, 'image'),
                'models'     => 'genai',
                'api_user'   => self::env('SIGHTENGINE_API_USER'),
                'api_secret' => self::env('SIGHTENGINE_API_SECRET'),
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            error_log('sightengine request failed: ' . $err);
            return ['ok' => false, 'error' => 'Could not reach Sightengine.'];
        }
        return self::parse((string) $body, $code);
    }

    /**
     * Turns the API answer into a score. Public so it can be tested without the network.
     * Expected: {"status":"success","type":{"ai_generated":0.97,"ai_generators":{"midjourney":0.9,...}}}
     *
     * @return array{ok:bool, score?:float, generators?:list<array{name:string, score:float}>, error?:string}
     */
    public static function parse(string $body, int $httpCode): array
    {
        $j = json_decode($body, true);
        $score = $j['type']['ai_generated'] ?? null;

        if (!is_array($j) || ($j['status'] ?? '') !== 'success' || !is_numeric($score)) {
            $msg = is_array($j) && is_string($j['error']['message'] ?? null) ? $j['error']['message'] : 'Unexpected answer';
            error_log("sightengine error (HTTP $httpCode): " . substr($msg, 0, 200));
            return ['ok' => false, 'error' => 'Sightengine: ' . substr($msg, 0, 120)];
        }

        // the most likely generators, if the model names any with some confidence
        $generators = [];
        foreach ((array) ($j['type']['ai_generators'] ?? []) as $name => $s) {
            if (is_string($name) && is_numeric($s) && $s >= 0.2 && $name !== 'other') {
                $generators[] = ['name' => substr($name, 0, 40), 'score' => (float) $s];
            }
        }
        usort($generators, fn($a, $b) => $b['score'] <=> $a['score']);

        return [
            'ok'         => true,
            'score'      => max(0.0, min(1.0, (float) $score)),
            'generators' => array_slice($generators, 0, 3),
        ];
    }

    private static function env(string $name): string
    {
        return (string) ($_SERVER[$name] ?? getenv($name) ?: '');
    }
}
