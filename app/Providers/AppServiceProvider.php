<?php

namespace App\Providers;

use App\Domain\Logs\Contracts\LogBroadcaster;
use App\Domain\Logs\Contracts\LogStreamService;
use App\Domain\Logs\Services\LogNormalizerService;
use App\Domain\Logs\Services\LogObserverService;
use App\Domain\Metrics\Contracts\SystemMetricsProvider;
use App\Domain\Metrics\Services\HostMetricsService;
use App\Infrastructure\Broadcasting\PusherLogBroadcaster;
use App\Infrastructure\Docker\DockerHttpClient;
use App\Infrastructure\Docker\DockerLogStreamService;
use App\Infrastructure\System\LinuxProcMetricsProvider;
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
            return new PusherLogBroadcaster(
                channel: (string) config('logs_collector.pusher.channel'),
                event: config('logs_collector.pusher.event'),
                logger: $this->app->make(LoggerInterface::class),
                logSocketErrors: (bool) config('logs_collector.upstream.log_socket_errors', false),
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
                logSocketErrors: (bool) config('logs_collector.upstream.log_socket_errors', false),
                logPayloads: (bool) config('logs_collector.log_payloads', false),
            );
        });

        $this->app->bind(SystemMetricsProvider::class, LinuxProcMetricsProvider::class);

        $this->app->bind(HostMetricsService::class, function (): HostMetricsService {
            return new HostMetricsService(
                provider: $this->app->make(SystemMetricsProvider::class),
                broadcaster: $this->app->make(LogBroadcaster::class),
                logger: $this->app->make(LoggerInterface::class),
                logSocketErrors: (bool) config('logs_collector.upstream.log_socket_errors', false),
                logPayloads: (bool) config('logs_collector.log_payloads', false),
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
