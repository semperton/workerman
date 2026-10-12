# Build context is the repository root
FROM ghcr.io/roadrunner-server/roadrunner:2025.1.15 AS roadrunner
FROM composer:2.10.1 AS composer

FROM php:8.4.26-cli
RUN apt-get update && apt-get install -y --no-install-recommends unzip && rm -rf /var/lib/apt/lists/* \
    # roadrunner workers talk to the server over pipes, spiral/roadrunner-worker requires sockets anyway
    && docker-php-ext-install sockets \
    && docker-php-ext-enable opcache
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY --from=roadrunner /usr/bin/rr /usr/local/bin/rr
COPY benchmark/php.ini /usr/local/etc/php/conf.d/zz-bench.ini

WORKDIR /bench
COPY benchmark/app app
COPY benchmark/servers/roadrunner server
RUN cd server && composer install --no-dev --classmap-authoritative --no-interaction --no-progress
WORKDIR /bench/server
CMD ["rr", "serve", "-c", ".rr.yaml"]
