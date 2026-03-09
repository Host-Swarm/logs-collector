<?php

namespace App\Providers;

use App\Domain\Logs\Contracts\LogBroadcaster;
use App\Domain\Logs\Contracts\LogStreamService;
use App\Domain\Logs\Services\LogNormalizerService;
use App\Domain\Logs\Services\LogObserverService;
use App\Domain\Metrics\Contracts\SystemMetricsProvider;
use App\Infrastructure\Docker\DockerHttpClient;
use App\Infrastructure\Docker\DockerLogStreamService;
use App\Infrastructure\System\LinuxProcMetricsProvider;
use App\Infrastructure\WebSocket\ServerManagerSocketBroadcaster;
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

        $this->app->bind(LogStreamService::class, DockerLogStreamService::class);

        $this->app->singleton(LogBroadcaster::class, function (): LogBroadcaster {
            return new ServerManagerSocketBroadcaster(
                endpoint: (string) config('logs_collector.upstream.socket_endpoint'),
                token: config('logs_collector.upstream.token'),
                connectTimeout: (int) config('logs_collector.upstream.connect_timeout'),
                timeout: (int) config('logs_collector.upstream.timeout'),
                logger: $this->app->make(LoggerInterface::class),
            );
        });

        $this->app->bind(LogObserverService::class, function (): LogObserverService {
            $environment = (string) config('app.env');

            return new LogObserverService(
                streamService: $this->app->make(LogStreamService::class),
                normalizer: $this->app->make(LogNormalizerService::class),
                broadcaster: $this->app->make(LogBroadcaster::class),
                logger: $this->app->make(LoggerInterface::class),
                logDevelopmentFormat: in_array($environment, ['local', 'development'], true),
            );
        });

        $this->app->bind(SystemMetricsProvider::class, LinuxProcMetricsProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
