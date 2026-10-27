FROM php:8.3-apache

# Estensioni richieste (libcurl opzionale per ICS/airbnb)
RUN apt-get update && apt-get install -y --no-install-recommends \
        libcurl4-openssl-dev libxml2-dev libmariadb-dev \
    && docker-php-ext-install -j"$(nproc)" curl xml pdo_mysql \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# vendor/ e' committato: NON eseguire composer install
ENV APP_DATABASE_HOST=db \
    APP_DATABASE_USER=root \
    APP_DATABASE_PASS="" \
    APP_DATABASE_NOME=db

WORKDIR /var/www/html

COPY . /var/www/html/

# DocumentRoot = public/ (rotte solo in public/index.php)
RUN set -eux; \
    a2enmod rewrite headers; \
    rm -f /etc/apache2/sites-enabled/000-default.conf; \
    printf '<VirtualHost *:80>\n\
    ServerName localhost\n\
    DocumentRoot /var/www/html/public\n\
    <Directory /var/www/html/public>\n\
        AllowOverride All\n\
        Require all granted\n\
        Options -Indexes\n\
    </Directory>\n\
</VirtualHost>\n' > /etc/apache2/sites-available/ospiti.conf; \
    a2dissite 000-default; \
    a2ensite ospiti; \
    ln -sf /dev/stderr /var/log/apache2/error.log; \
    ln -sf /dev/stdout /var/log/apache2/access.log

EXPOSE 80
