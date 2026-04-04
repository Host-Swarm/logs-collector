<?php

declare(strict_types=1);

return [
    'server_secret' => env('SERVER_SECRET'),
    'swarm_key' => env('LOG_COLLECTOR_SWARM_KEY', 'main-swarm'),
    'docker' => [
        'socket_path' => env('DOCKER_SOCKET_PATH', '/var/run/docker.sock'),
        'timeout' => (int) env('DOCKER_TIMEOUT', 10),
        'connect_timeout' => (int) env('DOCKER_CONNECT_TIMEOUT', 5),
        'stream_timeout' => (int) env('DOCKER_STREAM_TIMEOUT', 0),
    ],
    'parent_app' => [
        'url' => env('PARENT_APP_URL'),
        'token_verify_path' => env('PARENT_APP_TOKEN_VERIFY_PATH', '/api/token/verify'),
        'timeout' => (int) env('PARENT_APP_TIMEOUT', 5),
    ],
];
