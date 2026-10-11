# ============================================================================
# Image de l'application Wallet (PHP 8.2 + extensions PDO/MySQL)
# ============================================================================
FROM php:8.2-apache

RUN docker-php-ext-install pdo pdo_mysql \
    && a2enmod rewrite

# Le code de l'application est monté dans /var/www/html.
WORKDIR /var/www/html

EXPOSE 80
