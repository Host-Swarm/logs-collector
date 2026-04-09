<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Infrastructure\WebSocket\DockerWebSocketHandler;
use Illuminate\Console\Command;
use Workerman\Worker;

final class WebSocketServeCommand extends Command
{
    protected $signature = 'websocket:serve {--host=} {--port=}';

    protected $description = 'Start the WebSocket server for Docker log streaming and exec sessions.';

    public function handle(DockerWebSocketHandler $handler): int
    {
        $host = (string) ($this->option('host') ?: config('logs_collector.websocket.host', '0.0.0.0'));
        $port = (int) ($this->option('port') ?: config('logs_collector.websocket.port', 8080));

        Worker::$pidFile = storage_path('logs/websocket.pid');
        Worker::$logFile = storage_path('logs/websocket.log');

        $this->info("Starting WebSocket server on {$host}:{$port}...");

        $handler->start($host, $port);

        return self::SUCCESS;
    }
}
