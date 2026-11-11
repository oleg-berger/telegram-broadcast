FROM php:8.4-cli-bookworm AS base
RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip libicu-dev libzip-dev \
    && docker-php-ext-install -j"$(nproc)" pcntl intl zip \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer/composer:2-bin /composer /usr/local/bin/composer
RUN test -s /usr/local/bin/composer && php /usr/local/bin/composer --version
WORKDIR /app
COPY composer.json composer.lock ./

FROM base AS test
RUN php /usr/local/bin/composer install --no-interaction --prefer-dist --no-progress \
    && test -s vendor/autoload.php && test -s vendor/bin/phpunit
COPY assets/ assets/
COPY src/ src/
COPY migrations/ migrations/
COPY bin/ bin/
COPY public/ public/
COPY tests/ tests/
COPY phpunit.xml ./
RUN php vendor/bin/phpunit

FROM base AS application
RUN php /usr/local/bin/composer install --no-dev --no-interaction --prefer-dist --no-progress --optimize-autoloader \
    && test -s vendor/autoload.php
COPY assets/ assets/
COPY src/ src/
COPY migrations/ migrations/
COPY bin/ bin/
COPY public/ public/
RUN groupadd --gid 1000 bot \
    && useradd --uid 1000 --gid bot --no-create-home bot \
    && mkdir -p /data \
    && chown bot:bot /data \
    && chmod 700 /data
USER bot
ENV DATABASE_PATH=/data/broadcast.sqlite
ENTRYPOINT ["php", "bin/console"]
CMD ["worker"]

# This process receives only download settings and a read-only data volume, never bot/mail secrets.
FROM php:8.4-fpm-bookworm AS download
WORKDIR /app
COPY --from=application /app/vendor/ vendor/
COPY src/ src/
COPY public/ public/
COPY deploy/attachments-fpm.conf /usr/local/etc/php-fpm.d/zz-attachments.conf
RUN groupadd --gid 1000 bot && useradd --uid 1000 --gid bot --no-create-home bot
USER bot
EXPOSE 9000
CMD ["php-fpm", "-F"]

FROM application AS production
