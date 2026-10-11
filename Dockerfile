# ============================================================================
# Image de l'application Wallet (PHP 8.2 + extensions PDO/MySQL)
# Compatible Render : Apache ecoute sur $PORT (defaut 10000).
# ============================================================================
FROM php:8.2-apache

RUN docker-php-ext-install pdo_mysql pdo_pgsql \
    && apt-get update \
    && apt-get install -y --no-install-recommends libcurl4-openssl-dev libonig-dev \
    && docker-php-ext-install curl mbstring \
    && rm -rf /var/lib/apt/lists/* \
    && a2enmod rewrite headers

WORKDIR /var/www/html
COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html

COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
CMD ["apache2-foreground"]
