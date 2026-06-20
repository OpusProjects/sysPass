# sysPass development image — PHP 8.2 (matches the rewrite's `~8.2 || ~8.3` requirement).
FROM php:8.2-apache

# System libraries to build the PHP extensions, plus test-suite needs:
#  - iproute2: the test bootstrap's getRealIpAddress() runs `ip a s eth0`.
#  - locales:  LanguageTest asserts against en_US.UTF-8 / es_ES.UTF-8.
RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        gettext \
        iproute2 \
        locales \
        libldap2-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libzip-dev \
        libxml2-dev \
        libicu-dev \
        libonig-dev \
    && rm -rf /var/lib/apt/lists/*

# Locales the test suite asserts against.
RUN localedef -i en_US -f UTF-8 en_US.UTF-8 && localedef -i es_ES -f UTF-8 es_ES.UTF-8

# PHP extensions required by composer.json (gd, ldap, gettext, pdo_mysql, zip, intl, mbstring).
# dom, json, libxml, fileinfo, zlib, curl ship enabled in the base image.
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-configure ldap --with-libdir=lib/x86_64-linux-gnu \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        gd \
        ldap \
        gettext \
        zip \
        intl \
        mbstring

RUN a2enmod rewrite headers

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/php/syspass.ini /usr/local/etc/php/conf.d/zz-syspass.ini
COPY docker/apache/syspass.conf /etc/apache2/sites-available/000-default.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /var/www/html

ENTRYPOINT ["entrypoint.sh"]
CMD ["apache2-foreground"]
