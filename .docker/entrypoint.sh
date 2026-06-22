#!/bin/sh
set -e

composer install --no-interaction

# recipe-cms scaffolds app/src/Page.php and app/_config/ into /app/app/ (relative
# to the project root /app). SilverStripe's module manifest then detects /app/app
# as a second module alongside the project root /app and fatals. Fix: move the
# scaffold sources up one level then remove the nested app/ dir.
if [ -d /app/app ]; then
    cp -rf /app/app/src/. /app/src/ 2>/dev/null || true
    rm -rf /app/app
fi

# FrankenPHP ships a default phpinfo() index.php. Replace it with the SilverStripe
# bootstrap once composer install makes the recipe available.
cp -f vendor/silverstripe/recipe-core/public/index.php /app/public/index.php

vendor/bin/sake dev/build flush=1

touch /tmp/.app-ready

exec frankenphp run --config /etc/caddy/Caddyfile
