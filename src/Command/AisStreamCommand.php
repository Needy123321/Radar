<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use WebSocket\Client;
use WebSocket\TimeoutException;

#[AsCommand(name: 'app:ais-stream', description: 'Слуша AISstream за Черно море и анализира аварийни кодове на корабите')]
class AisStreamCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $cacheFile = dirname(__DIR__, 2) . '/var/ais_ships.json';

        $apiKey = $_ENV['AISSTREAM_API_KEY'] ?? $_SERVER['AISSTREAM_API_KEY'] ?? getenv('AISSTREAM_API_KEY');
        if (!$apiKey) {
            $output->writeln('<error>Липсва AISSTREAM_API_KEY в .env!</error>');
            return Command::FAILURE;
        }

        $ships = file_exists($cacheFile)
            ? (json_decode(file_get_contents($cacheFile), true) ?? [])
            : [];

        $shipsMap = [];
        foreach ($ships as $s) {
            if (!empty($s['mmsi'])) {
                $shipsMap[$s['mmsi']] = $s;
            }
        }

        while (true) {
            try {
                $output->writeln('<comment>Свързване към AISstream...</comment>');
                $client = new Client('wss://stream.aisstream.io/v0/stream', [
                    'timeout' => 30,
                ]);

                $subscription = [
                    'APIKey' => trim($apiKey),
                    'BoundingBoxes' => [
                        [[40.5, 26.5], [47.0, 42.0]]
                    ],
                    'FilterMessageTypes' => [
                        'PositionReport',
                        'StandardClassBPositionReport',
                        'ExtendedClassBPositionReport',
                        'ShipStaticData',
                        'SafetyBroadcastMessage',
                        'AddressedSafetyMessage'
                    ]
                ];

                $client->text(json_encode($subscription));
                $output->writeln('<info>Успешен абонамент! Следим корабите и аварийните AIS честоти...</info>');

                while (true) {
                    try {
                        $raw = $client->receive();
                    } catch (TimeoutException $te) {
                        $client->ping();
                        continue;
                    }

                    if (!$raw) continue;

                    $msg = json_decode($raw, true);
                    if (!is_array($msg)) continue;

                    if (($msg['MessageType'] ?? '') === 'SubscriptionConfirmation') {
                        $output->writeln('<info>AISstream потвърди абонамента!</info>');
                        continue;
                    }

                    $meta = $msg['MetaData'] ?? [];
                    $mmsi = (string)($meta['MMSI'] ?? $meta['MMSI_String'] ?? '');
                    if ($mmsi === '') continue;

                    $metaLat = $meta['Latitude'] ?? $meta['latitude'] ?? null;
                    $metaLon = $meta['Longitude'] ?? $meta['longitude'] ?? null;

                    $existing = $shipsMap[$mmsi] ?? [
                        'mmsi'          => $mmsi,
                        'name'          => trim($meta['ShipName'] ?? '') ?: "MMSI {$mmsi}",
                        'lat'           => $metaLat,
                        'lon'           => $metaLon,
                        'sog'           => 0.0,
                        'maxRecentSog'  => 0.0,
                        'cog'           => 0.0,
                        'rot'           => 0.0,
                        'heading'       => null,
                        'navStatus'     => 0,
                        'shipType'      => 0,
                        'callsign'      => '',
                        'imo'           => 0,
                        'destination'   => '',
                        'draught'       => 0.0,
                        'length'        => 0,
                        'width'         => 0,
                        'safetyText'    => '',
                        'lastSeen'      => time(),
                    ];

                    if (!empty(trim($meta['ShipName'] ?? ''))) {
                        $existing['name'] = trim($meta['ShipName']);
                    }

                    $type = $msg['MessageType'] ?? '';
                    $payload = $msg['Message'][$type] ?? null;

                    if (in_array($type, ['PositionReport', 'StandardClassBPositionReport', 'ExtendedClassBPositionReport'], true) && is_array($payload)) {
                        $existing['lat'] = $payload['Latitude'] ?? $metaLat ?? $existing['lat'];
                        $existing['lon'] = $payload['Longitude'] ?? $metaLon ?? $existing['lon'];

                        $newSog = (float)($payload['Sog'] ?? $existing['sog']);
                        // Помним дали корабът е бил в нормален ход (> 4.5 възела), за да хванем рязко спиране в морето
                        if ($newSog > 4.5) {
                            $existing['maxRecentSog'] = max((float)($existing['maxRecentSog'] ?? 0.0), $newSog);
                        }
                        $existing['sog'] = $newSog;
                        $existing['rot'] = (float)($payload['RateOfTurn'] ?? 0.0);

                        $hdg = (int)($payload['TrueHeading'] ?? 511);
                        $existing['heading'] = ($hdg >= 0 && $hdg < 360) ? $hdg : null;
                        $existing['cog'] = ($hdg >= 0 && $hdg < 360) ? $hdg : (float)($payload['Cog'] ?? $existing['cog']);

                        if (isset($payload['NavigationalStatus'])) {
                            $existing['navStatus'] = (int)$payload['NavigationalStatus'];
                        }
                    }

                    // Прихващане на аварийни текстови AIS съобщения (Type 12 / Type 14) от самия кораб
                    if (in_array($type, ['SafetyBroadcastMessage', 'AddressedSafetyMessage'], true) && is_array($payload)) {
                        $sText = trim((string)($payload['Text'] ?? ''));
                        if ($sText !== '') {
                            $existing['safetyText'] = $sText;
                        }
                    }

                    if ($type === 'ShipStaticData' && is_array($payload)) {
                        $existing['shipType'] = (int)($payload['Type'] ?? $existing['shipType']);
                        if (!empty(trim($payload['Name'] ?? ''))) {
                            $existing['name'] = trim($payload['Name']);
                        }
                        if (!empty(trim($payload['CallSign'] ?? ''))) {
                            $existing['callsign'] = trim($payload['CallSign']);
                        }
                        if (!empty($payload['ImoNumber'])) {
                            $existing['imo'] = (int)$payload['ImoNumber'];
                        }
                        if (!empty(trim($payload['Destination'] ?? ''))) {
                            $existing['destination'] = trim(str_replace('@', '', $payload['Destination']));
                        }
                        if (!empty($payload['MaximumStaticDraught'])) {
                            $existing['draught'] = (float)$payload['MaximumStaticDraught'];
                        }
                        if (isset($payload['Dimension']) && is_array($payload['Dimension'])) {
                            $dim = $payload['Dimension'];
                            $len = (int)($dim['A'] ?? 0) + (int)($dim['B'] ?? 0);
                            $wid = (int)($dim['C'] ?? 0) + (int)($dim['D'] ?? 0);
                            if ($len > 0) $existing['length'] = $len;
                            if ($wid > 0) $existing['width'] = $wid;
                        }
                    }

                    $existing['lastSeen'] = time();

                    if ($existing['lat'] !== null && $existing['lon'] !== null) {
                        $shipsMap[$mmsi] = $existing;

                        $now = time();
                        // Пазим корабите 45 минути (2700 сек), за да засечем кораб, който е изгубил сигнал в открито море!
                        $shipsMap = array_filter($shipsMap, fn($s) => ($now - $s['lastSeen']) <= 2700);
                        file_put_contents($cacheFile, json_encode(array_values($shipsMap), JSON_UNESCAPED_UNICODE));
                    }
                }
            } catch (\Throwable $e) {
                $output->writeln('<error>Грешка: ' . $e->getMessage() . '. Рестарт след 5 сек...</error>');
                sleep(5);
            }
        }
    }
}
