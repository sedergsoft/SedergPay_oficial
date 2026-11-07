FROM php:8.3-cli
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev \
    && docker-php-ext-install pdo_mysql pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /migration
COPY migrate-mysql-to-postgres.php /migration/migrate.php
ENTRYPOINT ["php", "/migration/migrate.php"]
