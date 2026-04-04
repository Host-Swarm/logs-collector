<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth;

use App\Domain\Auth\Contracts\TokenValidator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Psr\Log\LoggerInterface;
use Throwable;

final class PassportTokenValidator implements TokenValidator
{
    public function __construct(
        private string $parentAppUrl,
        private string $verifyPath,
        private int $timeoutSeconds,
        private LoggerInterface $logger,
    ) {}

    public function validate(string $token, string $containerId): bool
    {
        $url = rtrim($this->parentAppUrl, '/').'/'.ltrim($this->verifyPath, '/');

        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->withToken($token)
                ->post($url, ['container_id' => $containerId]);

            if ($response->successful()) {
                return true;
            }

            $this->logger->info('Passport token validation rejected.', [
                'container_id' => $containerId,
                'status' => $response->status(),
            ]);

            return false;
        } catch (ConnectionException $exception) {
            $this->logger->warning('Passport token validation failed: parent app unreachable.', [
                'container_id' => $containerId,
                'error' => $exception->getMessage(),
            ]);

            return false;
        } catch (Throwable $exception) {
            $this->logger->warning('Passport token validation failed with unexpected error.', [
                'container_id' => $containerId,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }
}
