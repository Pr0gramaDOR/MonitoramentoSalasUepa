FROM php:8.3-apache

# Driver MySQL para o PDO (a imagem base só traz SQLite)
RUN docker-php-ext-install pdo pdo_mysql \
    && a2enmod headers rewrite \
    && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Fuso horário do PHP (Pará)
RUN echo "date.timezone=America/Belem" > "$PHP_INI_DIR/conf.d/timezone.ini"

WORKDIR /var/www/html

# Pasta do banco SQLite, fora de /var/www/html (o Apache não serve esta pasta).
RUN mkdir -p /var/www/data && chown www-data:www-data /var/www/data

# Defesa extra: se algum arquivo de banco parar na pasta pública, o Apache recusa o download.
RUN printf '<FilesMatch "\\.(sqlite|sqlite-wal|sqlite-shm|db)$">\n  Require all denied\n</FilesMatch>\n' > /etc/apache2/conf-available/block-db.conf \
    && a2enconf block-db