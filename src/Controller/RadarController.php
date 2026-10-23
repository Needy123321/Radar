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
    private const MAX_HISTORY_FRAMES = 240;
    private const SEARCH_RADIUS_NM = 250;

    // Максимална възраст на позицията в секунди (реже фантоми като 0547)
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
        $centerLat = (float) $request->query->get('lat', 43.3676);
        $centerLon = (float) $request->query->get('lon', 24.6667);

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
        $historyFile = $cacheDir . '/radar_history_ring.json';
        $lockFile = $cacheDir . '/radar_live_' . $locKey . '.lock';
        $now = time();

        if (
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

            // 3. OpenSky САМО като авариен резерв (ако всички горни върнат 0 самолета),
            // за да не вкарва замръзнали фантоми със стари координати!
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

            // Изчистваме вътрешните служебни полета ЧАК СЛЕД като всички потоци са обединени
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

            $this->appendHistorySnapshot($historyFile, $now, $finalPlanes);

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
        $historyFile = sys_get_temp_dir() . '/radar_history_ring.json';

        if (!is_file($historyFile)) {
            return new JsonResponse(['timestamps' => [], 'planes' => [], 'total' => 0]);
        }

        $raw = @file_get_contents($historyFile);
        $history = is_string($raw) ? json_decode($raw, true) : [];

        if (!is_array($history) || $history === []) {
            return new JsonResponse(['timestamps' => [], 'planes' => [], 'total' => 0]);
        }

        $frameIndex = $request->query->get('index');

        if ($frameIndex !== null) {
            $idx = (int) $frameIndex;
            if (isset($history[$idx])) {
                return new JsonResponse([
                    'timestamp' => $history[$idx]['ts'] ?? null,
                    'planes'    => $history[$idx]['planes'] ?? [],
                    'total'     => count($history),
                ]);
            }
        }

        return new JsonResponse([
            'timestamps' => array_map(static fn($item) => $item['ts'] ?? null, $history),
            'total'      => count($history),
        ]);
    }

    private function appendHistorySnapshot(string $historyFile, int $timestamp, array $planes): void
    {
        $history = [];

        if (is_file($historyFile)) {
            $raw = @file_get_contents($historyFile);
            $decoded = is_string($raw) ? json_decode($raw, true) : [];

            if (is_array($decoded)) {
                $history = $decoded;
            }
        }

        $last = end($history);
        if (is_array($last) && isset($last['ts']) && ($timestamp - (int) $last['ts']) < 15) {
            return;
        }

        $history[] = ['ts' => $timestamp, 'planes' => $planes];

        if (count($history) > self::MAX_HISTORY_FRAMES) {
            $history = array_slice($history, -self::MAX_HISTORY_FRAMES);
        }

        $tmp = $historyFile . '.tmp.' . getmypid();
        $json = json_encode($history, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        if (is_string($json) && @file_put_contents($tmp, $json, LOCK_EX) !== false) {
            @rename($tmp, $historyFile);
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

        // bounds формат за FR24: north,south,west,east
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

            // 1. Игнорираме кацнали самолети / наземни обекти
            $altRaw = $ac['alt_baro'] ?? $ac['alt_geom'] ?? $ac['alt'] ?? 0;
            if ($altRaw === 'ground' || !empty($ac['ground'])) {
                continue;
            }

            // 2. Филтър срещу ФАНТОМИ: игнорираме позиции, по-стари от 60 секунди!
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

            // 3. Географска рамка около центъра (задължително за глобалния /v2/mil поток!)
            if (abs($lat - $centerLat) > 5.5 || abs($lon - $centerLon) > 7.5) {
                continue;
            }

            $id = strtolower(trim((string) ($ac['hex'] ?? $ac['icao24'] ?? '')));
            if ($id === '' || str_starts_with($id, '~')) {
                // '~' в ADS-B фийдовете са невалидни/синтетични TIS-B/наземни маркери
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

            // Ако обектът е на 0 метра височина и под 40 км/ч, значи рулира на земята -> пропускаме го
            if ($altitudeMeters <= 0 && $speedKmh < 40) {
                continue;
            }

            $existing = $planesMap[$id] ?? null;
            if (is_array($existing)) {
                // Запазваме военния статус, ако вече е бил засечен от друг поток
                if (!empty($existing['isMilitary'])) {
                    $isMil = true;
                }
                // Ако съществуващият запис е по-пресен и не добавяме военен флаг, пропускаме
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

    /**
     * Парсва директния фийд на Flightradar24 (включително MLAT самолети като PC-9M без пълен HEX в другите мрежи)
     */
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
            // При MLAT полети във FR24 понякога HEX полето е празно — ползваме уникалния FR24 ключ
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
                // Ако вече го имаме от ADS-B v2, обогатяваме само ако FR24 знае модела (напр. PC9) или военния статус
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

            // Игнорираме обекти на земята (индекс 8 е on_ground)
            if (!empty($st[8])) {
                continue;
            }

            // Игнорираме стари позиции (индекс 3 е time_position, индекс 4 е last_contact)
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
            'UAE', 'ETD', 'FDB', 'tuk', 'BGH', 'VBB', 'LAZ',
        ];
        foreach ($civilCallsigns as $civ) {
            if ($cs !== '' && str_starts_with($cs, $civ)) {
                return false;
            }
        }

        // 1. Точни военни ICAO HEX диапазони:
        // 45f4xx = Български ВВС
        // ae / af = САЩ (USAF / US Navy / US Army)
        // 43cxxx = Великобритания (RAF)
        // 3f4..3fb = Германия (Luftwaffe)
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

        // 2. Строго военен Squawk (махнати са 44xx и 74xx, защото в Европа са за граждански трафик!)
        if ($squawk === '7777' || $squawk === '0000') {
            // 0000 зачитаме само ако е комбиниран с военен тип или регистрация по-долу
            if ($squawk === '7777') {
                return true;
            }
        }

        // 3. Бордни номера на Българските ВВС (напр. 664 за PC-9M, 071/072/073 за C-27J Spartan)
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

        // 5. Военни типове самолети (вкл. PC-9M, L-39, Spartan, Cougar)
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
