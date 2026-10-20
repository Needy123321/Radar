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
    #[Route('/radar', name: 'app_radar')]
    public function index(): Response
    {
        return $this->render('radar/index.html.twig');
    }

    #[Route('/api/planes', name: 'api_planes')]
    public function getPlanes(Request $request, HttpClientInterface $client): JsonResponse
    {
        // Закръгляме координатите до 2 знака, за да не се чупи кешът при минимално мърдане на GPS-а
        $myLat = round((float) $request->query->get('lat', 43.37), 2);
        $myLon = round((float) $request->query->get('lon', 24.67), 2);

        if ($myLat < 35.0 || $myLat > 50.0 || $myLon < 15.0 || $myLon > 35.0) {
            $myLat = 43.37;
            $myLon = 24.67;
        }

        $cacheFile = sys_get_temp_dir() . '/radar_render_stable_cache.json';

        // 1. Ако кешът е на по-малко от 10 секунди -> връщаме го веднага (0.001 сек, без да тормозим API-тата)
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 10) {
            $cachedContent = @file_get_contents($cacheFile);
            if ($cachedContent) {
                $decoded = json_decode($cachedContent, true);
                if (is_array($decoded) && count($decoded) > 0) {
                    return new JsonResponse($decoded);
                }
            }
        }

        $planesMap = [];

        $headers = [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/124.0.0.0 Safari/537.36',
            'Accept' => 'application/json',
        ];

        // 2. Стартираме всички заявки ПАРАЛЕЛНО с кратък таймаут (2.8 сек общо!), за да не зависва Render
        $endpoints = [
            'airplanes_local' => ['url' => "https://api.airplanes.live/v2/point/{$myLat}/{$myLon}/250", 'mil' => false],
            'airplanes_mil'   => ['url' => "https://api.airplanes.live/v2/mil", 'mil' => true],
            'adsb_lol_local'  => ['url' => "https://api.adsb.lol/v2/point/{$myLat}/{$myLon}/250", 'mil' => false],
            'adsb_lol_mil'    => ['url' => "https://api.adsb.lol/v2/mil", 'mil' => true],
            'adsb_fi_local'   => ['url' => "https://opendata.adsb.fi/api/v2/lat/{$myLat}/lon/{$myLon}/dist/250", 'mil' => false],
        ];

        $asyncResponses = [];
        foreach ($endpoints as $key => $cfg) {
            try {
                $asyncResponses[$key] = [
                    'res' => $client->request('GET', $cfg['url'], [
                        'timeout' => 2.5,
                        'max_duration' => 2.8,
                        'headers' => $headers,
                    ]),
                    'mil' => $cfg['mil']
                ];
            } catch (\Throwable $e) {
            }
        }

        // Събираме само тези, които са отговорили бързо и успешно
        foreach ($asyncResponses as $item) {
            try {
                if ($item['res']->getStatusCode() === 200) {
                    $data = $item['res']->toArray(false);
                    $list = $data['ac'] ?? $data['aircraft'] ?? [];
                    $this->parseAdsbV2List($list, $planesMap, $myLat, $myLon, $item['mil']);
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        $finalPlanes = array_values($planesMap);

        // 3. АКО ИМАМЕ НОВИ САМОЛЕТИ -> презаписваме кеша и ги връщаме
        if (count($finalPlanes) > 0) {
            @file_put_contents($cacheFile, json_encode($finalPlanes));
            return new JsonResponse($finalPlanes);
        }

        // 4. ЗЛАТНА ЗАЩИТА: Ако в тази секунда всички API-та са блокирали Render,
        // връщаме последния запазен кеш (за да НЕ изчезват самолетите от екрана!)
        if (file_exists($cacheFile)) {
            $fallbackContent = @file_get_contents($cacheFile);
            if ($fallbackContent) {
                $decodedFallback = json_decode($fallbackContent, true);
                if (is_array($decodedFallback) && count($decodedFallback) > 0) {
                    return new JsonResponse($decodedFallback);
                }
            }
        }

        return new JsonResponse([]);
    }

    private function parseAdsbV2List(array $aircraftList, array &$planesMap, float $myLat, float $myLon, bool $isMilitaryFeed): void
    {
        foreach ($aircraftList as $ac) {
            $lat = $ac['lat'] ?? $ac['latitude'] ?? null;
            $lon = $ac['lon'] ?? $ac['longitude'] ?? null;

            if ($lat === null || $lon === null) {
                continue;
            }

            if (abs((float)$lat - $myLat) > 4.5 || abs((float)$lon - $myLon) > 6.0) {
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
                'lat'      => (float) $lat,
                'lon'      => (float) $lon,
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
