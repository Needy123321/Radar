<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class RadarController extends AbstractController
{
    private const CACHE_TTL = 12;
    private const PROVIDER_COOLDOWN = 300;
    private const MAX_HISTORY_FRAMES = 120;
    private const SEARCH_RADIUS_NM = 250;

    // Максимална възраст на позицията в секунди (реже фантоми)
    private const MAX_POS_AGE_SEC = 60.0;

    #[Route('/', name: 'app_home')]
    public function home(): Response
    {
        return $this->redirectToRoute('app_radar');
    }

    #[Route('/radar', name: 'app_radar')]
    public function index(): Response
    {
        $response = $this->render('radar/index.html.twig');
        $response->headers->set(
            'Cache-Control',
            'no-store, no-cache, must-revalidate, max-age=0'
        );

        return $response;
    }

    #[Route('/api/planes', name: 'api_planes')]
    public function getPlanes(Request $request): JsonResponse
    {
        $centerLat = (float) $request->query->get('lat', 43.3664995);
        $centerLon = (float) $request->query->get('lon', 24.6621337);
        $force = $request->query->getBoolean('force', false);

        if (
            !is_finite($centerLat) ||
            !is_finite($centerLon) ||
            $centerLat < -90 || $centerLat > 90 ||
            $centerLon < -180 || $centerLon > 180
        ) {
            return new JsonResponse(['error' => 'Invalid coordinates'], 400);
        }

        $locKey = round($centerLat, 2) . '_' . round($centerLon, 2);
        $cacheDir = sys_get_temp_dir();
        $cacheFile = $cacheDir . '/radar_live_buffer_' . $locKey . '.json';
        $lockFile = $cacheDir . '/radar_live_' . $locKey . '.lock';
        $now = time();

        if (
            !$force &&
            is_file($cacheFile) &&
            ($now - (int) @filemtime($cacheFile)) < self::CACHE_TTL
        ) {
            $cached = @file_get_contents($cacheFile);
            $decoded = is_string($cached) ? json_decode($cached, true) : null;

            if (is_array($decoded)) {
                return new JsonResponse($decoded);
            }
        }

        $lockFp = @fopen($lockFile, 'c+');
        $gotLock = $lockFp && @flock($lockFp, LOCK_EX | LOCK_NB);

        if (!$gotLock) {
            if ($lockFp) {
                @fclose($lockFp);
            }

            $cached = is_file($cacheFile) ? @file_get_contents($cacheFile) : false;
            $decoded = is_string($cached) ? json_decode($cached, true) : null;

            return new JsonResponse(is_array($decoded) ? $decoded : []);
        }

        try {
            $planesMap = [];
            $providerNamesWithData = [];

            // 1. Локални ADS-B v2 потоци + Военни потоци (паралелно)
            $adsbProviders = [
                [
                    'name' => 'adsb.fi',
                    'base' => 'adsb.fi',
                    'url'  => "https://opendata.adsb.fi/api/v2/lat/{$centerLat}/lon/{$centerLon}/dist/" . self::SEARCH_RADIUS_NM,
                    'mil'  => false,
                ],
                [
                    'name' => 'airplanes.live',
                    'base' => 'airplanes.live',
                    'url'  => "https://api.airplanes.live/v2/point/{$centerLat}/{$centerLon}/" . self::SEARCH_RADIUS_NM,
                    'mil'  => false,
                ],
                [
                    'name' => 'adsb.lol',
                    'base' => 'adsb.lol',
                    'url'  => "https://api.adsb.lol/v2/point/{$centerLat}/{$centerLon}/" . self::SEARCH_RADIUS_NM,
                    'mil'  => false,
                ],
                [
                    'name' => 'airplanes.live-mil',
                    'base' => 'airplanes.live-mil',
                    'url'  => 'https://api.airplanes.live/v2/mil',
                    'mil'  => true,
                ],
                [
                    'name' => 'adsb.lol-mil',
                    'base' => 'adsb.lol-mil',
                    'url'  => 'https://api.adsb.lol/v2/mil',
                    'mil'  => true,
                ],
            ];

            $responses = $this->curlFetchMany($adsbProviders);

            foreach ($adsbProviders as $provider) {
                $name = $provider['name'];
                $base = $provider['base'];

                if ($this->providerIsCoolingDown($base)) {
                    continue;
                }

                $res = $responses[$name] ?? ['code' => 0, 'body' => '', 'error' => 'No response', 'time_ms' => 0];

                if (in_array($res['code'], [403, 429], true)) {
                    $this->putProviderInCooldown($base);
                    continue;
                }

                if ($res['code'] !== 200 || $res['body'] === '') {
                    continue;
                }

                $data = json_decode($res['body'], true);
                if (!is_array($data)) {
                    continue;
                }

                $list = $data['ac'] ?? $data['aircraft'] ?? [];
                if (!is_array($list) || $list === []) {
                    continue;
                }

                $before = count($planesMap);
                $this->parseAdsbV2List(
                    $list,
                    $planesMap,
                    $centerLat,
                    $centerLon,
                    (bool) $provider['mil'],
                    $name
                );

                if (count($planesMap) > $before) {
                    $providerNamesWithData[] = $name;
                }
            }

            // 2. Публичен MLAT/ADS-B фийд от Flightradar24 (хваща PC-9M и местните приемници в БГ)
            if (!$this->providerIsCoolingDown('fr24')) {
                $fr24Url = $this->buildFr24Url($centerLat, $centerLon);
                $frRes = $this->curlFetch($fr24Url, true);

                if (in_array($frRes['code'], [403, 429], true)) {
                    $this->putProviderInCooldown('fr24');
                } elseif ($frRes['code'] === 200 && $frRes['body'] !== '') {
                    $frData = json_decode($frRes['body'], true);
                    if (is_array($frData)) {
                        $before = count($planesMap);
                        $this->parseFr24Data($frData, $planesMap, $centerLat, $centerLon);
                        if (count($planesMap) > $before) {
                            $providerNamesWithData[] = 'FR24';
                        }
                    }
                }
            }

            // 3. OpenSky САМО като авариен резерв
            if ($planesMap === []) {
                $openSkyUrl = $this->buildOpenSkyUrl($centerLat, $centerLon, self::SEARCH_RADIUS_NM);
                $openSky = $this->curlFetch($openSkyUrl);

                if ($openSky['code'] === 200 && $openSky['body'] !== '') {
                    $openSkyData = json_decode($openSky['body'], true);
                    if (is_array($openSkyData) && !empty($openSkyData['states']) && is_array($openSkyData['states'])) {
                        $this->parseOpenSkyList($openSkyData['states'], $planesMap, $centerLat, $centerLon);
                        if ($planesMap !== []) {
                            $providerNamesWithData[] = 'OpenSky';
                        }
                    }
                }
            }

            foreach ($planesMap as &$plane) {
                unset($plane['_source']);
            }
            unset($plane);

            $finalPlanes = array_values($planesMap);

            if ($finalPlanes === []) {
                $old = is_file($cacheFile) ? @file_get_contents($cacheFile) : false;
                $decoded = is_string($old) ? json_decode($old, true) : null;

                if (is_array($decoded) && $decoded !== []) {
                    return new JsonResponse($decoded);
                }

                return new JsonResponse([]);
            }

            usort($finalPlanes, static fn(array $a, array $b): int => strcmp($a['id'] ?? '', $b['id'] ?? ''));

            $jsonPayload = json_encode(
                $finalPlanes,
                JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
            );

            if (is_string($jsonPayload)) {
                @file_put_contents($cacheFile, $jsonPayload, LOCK_EX);
            }

            $this->appendHistorySnapshot($cacheDir, $now, $finalPlanes);

            error_log(sprintf(
                '[RADAR] FINAL AIRCRAFT=%d SOURCES=%s',
                count($finalPlanes),
                implode(',', array_unique($providerNamesWithData))
            ));

            return new JsonResponse($finalPlanes);
        } finally {
            if ($lockFp) {
                @flock($lockFp, LOCK_UN);
                @fclose($lockFp);
            }
        }
    }

    #[Route('/api/history', name: 'api_history')]
    public function getHistory(Request $request): JsonResponse
    {
        $cacheDir = sys_get_temp_dir();
        $indexFile = $cacheDir . '/radar_history_index.json';

        // Миграция/изчистване на стария огромен 22MB файл, ако все още стои в /tmp
        $legacyHugeFile = $cacheDir . '/radar_history_ring.json';
        if (is_file($legacyHugeFile)) {
            @unlink($legacyHugeFile);
        }

        if (!is_file($indexFile)) {
            return new JsonResponse(['timestamps' => [], 'planes' => [], 'total' => 0]);
        }

        $rawIndex = @file_get_contents($indexFile);
        $indexData = is_string($rawIndex) ? json_decode($rawIndex, true) : [];

        if (!is_array($indexData) || $indexData === []) {
            return new JsonResponse(['timestamps' => [], 'planes' => [], 'total' => 0]);
        }

        $frameIndex = $request->query->get('index');

        // Ако потребителят иска конкретен кадър от плъзгача, четем САМО малкия файл за този слот (~60 KB)!
        if ($frameIndex !== null) {
            $idx = (int) $frameIndex;
            if (isset($indexData[$idx])) {
                $slotNum = (int) ($indexData[$idx]['slot'] ?? -1);
                $ts = (int) ($indexData[$idx]['ts'] ?? 0);
                $slotFile = $cacheDir . '/radar_hist_slot_' . $slotNum . '.json';

                $planes = [];
                if ($slotNum >= 0 && is_file($slotFile)) {
                    $rawSlot = @file_get_contents($slotFile);
                    $decodedSlot = is_string($rawSlot) ? json_decode($rawSlot, true) : [];
                    if (is_array($decodedSlot)) {
                        $planes = $decodedSlot;
                    }
                }

                return new JsonResponse([
                    'timestamp' => $ts,
                    'planes'    => $planes,
                    'total'     => count($indexData),
                ]);
            }

            return new JsonResponse(['timestamp' => null, 'planes' => [], 'total' => count($indexData)]);
        }

        return new JsonResponse([
            'timestamps' => array_map(static fn($item) => $item['ts'] ?? null, $indexData),
            'total'      => count($indexData),
        ]);
    }

    #[Route('/api/ai-brief', name: 'api_ai_brief', methods: ['POST'])]
    public function aiBrief(Request $request): JsonResponse
    {
        $rawKey = $_ENV['GEMINI_API_KEY'] ?? $_SERVER['GEMINI_API_KEY'] ?? getenv('GEMINI_API_KEY') ?: '';
        $apiKey = trim((string) $rawKey, " \t\n\r\0\x0B\"'");

        if ($apiKey === '') {
            return new JsonResponse([
                'brief' => '⚠️ Липсва GEMINI_API_KEY в настройките на сървъра (.env или Render Environment).'
            ]);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return new JsonResponse(['error' => 'Invalid payload'], 400);
        }

        $hex      = strtoupper(trim((string) ($payload['id'] ?? 'НЕИЗВЕСТЕН')));
        $hexLower = strtolower($hex);
        $callsign = strtoupper(trim((string) ($payload['callsign'] ?? '')));
        $squawk   = trim((string) ($payload['squawk'] ?? '----'));
        $type     = trim((string) ($payload['type'] ?? 'Неизвестен тип'));
        $operator = trim((string) ($payload['operator'] ?? 'Неизвестен оператор'));
        $altM     = (int) ($payload['altitude'] ?? 0);
        $speedKmh = (int) ($payload['speed'] ?? 0);
        $trackDeg = (int) ($payload['track'] ?? 0);
        $aziDeg   = (int) ($payload['bearing'] ?? 0);
        $distKm   = round((float) ($payload['distKm'] ?? 0), 1);
        $isMil    = !empty($payload['isMilitary']);
        $route    = trim((string) ($payload['route'] ?? 'Няма данни'));
        $trailPts = is_array($payload['trail'] ?? null) ? array_slice($payload['trail'], -15) : [];

        // Текущи координати на целта (от последната точка на следата или подадените)
        $lastPt   = !empty($trailPts) ? end($trailPts) : null;
        $planeLat = (float) ($payload['lat'] ?? ($lastPt['lat'] ?? 43.3665));
        $planeLon = (float) ($payload['lon'] ?? ($lastPt['lon'] ?? 24.6621));

        // 1. ПАРАЛЕЛНО ИЗТЕГЛЯНЕ ОТ 5 ВЪНШНИ РАЗУЗНАВАТЕЛНИ API-ТА НАВЕДНЪЖ (~0.4 сек)
        $osintQueries = [];
        if ($hex !== '' && !str_starts_with($hex, 'FR_')) {
            $osintQueries[] = [
                'name' => 'adsbdb_ac',
                'base' => 'adsbdb_ac',
                'url'  => 'https://api.adsbdb.com/v0/aircraft/' . urlencode($hexLower),
            ];
            $osintQueries[] = [
                'name' => 'hexdb_ac',
                'base' => 'hexdb_ac',
                'url'  => 'https://hexdb.io/api/v1/aircraft/' . urlencode($hexLower),
            ];
            $osintQueries[] = [
                'name' => 'live_telemetry',
                'base' => 'live_telemetry',
                'url'  => 'https://api.airplanes.live/v2/hex/' . urlencode($hexLower),
            ];
        }
        if ($callsign !== '' && !str_starts_with($callsign, 'FR_')) {
            $osintQueries[] = [
                'name' => 'adsbdb_cs',
                'base' => 'adsbdb_cs',
                'url'  => 'https://api.adsbdb.com/v0/callsign/' . urlencode($callsign),
            ];
        }
        // Метео, ветрове на височина и релеф под самолета (Open-Meteo API)
        $osintQueries[] = [
            'name' => 'meteo_terrain',
            'base' => 'meteo_terrain',
            'url'  => sprintf(
                'https://api.open-meteo.com/v1/forecast?latitude=%.4f&longitude=%.4f&current=temperature_2m,wind_speed_10m,wind_direction_10m&hourly=wind_speed_850hPa,wind_direction_850hPa,wind_speed_300hPa,wind_direction_300hPa,temperature_300hPa&forecast_days=1',
                $planeLat,
                $planeLon
            ),
        ];

        $osintResponses = $this->curlFetchMany($osintQueries);

        // 2. ОБРАБОТКА НА ДОСИЕТО ОТ РЕГИСТРИТЕ (ADSBDB + HEXDB)
        $osintReg      = 'Няма данни';
        $osintCountry  = 'Няма данни';
        $osintFullType = $type;
        $osintRoute    = $route;

        if (($osintResponses['adsbdb_ac']['code'] ?? 0) === 200) {
            $acObj = json_decode($osintResponses['adsbdb_ac']['body'], true)['response']['aircraft'] ?? null;
            if (is_array($acObj)) {
                $osintReg      = (string) ($acObj['registration'] ?? $osintReg);
                $osintCountry  = (string) ($acObj['registered_owner_country_name'] ?? $osintCountry);
                $osintFullType = trim(($acObj['manufacturer'] ?? '') . ' ' . ($acObj['type'] ?? $type));
                if (!empty($acObj['registered_owner'])) {
                    $operator = (string) $acObj['registered_owner'];
                }
            }
        }

        // Допълване от HexDB.io, ако липсват данни в ADSBdb
        if (($osintResponses['hexdb_ac']['code'] ?? 0) === 200) {
            $hxObj = json_decode($osintResponses['hexdb_ac']['body'], true);
            if (is_array($hxObj)) {
                if ($osintReg === 'Няма данни' && !empty($hxObj['Registration'])) {
                    $osintReg = (string) $hxObj['Registration'];
                }
                if (($osintFullType === '' || $osintFullType === 'Неизвестен тип') && !empty($hxObj['Type'])) {
                    $osintFullType = trim(($hxObj['Manufacturer'] ?? '') . ' ' . $hxObj['Type']);
                }
                if (($operator === 'Неизвестен оператор' || $operator === 'Граждански полет') && !empty($hxObj['RegisteredOwners'])) {
                    $operator = (string) $hxObj['RegisteredOwners'];
                }
            }
        }

        if (($osintResponses['adsbdb_cs']['code'] ?? 0) === 200) {
            $frObj = json_decode($osintResponses['adsbdb_cs']['body'], true)['response']['flightroute'] ?? null;
            if (is_array($frObj)) {
                $orig     = $frObj['origin']['municipality'] ?? ($frObj['origin']['name'] ?? '');
                $origIcao = $frObj['origin']['icao_code'] ?? '';
                $dest     = $frObj['destination']['municipality'] ?? ($frObj['destination']['name'] ?? '');
                $destIcao = $frObj['destination']['icao_code'] ?? '';
                $airline  = $frObj['airline']['name'] ?? '';
                $osintRoute = "{$orig} ({$origIcao}) -> {$dest} ({$destIcao})" . ($airline ? " | Авиокомпания: {$airline}" : '');
            }
        }

        // 3. ДЪЛБОКА ТЕЛЕМЕТРИЯ ОТ ТРАНСПОНДЕРА И АВТОПИЛОТА (MCP / Вертикална скорост / GPS Integrity)
        $telemetryNote = 'Стандартно ADS-B излъчване';
        if (($osintResponses['live_telemetry']['code'] ?? 0) === 200) {
            $acLive = json_decode($osintResponses['live_telemetry']['body'], true)['ac'][0] ?? null;
            if (is_array($acLive)) {
                $tParts = [];
                if (isset($acLive['baro_rate'])) {
                    $vRateMps = round(((float) $acLive['baro_rate']) * 0.00508, 1);
                    $vSign    = $vRateMps > 0 ? '+' : '';
                    $tParts[] = "Верт. скорост: {$vSign}{$vRateMps} m/s";
                }
                if (isset($acLive['nav_altitude_mcp'])) {
                    $mcpMeters = (int) round(((float) $acLive['nav_altitude_mcp']) * 0.3048);
                    $tParts[]  = "Зададена височина в автопилота (MCP): {$mcpMeters} м";
                }
                if (isset($acLive['mach'])) {
                    $tParts[] = 'Бордови Mach: ' . round((float) $acLive['mach'], 2);
                }
                if (isset($acLive['rssi'])) {
                    $tParts[] = 'Ниво на сигнала (RSSI): ' . $acLive['rssi'] . ' dBFS';
                }
                if (isset($acLive['nic']) && (int) $acLive['nic'] < 5) {
                    $tParts[] = '⚠️ НИСКА GPS ТОЧНОСТ (NIC=' . $acLive['nic'] . ' - възможни РЕБ/GPS смущения!)';
                }
                if (!empty($acLive['r']) && $osintReg === 'Няма данни') {
                    $osintReg = (string) $acLive['r'];
                }
                if ($tParts !== []) {
                    $telemetryNote = implode(' | ', $tParts);
                }
            }
        }

        // 4. РЕЛЕФ ПОД ЦЕЛТА (AGL ВИСОЧИНА), ВЯТЪР НА ЕШЕЛОНА (TAS / MACH) И РАДИОХОРИЗОНТ
        $terrainElevationM = 180;
        $aglMeters         = max(0, $altM - $terrainElevationM);
        $windInfo          = 'Няма данни';

        if (($osintResponses['meteo_terrain']['code'] ?? 0) === 200) {
            $meteo = json_decode($osintResponses['meteo_terrain']['body'], true);
            if (is_array($meteo)) {
                $terrainElevationM = (int) round((float) ($meteo['elevation'] ?? 180));
                $aglMeters         = max(0, $altM - $terrainElevationM);

                $hourIdx = (int) gmdate('G');
                if ($altM > 5500) {
                    $wSpd = (float) ($meteo['hourly']['wind_speed_300hPa'][$hourIdx] ?? 0);
                    $wDir = (float) ($meteo['hourly']['wind_direction_300hPa'][$hourIdx] ?? 0);
                } elseif ($altM > 1000) {
                    $wSpd = (float) ($meteo['hourly']['wind_speed_850hPa'][$hourIdx] ?? 0);
                    $wDir = (float) ($meteo['hourly']['wind_direction_850hPa'][$hourIdx] ?? 0);
                } else {
                    $wSpd = (float) ($meteo['current']['wind_speed_10m'] ?? 0);
                    $wDir = (float) ($meteo['current']['wind_direction_10m'] ?? 0);
                }

                // Изчисляване на попътен/насрещен компонент на вятъра и Истинска въздушна скорост (TAS)
                $angleDiffRad = deg2rad($wDir - $trackDeg);
                $headwindKmh  = (int) round($wSpd * cos($angleDiffRad));
                $tasKmh       = max(0, $speedKmh + $headwindKmh);
                $soundSpdKmh  = $altM > 8000 ? 1065 : ($altM > 4000 ? 1140 : 1210);
                $estMach      = round($tasKmh / $soundSpdKmh, 2);

                $windType = $headwindKmh >= 0 ? "насрещен {$headwindKmh} км/ч" : 'попътен ' . abs($headwindKmh) . ' км/ч';
                $windInfo = "Вятър на височината: " . round($wSpd) . " км/ч от " . round($wDir) . "° ({$windType}) | Истинска въздушна скорост (TAS): ~{$tasKmh} км/ч (Mach {$estMach})";
            }
        }

        // Формула за радиохоризонт на УКВ/РЛС спрямо с. Радишево (~240м н.в. + антена)
        $radarHorizonKm = (int) round(4.12 * (sqrt(250) + sqrt(max(10, $altM))));
        $horizonStatus  = $distKm <= $radarHorizonKm
            ? "В ПРЯКА РАДИОВИДИМОСТ (Радиохоризонт за тази височина: {$radarHorizonKm} км)"
            : "На ръба/отвъд стандартния радиохоризонт ({$radarHorizonKm} км) - приема се чрез рефракция/височинен коридор";

        // 5. ГЕОМЕТРИЯ НА МАНЕВРИТЕ ОТ СЛЕДАТА
        $maneuverProfile = 'Прав курс (Стабилен)';
        if (count($trailPts) >= 4) {
            $totalTurnDeg = 0.0;
            $prevBearing  = null;
            for ($i = 1, $n = count($trailPts); $i < $n; $i++) {
                $lat1 = (float) ($trailPts[$i - 1]['lat'] ?? 0);
                $lon1 = (float) ($trailPts[$i - 1]['lon'] ?? 0);
                $lat2 = (float) ($trailPts[$i]['lat'] ?? 0);
                $lon2 = (float) ($trailPts[$i]['lon'] ?? 0);
                $dLon = deg2rad($lon2 - $lon1);
                $y = sin($dLon) * cos(deg2rad($lat2));
                $x = cos(deg2rad($lat1)) * sin(deg2rad($lat2)) - sin(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos($dLon);
                $segBrg = fmod(rad2deg(atan2($y, $x)) + 360.0, 360.0);

                if ($prevBearing !== null) {
                    $diff = abs($segBrg - $prevBearing);
                    if ($diff > 180.0) {
                        $diff = 360.0 - $diff;
                    }
                    $totalTurnDeg += $diff;
                }
                $prevBearing = $segBrg;
            }
            $roundedTurn = (int) round($totalTurnDeg);
            if ($roundedTurn >= 135) {
                $maneuverProfile = "АКТИВНО МАНЕВРИРАНЕ / ОРБИТА (натрупан завой {$roundedTurn}° - зона за патрул, дозареждане или пилотаж)";
            } elseif ($roundedTurn >= 45) {
                $maneuverProfile = "Маневра за смяна на курса / Заход ({$roundedTurn}° сумарен завой)";
            } else {
                $maneuverProfile = "Праволинеен коридор (отклонение само {$roundedTurn}°)";
            }
        }

        // 6. ДЕКОДЕР НА SQUAWK КОДОВЕ
        $squawkNote = 'Стандартен транспордерен код от РВД';
        if ($squawk === '7700') {
            $squawkNote = '🚨 АВАРИЯ НА БОРДА (EMERGENCY 7700)!';
        } elseif ($squawk === '7600') {
            $squawkNote = '📻 ПЪЛНА ЗАГУБА НА РАДИОВРЪЗКА (NORDO / LOST COMMS 7600)!';
        } elseif ($squawk === '7500') {
            $squawkNote = '🚨 НЕЗАКОННА НАМЕСА / ОТВЛИЧАНЕ (HIJACK 7500)!';
        } elseif ($squawk === '7777') {
            $squawkNote = '⚔️ ВОЕНЕН ПРЕХВАТ / БОЙНО ДЕЖУРСТВО НА НАТО (MILITARY INTERCEPT 7777)';
        } elseif ($squawk === '7000' || $squawk === '1200') {
            $squawkNote = 'Визуален полет (VFR) на малка височина без инструментален план';
        } elseif ($squawk === '0000' || $squawk === '----' || $squawk === '') {
            $squawkNote = 'Скрит / Неактивен Mode-S код (чисто MLAT засичане)';
        }

        $milStatusText = $isMil ? 'ВОЕНЕН / ДЪРЖАВЕН БОРД' : 'Граждански / Търговски';
        $csDisplay     = $callsign !== '' ? $callsign : 'НЯМА ПОЗИВНА';

        $lat1R = deg2rad($planeLat);
        $lon1R = deg2rad($planeLon);
        $brgR = deg2rad($trackDeg);

        $compassDirs = ['Север (С)', 'Североизток (СИ)', 'Изток (И)', 'Югоизток (ЮИ)', 'Юг (Ю)', 'Югозапад (ЮЗ)', 'Запад (З)', 'Северозапад (СЗ)'];
        $compassDir = $compassDirs[(int) round(fmod($trackDeg, 360) / 45) % 8];

        $angDist = 15 * ($speedKmh / 60) / 111.12;
        $lat2R = asin(sin($lat1R) * cos(deg2rad($angDist)) + cos($lat1R) * sin(deg2rad($angDist)) * cos($brgR));
        $lon2R = $lon1R + atan2(sin($brgR) * sin(deg2rad($angDist)) * cos($lat1R), cos(deg2rad($angDist)) - sin($lat1R) * sin($lat2R));
        $projLat = round(rad2deg($lat2R), 4);
        $projLon = round(rad2deg($lon2R), 4);
        $dist15MinKm = round(($speedKmh / 60) * 15);

        $waypoints = [
            ['name' => 'Плевен / Долна Митрополия (LBPL)', 'lat' => 43.451, 'lon' => 24.503],
            ['name' => 'Горна Оряховица (LBGO)', 'lat' => 43.151, 'lon' => 25.713],
            ['name' => 'София (LBSF)', 'lat' => 42.695, 'lon' => 23.406],
            ['name' => 'Граф Игнатиево / Пловдив (LBPG)', 'lat' => 42.290, 'lon' => 24.714],
            ['name' => 'Безмер / Ямбол (LBIA)', 'lat' => 42.455, 'lon' => 26.352],
            ['name' => 'Варна (LBWN)', 'lat' => 43.232, 'lon' => 27.825],
            ['name' => 'Бургас (LBBG)', 'lat' => 42.570, 'lon' => 27.515],
            ['name' => 'Букурещ Отопени (LROP)', 'lat' => 44.571, 'lon' => 26.085],
            ['name' => 'Констанца / М. Когълничану (LRCK)', 'lat' => 44.362, 'lon' => 28.488],
            ['name' => 'Крайова (LRCV)', 'lat' => 44.318, 'lon' => 23.888],
            ['name' => 'Белград (LYBE)', 'lat' => 44.818, 'lon' => 20.309],
            ['name' => 'Истанбул (LTFM)', 'lat' => 41.275, 'lon' => 28.751],
        ];

        $headingTargets = [];
        foreach ($waypoints as $wp) {
            $dLon = deg2rad($wp['lon'] - $planeLon);
            $y = sin($dLon) * cos(deg2rad($wp['lat']));
            $x = cos($lat1R) * sin(deg2rad($wp['lat'])) - sin($lat1R) * cos(deg2rad($wp['lat'])) * cos($dLon);
            $wpBrg = fmod(rad2deg(atan2($y, $x)) + 360.0, 360.0);

            $diff = abs($trackDeg - $wpBrg);
            if ($diff > 180.0) {
                $diff = 360.0 - $diff;
            }

            $wpDistKm = round(6371.0 * acos(min(1.0, max(-1.0, sin($lat1R) * sin(deg2rad($wp['lat'])) + cos($lat1R) * cos(deg2rad($wp['lat'])) * cos($dLon)))));

            if ($diff <= 22.0 && $wpDistKm >= 8 && $wpDistKm <= 450) {
                $etaMin = $speedKmh > 40 ? (int) round(($wpDistKm / $speedKmh) * 60) : 0;
                $headingTargets[] = "{$wp['name']} (на {$wpDistKm} км, след ~{$etaMin} мин)";
            }
        }

        $vectorSummary = !empty($headingTargets)
            ? 'Насочен по курс към: ' . implode(' / ', array_slice($headingTargets, 0, 2))
            : "Транзитен коридор на {$compassDir} (след 15 мин на {$dist15MinKm} км -> коорд. {$projLat}, {$projLon})";

        $prompt = <<<PROMPT
Ти си началник на разузнавателен център.
Разполагаш с обединена телеметрия от 5 външни сензорни API-та в реално време + Google Search (ADSBdb, HexDB, Airplanes.live, Open-Meteo). Направи извънземно точен, експертен анализ без общи приказки:

[1. ДОСИЕ ОТ РЕГИСТРИТЕ (ADSBdb + HexDB)]
- Позивна: {$csDisplay} | ICAO HEX: {$hex} | Бордна регистрация: {$osintReg} ({$osintCountry})
- Тип машина: {$osintFullType} | Оператор: {$operator} | Клас: {$milStatusText}
- Официален маршрут: {$osintRoute}

[2. КИНЕМАТИКА, РЕЛЕФ И МЕТЕО НА ЕШЕЛОНА (Open-Meteo + Airplanes.live)]
- Изчислен вектор движение и накъде отива: {$vectorSummary}
- Барометрична височина: {$altM} м | Надморска височина на терена отдолу: {$terrainElevationM} м -> РЕАЛНА ВИСОЧИНА НАД РЕЛЕФА (AGL): {$aglMeters} м
- Пътна скорост (GS): {$speedKmh} км/ч, Курс: {$trackDeg}° ({$compassDir}) | {$windInfo}
- Автопилот и бордова телеметрия: {$telemetryNote}
- Геометрия на следата: {$maneuverProfile}
- Радиолокационна видимост: {$horizonStatus}
- Транспондер (Squawk): {$squawk} ({$squawkNote})

Изведи анализа на български точно в тези 4 кратки и ударни точки (без звездички за удебеляване):
1. ИДЕНТИФИКАЦИЯ И МИСИЯ: (Точен борд {$osintReg}, машина, база/оператор и дали полетът "{$csDisplay}" е редовен по разписание, чартър, карго или специална/военна задача).
2. НАКЪДЕ ОТИВА, АЕРОДИНАМИКА И АВТОПИЛОТ: (Кажи ясно откъде и за къде лети самолетът според маршрута "{$osintRoute}" и изчисления вектор "{$vectorSummary}", коментирай реалната височина над терена AGL {$aglMeters}м, истинската скорост/Mach спрямо вятъра и какво прави автопилотът/следата).
3. РАДИОЛОКАЦИОНЕН И SQUAWK СТАТУС: (Оцени радиохоризонта, точността на GPS/NIC и кода {$squawk}).
4. ОПЕРАТИВНА ОЦЕНКА НА ДЕЖУРНИЯ: (1 категорично заключение за характера на целта и очакваното ѝ движение).
PROMPT;

        $models = [
            'gemini-3.5-flash-lite',
            'gemini-3.0-flash',
            'gemini-3.5-flash',
        ];

        $listUrl = 'https://generativelanguage.googleapis.com/v1beta/models?key=' . urlencode($apiKey);
        $listRes = $this->curlFetch($listUrl);
        if ($listRes['code'] === 200 && $listRes['body'] !== '') {
            $listData = json_decode($listRes['body'], true);
            foreach (($listData['models'] ?? []) as $m) {
                $mName = str_replace('models/', '', (string) ($m['name'] ?? ''));
                $methods = (array) ($m['supportedGenerationMethods'] ?? []);
                if (
                    $mName !== '' &&
                    str_contains($mName, 'gemini') &&
                    in_array('generateContent', $methods, true) &&
                    !in_array($mName, $models, true)
                ) {
                    $models[] = $mName;
                }
            }
        }

        $lastCode = 0;
        $lastErrorDetail = '';

        foreach ($models as $model) {
            $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . urlencode($apiKey);

            foreach ([true, false] as $useSearchTool) {
                $bodyPayload = [
                    'contents' => [
                        ['parts' => [['text' => $prompt]]]
                    ],
                    'generationConfig' => [
                        'temperature'     => 0.25,
                        'maxOutputTokens' => 1024,
                    ],
                ];

                if ($useSearchTool) {
                    $bodyPayload['tools'] = [
                        ['google_search' => new \stdClass()]
                    ];
                }

                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST           => true,
                    CURLOPT_TIMEOUT        => 22,
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                    CURLOPT_POSTFIELDS     => json_encode($bodyPayload, JSON_UNESCAPED_UNICODE),
                ]);

                $body = curl_exec($ch);
                $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlErr = curl_error($ch);
                curl_close($ch);

                $lastCode = $code;

                if ($code === 200 && is_string($body) && $body !== '') {
                    $decoded = json_decode($body, true);
                    $parts = $decoded['candidates'][0]['content']['parts'] ?? [];
                    $text = '';
                    foreach ($parts as $part) {
                        if (isset($part['text'])) {
                            $text .= $part['text'];
                        }
                    }
                    if (trim($text) !== '') {
                        return new JsonResponse(['brief' => trim($text)]);
                    }
                }

                if (is_string($body) && $body !== '') {
                    $errDecoded = json_decode($body, true);
                    if (!empty($errDecoded['error']['message'])) {
                        $lastErrorDetail = $errDecoded['error']['message'];
                    }
                } elseif ($curlErr !== '') {
                    $lastErrorDetail = $curlErr;
                }

                if ($code === 404) {
                    break;
                }
            }

            if ($lastCode === 429 || str_contains(strtolower($lastErrorDetail), 'api_key_invalid')) {
                break;
            }
        }

        $msg = '⚠️ Грешка при връзка с AI модула (HTTP ' . $lastCode . ').';
        if ($lastErrorDetail !== '') {
            $msg .= ' Детайли: ' . $lastErrorDetail;
        }

        return new JsonResponse(['brief' => $msg]);
    }

    // аве ЕЙЙ!

    /**
     * Записва историята в лек Ring Buffer от отделни слот файлове (~60KB всеки),
     * така че PHP никога да не превишава RAM лимита и да не връща 502 Bad Gateway.
     */
    private function appendHistorySnapshot(string $cacheDir, int $timestamp, array $planes): void
    {
        $indexFile = $cacheDir . '/radar_history_index.json';
        $indexData = [];

        if (is_file($indexFile)) {
            $raw = @file_get_contents($indexFile);
            $decoded = is_string($raw) ? json_decode($raw, true) : [];
            if (is_array($decoded)) {
                $indexData = $decoded;
            }
        }

        $last = end($indexData);
        if (is_array($last) && isset($last['ts']) && ($timestamp - (int) $last['ts']) < 15) {
            return;
        }

        // Определяме следващия свободен слот в кръговия буфер (0 .. MAX_HISTORY_FRAMES - 1)
        $nextSlot = is_array($last) && isset($last['slot'])
            ? (((int) $last['slot'] + 1) % self::MAX_HISTORY_FRAMES)
            : 0;

        // Олекотяваме обектите за историята (закръгляме координатите до 4 знака), за да е още по-бързо
        $compactPlanes = [];
        foreach ($planes as $p) {
            $compactPlanes[] = [
                'id'         => $p['id'] ?? '',
                'callsign'   => $p['callsign'] ?? '',
                'lat'        => round((float) ($p['lat'] ?? 0), 4),
                'lon'        => round((float) ($p['lon'] ?? 0), 4),
                'track'      => (int) round((float) ($p['track'] ?? 0)),
                'altitude'   => (int) ($p['altitude'] ?? 0),
                'speed'      => (int) ($p['speed'] ?? 0),
                'squawk'     => $p['squawk'] ?? '----',
                'type'       => $p['type'] ?? '',
                'operator'   => $p['operator'] ?? '',
                'isMilitary' => !empty($p['isMilitary']),
            ];
        }

        $slotFile = $cacheDir . '/radar_hist_slot_' . $nextSlot . '.json';
        $slotJson = json_encode($compactPlanes, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        if (!is_string($slotJson) || @file_put_contents($slotFile, $slotJson, LOCK_EX) === false) {
            return;
        }

        $indexData[] = [
            'ts'   => $timestamp,
            'slot' => $nextSlot,
        ];

        if (count($indexData) > self::MAX_HISTORY_FRAMES) {
            $indexData = array_slice($indexData, -self::MAX_HISTORY_FRAMES);
        }

        $indexJson = json_encode($indexData, JSON_UNESCAPED_UNICODE);
        if (is_string($indexJson)) {
            @file_put_contents($indexFile, $indexJson, LOCK_EX);
        }
    }

    private function curlFetchMany(array $providers): array
    {
        if (!function_exists('curl_multi_init') || !function_exists('curl_init')) {
            $results = [];
            foreach ($providers as $provider) {
                $results[$provider['name']] = $this->providerIsCoolingDown($provider['base'] ?? $provider['name'])
                    ? ['code' => 0, 'body' => '', 'error' => 'Cooldown', 'time_ms' => 0]
                    : $this->curlFetch($provider['url']);
            }
            return $results;
        }

        $multi = curl_multi_init();
        $handles = [];
        $results = [];

        foreach ($providers as $provider) {
            $name = $provider['name'];
            $base = $provider['base'] ?? $name;

            if ($this->providerIsCoolingDown($base)) {
                $results[$name] = ['code' => 0, 'body' => '', 'error' => 'Cooldown', 'time_ms' => 0];
                continue;
            }

            $ch = curl_init($provider['url']);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT        => 5,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_ENCODING       => '',
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                CURLOPT_HTTPHEADER     => [
                    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                    'Accept: application/json',
                ],
            ]);

            $handles[$name] = [
                'handle' => $ch,
                'start'  => microtime(true),
            ];
            curl_multi_add_handle($multi, $ch);
        }

        do {
            $status = curl_multi_exec($multi, $running);
            if ($running > 0 && $status === CURLM_OK) {
                $selected = curl_multi_select($multi, 1.0);
                if ($selected === -1) {
                    usleep(10000);
                }
            }
        } while ($running > 0 && $status === CURLM_OK);

        foreach ($handles as $name => $item) {
            $ch = $item['handle'];
            $body = curl_multi_getcontent($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);

            $results[$name] = [
                'code'    => $code,
                'body'    => is_string($body) ? $body : '',
                'error'   => $error,
                'time_ms' => (int) round((microtime(true) - $item['start']) * 1000),
            ];

            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
        }

        curl_multi_close($multi);
        return $results;
    }

    private function curlFetch(string $url, bool $isFr24 = false): array
    {
        if (!function_exists('curl_init')) {
            return ['code' => 0, 'body' => '', 'error' => 'No cURL', 'time_ms' => 0];
        }

        $headers = [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
            'Accept: application/json, text/javascript, */*; q=0.01',
        ];

        if ($isFr24) {
            $headers[] = 'Origin: https://www.flightradar24.com';
            $headers[] = 'Referer: https://www.flightradar24.com/';
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING       => '',
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_HTTPHEADER     => $headers,
        ]);

        $start = microtime(true);
        $body = curl_exec($ch);
        $elapsed = (int) round((microtime(true) - $start) * 1000);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        return [
            'code'    => $code,
            'body'    => is_string($body) ? $body : '',
            'error'   => $error,
            'time_ms' => $elapsed,
        ];
    }

    private function buildFr24Url(float $lat, float $lon): string
    {
        $north = min(90.0, round($lat + 4.5, 2));
        $south = max(-90.0, round($lat - 4.5, 2));
        $west  = max(-180.0, round($lon - 6.5, 2));
        $east  = min(180.0, round($lon + 6.5, 2));

        return "https://data-cloud.flightradar24.com/zones/fcgi/feed.js?bounds={$north},{$south},{$west},{$east}&faa=1&satellite=1&mlat=1&flarm=1&adsb=1&gnd=0&air=1&vehicles=0&estimated=0&maxage=60&gliders=1&stats=0";
    }

    private function buildOpenSkyUrl(float $lat, float $lon, float $radiusNm): string
    {
        $latDelta = $radiusNm / 60.0;
        $cosLat = max(0.15, abs(cos(deg2rad($lat))));
        $lonDelta = min(180.0, $radiusNm / (60.0 * $cosLat));

        $lamin = max(-90.0, $lat - $latDelta);
        $lamax = min(90.0, $lat + $latDelta);
        $lomin = max(-180.0, $lon - $lonDelta);
        $lomax = min(180.0, $lon + $lonDelta);

        return 'https://opensky-network.org/api/states/all?' . http_build_query([
                'lamin' => $lamin,
                'lomin' => $lomin,
                'lamax' => $lamax,
                'lomax' => $lomax,
            ]);
    }

    private function providerIsCoolingDown(string $provider): bool
    {
        $file = sys_get_temp_dir() . '/radar_provider_' . md5($provider) . '.cooldown';

        if (!is_file($file)) {
            return false;
        }

        $timestamp = (int) @file_get_contents($file);
        if ($timestamp <= 0 || time() - $timestamp > self::PROVIDER_COOLDOWN) {
            @unlink($file);
            return false;
        }

        return true;
    }

    private function putProviderInCooldown(string $provider): void
    {
        $file = sys_get_temp_dir() . '/radar_provider_' . md5($provider) . '.cooldown';
        @file_put_contents($file, (string) time(), LOCK_EX);
    }

    private function parseAdsbV2List(
        array $aircraftList,
        array &$planesMap,
        float $centerLat,
        float $centerLon,
        bool $isMilitaryFeed,
        string $source
    ): void {
        foreach ($aircraftList as $ac) {
            if (!is_array($ac)) {
                continue;
            }

            $altRaw = $ac['alt_baro'] ?? $ac['alt_geom'] ?? $ac['alt'] ?? 0;
            if ($altRaw === 'ground' || !empty($ac['ground'])) {
                continue;
            }

            $seenPos = (float) ($ac['seen_pos'] ?? $ac['seen'] ?? 0.5);
            if ($seenPos > self::MAX_POS_AGE_SEC) {
                continue;
            }

            $lat = $ac['lat'] ?? $ac['latitude'] ?? null;
            $lon = $ac['lon'] ?? $ac['longitude'] ?? null;

            if (!is_numeric($lat) || !is_numeric($lon)) {
                continue;
            }

            $lat = (float) $lat;
            $lon = (float) $lon;

            if (abs($lat - $centerLat) > 5.5 || abs($lon - $centerLon) > 7.5) {
                continue;
            }

            $id = strtolower(trim((string) ($ac['hex'] ?? $ac['icao24'] ?? '')));
            if ($id === '' || str_starts_with($id, '~')) {
                continue;
            }

            $rawCallsign = trim((string) ($ac['flight'] ?? $ac['call'] ?? $ac['callsign'] ?? ''));
            $squawk = trim((string) ($ac['squawk'] ?? '----'));
            $aircraftType = trim((string) ($ac['t'] ?? $ac['type'] ?? ''));
            $desc = trim((string) ($ac['desc'] ?? ''));
            $operator = trim((string) ($ac['ownOp'] ?? $ac['op'] ?? $ac['operator'] ?? ''));
            $reg = trim((string) ($ac['r'] ?? ''));

            $isMil = $isMilitaryFeed
                || (((int) ($ac['dbFlags'] ?? 0) & 1) === 1)
                || $this->isKnownMilitary($id, $rawCallsign, $squawk, $aircraftType, $operator, $desc, $reg);

            $altitudeMeters = is_numeric($altRaw) ? (int) round((float) $altRaw * 0.3048) : 0;
            $speedKnots = (float) ($ac['gs'] ?? $ac['spd'] ?? 0);
            $speedKmh = (int) round($speedKnots * 1.852);

            if ($altitudeMeters <= 0 && $speedKmh < 40) {
                continue;
            }

            $existing = $planesMap[$id] ?? null;
            if (is_array($existing)) {
                if (!empty($existing['isMilitary'])) {
                    $isMil = true;
                }
                if (($existing['age'] ?? 99.0) < $seenPos && (!empty($existing['isMilitary']) || !$isMil)) {
                    continue;
                }
            }

            $displayCallsign = $rawCallsign !== '' ? $rawCallsign : ($reg !== '' ? $reg : strtoupper($id));
            if ($isMil && !str_contains($displayCallsign, '[ВОЕНЕН]')) {
                $displayCallsign .= ' [ВОЕНЕН]';
            }

            $track = (float) ($ac['track'] ?? $ac['true_heading'] ?? $ac['mag_heading'] ?? 0);

            $planesMap[$id] = [
                'id'         => $id,
                'callsign'   => $displayCallsign,
                'lat'        => $lat,
                'lon'        => $lon,
                'track'      => $track,
                'altitude'   => $altitudeMeters,
                'speed'      => $speedKmh,
                'squawk'     => $squawk !== '' ? $squawk : ($existing['squawk'] ?? '----'),
                'type'       => $desc !== '' ? $desc : ($aircraftType !== '' ? $aircraftType : ($existing['type'] ?? 'Няма данни')),
                'operator'   => $operator !== '' ? $operator : ($existing['operator'] ?? ($isMil ? 'Военновъздушни сили (ВВС)' : 'Граждански полет')),
                'age'        => round($seenPos, 1),
                'isMilitary' => $isMil,
                '_source'    => $source,
            ];
        }
    }

    private function parseFr24Data(
        array $frData,
        array &$planesMap,
        float $centerLat,
        float $centerLon
    ): void {
        $now = time();

        foreach ($frData as $key => $val) {
            if (!is_array($val) || count($val) < 15) {
                continue;
            }

            $lat = $val[1] ?? null;
            $lon = $val[2] ?? null;
            if (!is_numeric($lat) || !is_numeric($lon)) {
                continue;
            }

            $lat = (float) $lat;
            $lon = (float) $lon;

            if (abs($lat - $centerLat) > 5.5 || abs($lon - $centerLon) > 7.5) {
                continue;
            }

            $onGround = (int) ($val[14] ?? 0);
            if ($onGround === 1) {
                continue;
            }

            $ts = (int) ($val[10] ?? 0);
            $age = $ts > 0 ? max(0.0, (float) ($now - $ts)) : 2.0;
            if ($age > self::MAX_POS_AGE_SEC) {
                continue;
            }

            $hex = strtolower(trim((string) ($val[0] ?? '')));
            $id = $hex !== '' ? $hex : 'fr_' . strtolower(trim((string) $key));

            $track        = (float) ($val[3] ?? 0);
            $altFeet      = (float) ($val[4] ?? 0);
            $speedKnots   = (float) ($val[5] ?? 0);
            $squawk       = trim((string) ($val[6] ?? '----'));
            $aircraftType = trim((string) ($val[8] ?? ''));
            $reg          = trim((string) ($val[9] ?? ''));
            $rawCallsign  = trim((string) ($val[16] ?? $val[13] ?? ''));
            $operator     = trim((string) ($val[18] ?? ''));

            $altitudeMeters = (int) round($altFeet * 0.3048);
            $speedKmh       = (int) round($speedKnots * 1.852);

            if ($altitudeMeters <= 0 && $speedKmh < 40) {
                continue;
            }

            $isMil = $this->isKnownMilitary($hex, $rawCallsign, $squawk, $aircraftType, $operator, '', $reg);

            $existing = $planesMap[$id] ?? null;
            if (is_array($existing)) {
                if ($isMil && empty($existing['isMilitary'])) {
                    $existing['isMilitary'] = true;
                    if (!str_contains($existing['callsign'], '[ВОЕНЕН]')) {
                        $existing['callsign'] .= ' [ВОЕНЕН]';
                    }
                    if ($existing['operator'] === 'Граждански полет') {
                        $existing['operator'] = 'Военновъздушни сили (ВВС)';
                    }
                }
                if (($existing['type'] === 'Няма данни' || $existing['type'] === '') && $aircraftType !== '') {
                    $existing['type'] = $aircraftType;
                }
                if (($existing['squawk'] === '----' || $existing['squawk'] === '') && $squawk !== '' && $squawk !== '0000') {
                    $existing['squawk'] = $squawk;
                }
                $planesMap[$id] = $existing;
                continue;
            }

            $displayCallsign = $rawCallsign !== '' ? $rawCallsign : ($reg !== '' ? $reg : ($aircraftType !== '' ? $aircraftType : strtoupper($id)));
            if ($isMil && !str_contains($displayCallsign, '[ВОЕНЕН]')) {
                $displayCallsign .= ' [ВОЕНЕН]';
            }

            $planesMap[$id] = [
                'id'         => $id,
                'callsign'   => $displayCallsign,
                'lat'        => $lat,
                'lon'        => $lon,
                'track'      => $track,
                'altitude'   => $altitudeMeters,
                'speed'      => $speedKmh,
                'squawk'     => ($squawk !== '' && $squawk !== '0000') ? $squawk : '----',
                'type'       => $aircraftType !== '' ? $aircraftType : 'Няма данни',
                'operator'   => $operator !== '' ? $operator : ($isMil ? 'Военновъздушни сили (ВВС)' : 'Граждански полет'),
                'age'        => round($age, 1),
                'isMilitary' => $isMil,
                '_source'    => 'FR24',
            ];
        }
    }

    private function parseOpenSkyList(
        array $states,
        array &$planesMap,
        float $centerLat,
        float $centerLon
    ): void {
        $now = time();

        foreach ($states as $st) {
            if (!is_array($st)) {
                continue;
            }

            if (!empty($st[8])) {
                continue;
            }

            $posTime = (int) ($st[3] ?? $st[4] ?? 0);
            if ($posTime > 0 && ($now - $posTime) > self::MAX_POS_AGE_SEC) {
                continue;
            }

            $id = strtolower(trim((string) ($st[0] ?? '')));
            $rawCallsign = trim((string) ($st[1] ?? ''));
            $originCountry = trim((string) ($st[2] ?? 'Граждански полет'));
            $lon = $st[5] ?? null;
            $lat = $st[6] ?? null;
            $altMeters = $st[7] ?? $st[13] ?? 0;
            $velocityMs = (float) ($st[9] ?? 0);
            $track = (float) ($st[10] ?? 0);
            $squawk = trim((string) ($st[14] ?? '----'));

            if ($id === '' || !is_numeric($lat) || !is_numeric($lon)) {
                continue;
            }

            $isMil = $this->isKnownMilitary($id, $rawCallsign, $squawk, '', $originCountry);
            $displayCallsign = $rawCallsign !== '' ? $rawCallsign : strtoupper($id);

            if ($isMil && !str_contains($displayCallsign, '[ВОЕНЕН]')) {
                $displayCallsign .= ' [ВОЕНЕН]';
            }

            $planesMap[$id] = [
                'id'         => $id,
                'callsign'   => $displayCallsign,
                'lat'        => (float) $lat,
                'lon'        => (float) $lon,
                'track'      => $track,
                'altitude'   => is_numeric($altMeters) ? (int) round((float) $altMeters) : 0,
                'speed'      => (int) round($velocityMs * 3.6),
                'squawk'     => $squawk !== '' ? $squawk : '----',
                'type'       => $isMil ? 'Военен борд' : 'Въздушна цел',
                'operator'   => $originCountry,
                'age'        => 1.0,
                'isMilitary' => $isMil,
                '_source'    => 'OpenSky',
            ];
        }
    }

    private function isKnownMilitary(
        string $hex,
        string $callsign,
        string $squawk,
        string $type,
        string $operator,
        string $description = '',
        string $reg = ''
    ): bool {
        $hex = strtolower(trim($hex));
        $cs = strtoupper(trim($callsign));
        $squawk = trim($squawk);
        $type = strtoupper(str_replace(['-', ' '], '', trim($type)));
        $op = strtoupper(trim($operator));
        $desc = strtoupper(trim($description));
        $regClean = strtoupper(trim($reg));

        // 0. Защита: Игнорираме сигурни граждански авиокомпании (Turkish, Wizz, Ryanair, Bulgaria Air и др.)
        $civilCallsigns = [
            'THY', 'WZZ', 'WMT', 'RYR', 'RYS', 'DLH', 'LZB', 'AUA', 'BAW',
            'AFR', 'KLM', 'SWR', 'EZY', 'EJU', 'TAR', 'ROT', 'ASL', 'AEE',
            'PGT', 'SXS', 'CAI', 'LOT', 'TVS', 'BTI', 'ELAL', 'ELY', 'QTR',
            'UAE', 'ETD', 'FDB', 'TUK', 'BGH', 'VBB', 'LAZ',
        ];
        foreach ($civilCallsigns as $civ) {
            if ($cs !== '' && str_starts_with($cs, $civ)) {
                return false;
            }
        }

        // 1. Точни военни ICAO HEX диапазони
        if ($hex !== '') {
            if (
                str_starts_with($hex, '45f4') ||
                str_starts_with($hex, 'ae') ||
                str_starts_with($hex, 'af') ||
                str_starts_with($hex, '43c') ||
                preg_match('/^3f[4-9a-b]/', $hex)
            ) {
                return true;
            }
        }

        // 2. Строго военен Squawk
        if ($squawk === '7777') {
            return true;
        }

        // 3. Бордни номера на Българските ВВС
        if ($regClean !== '' && preg_match('/^(66\d|07[123]|11\d|20\d)$/', $regClean)) {
            return true;
        }

        // 4. Военни позивни (Callsigns)
        $milPrefixes = [
            'BAF', 'BULAF', 'BGF', 'NATO', 'FORTE', 'LAGR', 'NCHO',
            'JAKE', 'RCH', 'RRR', 'ASCOT', 'DUKE', 'VIPER', 'COBRA',
            'HAWK', 'GAF', 'FAF', 'TUAF', 'HAF', 'ROAF', 'PLF',
            'SAM', 'SPAR', 'IAM', 'CNV', 'MMF', 'IAF', 'USAF',
        ];

        foreach ($milPrefixes as $prefix) {
            if ($cs !== '' && str_starts_with($cs, $prefix)) {
                return true;
            }
        }

        // 5. Военни типове самолети
        $milTypes = [
            'F16', 'C27J', 'C130', 'C30J', 'PC9', 'PC7', 'L39', 'MIG29',
            'SU25', 'UH60', 'CH47', 'MI8', 'MI17', 'MI24', 'AS32', 'AS532',
            'KC135', 'E3TF', 'C17', 'A400', 'P8', 'RQ4', 'MQ9', 'B52',
            'F15', 'F18', 'F35', 'TYPH', 'EUFI', 'RAFL', 'PILATUS',
        ];

        foreach ($milTypes as $milType) {
            if (($type !== '' && str_contains($type, $milType)) ||
                ($desc !== '' && str_contains($desc, $milType))) {
                return true;
            }
        }

        // 6. Оператори
        $milKeywords = [
            'AIR FORCE', 'MILITARY', 'NAVY', 'ARMY', 'NATO',
            'DEFENCE', 'DEFENSE', 'LUFTWAFFE', 'GUARD',
            'ВОЕННОВЪЗДУШ', 'ВВС', 'BULGARIA - AIR FORCE',
        ];

        foreach ($milKeywords as $keyword) {
            if (($op !== '' && str_contains($op, $keyword)) ||
                ($desc !== '' && str_contains($desc, $keyword))) {
                return true;
            }
        }

        return false;
    }
}
