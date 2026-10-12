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
        // Взимаме координатите сигурно
        $lat = (float) $request->query->get('lat', 43.4170);
        $lon = (float) $request->query->get('lon', 24.6067);

        // Изчисляваме границите за OpenSky и IntelSky (твърдо форматирани с точка)
        $lamin = number_format($lat - 2.5, 4, '.', '');
        $lamax = number_format($lat + 2.5, 4, '.', '');
        $lomin = number_format($lon - 2.5, 4, '.', '');
        $lomax = number_format($lon + 2.5, 4, '.', '');

        $latStr = number_format($lat, 4, '.', '');
        $lonStr = number_format($lon, 4, '.', '');

        $allPlanes = [];

        $urlOpenSky = "https://opensky-network.org/api/states/all?lamin={$lamin}&lomin={$lomin}&lamax={$lamax}&lomax={$lomax}";
        $urlAdsbLol = "https://api.adsb.lol/v2/lat/{$latStr}/lon/{$lonStr}/dist/250";
        $urlIntelSky = "https://intelsky.org/api/";

        // Правим заявките паралелно с 4 секунди таймаут, за да не бавим излишно
        $responses = [
            'OpenSky' => $httpClient->request('GET', $urlOpenSky, ['timeout' => 4]),
            'ADSB.lol' => $httpClient->request('GET', $urlAdsbLol, ['timeout' => 4]),
            'IntelSky' => $httpClient->request('GET', $urlIntelSky, ['timeout' => 4]),
        ];

        // 1. OpenSky
        try {
            if ($responses['OpenSky']->getStatusCode() === 200) {
                $dataOS = $responses['OpenSky']->toArray(false);
                if (isset($dataOS['states']) && is_array($dataOS['states'])) {
                    foreach ($dataOS['states'] as $state) {
                        $icao = trim(strtolower($state[0] ?? ''));
                        if ($icao && isset($state[6]) && isset($state[5])) {
                            $allPlanes[$icao] = [
                                'id' => $icao,
                                'callsign' => trim($state[1] ?? 'Няма позивна'),
                                'lat' => (float)$state[6],
                                'lon' => (float)$state[5],
                                'altitude' => round((float)($state[7] ?? 0)),
                                'speed' => round(((float)($state[9] ?? 0)) * 3.6),
                                'track' => (float)($state[10] ?? 0),
                                'source' => 'OpenSky',
                                'type' => 'Неизвестен',
                                'operator' => 'Неизвестен'
                            ];
                        }
                    }
                }
            }
        } catch (Throwable $e) {}

        // 2. ADSB.lol
        try {
            if ($responses['ADSB.lol']->getStatusCode() === 200) {
                $dataAdsb = $responses['ADSB.lol']->toArray(false);
                if (isset($dataAdsb['ac']) && is_array($dataAdsb['ac'])) {
                    foreach ($dataAdsb['ac'] as $ac) {
                        $icao = trim(strtolower($ac['hex'] ?? ''));
                        if ($icao && isset($ac['lat']) && isset($ac['lon'])) {
                            $typeDesc = trim(($ac['t'] ?? '') . ' ' . ($ac['desc'] ?? ''));
                            $operator = trim($ac['ownOp'] ?? 'Неизвестен');

                            if (isset($allPlanes[$icao])) {
                                if (!str_contains($allPlanes[$icao]['source'], 'ADSB.lol')) {
                                    $allPlanes[$icao]['source'] .= ', ADSB.lol';
                                }
                                if ($allPlanes[$icao]['type'] === 'Неизвестен' && $typeDesc) {
                                    $allPlanes[$icao]['type'] = $typeDesc;
                                    $allPlanes[$icao]['operator'] = $operator;
                                }
                            } else {
                                $allPlanes[$icao] = [
                                    'id' => $icao,
                                    'callsign' => trim($ac['flight'] ?? 'Няма позивна'),
                                    'lat' => (float)$ac['lat'],
                                    'lon' => (float)$ac['lon'],
                                    'altitude' => round(((float)($ac['alt_baro'] ?? 0)) * 0.3048),
                                    'speed' => round(((float)($ac['gs'] ?? 0)) * 1.852),
                                    'track' => (float)($ac['track'] ?? 0),
                                    'source' => 'ADSB.lol',
                                    'type' => $typeDesc ?: 'Неизвестен',
                                    'operator' => $operator
                                ];
                            }
                        }
                    }
                }
            }
        } catch (Throwable $e) {}

        // 3. IntelSky
        try {
            if ($responses['IntelSky']->getStatusCode() === 200) {
                $dataIntel = $responses['IntelSky']->toArray(false);
                if (isset($dataIntel['ac']) && is_array($dataIntel['ac'])) {
                    foreach ($dataIntel['ac'] as $flight) {
                        $lat_ac = (float)($flight['lat'] ?? 0);
                        $lon_ac = (float)($flight['lon'] ?? 0);
                        $icao = trim(strtolower($flight['hex'] ?? ''));

                        if ($lat_ac >= $lat - 2.5 && $lat_ac <= $lat + 2.5 && $lon_ac >= $lon - 2.5 && $lon_ac <= $lon + 2.5) {
                            if ($icao && $lat_ac != 0 && $lon_ac != 0) {
                                $isMilitary = isset($flight['dbFlags']) ? ' [ВОЕНЕН]' : '';
                                $callsign = trim($flight['flight'] ?? 'Няма позивна') . $isMilitary;
                                $typeDesc = trim(($flight['t'] ?? '') . ' - ' . ($flight['desc'] ?? ''));
                                $operator = trim($flight['ownOp'] ?? 'Неизвестен');

                                if (isset($allPlanes[$icao])) {
                                    if (!str_contains($allPlanes[$icao]['source'], 'IntelSky')) {
                                        $allPlanes[$icao]['source'] .= ', IntelSky';
                                    }
                                    $allPlanes[$icao]['callsign'] = $callsign;
                                    $allPlanes[$icao]['type'] = $typeDesc;
                                    $allPlanes[$icao]['operator'] = $operator;
                                } else {
                                    $allPlanes[$icao] = [
                                        'id' => $icao,
                                        'callsign' => $callsign,
                                        'lat' => $lat_ac,
                                        'lon' => $lon_ac,
                                        'altitude' => round(((float)($flight['alt_baro'] ?? 0)) * 0.3048),
                                        'speed' => round(((float)($flight['gs'] ?? 0)) * 1.852),
                                        'track' => (float)($flight['track'] ?? 0),
                                        'source' => 'IntelSky',
                                        'type' => $typeDesc ?: 'Неизвестен',
                                        'operator' => $operator
                                    ];
                                }
                            }
                        }
                    }
                }
            }
        } catch (Throwable $e) {}

        return new JsonResponse(array_values($allPlanes));
    }
}
