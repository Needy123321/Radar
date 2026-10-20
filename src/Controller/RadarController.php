<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class RadarController extends AbstractController
{
    private const CACHE_TTL = 8; // Обновяване от външните API-та максимум веднъж на 8 секунди

    #[Route('/radar', name: 'app_radar')]
    public function index(): Response
    {
        $response = $this->render('radar/index.html.twig');
        // Забраняваме на браузъра да кешира стар HTML/JS код при деплой
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        return $response;
    }

    #[Route('/api/planes', name: 'api_planes')]
    public function getPlanes(Request $request, HttpClientInterface $client): Response
    {
        // Централна точка за регионалния буфер (покрива 350nm / ~650km радиус - цяла България и Балканите)
        $centerLat = 43.37;
        $centerLon = 24.67;

        $cacheDir = sys_get_temp_dir();
        $cacheFile = $cacheDir . '/radar_bg_master_buffer.json';
        $lockFile  = $cacheDir . '/radar_bg_master_buffer.lock';

        $now = time();
        $cachedJson = null;
        $cacheAge = 999999;

        if (file_exists($cacheFile)) {
            $cacheAge = $now - (int) @filemtime($cacheFile);
            $cachedJson = @file_get_contents($cacheFile);
        }

        // 1. БЪРЗ БУФЕР: Ако кешът е пресен (под 8 сек), връщаме го веднага като суров JSON (0% CPU разход!)
        if ($cachedJson && $cacheAge < self::CACHE_TTL && strlen($cachedJson) > 10) {
            return $this->createBufferedResponse($cachedJson, 'HIT', $cacheAge);
        }

        // 2. ЗАЩИТА ОТ ЗАПИЧАНЕ (Mutex Lock):
        // Само 1 процес има право да тегли от външните API-та.
        // Ако друг телефон вече е стартирал обновяване в тази секунда, останалите веднага взимат стария буфер без да чакат!
        $lockFp = @fopen($lockFile, 'c+');
        $gotLock = $lockFp && @flock($lockFp, LOCK_EX | LOCK_NB);

        if (!$gotLock) {
            if ($lockFp) {
                @fclose($lockFp);
            }
            if ($cachedJson && strlen($cachedJson) > 10) {
                return $this->createBufferedResponse($cachedJson, 'STALE-LOCKED', $cacheAge);
            }
            return $this->createBufferedResponse('[]', 'EMPTY-LOCKED', 0);
        }

        // Този процес спечели ключалката -> обновява буфера за всички устройства
        try {
            // Докосваме файла веднага, за да знаят другите процеси, че се обновява
            if (file_exists($cacheFile)) {
                @touch($cacheFile);
            }

            $planesMap = [];
            $headers = [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/124.0.0.0 Safari/537.36',
                'Accept'     => 'application/json',
            ];

            // Използваме най-бързите и надеждни потоци с голям радиус (350 морски мили = 648 км)
            $endpoints = [
                'airplanes_local' => ['url' => "https://api.airplanes.live/v2/point/{$centerLat}/{$centerLon}/350", 'mil' => false],
                'airplanes_mil'   => ['url' => "https://api.airplanes.live/v2/mil", 'mil' => true],
                'adsb_lol_local'  => ['url' => "https://api.adsb.lol/v2/point/{$centerLat}/{$centerLon}/350", 'mil' => false],
                'adsb_lol_mil'    => ['url' => "https://api.adsb.lol/v2/mil", 'mil' => true],
            ];

            $asyncResponses = [];
            foreach ($endpoints as $key => $cfg) {
                try {
                    $asyncResponses[$key] = [
                        'res' => $client->request('GET', $cfg['url'], [
                            'timeout'      => 2.2,
                            'max_duration' => 2.5,
                            'headers'      => $headers,
                        ]),
                        'mil' => $cfg['mil']
                    ];
                } catch (\Throwable $e) {
                }
            }

            foreach ($asyncResponses as $item) {
                try {
                    if ($item['res']->getStatusCode() === 200) {
                        $data = $item['res']->toArray(false);
                        $list = $data['ac'] ?? $data['aircraft'] ?? [];
                        $this->parseAdsbV2List($list, $planesMap, $centerLat, $centerLon, $item['mil']);
                    }
                } catch (\Throwable $e) {
                    continue;
                }
            }

            $finalPlanes = array_values($planesMap);

            // Ако сме получили самолети -> записваме ги атомарно в буфера
            if (count($finalPlanes) > 0) {
                $newJson = json_encode($finalPlanes, JSON_UNESCAPED_UNICODE);
                $tmpFile = $cacheFile . '.tmp.' . getmypid();
                if (@file_put_contents($tmpFile, $newJson) !== false) {
                    @rename($tmpFile, $cacheFile);
                }
                return $this->createBufferedResponse($newJson, 'MISS-UPDATED', 0);
            }

            // Ако външните API-та временно са върнали празно -> пазим стария буфер жив!
            if ($cachedJson && strlen($cachedJson) > 10) {
                return $this->createBufferedResponse($cachedJson, 'FALLBACK-CACHE', $cacheAge);
            }

            return $this->createBufferedResponse('[]', 'EMPTY', 0);
        } finally {
            if ($lockFp) {
                @flock($lockFp, LOCK_UN);
                @fclose($lockFp);
            }
        }
    }

    private function createBufferedResponse(string $jsonContent, string $bufferStatus, int $ageSeconds): Response
    {
        $response = new Response($jsonContent, 200, [
            'Content-Type'           => 'application/json; charset=UTF-8',
            'Cache-Control'          => 'public, max-age=3, stale-while-revalidate=5',
            'X-Radar-Buffer-Status'  => $bufferStatus,
            'X-Radar-Buffer-Age'     => (string) $ageSeconds,
        ]);

        return $response;
    }

    private function parseAdsbV2List(array $aircraftList, array &$planesMap, float $centerLat, float $centerLon, bool $isMilitaryFeed): void
    {
        foreach ($aircraftList as $ac) {
            $lat = $ac['lat'] ?? $ac['latitude'] ?? null;
            $lon = $ac['lon'] ?? $ac['longitude'] ?? null;

            if ($lat === null || $lon === null) {
                continue;
            }

            $latF = (float) $lat;
            $lonF = (float) $lon;

            if (abs($latF - $centerLat) > 5.0 || abs($lonF - $centerLon) > 6.5) {
                continue;
            }

            $id = strtolower(trim((string) ($ac['hex'] ?? '')));
            if ($id === '') {
                continue;
            }

            $dbFlags = (int) ($ac['dbFlags'] ?? 0);
            $rawCallsign = trim((string) ($ac['flight'] ?? $ac['call'] ?? ''));
            $squawk = (string) ($ac['squawk'] ?? '----');
            $aircraftType = trim((string) ($ac['t'] ?? $ac['type'] ?? ''));
            $desc = trim((string) ($ac['desc'] ?? ''));
            $operator = trim((string) ($ac['ownOp'] ?? $ac['op'] ?? ''));

            $isMil = $isMilitaryFeed
                || (($dbFlags & 1) === 1)
                || $this->isKnownMilitary($id, $rawCallsign, $squawk, $aircraftType, $operator);

            $displayCallsign = $rawCallsign !== '' ? $rawCallsign : strtoupper($id);
            if ($isMil && !str_contains($displayCallsign, '[ВОЕНЕН]')) {
                $displayCallsign .= ' [ВОЕНЕН]';
            }

            $altBaro = $ac['alt_baro'] ?? $ac['alt_geom'] ?? $ac['alt'] ?? 0;
            $altitudeMeters = is_numeric($altBaro) ? (int) round(((float) $altBaro) * 0.3048) : 0;

            $gsKnots = (float) ($ac['gs'] ?? $ac['spd'] ?? 0);
            $speedKmh = (int) round($gsKnots * 1.852);

            $track = (float) ($ac['track'] ?? $ac['true_heading'] ?? $ac['mag_heading'] ?? 0);
            $seenPos = (float) ($ac['seen_pos'] ?? $ac['seen'] ?? 0.5);

            $existing = $planesMap[$id] ?? null;

            $typeLabel = $desc !== '' ? $desc : ($aircraftType !== '' ? $aircraftType : ($existing['type'] ?? 'Няма данни'));
            $opLabel = $operator !== '' ? $operator : ($existing['operator'] ?? ($isMil ? 'Военновъздушни сили (ВВС)' : 'Граждански полет'));

            if ($existing && str_contains($existing['callsign'], '[ВОЕНЕН]') && !str_contains($displayCallsign, '[ВОЕНЕН]')) {
                $displayCallsign .= ' [ВОЕНЕН]';
            }

            $planesMap[$id] = [
                'id'       => $id,
                'callsign' => $displayCallsign,
                'lat'      => $latF,
                'lon'      => $lonF,
                'track'    => $track,
                'altitude' => $altitudeMeters,
                'speed'    => $speedKmh,
                'squawk'   => $squawk !== '' ? $squawk : ($existing['squawk'] ?? '----'),
                'type'     => $typeLabel,
                'operator' => $opLabel,
                'age'      => min($seenPos, 15.0),
            ];
        }
    }

    private function isKnownMilitary(string $hex, string $callsign, string $squawk, string $type, string $operator): bool
    {
        $hex = strtolower($hex);
        $cs = strtoupper($callsign);
        $type = strtoupper($type);
        $op = strtoupper($operator);

        if (str_starts_with($hex, '45f4') || str_starts_with($hex, 'ae') || str_starts_with($hex, 'af') || str_starts_with($hex, '43c') || str_starts_with($hex, '3f')) {
            return true;
        }

        if ($squawk === '7777' || str_starts_with($squawk, '44') || str_starts_with($squawk, '74')) {
            return true;
        }

        $milPrefixes = [
            'BAF', 'BULAF', 'BGF', 'NATO', 'FORTE', 'LAGR', 'NCHO', 'JAKE', 'RCH', 'RRR',
            'ASCOT', 'DUKE', 'HKY', 'HERKY', 'SPARTAN', 'VIPER', 'COBRA', 'HAWK', 'PANTHER',
            'GAF', 'FAF', 'CTM', 'IAM', 'AME', 'TUAF', 'HAF', 'ROAF', 'PLF', 'FNY', 'MMF',
            'CNV', 'PAT', 'EVAC', 'SAM', 'SPAR', 'BALL', 'HOBO', 'SNTRY', 'DARK'
        ];
        foreach ($milPrefixes as $prefix) {
            if ($cs !== '' && str_starts_with($cs, $prefix)) {
                return true;
            }
        }

        $milTypes = [
            'F16', 'C27J', 'C130', 'C30J', 'PC9', 'PC12', 'L39', 'MIG29', 'MG29', 'SU25',
            'AS32', 'AS65', 'BELL', 'UH60', 'H60', 'CH47', 'MI8', 'MI17', 'MI24',
            'KC135', 'K35R', 'KC46', 'A332', 'A400', 'C17', 'E3TF', 'E3CF', 'E737',
            'P8', 'EP3', 'RC135', 'R135', 'RQ4', 'MQ9', 'B52', 'F15', 'F18', 'F35', 'TYPH', 'EUFI', 'RAFL'
        ];
        foreach ($milTypes as $mt) {
            if ($type !== '' && str_contains($type, $mt)) {
                return true;
            }
        }

        $milOpKeywords = ['AIR FORCE', 'MILITARY', 'NAVY', 'ARMY', 'NATO', 'DEFENCE', 'DEFENSE', 'LUFTWAFFE', 'GUARD', 'ВВС'];
        foreach ($milOpKeywords as $kw) {
            if ($op !== '' && str_contains($op, $kw)) {
                return true;
            }
        }

        return false;
    }
}
