<?php

declare(strict_types=1);

namespace App\Infrastructure\Docker;

class DockerHttpClient
{
    public function __construct(
        private string $socketPath,
        private int $timeout,
        private int $connectTimeout,
        private int $streamTimeout,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function getJson(string $path, array $query = []): array
    {
        $response = $this->request('GET', $path, $query);

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new DockerApiException(
                sprintf('Docker API request failed with status %d.', $response['status']),
                $response['status'],
            );
        }

        $data = json_decode($response['body'], true);

        if (! is_array($data)) {
            throw new DockerApiException('Docker API response was not valid JSON.');
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function postJson(string $path, array $body = []): array
    {
        $response = $this->requestWithBody('POST', $path, $body);

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new DockerApiException(
                sprintf('Docker API POST failed with status %d.', $response['status']),
                $response['status'],
            );
        }

        if ($response['body'] === '' || $response['body'] === 'null') {
            return [];
        }

        $data = json_decode($response['body'], true);

        if (! is_array($data)) {
            throw new DockerApiException('Docker API POST response was not valid JSON.');
        }

        return $data;
    }

    /**
     * Opens a raw hijacked stream to Docker (used for exec start).
     * Returns the open socket after consuming the 101 response headers.
     *
     * @param  array<string, mixed>  $body
     * @return resource
     */
    public function openHijackedStream(string $path, array $body = [])
    {
        $jsonBody = json_encode($body, JSON_THROW_ON_ERROR);
        $bodyLength = strlen($jsonBody);

        $request = sprintf(
            "POST %s HTTP/1.1\r\nHost: localhost\r\nUser-Agent: host-swarm-agent\r\nContent-Type: application/json\r\nContent-Length: %d\r\nUpgrade: tcp\r\nConnection: Upgrade\r\n\r\n%s",
            $path,
            $bodyLength,
            $jsonBody,
        );

        $socket = $this->openSocket();
        fwrite($socket, $request);

        [$status] = $this->readHeaders($socket);

        // Docker responds with 101 Switching Protocols for hijacked exec streams.
        // 200 is also acceptable for non-TTY attach.
        if ($status !== 101 && $status !== 200) {
            fclose($socket);
            throw new DockerApiException(
                sprintf('Docker exec start failed with status %d.', $status),
                $status,
            );
        }

        // Switch to the stream timeout (0 = infinite) now that the handshake
        // is complete and we need to keep the socket open for the exec session.
        stream_set_timeout($socket, $this->streamTimeout);

        return $socket;
    }

    /**
     * @param  callable(string $chunk): void  $onChunk
     */
    public function stream(string $path, array $query, callable $onChunk): void
    {
        $socket = $this->openSocket();
        $request = $this->buildRequest('GET', $path, $query);
        fwrite($socket, $request);

        [$status, $headers, $buffer] = $this->readHeaders($socket);

        if ($status < 200 || $status >= 300) {
            fclose($socket);
            throw new DockerApiException(
                sprintf('Docker stream request failed with status %d.', $status),
                $status,
            );
        }

        // A streamTimeout of 0 means infinite — always override the general
        // socket timeout set in openSocket() so long-running follow streams
        // are never killed by a read timeout.
        stream_set_timeout($socket, $this->streamTimeout);

        $isChunked = isset($headers['transfer-encoding']) && $headers['transfer-encoding'] === 'chunked';
        $chunkedBuffer = '';

        if ($buffer !== '') {
            if ($isChunked) {
                [$decoded, $chunkedBuffer] = $this->decodeChunkedStream($chunkedBuffer.$buffer);
                if ($decoded !== '') {
                    $onChunk($decoded);
                }
            } else {
                $onChunk($buffer);
            }
        }

        while (true) {
            if (feof($socket)) {
                break;
            }

            $chunk = fread($socket, 8192);

            if ($chunk === '' || $chunk === false) {
                $meta = stream_get_meta_data($socket);

                if ($meta['timed_out']) {
                    // Transient read timeout — keep waiting for more data.
                    continue;
                }

                // Real EOF or unrecoverable error.
                break;
            }

            if ($isChunked) {
                [$decoded, $chunkedBuffer] = $this->decodeChunkedStream($chunkedBuffer.$chunk);
                if ($decoded !== '') {
                    $onChunk($decoded);
                }
            } else {
                $onChunk($chunk);
            }
        }

        fclose($socket);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function requestWithBody(string $method, string $path, array $body = []): array
    {
        $jsonBody = json_encode($body, JSON_THROW_ON_ERROR);
        $bodyLength = strlen($jsonBody);

        $socket = $this->openSocket();
        $request = sprintf(
            "%s %s HTTP/1.1\r\nHost: localhost\r\nUser-Agent: host-swarm-agent\r\nContent-Type: application/json\r\nContent-Length: %d\r\nConnection: close\r\n\r\n%s",
            $method,
            $path,
            $bodyLength,
            $jsonBody,
        );

        fwrite($socket, $request);

        [$status, $headers, $buffer] = $this->readHeaders($socket);
        $responseBody = $buffer;

        while (! feof($socket)) {
            $chunk = fread($socket, 8192);

            if ($chunk === '' || $chunk === false) {
                continue;
            }

            $responseBody .= $chunk;
        }

        fclose($socket);

        if (isset($headers['transfer-encoding']) && $headers['transfer-encoding'] === 'chunked') {
            $responseBody = $this->decodeChunked($responseBody);
        }

        return [
            'status' => $status,
            'headers' => $headers,
            'body' => $responseBody,
        ];
    }

    /**
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function request(string $method, string $path, array $query = []): array
    {
        $socket = $this->openSocket();
        $request = $this->buildRequest($method, $path, $query);
        fwrite($socket, $request);

        [$status, $headers, $buffer] = $this->readHeaders($socket);
        $body = $buffer;

        while (! feof($socket)) {
            $chunk = fread($socket, 8192);

            if ($chunk === '' || $chunk === false) {
                continue;
            }

            $body .= $chunk;
        }

        fclose($socket);

        if (isset($headers['transfer-encoding']) && $headers['transfer-encoding'] === 'chunked') {
            $body = $this->decodeChunked($body);
        }

        return [
            'status' => $status,
            'headers' => $headers,
            'body' => $body,
        ];
    }

    /**
     * @return resource
     */
    private function openSocket()
    {
        $socket = @stream_socket_client(
            'unix://'.$this->socketPath,
            $errno,
            $errstr,
            $this->connectTimeout,
        );

        if (! is_resource($socket)) {
            throw new DockerApiException(
                sprintf('Unable to connect to Docker socket: %s', $errstr),
                statusCode: 0,
            );
        }

        stream_set_timeout($socket, $this->timeout);

        return $socket;
    }

    private function buildRequest(string $method, string $path, array $query = []): string
    {
        $queryString = $query === [] ? '' : '?'.http_build_query($query);

        return sprintf(
            "%s %s%s HTTP/1.1\r\nHost: localhost\r\nUser-Agent: host-swarm-agent\r\nConnection: close\r\n\r\n",
            $method,
            $path,
            $queryString,
        );
    }

    /**
     * @param  resource  $socket
     * @return array{0: int, 1: array<string, string>, 2: string}
     */
    private function readHeaders($socket): array
    {
        $buffer = '';

        while (! str_contains($buffer, "\r\n\r\n")) {
            $chunk = fread($socket, 1024);

            if ($chunk === '' || $chunk === false) {
                break;
            }

            $buffer .= $chunk;
        }

        [$headerBlock, $rest] = explode("\r\n\r\n", $buffer, 2) + [1 => ''];
        $lines = explode("\r\n", $headerBlock);
        $statusLine = array_shift($lines) ?: 'HTTP/1.1 500';
        $statusParts = explode(' ', $statusLine);
        $status = isset($statusParts[1]) ? (int) $statusParts[1] : 500;

        $headers = [];
        foreach ($lines as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }

        return [$status, $headers, $rest];
    }

    private function decodeChunked(string $payload): string
    {
        $decoded = '';

        while ($payload !== '') {
            $pos = strpos($payload, "\r\n");

            if ($pos === false) {
                break;
            }

            $lengthHex = substr($payload, 0, $pos);
            $length = hexdec(trim($lengthHex));

            if ($length === 0) {
                break;
            }

            $payload = substr($payload, $pos + 2);

            if (strlen($payload) < $length + 2) {
                break;
            }

            $decoded .= substr($payload, 0, $length);
            $payload = substr($payload, $length + 2);
        }

        return $decoded;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function decodeChunkedStream(string $payload): array
    {
        $decoded = '';

        while (true) {
            $pos = strpos($payload, "\r\n");

            if ($pos === false) {
                break;
            }

            $lengthHex = substr($payload, 0, $pos);
            $length = hexdec(trim($lengthHex));
            $payload = substr($payload, $pos + 2);

            if ($length === 0) {
                $payload = '';
                break;
            }

            if (strlen($payload) < $length + 2) {
                $payload = $lengthHex."\r\n".$payload;
                break;
            }

            $decoded .= substr($payload, 0, $length);
            $payload = substr($payload, $length + 2);
        }

        return [$decoded, $payload];
    }
}
