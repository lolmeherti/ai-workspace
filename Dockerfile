FROM php:8.3-apache

RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libzip-dev \
    && rm -rf /var/lib/apt/lists/*

RUN pecl install xdebug \
    && docker-php-ext-enable xdebug

RUN echo "xdebug.mode=debug" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini \
    && echo "xdebug.client_host=host.docker.internal" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini \
    && echo "xdebug.start_with_request=yes" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini

RUN docker-php-ext-install pdo_mysql pdo zip \
    && pecl install redis \
    && docker-php-ext-enable redis

RUN echo "log_errors = On" > /usr/local/etc/php/conf.d/docker-php-logging.ini \
    && echo "error_log = /dev/stderr" >> /usr/local/etc/php/conf.d/docker-php-logging.ini \
    && echo "display_errors = Off" >> /usr/local/etc/php/conf.d/docker-php-logging.ini

COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer

RUN a2enmod rewrite

WORKDIR /var/www/html

COPY ./src/composer.json ./src/composer.lock* /var/www/html/

RUN composer install --no-interaction --no-scripts --no-autoloader --prefer-dist

COPY ./src /var/www/html

RUN composer dump-autoload --optimize

RUN mkdir -p /var/www/html/uploads && chown -R www-data:www-data /var/www/html/uploads