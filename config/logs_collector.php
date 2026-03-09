<?php

declare(strict_types=1);

return [
    'swarm_key' => env('LOG_COLLECTOR_SWARM_KEY', 'main-swarm'),
    'queue' => env('LOG_COLLECTOR_QUEUE', 'default'),
    'retry_until_minutes' => (int) env('LOG_COLLECTOR_RETRY_UNTIL_MINUTES', 15),
    'discovery_interval' => (int) env('LOG_COLLECTOR_DISCOVERY_INTERVAL', 30),
    'docker' => [
        'socket_path' => env('DOCKER_SOCKET_PATH', '/var/run/docker.sock'),
        'timeout' => (int) env('DOCKER_TIMEOUT', 10),
        'connect_timeout' => (int) env('DOCKER_CONNECT_TIMEOUT', 5),
        'stream_timeout' => (int) env('DOCKER_STREAM_TIMEOUT', 0),
    ],
    'upstream' => [
        'log_socket_errors' => (bool) env('LOG_COLLECTOR_LOG_SOCKET_ERRORS', false),
    ],
    'pusher' => [
        'channel' => env('LOG_COLLECTOR_PUSHER_CHANNEL', 'swarm-logs'),
        'event' => env('LOG_COLLECTOR_PUSHER_EVENT'),
    ],
    'log_payloads' => (bool) env('LOG_COLLECTOR_LOG_PAYLOADS', false),
    'metrics' => [
        'interval' => (int) env('LOG_COLLECTOR_METRICS_INTERVAL', 60),
    ],
];
