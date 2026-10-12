FROM php:8.2-apache

# Инсталиране на нужните пакети за Symfony
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libzip-dev \
    && docker-php-ext-install zip

# Активиране на mod_rewrite за правилното рутиране
RUN a2enmod rewrite

# Инсталиране на Composer в контейнера
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Настройване на Apache да сочи към public/ папката
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

WORKDIR /var/www/html