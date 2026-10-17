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
        // Точни координати на базата в с. Радишево
        $lat = (float) $request->query->get('lat', 43.36763806288808);
        $lon = (float) $request->query->get('lon', 24.66672222828328);

        // Числови граници за региона (~420 км около теб - Балканите и Черно море)
        $minLat = $lat + (-3.8);
        $maxLat = $lat + 3.8;
        $minLon = $lon + (-4.8);
        $maxLon = $lon + 4.8;

        $lamin = number_format($minLat, 4, '.', '');
        $lamax = number_format($maxLat, 4, '.', '');
        $lomin = number_format($minLon, 4, '.', '');
        $lomax = number_format($maxLon, 4, '.', '');

        $latStr = number_format($lat, 4, '.', '');
        $lonStr = number_format($lon, 4, '.', '');

        $allPlanes = [];

        // 8 потока: Всички цивилни + Всички съществуващи военни агрегатори
        $urls = [
            'OpenSky'           => "https://opensky-network.org/api/states/all?lamin={$lamin}&lomin={$lomin}&lamax={$lamax}&lomax={$lomax}",
            'ADSB.lol'          => "https://api.adsb.lol/v2/lat/{$latStr}/lon/{$lonStr}/dist/350",
            'Airplanes-Local'   => "https://api.airplanes.live/v2/point/{$latStr}/{$lonStr}/350",
            'ADSB.lol-MIL'      => 'https://api.adsb.lol/v2/mil',
            'Airplanes-MIL'     => 'https://api.airplanes.live/v2/mil',
            'ADSB.fi-MIL'       => 'https://opendata.adsb.fi/api/v2/mil',
            'TheAirTraffic-MIL' => 'https://api.theairtraffic.com/v2/mil',
            'IntelSky'          => 'https://intelsky.org/api/',
        ];

        $responses = [];
        foreach ($urls as $sourceName => $url) {
            try {
                $responses[$sourceName] = $httpClient->request('GET', $url, [
                    'timeout' => 4,
                    'headers' => [
                        'User-Agent' => 'MilitaryRadar-Radishevo/2.0',
                        'Accept'     => 'application/json',
                    ],
                ]);
            } catch (Throwable $e) {}
        }

        // 1. OpenSky (Граждански + Военни в региона)
        try {
            if (isset($responses['OpenSky']) && $responses['OpenSky']->getStatusCode() === 200) {
                $dataOS = $responses['OpenSky']->toArray(false);
                if (isset($dataOS['states']) && is_array($dataOS['states'])) {
                    foreach ($dataOS['states'] as $state) {
                        $icao = trim(strtolower($state[0] ?? ''));
                        if ($icao && isset($state[6]) && isset($state[5])) {
                            $rawCallsign = trim($state[1] ?? '');
                            $squawk = trim((string)($state[14] ?? ''));
                            $isMil = $this->isKnownMilitary($icao, $rawCallsign, '', '', $squawk);
                            $callsign = ($rawCallsign ?: strtoupper($icao)) . ($isMil ? ' [ВОЕНЕН]' : '');

                            $speedMs = (float) ($state[9] ?? 0);
                            $speedKmh = round($speedMs / 0.277778);

                            $allPlanes[$icao] = [
                                'id'       => $icao,
                                'squawk'   => $squawk ?: '----',
                                'callsign' => $callsign,
                                'lat'      => (float) $state[6],
                                'lon'      => (float) $state[5],
                                'altitude' => round((float) ($state[7] ?? 0)),
                                'speed'    => $speedKmh,
                                'track'    => (float) ($state[10] ?? 0),
                                'source'   => 'OpenSky',
                                'type'     => $isMil ? 'Военен обект' : 'Неизвестен',
                                'operator' => $isMil ? 'Военновъздушни сили' : 'Неизвестен'
                            ];
                        }
                    }
                }
            }
        } catch (Throwable $e) {}

        // 2. Всички ADSB потоци (Локални цивилни + Глобални военни)
        $adsbSources = [
            'ADSB.lol',
            'Airplanes-Local',
            'ADSB.lol-MIL',
            'Airplanes-MIL',
            'ADSB.fi-MIL',
            'TheAirTraffic-MIL',
            'IntelSky'
        ];

        foreach ($adsbSources as $src) {
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

                    // Режем самолетите извън Балканите и Черно море
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

                    $isMilEndpoint = str_contains($src, 'MIL') || $src === 'IntelSky';
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

                    if (isset($allPlanes[$icao])) {
                        $allPlanes[$icao]['lat'] = $acLat;
                        $allPlanes[$icao]['lon'] = $acLon;
                        if ($altitude > 0) {
                            $allPlanes[$icao]['altitude'] = $altitude;
                        }
                        if (($allPlanes[$icao]['squawk'] ?? '----') === '----' && $squawk !== '') {
                            $allPlanes[$icao]['squawk'] = $squawk;
                        }
                        if (!str_contains($allPlanes[$icao]['source'], $src)) {
                            $allPlanes[$icao]['source'] .= ', ' . $src;
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
                            'track'    => (float) ($ac['track'] ?? 0),
                            'source'   => $src,
                            'type'     => $typeDesc ?: ($isMilitary ? 'Военен обект' : 'Неизвестен'),
                            'operator' => ($operator !== 'Неизвестен') ? $operator : ($isMilitary ? 'Военновъздушни сили' : 'Неизвестен')
                        ];
                    }
                }
            } catch (Throwable $e) {}
        }

        return new JsonResponse(array_values($allPlanes));
    }

    private function isKnownMilitary(string $icao, string $callsign, string $operator, string $typeCode, string $squawk = ''): bool
    {
        $hex = strtolower(trim($icao));
        $cs  = strtoupper(trim($callsign));
        $op  = strtoupper(trim($operator));
        $tp  = strtoupper(trim($typeCode));

        // 1. Военни ICAO Hex блокове
        $milHexPrefixes = [
            '45f4', // България ВВС
            'adf', 'ae', 'af', // САЩ ВВС / ВМС / Армия
            '43c',  // Великобритания RAF
            '3b',   // Франция ВВС
            '3f',   // Германия Luftwaffe
            '4d03', // НАТО AWACS
            '4781', // Норвегия ВВС
            '480',  // Нидерландия ВВС
            '33f',  // Италия ВВС
            '4b82', // Турция ВВС
        ];
        foreach ($milHexPrefixes as $hPrefix) {
            if (str_starts_with($hex, $hPrefix)) {
                return true;
            }
        }

        // 2. Военни позивни (Callsigns)
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
            if ($cs !== '' && str_starts_with($cs, $prefix)) {
                return true;
            }
        }

        // 3. Ключови думи в оператора
        $milKeywords = [
            'AIR FORCE', 'MILITARY', 'NAVY', 'ARMY', 'NATO', 'DEFENCE', 'DEFENSE',
            'ВВС', 'MARINE', 'GUARD', 'SQUADRON', 'LUFTWAFFE', 'ARMÉE', 'AERONAUTICA',
            'FORTELE AERIENE', 'GOVERNMENT', 'STATE', 'POLICE', 'BORDER'
        ];
        foreach ($milKeywords as $kw) {
            if ($op !== '' && str_contains($op, $kw)) {
                return true;
            }
        }

        // 4. Военни модели самолети, хеликоптери и дронове
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
        if ($tp !== '' && in_array($tp, $milTypes, true)) {
            return true;
        }

        // 5. Военни Squawk кодове
        if ($squawk !== '') {
            if (str_starts_with($squawk, '44') || str_starts_with($squawk, '74') || $squawk === '7777') {
                return true;
            }
        }

        return false;
    }
}
