# The target of the security tests: the working copy, served like in production
# Build context is the repository root, see compose.yaml
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
COPY benchmark/php.ini /usr/local/etc/php/conf.d/zz-production.ini

WORKDIR /security
COPY composer.json lib/
COPY src lib/src
COPY security/target/server.php security/target/composer.json target/
RUN cd target && composer install --no-dev --classmap-authoritative --no-interaction --no-progress
WORKDIR /security/target
STOPSIGNAL SIGQUIT
CMD ["php", "server.php", "start"]
