<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use React\EventLoop\Loop;
use React\Socket\SocketServer;
use Clue\React\Redis\Factory;
use App\Libraries\WebSocketServerHandler;

class WebSocketServe extends BaseCommand
{
    protected $group       = 'WebSocket';
    protected $name        = 'websocket:serve';
    protected $description = 'Starts the WebSocket server for real-time exam events.';

    public function run(array $params)
    {
        CLI::write("Starting WebSocket Server...", 'green');

        // Create the event loop
        $loop = Loop::get();

        // Redis config
        $redisHost = env('redis.host', 'redis');
        $redisPort = env('redis.port', 6379);
        $redisPassword = env('REDIS_PASSWORD', '');
        
        $auth = !empty($redisPassword) ? ":{$redisPassword}@" : "";
        $redisUrl = "redis://{$auth}{$redisHost}:{$redisPort}";

        $factory = new Factory($loop);
        
        // Create lazy async Redis client for token validation
        $asyncRedis = $factory->createLazyClient($redisUrl);

        // Create the WebSocket handler
        $wsHandler = new WebSocketServerHandler($asyncRedis);

        // Redis Pub/Sub client
        $factory->createClient($redisUrl)->then(function (\Clue\React\Redis\Client $client) use ($wsHandler) {
            CLI::write("Connected to Redis for Pub/Sub.", 'green');
            
            // Subscribe to channel
            $client->on('message', function ($channel, $payload) use ($wsHandler) {
                if ($channel === 'exam_events') {
                    $eventData = json_decode($payload, true);
                    if ($eventData) {
                        $wsHandler->broadcastEvent($eventData);
                    }
                }
            });
            
            $client->subscribe('exam_events');

        }, function (\Exception $e) {
            CLI::write("Failed to connect to Redis: " . $e->getMessage(), 'red');
        });

        // Set up the socket server
        $socket = new SocketServer('0.0.0.0:8060', [], $loop);
        
        $server = new IoServer(
            new HttpServer(
                new WsServer(
                    $wsHandler
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
