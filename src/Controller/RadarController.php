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
    #[Route('/radar', name: 'app_radar')]
    public function index(): Response
    {
        return $this->render('radar/index.html.twig');
    }

    #[Route('/api/planes', name: 'api_planes')]
    public function getPlanes(Request $request, HttpClientInterface $httpClient): JsonResponse
    {
        // Дефолтни координати: с. Радишево
        $lat = (float) $request->query->get('lat', 43.3556);
        $lon = (float) $request->query->get('lon', 24.6653);

        // Граници за региона (~400 км около теб)
        $lamin = number_format($lat - 3.5, 4, '.', '');
        $lamax = number_format($lat + 3.5, 4, '.', '');
        $lomin = number_format($lon - 4.5, 4, '.', '');
        $lomax = number_format($lon + 4.5, 4, '.', '');

        $latStr = number_format($lat, 4, '.', '');
        $lonStr = number_format($lon, 4, '.', '');

        $allPlanes = [];

        // Всички източници: OpenSky, ADSB.lol (локален + специален военен /mil), Airplanes.live (/mil) и IntelSky
        $urls = [
            'OpenSky'        => "https://opensky-network.org/api/states/all?lamin={$lamin}&lomin={$lomin}&lamax={$lamax}&lomax={$lomax}",
            'ADSB.lol'       => "https://api.adsb.lol/v2/lat/{$latStr}/lon/{$lonStr}/dist/350",
            'ADSB.lol-MIL'   => 'https://api.adsb.lol/v2/mil',
            'Airplanes.live' => 'https://api.airplanes.live/v2/mil',
            'IntelSky'       => 'https://intelsky.org/api/',
        ];

        $responses = [];
        foreach ($urls as $sourceName => $url) {
            try {
                $responses[$sourceName] = $httpClient->request('GET', $url, [
                    'timeout' => 4,
                    'headers' => [
                        'User-Agent' => 'MilitaryRadar-Radishevo/1.0',
                        'Accept'     => 'application/json',
                    ],
                ]);
            } catch (Throwable $e) {}
        }

        // 1. OpenSky (Граждански)
        try {
            if (isset($responses['OpenSky']) && $responses['OpenSky']->getStatusCode() === 200) {
                $dataOS = $responses['OpenSky']->toArray(false);
                if (isset($dataOS['states']) && is_array($dataOS['states'])) {
                    foreach ($dataOS['states'] as $state) {
                        $icao = trim(strtolower($state[0] ?? ''));
                        if ($icao && isset($state[6]) && isset($state[5])) {
                            $allPlanes[$icao] = [
                                'id'       => $icao,
                                'callsign' => trim($state[1] ?? 'Няма позивна'),
                                'lat'      => (float)$state[6],
                                'lon'      => (float)$state[5],
                                'altitude' => round((float)($state[7] ?? 0)),
                                'speed'    => round(((float)($state[9] ?? 0)) * 3.6),
                                'track'    => (float)($state[10] ?? 0),
                                'source'   => 'OpenSky',
                                'type'     => 'Неизвестен',
                                'operator' => 'Неизвестен'
                            ];
                        }
                    }
                }
            }
        } catch (Throwable $e) {}

        // 2. ADSB.lol, Airplanes.live и IntelSky (Граждански + Военни с /mil ендпойнтите)
        $adsbSources = ['ADSB.lol', 'ADSB.lol-MIL', 'Airplanes.live', 'IntelSky'];
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
                    $acLat = isset($ac['lat']) ? (float)$ac['lat'] : 0.0;
                    $acLon = isset($ac['lon']) ? (float)$ac['lon'] : 0.0;

                    if (!$icao || $acLat === 0.0 || $acLon === 0.0) {
                        continue;
                    }

                    $rawCallsign = trim($ac['flight'] ?? '');
                    $typeDesc = trim(($ac['t'] ?? '') . ' ' . ($ac['desc'] ?? ''));
                    $operator = trim($ac['ownOp'] ?? 'Неизвестен');
                    $dbFlags = (int)($ac['dbFlags'] ?? 0);

                    // Проверяваме дали самолетът е военен
                    $isMilEndpoint = str_contains($src, 'MIL') || $src === 'IntelSky';
                    $isFlaggedMil = ($dbFlags & 1) === 1;
                    $isHeuristicMil = $this->isKnownMilitary($icao, $rawCallsign, $operator, $typeDesc);

                    $isMilitary = $isMilEndpoint || $isFlaggedMil || $isHeuristicMil;

                    $cleanCallsign = $rawCallsign ?: strtoupper($icao);
                    if ($isMilitary && !str_contains($cleanCallsign, '[ВОЕНЕН]')) {
                        $cleanCallsign .= ' [ВОЕНЕН]';
                    }

                    if (isset($allPlanes[$icao])) {
                        if (!str_contains($allPlanes[$icao]['source'], $src)) {
                            $allPlanes[$icao]['source'] .= ', ' . $src;
                        }
                        if ($isMilitary && !str_contains($allPlanes[$icao]['callsign'], '[ВОЕНЕН]')) {
                            $allPlanes[$icao]['callsign'] = $cleanCallsign;
                        }
                        if ($allPlanes[$icao]['type'] === 'Неизвестен' && $typeDesc) {
                            $allPlanes[$icao]['type'] = $typeDesc;
                            $allPlanes[$icao]['operator'] = $operator;
                        }
                    } else {
                        $allPlanes[$icao] = [
                            'id'       => $icao,
                            'callsign' => $cleanCallsign,
                            'lat'      => $acLat,
                            'lon'      => $acLon,
                            'altitude' => round(((float)($ac['alt_baro'] ?? $ac['alt_geom'] ?? 0)) * 0.3048),
                            'speed'    => round(((float)($ac['gs'] ?? 0)) * 1.852),
                            'track'    => (float)($ac['track'] ?? 0),
                            'source'   => $src,
                            'type'     => $typeDesc ?: 'Неизвестен',
                            'operator' => $operator
                        ];
                    }
                }
            } catch (Throwable $e) {}
        }

        return new JsonResponse(array_values($allPlanes));
    }

    private function isKnownMilitary(string $icao, string $callsign, string $operator, string $type): bool
    {
        $cs = strtoupper($callsign);
        $op = strtoupper($operator);
        $tp = strtoupper($type);

        $milPrefixes = ['BAF', 'ROF', 'RCH', 'NATO', 'FORTE', 'LAGR', 'HOMER', 'JAKE', 'NCHO', 'DUKE', 'VIPER', 'RRR', 'ASCOT', 'IAM', 'GAF', 'FAF', 'CTM', 'PLF', 'HUF', 'TUAF', 'HVK'];
        foreach ($milPrefixes as $prefix) {
            if (str_starts_with($cs, $prefix)) return true;
        }

        $milKeywords = ['AIR FORCE', 'MILITARY', 'NAVY', 'ARMY', 'NATO', 'DEFENCE', 'DEFENSE', 'ВВС'];
        foreach ($milKeywords as $kw) {
            if (str_contains($op, $kw)) return true;
        }

        $milTypes = ['C27J', 'C130', 'C30J', 'C17', 'K35R', 'E3TF', 'E3CF', 'F16', 'F35', 'F15', 'EUFI', 'P8', 'A400', 'AS32', 'MI8', 'MI17', 'MI24', 'UH60'];
        if (in_array($tp, $milTypes, true)) return true;

        if (str_starts_with($icao, '45f4')) return true;

        return false;
    }
}
