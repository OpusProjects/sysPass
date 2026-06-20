# sysPass development image — PHP 7.4 baseline (matches the 3.2.x release line).
# This is intentionally the legacy runtime so we have a known-good baseline to
# upgrade against, one dependency at a time.
FROM php:7.4-apache

# System libraries needed to build the PHP extensions sysPass requires.
RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        gettext \
        libldap2-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libzip-dev \
        libxml2-dev \
    && rm -rf /var/lib/apt/lists/*

# PHP extensions required by composer.json (gd, ldap, gettext, pdo_mysql, zip).
# dom, json, libxml, mbstring, fileinfo, zlib, curl ship enabled in the base image.
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-configure ldap --with-libdir=lib/x86_64-linux-gnu \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        gd \
        ldap \
        gettext \
        zip

RUN a2enmod rewrite headers

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/php/syspass.ini /usr/local/etc/php/conf.d/zz-syspass.ini
COPY docker/apache/syspass.conf /etc/apache2/sites-available/000-default.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /var/www/html

ENTRYPOINT ["entrypoint.sh"]
CMD ["apache2-foreground"]
