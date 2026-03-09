<?php

declare(strict_types=1);

namespace App\Infrastructure\WebSocket;

use RuntimeException;

final class WebSocketClient
{
    /** @var resource|null */
    private $socket = null;

    private bool $connected = false;

    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        private string $endpoint,
        private int $connectTimeout,
        private int $timeout,
        private array $headers = [],
    ) {}

    public function send(string $payload): void
    {
        if (! $this->connected) {
            $this->connect();
        }

        $frame = $this->encodeFrame($payload);
        $written = fwrite($this->socket, $frame);

        if ($written === false) {
            $this->connected = false;
            throw new RuntimeException('WebSocket write failed.');
        }
    }

    public function close(): void
    {
        if ($this->socket !== null) {
            fclose($this->socket);
        }

        $this->socket = null;
        $this->connected = false;
    }

    private function connect(): void
    {
        $parts = parse_url($this->endpoint);

        if (! is_array($parts) || ! isset($parts['host'])) {
            throw new RuntimeException('WebSocket endpoint is invalid.');
        }

        $scheme = $parts['scheme'] ?? 'ws';
        $host = $parts['host'];
        $port = $parts['port'] ?? ($scheme === 'wss' ? 443 : 80);
        $path = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');

        $transport = $scheme === 'wss' ? 'ssl' : 'tcp';
        $socket = @stream_socket_client(
            sprintf('%s://%s:%d', $transport, $host, $port),
            $errno,
            $errstr,
            $this->connectTimeout,
        );

        if (! is_resource($socket)) {
            throw new RuntimeException(sprintf('Unable to connect to websocket: %s', $errstr));
        }

        stream_set_timeout($socket, $this->timeout);

        $key = base64_encode(random_bytes(16));
        $headers = [
            sprintf('GET %s HTTP/1.1', $path),
            sprintf('Host: %s:%d', $host, $port),
            'Upgrade: websocket',
            'Connection: Upgrade',
            'Sec-WebSocket-Key: '.$key,
            'Sec-WebSocket-Version: 13',
        ];

        foreach ($this->headers as $name => $value) {
            $headers[] = $name.': '.$value;
        }

        $headers[] = "\r\n";
        fwrite($socket, implode("\r\n", $headers));

        $response = '';
        while (! str_contains($response, "\r\n\r\n")) {
            $chunk = fread($socket, 1024);

            if ($chunk === '' || $chunk === false) {
                break;
            }

            $response .= $chunk;
        }

        if (! str_contains($response, ' 101 ')) {
            fclose($socket);
            throw new RuntimeException('WebSocket handshake failed.');
        }

        $this->socket = $socket;
        $this->connected = true;
    }

    private function encodeFrame(string $payload): string
    {
        $length = strlen($payload);
        $frame = chr(0x81);

        if ($length <= 125) {
            $frame .= chr(0x80 | $length);
        } elseif ($length <= 65535) {
            $frame .= chr(0x80 | 126).pack('n', $length);
        } else {
            $frame .= chr(0x80 | 127).pack('NN', 0, $length);
        }

        $mask = random_bytes(4);
        $frame .= $mask;

        $masked = '';
        for ($i = 0; $i < $length; $i += 1) {
            $masked .= $payload[$i] ^ $mask[$i % 4];
        }

        return $frame.$masked;
    }
}
