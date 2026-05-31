#!/bin/bash
# Load latest SQL backup into lrc-spreadsheet-mysql container
# Usage: ./scripts/reload-db.sh

set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
BACKUP_DIR="$HOME/Documents/records.launcestonrunningclub.com.au/bck"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

# Find latest backup (newest lacsite_deploy_*.sql, not .gz)
LATEST_SQL=$(ls -t "$BACKUP_DIR"/lacsite_deploy_*.sql 2>/dev/null | head -1)

if [ -z "$LATEST_SQL" ]; then
    echo "ERROR: No lacsite_deploy SQL backup found in $BACKUP_DIR"
    exit 1
fi

echo "Latest backup: $LATEST_SQL"
BACKUP_DATE=$(stat -c %y "$LATEST_SQL" 2>/dev/null | cut -d' ' -f1 || stat -f "%Sm" -t "%Y-%m-%d" "$LATEST_SQL" 2>/dev/null)
echo "Backup file date: $BACKUP_DATE"

# Check if container exists
if ! docker ps -a --format '{{.Names}}' | grep -q '^lrc-spreadsheet-mysql$'; then
    echo "Container not found — creating..."
    docker create \
        --name lrc-spreadsheet-mysql \
        -e MYSQL_ROOT_PASSWORD=rootpassword \
        -e MYSQL_DATABASE=lacsite_deploy \
        -e MYSQL_USER=lrcuser \
        -e MYSQL_PASSWORD=lrcpassword \
        -p 3307:3306 \
        mysql:8.0
fi

# Start container if not running
if ! docker ps --format '{{.Names}}' | grep -q '^lrc-spreadsheet-mysql$'; then
    echo "Starting container..."
    docker start lrc-spreadsheet-mysql
fi

# Wait for MySQL to be ready
echo "Waiting for MySQL to be ready..."
until docker exec lrc-spreadsheet-mysql mysqladmin ping -h localhost -uroot -prootpassword --silent 2>/dev/null; do
    sleep 2
    echo -n "."
done
echo ""
echo "MySQL ready."

# Drop and recreate database
echo "Resetting lacsite_deploy database..."
docker exec -i lrc-spreadsheet-mysql mysql -uroot -prootpassword <<EOSQL
DROP DATABASE IF EXISTS lacsite_deploy;
CREATE DATABASE lacsite_deploy CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
EOSQL
echo "Database reset."

# Load backup
echo "Loading backup: $LATEST_SQL"
gunzip -c "$LATEST_SQL" 2>/dev/null | docker exec -i lrc-spreadsheet-mysql mysql -uroot -prootpassword lacsite_deploy \
    || cat "$LATEST_SQL" | docker exec -i lrc-spreadsheet-mysql mysql -uroot -prootpassword lacsite_deploy

# Verify
echo ""
ROWS=$(docker exec lrc-spreadsheet-mysql mysql -uroot -prootpassword lacsite_deploy -N -e "SELECT COUNT(*) FROM eventEntry;" 2>/dev/null)
echo "eventEntry rows loaded: $ROWS"

echo ""
echo "✅ Database loaded successfully"
echo "Connect: mysql -ulrcuser -plrcpassword -h 127.0.0.1 -P 3307 lacsite_deploy"