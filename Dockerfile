FROM php:8.2-apache

# Инсталираме нужните библиотеки за Symfony
RUN apt-get update && apt-get install -y git unzip libzip-dev \
    && docker-php-ext-install zip

# Включваме пренасочването за Symfony
RUN a2enmod rewrite

# Настройваме Apache да търси директно в папка public/
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Инсталираме Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Създаваме работната директория
WORKDIR /var/www/html

# ТУК БЕШЕ ПРОБЛЕМЪТ: Копираме всички файлове от GitHub вътре в сървъра!
COPY . /var/www/html

# Инсталираме пакетите на Symfony
RUN composer install --no-dev --optimize-autoloader

# Даваме права на сървъра да чете и изпълнява файловете
RUN chown -R www-data:www-data /var/www/html
