FROM composer:2.10.1 AS composer

FROM phpswoole/swoole:6.2.3-php8.4
RUN apt-get update && apt-get install -y --no-install-recommends unzip && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-enable opcache
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY benchmark/php.ini /usr/local/etc/php/conf.d/zz-bench.ini

WORKDIR /bench
COPY benchmark/app app
COPY benchmark/servers/swoole server
RUN cd server && composer install --no-dev --classmap-authoritative --no-interaction --no-progress
WORKDIR /bench/server
