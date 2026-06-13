#!/bin/bash
# Start local MySQL for testing against real data

set -e
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"

echo "Starting lrc-spreadsheet MySQL..."
docker compose -f "$SCRIPT_DIR/docker-compose.yml" up -d

echo "Waiting for MySQL to be ready..."
until docker exec lrc-spreadsheet-mysql mysqladmin ping -h localhost -ulrcuser -plrcpassword --silent 2>/dev/null; do
    sleep 2
    echo -n "."
done
echo ""
echo "MySQL ready on localhost:3307"
echo ""
echo "Connect:    mysql -ulrcuser -plrcpassword -h 127.0.0.1 -P 3307 lacsite_deploy"
echo "Stop:       docker compose -f '$SCRIPT_DIR/docker-compose.yml' down"
echo "Reload DB:  $PROJECT_ROOT/scripts/reload-db.sh"