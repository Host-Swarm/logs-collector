#!/bin/sh
set -e

# Start the heartbeat agent in the background.
# It will call /api/agent/connect on start and /api/agent/disconnect on SIGTERM.
php /var/www/html/artisan agent:heartbeat &
HEARTBEAT_PID=$!

# Forward SIGTERM/SIGINT to the heartbeat process so it can disconnect gracefully.
trap 'kill -TERM $HEARTBEAT_PID 2>/dev/null; wait $HEARTBEAT_PID 2>/dev/null; exit 0' TERM INT

# Start the web server as the main foreground process.
exec frankenphp php-server --listen :8080 --root /var/www/html/public
