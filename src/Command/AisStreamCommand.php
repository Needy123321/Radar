<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use WebSocket\Client;
use WebSocket\TimeoutException;

#[AsCommand(name: 'app:ais-stream', description: 'Слуша AISstream за Черно море и синхронизира корабите')]
class AisStreamCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $varDir = dirname(__DIR__, 2) . '/var';
        if (!is_dir($varDir)) {
            @mkdir($varDir, 0777, true);
        }

        $cacheFile = $varDir . '/ais_ships.json';
        $apiKey = $_ENV['AISSTREAM_API_KEY']
            ?? $_SERVER['AISSTREAM_API_KEY']
            ?? getenv('AISSTREAM_API_KEY')
            ?: 'f163c13de17a22d752ec693b9653e34b3d1471d1';

        $apiKey = trim((string)$apiKey, " \t\n\r\0\x0B\"'");
        $renderUrl = 'https://radar-biww.onrender.com/api/ships-push';
        $isRenderEnv = !empty($_ENV['RENDER']) || !empty($_SERVER['RENDER']) || getenv('RENDER') !== false;

        $ships = is_file($cacheFile)
            ? (json_decode((string)@file_get_contents($cacheFile), true) ?? [])
            : [];

        $shipsMap = [];
        if (is_array($ships)) {
            foreach ($ships as $s) {
                if (is_array($s) && !empty($s['mmsi'])) {
                    $shipsMap[(string)$s['mmsi']] = $s;
                }
            }
        }

        $lastSave = 0;
        $lastRenderPush = 0;

        while (true) {
            $client = null;
            try {
                $output->writeln(sprintf('<comment>[%s] Свързване към AISstream...</comment>', date('H:i:s')));

                $client = new Client('wss://stream.aisstream.io/v0/stream', [
                    'timeout' => 25,
                ]);

                $subscription = [
                    'APIKey' => $apiKey,
                    'BoundingBoxes' => [
                        [[40.5, 26.5], [47.0, 42.0]],
                    ],
                    'FilterMessageTypes' => [
                        'PositionReport',
                        'StandardClassBPositionReport',
                        'ExtendedClassBPositionReport',
                        'ShipStaticData',
                        'SafetyBroadcastMessage',
                        'AddressedSafetyMessage',
                    ],
                ];

                $client->text((string)json_encode($subscription));
                $output->writeln('<info>Успешен абонамент към AISstream!</info>');

                $lastMessageAt = time();

                while (true) {
                    // WATCHDOG: Ако 90 секунди няма нито един пакет, рестартираме връзката!
                    if (time() - $lastMessageAt > 90) {
                        throw new \RuntimeException('Няма данни от AISstream над 90 сек - принудителен реконект.');
                    }

                    try {
                        $raw = $client->receive();
                    } catch (TimeoutException $te) {
                        // Ако сокетът просто изчаква трафик, проверяваме таймера горе и продължаваме
                        continue;
                    }

                    if (!is_string($raw) || $raw === '') {
                        continue;
                    }

                    $lastMessageAt = time();

                    $msg = json_decode($raw, true);
                    if (!is_array($msg)) {
                        continue;
                    }

                    if (($msg['MessageType'] ?? '') === 'SubscriptionConfirmation') {
                        $output->writeln('<info>AISstream потвърди абонамента!</info>');
                        continue;
                    }

                    if (isset($msg['error']) || isset($msg['Error'])) {
                        $errMsg = (string)($msg['error'] ?? $msg['Error']);
                        throw new \RuntimeException('AISstream грешка: ' . $errMsg);
                    }

                    $meta = $msg['MetaData'] ?? [];
                    $mmsi = trim((string)($meta['MMSI'] ?? $meta['MMSI_String'] ?? ''));
                    if ($mmsi === '') {
                        continue;
                    }

                    $metaLat = isset($meta['Latitude']) ? (float)$meta['Latitude'] : (isset($meta['latitude']) ? (float)$meta['latitude'] : null);
                    $metaLon = isset($meta['Longitude']) ? (float)$meta['Longitude'] : (isset($meta['longitude']) ? (float)$meta['longitude'] : null);

                    $existing = $shipsMap[$mmsi] ?? [
                        'mmsi'         => $mmsi,
                        'name'         => trim((string)($meta['ShipName'] ?? '')) ?: "MMSI {$mmsi}",
                        'lat'          => $metaLat,
                        'lon'          => $metaLon,
                        'sog'          => 0.0,
                        'maxRecentSog' => 0.0,
                        'cog'          => 0.0,
                        'rot'          => 0.0,
                        'heading'      => null,
                        'navStatus'    => 0,
                        'shipType'     => 0,
                        'callsign'     => '',
                        'imo'          => 0,
                        'destination'  => '',
                        'draught'      => 0.0,
                        'length'       => 0,
                        'width'        => 0,
                        'safetyText'   => '',
                        'lastSeen'     => time(),
                    ];

                    $metaShipName = trim((string)($meta['ShipName'] ?? ''));
                    if ($metaShipName !== '') {
                        $existing['name'] = $metaShipName;
                    }

                    $type = (string)($msg['MessageType'] ?? '');
                    $payload = $msg['Message'][$type] ?? null;

                    if (in_array($type, ['PositionReport', 'StandardClassBPositionReport', 'ExtendedClassBPositionReport'], true) && is_array($payload)) {
                        if (isset($payload['Latitude'])) {
                            $existing['lat'] = (float)$payload['Latitude'];
                        } elseif ($metaLat !== null) {
                            $existing['lat'] = $metaLat;
                        }

                        if (isset($payload['Longitude'])) {
                            $existing['lon'] = (float)$payload['Longitude'];
                        } elseif ($metaLon !== null) {
                            $existing['lon'] = $metaLon;
                        }

                        $newSog = (float)($payload['Sog'] ?? $existing['sog']);
                        if ($newSog > 4.5) {
                            $existing['maxRecentSog'] = max((float)($existing['maxRecentSog'] ?? 0.0), $newSog);
                        }
                        $existing['sog'] = round($newSog, 1);
                        $existing['rot'] = (float)($payload['RateOfTurn'] ?? 0.0);

                        $hdg = (int)($payload['TrueHeading'] ?? 511);
                        $existing['heading'] = ($hdg >= 0 && $hdg < 360) ? $hdg : null;
                        $existing['cog'] = ($hdg >= 0 && $hdg < 360)
                            ? (float)$hdg
                            : round((float)($payload['Cog'] ?? $existing['cog']), 1);

                        if (isset($payload['NavigationalStatus'])) {
                            $existing['navStatus'] = (int)$payload['NavigationalStatus'];
                        }
                    }

                    if (in_array($type, ['SafetyBroadcastMessage', 'AddressedSafetyMessage'], true) && is_array($payload)) {
                        $sText = trim((string)($payload['Text'] ?? ''));
                        if ($sText !== '') {
                            $existing['safetyText'] = $sText;
                        }
                    }

                    if ($type === 'ShipStaticData' && is_array($payload)) {
                        $existing['shipType'] = (int)($payload['Type'] ?? $existing['shipType']);

                        $staticName = trim((string)($payload['Name'] ?? ''));
                        if ($staticName !== '') {
                            $existing['name'] = $staticName;
                        }

                        $staticCs = trim((string)($payload['CallSign'] ?? ''));
                        if ($staticCs !== '') {
                            $existing['callsign'] = $staticCs;
                        }

                        if (!empty($payload['ImoNumber'])) {
                            $existing['imo'] = (int)$payload['ImoNumber'];
                        }

                        $dest = trim(str_replace('@', '', (string)($payload['Destination'] ?? '')));
                        if ($dest !== '') {
                            $existing['destination'] = $dest;
                        }

                        if (!empty($payload['MaximumStaticDraught'])) {
                            $existing['draught'] = (float)$payload['MaximumStaticDraught'];
                        }

                        if (isset($payload['Dimension']) && is_array($payload['Dimension'])) {
                            $dim = $payload['Dimension'];
                            $len = (int)($dim['A'] ?? 0) + (int)($dim['B'] ?? 0);
                            $wid = (int)($dim['C'] ?? 0) + (int)($dim['D'] ?? 0);
                            if ($len > 0) {
                                $existing['length'] = $len;
                            }
                            if ($wid > 0) {
                                $existing['width'] = $wid;
                            }
                        }
                    }

                    $existing['lastSeen'] = time();

                    if (
                        $existing['lat'] !== null &&
                        $existing['lon'] !== null &&
                        abs((float)$existing['lat']) <= 90 &&
                        abs((float)$existing['lon']) <= 180
                    ) {
                        $shipsMap[$mmsi] = $existing;
                        $now = time();

                        if ($now - $lastSave >= 2) {
                            $shipsMap = array_filter(
                                $shipsMap,
                                static fn(array $s): bool => ($now - (int)($s['lastSeen'] ?? 0)) <= 2700
                            );

                            $jsonPayload = json_encode(
                                array_values($shipsMap),
                                JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
                            );

                            if (is_string($jsonPayload)) {
                                @file_put_contents($cacheFile, $jsonPayload, LOCK_EX);
                                $lastSave = $now;

                                $output->writeln(sprintf(
                                    '[%s] Записани %d кораба в Черно море',
                                    date('H:i:s'),
                                    count($shipsMap)
                                ));

                                // Ако сме на локалната машина, пращаме корабите и към Render на всеки 5 сек
                                if (!$isRenderEnv && ($now - $lastRenderPush >= 5)) {
                                    $ch = curl_init($renderUrl);
                                    curl_setopt_array($ch, [
                                        CURLOPT_POST           => true,
                                        CURLOPT_POSTFIELDS     => $jsonPayload,
                                        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                                        CURLOPT_RETURNTRANSFER => true,
                                        CURLOPT_CONNECTTIMEOUT => 2,
                                        CURLOPT_TIMEOUT        => 3,
                                    ]);
                                    @curl_exec($ch);
                                    curl_close($ch);
                                    $lastRenderPush = $now;
                                }
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                $output->writeln(sprintf(
                    '<comment>[%s] [DOCKER AIS] Рестарт на потока: %s</comment>',
                    date('H:i:s'),
                    $e->getMessage()
                ));

                try {
                    if ($client !== null) {
                        $client->close();
                    }
                } catch (\Throwable $ignore) {
                }

                sleep(5);
            }
        }
    }
}
