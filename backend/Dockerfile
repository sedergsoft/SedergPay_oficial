FROM php:8.3-cli
RUN apt-get update \
    && apt-get install -y --no-install-recommends libzip-dev unzip libpq-dev \
    && docker-php-ext-configure zip \
    && docker-php-ext-install bcmath pdo_pgsql zip \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
# Limites de subida y de memoria: por defecto PHP rechaza cualquier fichero
# de mas de 2 MB y los avisos en pantalla rompen la respuesta JSON y el CORS.
COPY docker/php/app.ini /usr/local/etc/php/conf.d/zz-sederg.ini
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-progress --prefer-dist
COPY . .
# runtime/ y web/assets son excluidos por .dockerignore; Yii los necesita en
# tiempo de ejecucion (logs, cache, assets publicados) tambien fuera de Compose.
RUN mkdir -p runtime/logs runtime/debug runtime/cache
CMD ["sh","-c","php yii serve 0.0.0.0:${PORT:-8080} --docroot=web"]
