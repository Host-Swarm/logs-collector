<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth;

use App\Domain\Auth\Contracts\ScopedTokenValidator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Psr\Log\LoggerInterface;
use Throwable;

final class AccessTokenValidator implements ScopedTokenValidator
{
    public function __construct(
        private string $serverUrl,
        private int $timeoutSeconds,
        private LoggerInterface $logger,
    ) {}

    public function validate(string $token, string $scope): bool
    {
        $url = rtrim($this->serverUrl, '/').'/api/logs/validate-token';

        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->post($url, [
                    'token' => $token,
                    'scope' => $scope,
                ]);

            if ($response->successful()) {
                return true;
            }

            $this->logger->info('Access token validation rejected.', [
                'scope' => $scope,
                'status' => $response->status(),
            ]);

            return false;
        } catch (ConnectionException $exception) {
            $this->logger->warning('Access token validation failed: server unreachable.', [
                'scope' => $scope,
                'error' => $exception->getMessage(),
            ]);

            return false;
        } catch (Throwable $exception) {
            $this->logger->warning('Access token validation failed with unexpected error.', [
                'scope' => $scope,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }
}
