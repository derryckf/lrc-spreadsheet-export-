#!/bin/bash
# Run PHPUnit tests against the real MySQL database.
# Start:  .docker/mysql/start.sh
# Reload: scripts/reload-db.sh

set -e
cd "$(dirname "$0")"

# Load .env if present
if [ -f .env ]; then
    set -a
    source .env
    set +a
fi

: "${DB_HOST:=127.0.0.1}"
: "${DB_PORT:=3307}"
: "${DB_DATABASE:=lacsite_deploy}"
: "${DB_USERNAME:=lrcuser}"
: "${DB_PASSWORD:=lrcpassword}"

export DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD

echo "Running tests against MySQL: $DB_USERNAME@$DB_HOST:$DB_PORT/$DB_DATABASE"
echo ""

cd /home/simon/Desktop/scratch/lrc-spreadsheet-export

DB_HOST="$DB_HOST" \
DB_PORT="$DB_PORT" \
DB_DATABASE="$DB_DATABASE" \
DB_USERNAME="$DB_USERNAME" \
DB_PASSWORD="$DB_PASSWORD" \
php /tmp/phpunit-10.5.phar \
    --bootstrap tests/bootstrap.php \
    --testsuite Unit \
    "$@"

EXIT=$?

if [ $EXIT -eq 0 ]; then
    echo ""
    echo "✅ All tests passed against real DB"
else
    echo ""
    echo "❌ Tests failed (exit $EXIT)"
fi

exit $EXIT