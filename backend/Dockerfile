FROM php:8.3-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends libzip-dev unzip \
    && docker-php-ext-configure zip \
    && docker-php-ext-install bcmath pdo_mysql zip \
    && rm -rf /var/lib/apt/lists/*
# KYC uploads allow up to 5 MB per document; PHP's 2 MB default rejects
# those files before the application can validate them.
COPY docker/php-uploads.ini /usr/local/etc/php/conf.d/zz-sederg-uploads.ini
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-progress --prefer-dist

COPY . .

CMD ["sh", "-c", "php yii serve 0.0.0.0:${PORT:-8080} --docroot=web"]
