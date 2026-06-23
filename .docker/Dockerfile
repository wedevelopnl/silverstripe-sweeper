ARG PHP_VERSION=8.3
FROM dunglas/frankenphp:php${PHP_VERSION}

RUN install-php-extensions \
    intl \
    gd \
    mysqli \
    pdo_mysql \
    zip \
    bcmath \
    exif \
    pcov

RUN echo 'pcov.directory=/module/src' > /usr/local/etc/php/conf.d/99-pcov.ini

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

ARG COMPOSER_FILE=app/composer.json
COPY ${COMPOSER_FILE} /app/composer.json

# SilverStripe requires Page and PageController classes in the project. They are
# generated here (not stored in the repo) and the recipe's project-files-installed
# list (app/composer.json) stops recipe-plugin from scaffolding duplicates into
# /app/app. SilverStripe discovers modules by looking for _config/ or _config.php;
# /app/_config makes /app the project module, so /app/src is scanned for these.
RUN mkdir -p /app/src /app/_config \
    && printf '<?php\n\nuse SilverStripe\\CMS\\Model\\SiteTree;\n\nclass Page extends SiteTree\n{\n}\n' > /app/src/Page.php \
    && printf '<?php\n\nuse SilverStripe\\CMS\\Controllers\\ContentController;\n\nclass PageController extends ContentController\n{\n}\n' > /app/src/PageController.php

COPY app/_config/ /app/_config/
COPY app/templates/ /app/templates/
COPY app/phpunit.xml.dist /app/phpunit.xml.dist
COPY app/phpstan.neon.dist /app/phpstan.neon.dist

COPY Caddyfile /etc/caddy/Caddyfile
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["entrypoint.sh"]
