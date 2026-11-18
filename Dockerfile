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

# Копираме всички файлове от GitHub вътре в сървъра
COPY . /var/www/html

# ЗАДЪЛЖИТЕЛНО: Казваме на Symfony, че сме в production още по време на билда!
ENV APP_ENV=prod

# Инсталираме пакетите на Symfony (вкл. textalk/websocket от composer.json)
RUN composer install --no-dev --optimize-autoloader --no-scripts

# Създаваме var/ и даваме права на сървъра да чете и записва файловете
RUN mkdir -p /var/www/html/var/cache /var/www/html/var/log \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 777 /var/www/html/var

# Стартираме AIS WebSocket демона във фонов режим и след това Apache
CMD ["sh", "-c", "php bin/console cache:clear --env=prod || true; chmod -R 777 /var/www/html/var; (while true; do php bin/console app:ais-stream >> /var/www/html/var/log/ais.log 2>&1; sleep 5; done) & apache2-foreground"]
