<?php

declare(strict_types=1);

namespace App\Infrastructure\WebSocket;

use App\Domain\Auth\Contracts\ScopedTokenValidator;
use App\Domain\Docker\Contracts\ExecService;
use App\Infrastructure\Docker\DockerApiException;
use App\Infrastructure\Docker\DockerHttpClient;
use Psr\Log\LoggerInterface;
use Throwable;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Websocket;
use Workerman\Worker;

final class DockerWebSocketHandler
{
    public function __construct(
        private DockerHttpClient $docker,
        private ExecService $execService,
        private ScopedTokenValidator $tokenValidator,
        private LoggerInterface $logger,
    ) {}

    public function start(string $host, int $port): void
    {
        $worker = new Worker("websocket://{$host}:{$port}");
        $worker->count = 1;
        $worker->name = 'docker-websocket';

        $worker->onWebSocketConnect = $this->onOpen(...);
        $worker->onMessage = $this->onMessage(...);
        $worker->onClose = $this->onClose(...);
        $worker->onError = function (TcpConnection $conn, int $code, string $msg): void {
            $this->logger->error('WebSocket transport error.', ['code' => $code, 'message' => $msg]);
        };

        Worker::runAll();
    }

    private function onOpen(TcpConnection $conn, string $httpBuffer): void
    {
        $url = '/';

        if (preg_match('/^GET\s+(\S+)/i', $httpBuffer, $m)) {
            $url = $m[1];
        }

        $path = parse_url($url, PHP_URL_PATH) ?: '/';
        $queryString = parse_url($url, PHP_URL_QUERY) ?? '';
        parse_str($queryString, $query);

        $accessToken = (string) ($query['accessToken'] ?? '');

        if ($accessToken === '') {
            $conn->send(json_encode(['error' => 'Unauthorized.']));
            $conn->close();

            return;
        }

        if (preg_match('#^/ws/logs/([a-f0-9]{12,64})$#', $path, $matches)) {
            $this->handleLogConnection($conn, $matches[1], $query, $accessToken);
        } elseif (preg_match('#^/ws/exec/([a-f0-9]{12,64})$#', $path, $matches)) {
            $this->handleExecConnection($conn, $matches[1], $query, $accessToken);
        } else {
            $conn->send(json_encode(['error' => 'Not found.']));
            $conn->close();
        }
    }

    private function onMessage(TcpConnection $conn, string $data): void
    {
        if (($conn->dockerType ?? null) !== 'exec') {
            return;
        }

        $dockerSocket = $conn->dockerSocket ?? null;

        if (! is_resource($dockerSocket)) {
            return;
        }

        if (str_starts_with($data, '{')) {
            $decoded = @json_decode($data, true);

            if (is_array($decoded) && ($decoded['type'] ?? '') === 'resize') {
                $execId = $conn->execId ?? null;

                if (is_string($execId) && $execId !== '') {
                    $cols = (int) ($decoded['cols'] ?? 80);
                    $rows = (int) ($decoded['rows'] ?? 24);
                    $this->execService->resizeExec($execId, $cols, $rows);
                }

                return;
            }
        }

        @fwrite($dockerSocket, $data);
    }

    private function onClose(TcpConnection $conn): void
    {
        $this->cleanupConnection($conn);
    }

    /**
     * @param  array<string, string>  $query
     */
    private function handleLogConnection(
        TcpConnection $conn,
        string $containerId,
        array $query,
        string $accessToken,
    ): void {
        $scope = (string) ($query['stack'] ?? 'global');

        if (! $this->tokenValidator->validate($accessToken, $scope)) {
            $conn->send(json_encode(['error' => 'Unauthorized.']));
            $conn->close();

            return;
        }

        $serviceId = isset($query['serviceId']) && $query['serviceId'] !== '' ? (string) $query['serviceId'] : null;

        $dockerQuery = [
            'follow' => ($query['follow'] ?? '1') === '1' ? 'true' : 'false',
            'stdout' => ($query['stdout'] ?? '1') === '1' ? 'true' : 'false',
            'stderr' => ($query['stderr'] ?? '1') === '1' ? 'true' : 'false',
            'timestamps' => ($query['timestamps'] ?? '1') === '1' ? 'true' : 'false',
        ];

        if (isset($query['tail'])) {
            $dockerQuery['tail'] = $query['tail'];
        }

        if (isset($query['since'])) {
            $dockerQuery['since'] = $query['since'];
        }

        $stream = null;

        try {
            $stream = $this->docker->openStreamSocket("/containers/{$containerId}/logs", $dockerQuery);
        } catch (DockerApiException $e) {
            if ($e->isNotFound() && $serviceId !== null) {
                try {
                    $stream = $this->docker->openStreamSocket("/services/{$serviceId}/logs", $dockerQuery);
                } catch (Throwable) {
                    $conn->send(json_encode(['error' => 'Failed to open log stream.']));
                    $conn->close();

                    return;
                }
            } else {
                $error = $e->isNotFound() ? 'Container not found.' : 'Failed to open log stream.';
                $conn->send(json_encode(['error' => $error]));
                $conn->close();

                return;
            }
        } catch (Throwable) {
            $conn->send(json_encode(['error' => 'Failed to open log stream.']));
            $conn->close();

            return;
        }

        $socket = $stream['socket'];
        $initialBuffer = $stream['buffer'];
        $isChunked = $stream['chunked'];

        $conn->dockerType = 'logs';
        $conn->dockerSocket = $socket;
        $conn->chunkedBuffer = '';

        if ($initialBuffer !== '') {
            $this->sendLogData($conn, $initialBuffer, $isChunked);
        }

        Worker::getEventLoop()->onReadable($socket, function ($socket) use ($conn, $isChunked): void {
            $data = @fread($socket, 8192);

            if ($data === false || $data === '' || feof($socket)) {
                Worker::getEventLoop()->offReadable($socket);
                @fclose($socket);
                $conn->dockerSocket = null;
                $conn->close();

                return;
            }

            $this->sendLogData($conn, $data, $isChunked);
        });

        $this->logger->info('Log WebSocket stream started.', [
            'container_id' => $containerId,
            'service_id' => $serviceId,
        ]);
    }

    /**
     * @param  array<string, string>  $query
     */
    private function handleExecConnection(
        TcpConnection $conn,
        string $containerId,
        array $query,
        string $accessToken,
    ): void {
        $scope = (string) ($query['stack'] ?? 'global');

        if (! $this->tokenValidator->validate($accessToken, $scope)) {
            $conn->send(json_encode(['error' => 'Unauthorized.']));
            $conn->close();

            return;
        }

        try {
            $this->docker->getJson("/containers/{$containerId}/json");
        } catch (DockerApiException $e) {
            $error = $e->isNotFound() ? 'Container not found.' : 'Docker unavailable.';
            $conn->send(json_encode(['error' => $error]));
            $conn->close();

            return;
        } catch (Throwable) {
            $conn->send(json_encode(['error' => 'Docker unavailable.']));
            $conn->close();

            return;
        }

        try {
            $execId = $this->execService->createExec($containerId);
            $dockerSocket = $this->execService->startExec($execId);
        } catch (Throwable $e) {
            $this->logger->error('Exec session creation failed.', [
                'container_id' => $containerId,
                'error' => $e->getMessage(),
            ]);
            $conn->send(json_encode(['error' => 'Failed to create exec session.']));
            $conn->close();

            return;
        }

        stream_set_blocking($dockerSocket, false);

        $conn->dockerType = 'exec';
        $conn->dockerSocket = $dockerSocket;
        $conn->execId = $execId;

        $conn->websocketType = Websocket::BINARY_TYPE_BLOB;
        $conn->send(json_encode(['type' => 'session', 'exec_id' => $execId]));

        Worker::getEventLoop()->onReadable($dockerSocket, function ($socket) use ($conn): void {
            $data = @fread($socket, 4096);

            if ($data === false || $data === '' || feof($socket)) {
                Worker::getEventLoop()->offReadable($socket);
                @fclose($socket);
                $conn->dockerSocket = null;
                $conn->close();

                return;
            }

            $conn->websocketType = Websocket::BINARY_TYPE_ARRAYBUFFER;
            $conn->send($data);
        });

        $this->logger->info('Exec WebSocket session started.', [
            'container_id' => $containerId,
            'exec_id' => $execId,
        ]);
    }

    private function sendLogData(TcpConnection $conn, string $data, bool $isChunked): void
    {
        if ($isChunked) {
            $buf = $conn->chunkedBuffer ?? '';
            [$decoded, $buf] = $this->docker->decodeChunkedStream($buf.$data);
            $conn->chunkedBuffer = $buf;

            if ($decoded !== '') {
                $conn->websocketType = Websocket::BINARY_TYPE_ARRAYBUFFER;
                $conn->send($decoded);
            }
        } else {
            $conn->websocketType = Websocket::BINARY_TYPE_ARRAYBUFFER;
            $conn->send($data);
        }
    }

    private function cleanupConnection(TcpConnection $conn): void
    {
        $dockerSocket = $conn->dockerSocket ?? null;

        if (is_resource($dockerSocket)) {
            Worker::getEventLoop()->offReadable($dockerSocket);
            @fclose($dockerSocket);
            $conn->dockerSocket = null;
        }

        $type = $conn->dockerType ?? null;

        if ($type !== null) {
            $this->logger->info('WebSocket connection closed.', ['type' => $type]);
        }
    }
}
