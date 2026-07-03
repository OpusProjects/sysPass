# sysPass development image — PHP 8.5 (matches the rewrite's `~8.4 || ~8.5` requirement).
FROM php:8.5-apache

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

# All locales the app supports (Language::$langs) — without the system locale,
# setlocale() fails and gettext falls back to English. The test suite asserts
# against en_US/es_ES. 'da' and 'fo' are defined under the short names the app
# passes to setlocale().
RUN for l in en_US es_ES ca_ES de_DE hu_HU fr_FR pl_PL ru_RU nl_NL pt_BR it_IT ja_JP; do \
        localedef -i "$l" -f UTF-8 "$l.UTF-8"; \
    done \
    && localedef -i da_DK -f UTF-8 da.UTF-8 \
    && localedef -i fo_FO -f UTF-8 fo.UTF-8

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
