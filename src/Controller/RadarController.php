<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

class RadarController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    #[Route('/radar', name: 'app_radar')]
    public function index(): Response
    {
        return $this->render('radar/index.html.twig');
    }

    #[Route('/api/planes', name: 'api_planes')]
    public function getPlanes(Request $request, HttpClientInterface $httpClient): JsonResponse
    {
        $requestStartTime = microtime(true);

        // Точни координати на базата в с. Радишево
        $lat = (float) $request->query->get('lat', 43.36763806288808);
        $lon = (float) $request->query->get('lon', 24.66672222828328);

        $minLat = $lat + (-4.5);
        $maxLat = $lat + 4.5;
        $minLon = $lon + (-5.5);
        $maxLon = $lon + 5.5;

        $latStr = number_format($lat, 4, '.', '');
        $lonStr = number_format($lon, 4, '.', '');

        $allPlanes = [];

        // Бързите и надеждни ADS-B агрегатори (отговарят за ~0.3 - 0.5 сек без лимити!)
        // Радиус 250 морски мили (~460 км) покрива цяла България, Румъния, Сърбия, Гърция и Черно море
        $adsbUrls = [
            'ADSB.lol'       => "https://api.adsb.lol/v2/lat/{$latStr}/lon/{$lonStr}/dist/250",
            'Airplanes.live' => "https://api.airplanes.live/v2/point/{$latStr}/{$lonStr}/250",
            'ADSB.fi'        => "https://opendata.adsb.fi/api/v2/lat/{$latStr}/lon/{$lonStr}/dist/250",
            'ADSB.lol-MIL'   => 'https://api.adsb.lol/v2/mil',
            'Airplanes-MIL'  => 'https://api.airplanes.live/v2/mil',
            'ADSB.fi-MIL'    => 'https://opendata.adsb.fi/api/v2/mil',
        ];

        $responses = [];
        foreach ($adsbUrls as $sourceName => $url) {
            try {
                $responses[$sourceName] = $httpClient->request('GET', $url, [
                    'timeout'      => 3.5,
                    'max_duration' => 3.5,
                    'headers'      => [
                        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/126.0.0.0 Safari/537.36',
                        'Accept'     => 'application/json',
                    ],
                ]);
            } catch (Throwable $e) {}
        }

        foreach ($adsbUrls as $src => $url) {
            try {
                if (!isset($responses[$src]) || $responses[$src]->getStatusCode() !== 200) {
                    continue;
                }

                $dataAdsb = $responses[$src]->toArray(false);
                $aircraftList = $dataAdsb['ac'] ?? $dataAdsb['aircraft'] ?? [];

                if (!is_array($aircraftList)) {
                    continue;
                }

                foreach ($aircraftList as $ac) {
                    $icao = trim(strtolower($ac['hex'] ?? ''));
                    $acLat = isset($ac['lat']) ? (float) $ac['lat'] : 0.0;
                    $acLon = isset($ac['lon']) ? (float) $ac['lon'] : 0.0;

                    if (!$icao || $acLat === 0.0 || $acLon === 0.0) {
                        continue;
                    }

                    // За глобалните военни списъци филтрираме само Балканите и Черно море
                    if ($acLat < $minLat || $acLat > $maxLat || $acLon < $minLon || $acLon > $maxLon) {
                        continue;
                    }

                    $rawCallsign = trim($ac['flight'] ?? '');
                    $typeCode = trim($ac['t'] ?? '');
                    $desc = trim($ac['desc'] ?? '');
                    $typeDesc = trim($typeCode . ($desc ? ' - ' . $desc : ''));
                    $operator = trim($ac['ownOp'] ?? 'Неизвестен');
                    $squawk = trim((string)($ac['squawk'] ?? ''));
                    $dbFlags = (int) ($ac['dbFlags'] ?? 0);

                    $isMilEndpoint = str_contains($src, 'MIL');
                    $isFlaggedMil = ($dbFlags & 1) === 1;
                    $isHeuristicMil = $this->isKnownMilitary($icao, $rawCallsign, $operator, $typeCode, $squawk);

                    $isMilitary = $isMilEndpoint || $isFlaggedMil || $isHeuristicMil;

                    $cleanCallsign = $rawCallsign ?: strtoupper($icao);
                    if ($isMilitary && !str_contains($cleanCallsign, '[ВОЕНЕН]')) {
                        $cleanCallsign .= ' [ВОЕНЕН]';
                    }

                    $altRaw = $ac['alt_baro'] ?? $ac['alt_geom'] ?? 0;
                    $altitude = is_numeric($altRaw) ? round(((float) $altRaw) / 3.28084) : 0;

                    $gsKnots = (float) ($ac['gs'] ?? 0);
                    $speedKmh = round($gsKnots / 0.539957);
                    $trackDeg = (float) ($ac['track'] ?? $ac['true_heading'] ?? $ac['mag_heading'] ?? 0);

                    $posAge = isset($ac['seen_pos']) ? (float) $ac['seen_pos'] : (isset($ac['seen']) ? (float) $ac['seen'] : 0.8);
                    if ($posAge < 0 || $posAge > 60) {
                        $posAge = 0.8;
                    }

                    if (isset($allPlanes[$icao])) {
                        $existingAge = (float) ($allPlanes[$icao]['age'] ?? 99.0);
                        if ($posAge <= $existingAge + 1.0) {
                            $allPlanes[$icao]['lat'] = $acLat;
                            $allPlanes[$icao]['lon'] = $acLon;
                            $allPlanes[$icao]['age'] = $posAge;
                            if ($speedKmh > 0) $allPlanes[$icao]['speed'] = $speedKmh;
                            if ($trackDeg > 0) $allPlanes[$icao]['track'] = $trackDeg;
                            if ($altitude > 0) $allPlanes[$icao]['altitude'] = $altitude;
                        }
                        if (($allPlanes[$icao]['squawk'] ?? '----') === '----' && $squawk !== '') {
                            $allPlanes[$icao]['squawk'] = $squawk;
                        }
                        if ($isMilitary && !str_contains($allPlanes[$icao]['callsign'], '[ВОЕНЕН]')) {
                            $allPlanes[$icao]['callsign'] = $cleanCallsign;
                        }
                        if (($allPlanes[$icao]['type'] === 'Неизвестен' || $allPlanes[$icao]['type'] === 'Военен обект') && $typeDesc) {
                            $allPlanes[$icao]['type'] = $typeDesc;
                        }
                        if (($allPlanes[$icao]['operator'] === 'Неизвестен' || $allPlanes[$icao]['operator'] === 'Военновъздушни сили') && $operator !== 'Неизвестен') {
                            $allPlanes[$icao]['operator'] = $operator;
                        }
                    } else {
                        $allPlanes[$icao] = [
                            'id'       => $icao,
                            'squawk'   => $squawk ?: '----',
                            'callsign' => $cleanCallsign,
                            'lat'      => $acLat,
                            'lon'      => $acLon,
                            'altitude' => $altitude,
                            'speed'    => $speedKmh,
                            'track'    => $trackDeg,
                            'age'      => $posAge,
                            'source'   => $src,
                            'type'     => $typeDesc ?: ($isMilitary ? 'Военен обект' : 'Неизвестен'),
                            'operator' => ($operator !== 'Неизвестен') ? $operator : ($isMilitary ? 'Военновъздушни сили' : 'Неизвестен')
                        ];
                    }
                }
            } catch (Throwable $e) {}
        }

        $fetchDuration = max(0.0, microtime(true) + (-$requestStartTime));
        foreach ($allPlanes as &$p) {
            $p['age'] = round(($p['age'] ?? 0.8) + $fetchDuration, 2);
        }
        unset($p);

        return new JsonResponse(array_values($allPlanes));
    }

    private function isKnownMilitary(string $icao, string $callsign, string $operator, string $typeCode, string $squawk = ''): bool
    {
        $hex = strtolower(trim($icao));
        $cs  = strtoupper(trim($callsign));
        $op  = strtoupper(trim($operator));
        $tp  = strtoupper(trim($typeCode));

        $milHexPrefixes = ['45f4', 'adf', 'ae', 'af', '43c', '3b', '3f', '4d03', '4781', '480', '33f', '4b82'];
        foreach ($milHexPrefixes as $hPrefix) {
            if (str_starts_with($hex, $hPrefix)) return true;
        }

        $milPrefixes = [
            'BAF', 'BULAF', 'ROF', 'RCH', 'REACH', 'NATO', 'MAGIC', 'FORTE',
            'LAGR', 'NCHO', 'QUID', 'PACK', 'GOLD', 'CLEAN', 'HOMER', 'JAKE',
            'OLIVE', 'COBRA', 'SNUCK', 'DUKE', 'YANK', 'ARTEMIS', 'BRIO',
            'VIPER', 'VENOM', 'HAWK', 'FALCON', 'EAGLE', 'TIGER', 'WOLF', 'JEDI', 'REAPER',
            'RRR', 'ASCOT', 'TARTAN', 'LOSSIE', 'IAM', 'GAF', 'FAF', 'CTM', 'FNY',
            'PLF', 'HUF', 'BART', 'TUAF', 'HVK', 'TCB', 'HAF', 'HRZ', 'SVK', 'SQF',
            'CEF', 'SUI', 'MMF', 'CNV', 'VV', 'PAT', 'ARMY', 'EVAC', 'MEDEVAC', 'RESCUE', 'SAR'
        ];
        foreach ($milPrefixes as $prefix) {
            if ($cs !== '' && str_starts_with($cs, $prefix)) return true;
        }

        $milKeywords = [
            'AIR FORCE', 'MILITARY', 'NAVY', 'ARMY', 'NATO', 'DEFENCE', 'DEFENSE',
            'ВВС', 'MARINE', 'GUARD', 'SQUADRON', 'LUFTWAFFE', 'ARMÉE', 'AERONAUTICA',
            'FORTELE AERIENE', 'GOVERNMENT', 'STATE', 'POLICE', 'BORDER'
        ];
        foreach ($milKeywords as $kw) {
            if ($op !== '' && str_contains($op, $kw)) return true;
        }

        $milTypes = [
            'C27J', 'C130', 'C30J', 'C17', 'C5M', 'A400', 'AN12', 'AN26', 'AN32', 'IL76',
            'K35R', 'K35E', 'KC46', 'A332', 'CN35', 'C295', 'L410', 'C12', 'BE20', 'P180',
            'F16', 'F35', 'F15', 'F18', 'FA18', 'EUFI', 'RFAL', 'TORN', 'JAS39', 'SB39',
            'MIG21', 'MG29', 'SU24', 'SU25', 'SU27', 'SU30', 'L39', 'PC9', 'PC21', 'T6', 'M346',
            'E3TF', 'E3CF', 'E737', 'R135', 'E8', 'E6', 'P8', 'P3', 'CL60', 'GLF5', 'GLEX',
            'Q4', 'RQ4', 'MQ9', 'Q9', 'TB2', 'HERON',
            'AS32', 'AS52', 'EC25', 'H215', 'UH60', 'S70', 'AH64', 'CH47', 'H47',
            'MI8', 'MI17', 'MI24', 'MI35', 'BELL', 'B206', 'NH90', 'AW139', 'AW149', 'KA27', 'KA52', 'V22'
        ];
        if ($tp !== '' && in_array($tp, $milTypes, true)) return true;

        if ($squawk !== '' && (str_starts_with($squawk, '44') || str_starts_with($squawk, '74') || $squawk === '7777')) {
            return true;
        }

        return false;
    }
}
