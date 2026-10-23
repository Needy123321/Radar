<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class RadarController extends AbstractController
{
    /*
     * Колко време използваме пресния кеш.
     *
     * ВАЖНО:
     * Android/WebView може да пита /api/planes по-често,
     * но външните ADS-B API-та няма да бъдат викани
     * по-често от този интервал.
     */
    private const CACHE_TTL = 15;

    /*
     * При 403 / 429 спираме временно съответния provider.
     */
    private const PROVIDER_COOLDOWN = 300; // 5 минути

    private const MAX_HISTORY_FRAMES = 240;

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

    // ============================================================
    // LIVE AIRCRAFT API
    // ============================================================

    #[Route('/api/planes', name: 'api_planes')]
    public function getPlanes(Request $request): JsonResponse
    {
        $centerLat = (float) $request->query->get('lat', 43.3676);
        $centerLon = (float) $request->query->get('lon', 24.6667);

        /*
         * Нарочно НЕ използваме force за заобикаляне на кеша.
         *
         * Дори frontend-ът да праща:
         *
         * /api/planes?force=1
         *
         * това няма да предизвика допълнителни заявки към
         * външните ADS-B услуги.
         */

        $locKey = round($centerLat, 2) . '_' . round($centerLon, 2);

        $cacheDir = sys_get_temp_dir();

        $cacheFile =
            $cacheDir .
            '/radar_live_buffer_' .
            $locKey .
            '.json';

        $historyFile =
            $cacheDir .
            '/radar_history_ring.json';

        $lockFile =
            $cacheDir .
            '/radar_live_' .
            $locKey .
            '.lock';

        $now = time();

        // ========================================================
        // 1. ПРЕСЕН CACHE
        // ========================================================

        if (
            file_exists($cacheFile) &&
            ($now - (int) @filemtime($cacheFile)) < self::CACHE_TTL
        ) {
            $cached = @file_get_contents($cacheFile);

            if ($cached && strlen($cached) > 10) {

                error_log(
                    '[RADAR] CACHE HIT ' .
                    $locKey
                );

                return new JsonResponse(
                    json_decode($cached, true)
                );
            }
        }

        // ========================================================
        // 2. LOCK
        // ========================================================

        $lockFp = @fopen($lockFile, 'c+');

        $gotLock =
            $lockFp &&
            @flock(
                $lockFp,
                LOCK_EX | LOCK_NB
            );

        /*
         * Ако друга заявка вече обновява ADS-B данните,
         * връщаме стария кеш вместо да правим втора заявка.
         */

        if (!$gotLock) {

            if ($lockFp) {
                @fclose($lockFp);
            }

            if (file_exists($cacheFile)) {

                $cached =
                    @file_get_contents($cacheFile);

                if (
                    $cached &&
                    strlen($cached) > 10
                ) {

                    error_log(
                        '[RADAR] LOCK ACTIVE - RETURNING OLD CACHE'
                    );

                    return new JsonResponse(
                        json_decode($cached, true)
                    );
                }
            }

            return new JsonResponse([]);
        }

        try {

            // ====================================================
            // 3. PROVIDERS
            // ====================================================

            /*
             * ВАЖНО:
             *
             * Вече НЕ дърпаме всички API-та наведнъж.
             *
             * Опитваме:
             *
             * 1. adsb.fi
             * 2. airplanes.live
             * 3. adsb.lol
             *
             * Щом един даде самолети -> STOP.
             */

            $providers = [

                [
                    'name' => 'adsb.fi',
                    'url' =>
                        "https://opendata.adsb.fi/api/v2/lat/" .
                        "{$centerLat}/lon/{$centerLon}/dist/250",
                ],

                [
                    'name' => 'airplanes.live',
                    'url' =>
                        "https://api.airplanes.live/v2/point/" .
                        "{$centerLat}/{$centerLon}/250",
                ],

                [
                    'name' => 'adsb.lol',
                    'url' =>
                        "https://api.adsb.lol/v2/point/" .
                        "{$centerLat}/{$centerLon}/250",
                ],
            ];

            $planesMap = [];

            $workingProvider = null;

            foreach ($providers as $provider) {

                // -----------------------------------------------
                // Проверяваме дали provider-ът е в cooldown
                // -----------------------------------------------

                if (
                    $this->providerIsCoolingDown(
                        $provider['name']
                    )
                ) {

                    error_log(
                        '[RADAR] ' .
                        $provider['name'] .
                        ' SKIPPED - COOLDOWN'
                    );

                    continue;
                }

                error_log(
                    '[RADAR] REQUEST -> ' .
                    $provider['name']
                );

                $res =
                    $this->curlFetch(
                        $provider['url']
                    );

                error_log(
                    '[RADAR] ' .
                    $provider['name'] .
                    ' HTTP=' .
                    $res['code'] .
                    ' TIME=' .
                    $res['time_ms'] .
                    'ms BYTES=' .
                    strlen($res['body']) .
                    (
                    $res['error']
                        ? ' ERROR=' . $res['error']
                        : ''
                    )
                );

                // -----------------------------------------------
                // RATE LIMIT / BLOCK
                // -----------------------------------------------

                if (
                    $res['code'] === 429 ||
                    $res['code'] === 403
                ) {

                    $this->putProviderInCooldown(
                        $provider['name']
                    );

                    error_log(
                        '[RADAR] !!! ' .
                        $provider['name'] .
                        ' BLOCKED/RATE-LIMITED HTTP ' .
                        $res['code']
                    );

                    continue;
                }

                // -----------------------------------------------
                // Грешка
                // -----------------------------------------------

                if (
                    $res['code'] !== 200 ||
                    empty($res['body'])
                ) {

                    continue;
                }

                $data =
                    json_decode(
                        $res['body'],
                        true
                    );

                if (!is_array($data)) {

                    error_log(
                        '[RADAR] ' .
                        $provider['name'] .
                        ' INVALID JSON'
                    );

                    continue;
                }

                $list =
                    $data['ac']
                    ??
                    $data['aircraft']
                    ??
                    [];

                if (empty($list)) {

                    error_log(
                        '[RADAR] ' .
                        $provider['name'] .
                        ' RETURNED 0 AIRCRAFT'
                    );

                    continue;
                }

                $this->parseAdsbV2List(
                    $list,
                    $planesMap,
                    $centerLat,
                    $centerLon,
                    false
                );

                if (count($planesMap) > 0) {

                    $workingProvider =
                        $provider['name'];

                    error_log(
                        '[RADAR] SUCCESS ' .
                        $workingProvider .
                        ' AIRCRAFT=' .
                        count($planesMap)
                    );

                    /*
                     * НАМЕРИХМЕ РАБОТЕЩ PROVIDER.
                     *
                     * НЕ ВИКАМЕ ОСТАНАЛИТЕ.
                     */
                    break;
                }
            }

            // ====================================================
            // 4. OPEN SKY FALLBACK
            // ====================================================

            if (count($planesMap) === 0) {

                error_log(
                    '[RADAR] ALL ADS-B PROVIDERS FAILED -> OPEN SKY'
                );

                $openSkyUrl =
                    'https://opensky-network.org/api/states/all' .
                    '?lamin=39.5' .
                    '&lomin=19.5' .
                    '&lamax=47.5' .
                    '&lomax=31.0';

                $osRes =
                    $this->curlFetch(
                        $openSkyUrl
                    );

                error_log(
                    '[RADAR] OpenSky HTTP=' .
                    $osRes['code'] .
                    ' TIME=' .
                    $osRes['time_ms'] .
                    'ms'
                );

                if (
                    $osRes['code'] === 200 &&
                    !empty($osRes['body'])
                ) {

                    $osData =
                        json_decode(
                            $osRes['body'],
                            true
                        );

                    if (
                        !empty($osData['states']) &&
                        is_array(
                            $osData['states']
                        )
                    ) {

                        $this->parseOpenSkyList(
                            $osData['states'],
                            $planesMap
                        );

                        $workingProvider =
                            'OpenSky';
                    }
                }
            }

            // ====================================================
            // 5. FINAL DATA
            // ====================================================

            $finalPlanes =
                array_values(
                    $planesMap
                );

            if (count($finalPlanes) > 0) {

                error_log(
                    '[RADAR] FINAL PROVIDER=' .
                    ($workingProvider ?? 'unknown') .
                    ' AIRCRAFT=' .
                    count($finalPlanes)
                );

                $jsonPayload =
                    json_encode(
                        $finalPlanes,
                        JSON_UNESCAPED_UNICODE
                    );

                /*
                 * Записваме CACHE само ако реално имаме самолети.
                 */

                @file_put_contents(
                    $cacheFile,
                    $jsonPayload
                );

                // -----------------------------------------------
                // HISTORY
                // -----------------------------------------------

                $this->appendHistorySnapshot(
                    $historyFile,
                    $now,
                    $finalPlanes
                );

                return new JsonResponse(
                    $finalPlanes
                );
            }

            // ====================================================
            // 6. ВСИЧКИ API-та СА ПАДНАЛИ
            // ====================================================

            /*
             * НЕ връщаме [] веднага.
             *
             * Ако имаме стар snapshot, използваме него.
             *
             * Така самолетите няма внезапно да изчезнат
             * от радара при временен API проблем.
             */

            if (file_exists($cacheFile)) {

                $old =
                    @file_get_contents(
                        $cacheFile
                    );

                if (
                    $old &&
                    strlen($old) > 10
                ) {

                    error_log(
                        '[RADAR] ALL PROVIDERS FAILED -> STALE CACHE'
                    );

                    return new JsonResponse(
                        json_decode($old, true)
                    );
                }
            }

            error_log(
                '[RADAR] NO DATA AVAILABLE'
            );

            return new JsonResponse([]);

        } finally {

            if ($lockFp) {

                @flock(
                    $lockFp,
                    LOCK_UN
                );

                @fclose(
                    $lockFp
                );
            }
        }
    }

    // ============================================================
    // HISTORY API
    // ============================================================

    #[Route('/api/history', name: 'api_history')]
    public function getHistory(Request $request): JsonResponse
    {
        $historyFile =
            sys_get_temp_dir() .
            '/radar_history_ring.json';

        if (!file_exists($historyFile)) {

            return new JsonResponse([
                'timestamps' => [],
                'planes'     => [],
                'total'      => 0,
            ]);
        }

        $raw =
            @file_get_contents(
                $historyFile
            );

        $history =
            $raw
                ? json_decode($raw, true)
                : [];

        if (
            !is_array($history) ||
            empty($history)
        ) {

            return new JsonResponse([
                'timestamps' => [],
                'planes'     => [],
                'total'      => 0,
            ]);
        }

        $frameIndex =
            $request->query->get(
                'index'
            );

        if ($frameIndex !== null) {

            $idx =
                (int) $frameIndex;

            if (isset($history[$idx])) {

                return new JsonResponse([
                    'timestamp' =>
                        $history[$idx]['ts'],

                    'planes' =>
                        $history[$idx]['planes'],

                    'total' =>
                        count($history),
                ]);
            }
        }

        $timestamps =
            array_map(
                fn($item) => $item['ts'],
                $history
            );

        return new JsonResponse([
            'timestamps' => $timestamps,
            'total'      => count($history),
        ]);
    }

    // ============================================================
    // HISTORY STORAGE
    // ============================================================

    private function appendHistorySnapshot(
        string $historyFile,
        int $timestamp,
        array $planes
    ): void {

        $history = [];

        if (file_exists($historyFile)) {

            $raw =
                @file_get_contents(
                    $historyFile
                );

            $decoded =
                $raw
                    ? json_decode(
                    $raw,
                    true
                )
                    : [];

            if (is_array($decoded)) {
                $history = $decoded;
            }
        }

        $last =
            end($history);

        /*
         * Не записваме history frame прекалено често.
         */

        if (
            $last &&
            isset($last['ts']) &&
            ($timestamp - $last['ts']) < 15
        ) {
            return;
        }

        $history[] = [
            'ts'     => $timestamp,
            'planes' => $planes,
        ];

        if (
            count($history) >
            self::MAX_HISTORY_FRAMES
        ) {

            $history =
                array_slice(
                    $history,
                    -self::MAX_HISTORY_FRAMES
                );
        }

        $tmp =
            $historyFile .
            '.tmp.' .
            getmypid();

        if (
            @file_put_contents(
                $tmp,
                json_encode(
                    $history,
                    JSON_UNESCAPED_UNICODE
                )
            ) !== false
        ) {

            @rename(
                $tmp,
                $historyFile
            );
        }
    }

    // ============================================================
    // PROVIDER COOLDOWN
    // ============================================================

    private function providerIsCoolingDown(
        string $provider
    ): bool {

        $file =
            sys_get_temp_dir() .
            '/radar_provider_' .
            md5($provider) .
            '.cooldown';

        if (!file_exists($file)) {
            return false;
        }

        $timestamp =
            (int) @file_get_contents(
                $file
            );

        if ($timestamp <= 0) {
            return false;
        }

        /*
         * Ако cooldown-ът е изтекъл,
         * махаме файла.
         */

        if (
            time() - $timestamp >
            self::PROVIDER_COOLDOWN
        ) {

            @unlink($file);

            return false;
        }

        return true;
    }

    private function putProviderInCooldown(
        string $provider
    ): void {

        $file =
            sys_get_temp_dir() .
            '/radar_provider_' .
            md5($provider) .
            '.cooldown';

        @file_put_contents(
            $file,
            (string) time()
        );
    }

    // ============================================================
    // CURL
    // ============================================================

    private function curlFetch(
        string $url
    ): array {

        if (!function_exists('curl_init')) {

            return [
                'code'    => 0,
                'body'    => '',
                'error'   => 'No cURL',
                'time_ms' => 0,
            ];
        }

        $ch =
            curl_init(
                $url
            );

        curl_setopt_array(
            $ch,
            [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,

                /*
                 * Малко повече от старите 3 секунди,
                 * защото cloud -> external API понякога
                 * има кратък latency spike.
                 */
                CURLOPT_TIMEOUT        => 6,
                CURLOPT_CONNECTTIMEOUT => 3,

                /*
                 * SSL проверката е включена.
                 */
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,

                CURLOPT_ENCODING       => '',

                CURLOPT_HTTP_VERSION =>
                    CURL_HTTP_VERSION_1_1,

                CURLOPT_HTTPHEADER => [
                    'User-Agent: MilitaryRadar/1.0',
                    'Accept: application/json',
                    'Cache-Control: no-cache',
                ],
            ]
        );

        $start =
            microtime(true);

        $body =
            curl_exec(
                $ch
            );

        $elapsed =
            (int) round(
                (
                    microtime(true) -
                    $start
                ) * 1000
            );

        $code =
            (int) curl_getinfo(
                $ch,
                CURLINFO_HTTP_CODE
            );

        $err =
            curl_error(
                $ch
            );

        curl_close(
            $ch
        );

        return [
            'code' =>
                $code,

            'body' =>
                is_string($body)
                    ? $body
                    : '',

            'error' =>
                $err,

            'time_ms' =>
                $elapsed,
        ];
    }

    // ============================================================
    // OPEN SKY PARSER
    // ============================================================

    private function parseOpenSkyList(
        array $states,
        array &$planesMap
    ): void {

        foreach ($states as $st) {

            $id =
                strtolower(
                    trim(
                        (string) ($st[0] ?? '')
                    )
                );

            $rawCallsign =
                trim(
                    (string) ($st[1] ?? '')
                );

            $originCountry =
                trim(
                    (string) (
                        $st[2]
                        ??
                        'Граждански полет'
                    )
                );

            $lon =
                $st[5] ?? null;

            $lat =
                $st[6] ?? null;

            $altMeters =
                $st[7]
                ??
                $st[13]
                ??
                0;

            $velocityMs =
                (float) (
                    $st[9] ?? 0
                );

            $track =
                (float) (
                    $st[10] ?? 0
                );

            $squawk =
                (string) (
                    $st[14] ?? '----'
                );

            if (
                $id === '' ||
                $lat === null ||
                $lon === null
            ) {
                continue;
            }

            $isMil =
                $this->isKnownMilitary(
                    $id,
                    $rawCallsign,
                    $squawk,
                    '',
                    $originCountry
                );

            $displayCallsign =
                $rawCallsign !== ''
                    ? $rawCallsign
                    : strtoupper($id);

            if (
                $isMil &&
                !str_contains(
                    $displayCallsign,
                    '[ВОЕНЕН]'
                )
            ) {

                $displayCallsign .=
                    ' [ВОЕНЕН]';
            }

            $planesMap[$id] = [

                'id' =>
                    $id,

                'callsign' =>
                    $displayCallsign,

                'lat' =>
                    (float) $lat,

                'lon' =>
                    (float) $lon,

                'track' =>
                    $track,

                'altitude' =>
                    is_numeric($altMeters)
                        ? (int) round(
                        (float) $altMeters
                    )
                        : 0,

                'speed' =>
                    (int) round(
                        $velocityMs * 3.6
                    ),

                'squawk' =>
                    $squawk !== ''
                        ? $squawk
                        : '----',

                'type' =>
                    $isMil
                        ? 'Военен борд'
                        : 'Въздушна цел',

                'operator' =>
                    $originCountry,

                'age' =>
                    1.0,
            ];
        }
    }

    // ============================================================
    // ADS-B V2 PARSER
    // ============================================================

    private function parseAdsbV2List(
        array $aircraftList,
        array &$planesMap,
        float $centerLat,
        float $centerLon,
        bool $isMilitaryFeed
    ): void {

        foreach ($aircraftList as $ac) {

            $lat =
                $ac['lat']
                ??
                $ac['latitude']
                ??
                null;

            $lon =
                $ac['lon']
                ??
                $ac['longitude']
                ??
                null;

            if (
                $lat === null ||
                $lon === null
            ) {
                continue;
            }

            $latF =
                (float) $lat;

            $lonF =
                (float) $lon;

            /*
             * Защита срещу странни/далечни координати.
             */
            if (
                abs($latF - $centerLat) > 5.5 ||
                abs($lonF - $centerLon) > 7.5
            ) {
                continue;
            }

            $id =
                strtolower(
                    trim(
                        (string) (
                            $ac['hex'] ?? ''
                        )
                    )
                );

            if ($id === '') {
                continue;
            }

            $dbFlags =
                (int) (
                    $ac['dbFlags'] ?? 0
                );

            $rawCallsign =
                trim(
                    (string) (
                        $ac['flight']
                        ??
                        $ac['call']
                        ??
                        ''
                    )
                );

            $squawk =
                (string) (
                    $ac['squawk']
                    ??
                    '----'
                );

            $aircraftType =
                trim(
                    (string) (
                        $ac['t']
                        ??
                        $ac['type']
                        ??
                        ''
                    )
                );

            $desc =
                trim(
                    (string) (
                        $ac['desc']
                        ??
                        ''
                    )
                );

            $operator =
                trim(
                    (string) (
                        $ac['ownOp']
                        ??
                        $ac['op']
                        ??
                        ''
                    )
                );

            $isMil =
                $isMilitaryFeed ||
                (($dbFlags & 1) === 1) ||
                $this->isKnownMilitary(
                    $id,
                    $rawCallsign,
                    $squawk,
                    $aircraftType,
                    $operator
                );

            $displayCallsign =
                $rawCallsign !== ''
                    ? $rawCallsign
                    : strtoupper($id);

            if (
                $isMil &&
                !str_contains(
                    $displayCallsign,
                    '[ВОЕНЕН]'
                )
            ) {

                $displayCallsign .=
                    ' [ВОЕНЕН]';
            }

            $altBaro =
                $ac['alt_baro']
                ??
                $ac['alt_geom']
                ??
                $ac['alt']
                ??
                0;

            $altitudeMeters =
                is_numeric($altBaro)
                    ? (int) round(
                    ((float) $altBaro) *
                    0.3048
                )
                    : 0;

            $gsKnots =
                (float) (
                    $ac['gs']
                    ??
                    $ac['spd']
                    ??
                    0
                );

            $speedKmh =
                (int) round(
                    $gsKnots * 1.852
                );

            $track =
                (float) (
                    $ac['track']
                    ??
                    $ac['true_heading']
                    ??
                    $ac['mag_heading']
                    ??
                    0
                );

            $seenPos =
                (float) (
                    $ac['seen_pos']
                    ??
                    $ac['seen']
                    ??
                    0.5
                );

            $existing =
                $planesMap[$id]
                ??
                null;

            $typeLabel =
                $desc !== ''
                    ? $desc
                    : (
                $aircraftType !== ''
                    ? $aircraftType
                    : (
                    $existing['type']
                    ??
                    'Няма данни'
                )
                );

            $opLabel =
                $operator !== ''
                    ? $operator
                    : (
                    $existing['operator']
                    ??
                    (
                    $isMil
                        ? 'Военновъздушни сили (ВВС)'
                        : 'Граждански полет'
                    )
                );

            if (
                $existing &&
                str_contains(
                    $existing['callsign'],
                    '[ВОЕНЕН]'
                ) &&
                !str_contains(
                    $displayCallsign,
                    '[ВОЕНЕН]'
                )
            ) {

                $displayCallsign .=
                    ' [ВОЕНЕН]';
            }

            $planesMap[$id] = [

                'id' =>
                    $id,

                'callsign' =>
                    $displayCallsign,

                'lat' =>
                    $latF,

                'lon' =>
                    $lonF,

                'track' =>
                    $track,

                'altitude' =>
                    $altitudeMeters,

                'speed' =>
                    $speedKmh,

                'squawk' =>
                    $squawk !== ''
                        ? $squawk
                        : (
                        $existing['squawk']
                        ??
                        '----'
                    ),

                'type' =>
                    $typeLabel,

                'operator' =>
                    $opLabel,

                'age' =>
                    min(
                        $seenPos,
                        15.0
                    ),
            ];
        }
    }

    // ============================================================
    // MILITARY DETECTION
    // ============================================================

    private function isKnownMilitary(
        string $hex,
        string $callsign,
        string $squawk,
        string $type,
        string $operator
    ): bool {

        $hex =
            strtolower($hex);

        $cs =
            strtoupper($callsign);

        $type =
            strtoupper($type);

        $op =
            strtoupper($operator);

        // --------------------------------------------------------
        // HEX ranges
        // --------------------------------------------------------

        if (
            str_starts_with($hex, '45f4') ||
            str_starts_with($hex, 'ae') ||
            str_starts_with($hex, 'af') ||
            str_starts_with($hex, '43c') ||
            str_starts_with($hex, '3f')
        ) {
            return true;
        }

        // --------------------------------------------------------
        // Squawk
        // --------------------------------------------------------

        if (
            $squawk === '7777' ||
            str_starts_with($squawk, '44') ||
            str_starts_with($squawk, '74')
        ) {
            return true;
        }

        // --------------------------------------------------------
        // Callsigns
        // --------------------------------------------------------

        $milPrefixes = [
            'BAF',
            'BULAF',
            'BGF',
            'NATO',
            'FORTE',
            'LAGR',
            'NCHO',
            'JAKE',
            'RCH',
            'RRR',
            'ASCOT',
            'DUKE',
            'VIPER',
            'COBRA',
            'HAWK',
            'GAF',
            'FAF',
            'TUAF',
            'HAF',
            'ROAF',
            'PLF',
            'SAM',
            'SPAR',
        ];

        foreach (
            $milPrefixes as $prefix
        ) {

            if (
                $cs !== '' &&
                str_starts_with(
                    $cs,
                    $prefix
                )
            ) {
                return true;
            }
        }

        // --------------------------------------------------------
        // Aircraft types
        // --------------------------------------------------------

        $milTypes = [
            'F16',
            'C27J',
            'C130',
            'C30J',
            'PC9',
            'L39',
            'MIG29',
            'SU25',
            'UH60',
            'CH47',
            'MI8',
            'MI24',
            'KC135',
            'E3TF',
            'C17',
            'A400',
            'P8',
            'RQ4',
            'MQ9',
            'B52',
            'F15',
            'F18',
            'F35',
            'TYPH',
            'RAFL',
        ];

        foreach (
            $milTypes as $mt
        ) {

            if (
                $type !== '' &&
                str_contains(
                    $type,
                    $mt
                )
            ) {
                return true;
            }
        }

        // --------------------------------------------------------
        // Operator
        // --------------------------------------------------------

        $milOpKeywords = [
            'AIR FORCE',
            'MILITARY',
            'NAVY',
            'ARMY',
            'NATO',
            'DEFENCE',
            'DEFENSE',
            'LUFTWAFFE',
            'GUARD',
            'ВВС',
        ];

        foreach (
            $milOpKeywords as $kw
        ) {

            if (
                $op !== '' &&
                str_contains(
                    $op,
                    $kw
                )
            ) {
                return true;
            }
        }

        return false;
    }
}
