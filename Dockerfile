FROM php:8.2-cli-alpine

# intl + icu-data-full: ICU plurals in every bundle locale (NowoYopassBundle+intl-icu catalogues).
RUN apk add --no-cache git unzip bash libzip-dev icu-dev icu-data-full nodejs npm \
    && docker-php-ext-install -j2 zip intl

# pcov: PHPUnit coverage; mongodb: doctrine/mongodb-odm (optional persistence backend, dev/tests)
RUN apk add --no-cache $PHPIZE_DEPS \
    && pecl install pcov mongodb \
    && docker-php-ext-enable pcov mongodb \
    && apk del $PHPIZE_DEPS

RUN npm install -g pnpm@10

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

ENV COMPOSER_ALLOW_SUPERUSER=1
ENV PATH="/app/vendor/bin:${PATH}"
