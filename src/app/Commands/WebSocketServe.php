<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use App\Libraries\BoundedWsServer;
use App\Libraries\WebSocketOriginGuard;
use App\Libraries\RedisConnectionConfig;
use App\Libraries\WebSocketPromiseErrors;
use React\EventLoop\Loop;
use React\Socket\SocketServer;
use Clue\React\Redis\Factory;
use Clue\React\Redis\Client;
use App\Libraries\WebSocketServerHandler;

class WebSocketServe extends BaseCommand
{
    protected $group       = 'WebSocket';
    protected $name        = 'websocket:serve';
    protected $description = 'Starts the WebSocket server for real-time exam events.';

    public function run(array $params)
    {
        if (trim((string) ini_get('memory_limit')) === '-1') {
            if (ini_set('memory_limit', '256M') === false || trim((string) ini_get('memory_limit')) === '-1') {
                CLI::error('WebSocket requires a finite PHP memory limit.');
                return 1;
            }
        }
        CLI::write("Starting WebSocket Server...", 'green');
        WebSocketPromiseErrors::install(static function (string $message): void {
            CLI::error($message);
        });

        // Create the event loop
        $loop = Loop::get();
        CLI::write('Event loop: ' . get_class($loop) . '; PHP memory_limit=' . ini_get('memory_limit'));

        // Redis config
        $redisUrl = RedisConnectionConfig::fromEnvironment()->uri();

        $factory = new Factory($loop);
        
        // Create lazy async Redis client for token validation.
        // LazyClient reconnects automatically on demand after an outage.
        $asyncRedis = $factory->createLazyClient($redisUrl);
        $asyncRedis->on('error', static function (\Throwable $e): void {
            CLI::error('Redis async client error (' . get_class($e) . ')');
        });

        // Create the WebSocket handler
        $wsHandler = new WebSocketServerHandler($asyncRedis);

        // ─── Pub/Sub with automatic reconnection ──────────────────────────
        // A single-shot createClient() + subscribe() dies silently when Redis
        // restarts and never comes back. We track connection state and let a
        // periodic timer recreate + resubscribe until it succeeds again.
        $pubSubConnected = false;
        $pubSubConnecting = false;

        $connectPubSub = function () use (&$connectPubSub, $factory, $redisUrl, $wsHandler, &$pubSubConnected, &$pubSubConnecting) {
            if ($pubSubConnecting) {
                return; // an attempt is already in flight
            }
            $pubSubConnecting = true;

            $factory->createClient($redisUrl)->then(
                function (Client $client) use ($wsHandler, &$pubSubConnected, &$pubSubConnecting) {
                    $client->on('message', function ($channel, $payload) use ($wsHandler) {
                        if ($channel === 'exam_events') {
                            $eventData = json_decode($payload, true);
                            if ($eventData) {
                                $wsHandler->broadcastEvent($eventData);
                            }
                        }
                    });

                    // Swallow connection errors (e.g. Redis restarted) instead
                    // of killing the event loop; the timer below reconnects.
                    $client->on('error', function ($e) use (&$pubSubConnected) {
                        $pubSubConnected = false;
                        CLI::write('Redis Pub/Sub error (' . get_class($e) . ')', 'red');
                    });

                    $client->on('close', function () use (&$pubSubConnected) {
                        $pubSubConnected = false;
                        CLI::write("Redis Pub/Sub connection closed. Will reconnect...", 'yellow');
                    });

                    $client->subscribe('exam_events')->then(
                        function () use (&$pubSubConnected, &$pubSubConnecting): void {
                            $pubSubConnected = true;
                            $pubSubConnecting = false;
                            CLI::write('Connected to Redis for Pub/Sub.', 'green');
                        },
                        function (\Throwable $e) use ($client, &$pubSubConnected, &$pubSubConnecting): void {
                            $pubSubConnected = false;
                            $pubSubConnecting = false;
                            CLI::error('Redis subscription failed (' . get_class($e) . ')');
                            $client->close();
                        }
                    );
                },
                function (\Exception $e) use (&$pubSubConnected, &$pubSubConnecting) {
                    $pubSubConnected = false;
                    $pubSubConnecting = false;
                    CLI::write('Failed to connect to Redis (' . get_class($e) . ')', 'red');
                }
            );
        };

        $connectPubSub();

        // Every 15s, if the Pub/Sub link is down (initial failure or lost
        // connection), attempt to recreate it. While connected,
        // the state flag prevents duplicate subscriptions.
        $loop->addPeriodicTimer(15, function () use (&$pubSubConnected, $connectPubSub) {
            if (!$pubSubConnected) {
                CLI::write("Attempting to reconnect Redis Pub/Sub...", 'yellow');
                $connectPubSub();
            }
        });

        // Set up the socket server
        $socket = new SocketServer('0.0.0.0:8060', [], $loop);
        
        $server = new IoServer(
            new HttpServer(
                new WebSocketOriginGuard(
                    new BoundedWsServer($wsHandler),
                    (string) config('App')->baseURL,
                    (string) (env('KIOSK_CORS_ORIGIN') ?: 'https://appassets.androidplatform.net'),
                    explode(',', (string) env('CORS_ALLOWED_ORIGINS', '')),
                )
            ),
            $socket,
            $loop
        );

        // Add periodic timer for heartbeat (every 30 seconds)
        $loop->addPeriodicTimer(30, function () use ($wsHandler) {
            $wsHandler->broadcastHeartbeat();
        });

        // Add periodic timer for pruning stale connections (every 45 seconds)
        $loop->addPeriodicTimer(45, function () use ($wsHandler) {
            $wsHandler->pruneStaleConnections();
        });

        CLI::write("WebSocket Server listening on port 8060...", 'yellow');
        $server->run();
    }
}
