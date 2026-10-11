FROM composer:2.10.1 AS composer

FROM php:8.4.26-fpm
RUN apt-get update && apt-get install -y --no-install-recommends unzip && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-enable opcache
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY benchmark/php.ini /usr/local/etc/php/conf.d/zz-bench.ini
COPY benchmark/servers/nginx-fpm/www.conf /usr/local/etc/php-fpm.d/zz-www.conf

WORKDIR /bench
COPY benchmark/app app
COPY benchmark/servers/nginx-fpm server
RUN cd server && composer install --no-dev --classmap-authoritative --no-interaction --no-progress
