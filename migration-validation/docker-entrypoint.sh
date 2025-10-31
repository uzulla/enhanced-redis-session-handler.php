#!/bin/bash
set -e

if [ ! -f /app/vendor/autoload.php ]; then
  echo "Running composer install..."
  cd /app && composer install --no-interaction --no-progress --prefer-dist
fi

exec "$@"
