FROM php:8.4-cli-bookworm AS base
RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip libicu-dev libzip-dev \
    && docker-php-ext-install -j"$(nproc)" pcntl intl zip \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /app
COPY composer.json composer.lock ./

FROM base AS test
RUN composer install --no-interaction --prefer-dist --no-progress
COPY assets/ assets/
COPY src/ src/
COPY migrations/ migrations/
COPY bin/ bin/
COPY tests/ tests/
COPY phpunit.xml ./
RUN composer test

FROM base AS production
RUN composer install --no-dev --no-interaction --prefer-dist --no-progress --optimize-autoloader
COPY assets/ assets/
COPY src/ src/
COPY migrations/ migrations/
COPY bin/ bin/
RUN groupadd --gid 1000 bot \
    && useradd --uid 1000 --gid bot --no-create-home bot \
    && mkdir -p /data \
    && chown bot:bot /data \
    && chmod 700 /data
USER bot
ENV DATABASE_PATH=/data/broadcast.sqlite
ENTRYPOINT ["php", "bin/console"]
CMD ["worker"]
