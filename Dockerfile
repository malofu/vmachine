FROM php:8.3-cli-alpine

# Composer (copied from the official image)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN apk add --no-cache git unzip

WORKDIR /app

# Sensible defaults for a CLI dev container
ENV COMPOSER_ALLOW_SUPERUSER=1

CMD ["php", "-v"]
