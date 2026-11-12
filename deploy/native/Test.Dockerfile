# Test harness only; the installer never requires Docker on the server.
FROM golang:1.26.10-alpine@sha256:a2012b4a1d1f9f7e9f44e0d39b805a901e856b1dd80b4e6ee4338db547050308 AS transport
RUN apk add --no-cache python3 patch
COPY deploy/native/build_transport.py /input/deploy/native/build_transport.py
COPY tools/whatsapp-spike/ /input/tools/whatsapp-spike/
RUN --mount=type=cache,target=/build/go-cache --mount=type=cache,target=/build/go-path python3 /input/deploy/native/build_transport.py /input /build

FROM php:8.4-fpm-bookworm AS checks
RUN apt-get update && apt-get install -y --no-install-recommends python3 systemd nginx acl qrencode openssl libicu-dev libzip-dev && docker-php-ext-install intl zip && rm -rf /var/lib/apt/lists/*
COPY --from=telegram-broadcast:merge-test /app/vendor /app/vendor
COPY --from=transport /build/bin /opt/telegram-broadcast-transport
COPY src /app/src
COPY migrations /app/migrations
COPY bin /app/bin
COPY public /app/public
COPY deploy /app/deploy
COPY tests/installer /app/tests/installer
RUN ln -s /usr/local/bin/php /usr/bin/php8.4 && ln -s /usr/local/sbin/php-fpm /usr/sbin/php-fpm8.4
WORKDIR /app
RUN python3 -m unittest discover -s tests/installer -v
RUN python3 tests/installer/verify_linux.py
