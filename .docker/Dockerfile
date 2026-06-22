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

# Create a placeholder src/ directory for the app's autoload classmap entry.
# Page and PageController are scaffolded by recipe-cms during composer install
# (into /app/app/src/) — we must NOT pre-create them here or SilverStripe's
# manifest will detect duplicates and fatal.
RUN mkdir -p /app/src

COPY app/_config/ /app/_config/
COPY app/templates/ /app/templates/
COPY app/phpunit.xml.dist /app/phpunit.xml.dist
COPY app/phpstan.neon.dist /app/phpstan.neon.dist

COPY Caddyfile /etc/caddy/Caddyfile
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["entrypoint.sh"]
