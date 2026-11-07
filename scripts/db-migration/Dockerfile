FROM php:8.3-cli
RUN docker-php-ext-install pdo_mysql pdo_pgsql
WORKDIR /migration
COPY migrate-mysql-to-postgres.php /migration/migrate.php
ENTRYPOINT ["php", "/migration/migrate.php"]
