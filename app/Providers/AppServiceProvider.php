<?php

namespace App\Providers;

use App\Domain\Auth\Contracts\ScopedTokenValidator;
use App\Domain\Auth\Contracts\TokenValidator;
use App\Domain\Docker\Contracts\ExecService;
use App\Domain\Docker\Services\StackService;
use App\Domain\Docker\Services\SwarmDiscoveryService;
use App\Infrastructure\Auth\AccessTokenValidator;
use App\Infrastructure\Auth\PassportTokenValidator;
use App\Infrastructure\Docker\DockerExecService;
use App\Infrastructure\Docker\DockerHttpClient;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(DockerHttpClient::class, function (): DockerHttpClient {
            return new DockerHttpClient(
                socketPath: (string) config('logs_collector.docker.socket_path'),
                timeout: (int) config('logs_collector.docker.timeout'),
                connectTimeout: (int) config('logs_collector.docker.connect_timeout'),
                streamTimeout: (int) config('logs_collector.docker.stream_timeout'),
            );
        });

        $this->app->singleton(SwarmDiscoveryService::class, function (): SwarmDiscoveryService {
            return new SwarmDiscoveryService(
                docker: $this->app->make(DockerHttpClient::class),
                logger: $this->app->make(LoggerInterface::class),
            );
        });

        $this->app->singleton(StackService::class, function (): StackService {
            return new StackService(
                discovery: $this->app->make(SwarmDiscoveryService::class),
            );
        });

        $this->app->singleton(ExecService::class, function (): ExecService {
            return new DockerExecService(
                docker: $this->app->make(DockerHttpClient::class),
                logger: $this->app->make(LoggerInterface::class),
            );
        });

        $this->app->bind(TokenValidator::class, function (): TokenValidator {
            return new PassportTokenValidator(
                parentAppUrl: (string) config('logs_collector.parent_app.url'),
                verifyPath: (string) config('logs_collector.parent_app.token_verify_path'),
                timeoutSeconds: (int) config('logs_collector.parent_app.timeout'),
                logger: $this->app->make(LoggerInterface::class),
            );
        });

        $this->app->bind(ScopedTokenValidator::class, function (): ScopedTokenValidator {
            return new AccessTokenValidator(
                serverUrl: (string) config('logs_collector.server_url'),
                timeoutSeconds: (int) config('logs_collector.parent_app.timeout'),
                logger: $this->app->make(LoggerInterface::class),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
