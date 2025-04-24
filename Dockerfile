FROM php:8.2-cli

# System Abhängigkeiten
RUN apt-get update && apt-get install -y \
    git unzip libzip-dev libldap2-dev libonig-dev libicu-dev \
    libpq-dev libxml2-dev zlib1g-dev wget curl \
    && docker-php-ext-install intl pdo pdo_mysql zip ldap

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# PHP config
COPY .docker/php.ini /usr/local/etc/php/php.ini

# Symfony CLI (optional, aber cool für dev)
RUN wget https://get.symfony.com/cli/installer -O - | bash \
    && mv /root/.symfony*/bin/symfony /usr/local/bin/symfony

# Start-Script
COPY .docker/init.sh /usr/local/bin/init.sh
RUN chmod +x /usr/local/bin/init.sh

# Workdir
WORKDIR /var/www/app

CMD ["init.sh"]
