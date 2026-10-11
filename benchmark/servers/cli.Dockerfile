# Long-running CLI servers: semperton, workerman, reactphp, roadrunner
# Build context is the repository root, so semperton is installed from the working copy
FROM ghcr.io/roadrunner-server/roadrunner:2025.1.15 AS roadrunner
FROM composer:2.10.1 AS composer

FROM php:8.4.26-cli
RUN apt-get update \
    && apt-get install -y --no-install-recommends libevent-dev libssl-dev unzip \
    && docker-php-ext-install pcntl posix sockets \
    && pecl install event-3.1.6 \
    # loaded after sockets
    && docker-php-ext-enable --ini-name zz-event.ini event \
    && docker-php-ext-enable opcache \
    && rm -rf /var/lib/apt/lists/* /tmp/pear
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY --from=roadrunner /usr/bin/rr /usr/local/bin/rr
COPY benchmark/php.ini /usr/local/etc/php/conf.d/zz-bench.ini

ARG SERVER
WORKDIR /bench
COPY composer.json lib/
COPY src lib/src
COPY benchmark/app app
COPY benchmark/servers/${SERVER} server
RUN cd server && composer install --no-dev --classmap-authoritative --no-interaction --no-progress
WORKDIR /bench/server
