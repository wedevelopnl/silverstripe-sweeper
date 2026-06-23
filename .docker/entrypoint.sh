#!/bin/sh
set -e

composer install --no-interaction

# FrankenPHP ships a default phpinfo() index.php. Replace it with the SilverStripe
# bootstrap once composer install makes the recipe available.
cp -f vendor/silverstripe/recipe-core/public/index.php /app/public/index.php

vendor/bin/sake dev/build flush=1

touch /tmp/.app-ready

exec frankenphp run --config /etc/caddy/Caddyfile
