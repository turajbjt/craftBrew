FROM php:8.2-apache

# Install PDO MySQL, OpenSSL, libzip, and required PHP extensions
RUN apt-get update && apt-get install -y openssl ssl-cert libpng-dev libjpeg-dev libfreetype6-dev libzip-dev zip unzip \
    && docker-php-ext-install pdo pdo_mysql mysqli zip \
    && rm -rf /var/lib/apt/lists/*

# Enable Apache mod_rewrite, mod_headers, and mod_ssl
RUN a2enmod rewrite headers ssl socache_shmcb

# Generate Self-Signed SSL Certificate for localhost
RUN openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
    -keyout /etc/ssl/private/ssl-cert-snakeoil.key \
    -out /etc/ssl/certs/ssl-cert-snakeoil.pem \
    -subj "/C=US/ST=BrewState/L=BrewCity/O=CraftBrew/OU=IT/CN=localhost"

# Enable Default SSL Site
RUN a2ensite default-ssl

# Set Apache DocumentRoot to /var/www/html
WORKDIR /var/www/html

COPY . /var/www/html/

# Adjust permissions
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80 443
