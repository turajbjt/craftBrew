FROM php:8.2-apache

# Install PDO MySQL and required PHP extensions
RUN docker-php-ext-install pdo pdo_mysql mysqli

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Set Apache DocumentRoot to /var/www/html
WORKDIR /var/www/html

COPY . /var/www/html/

# Adjust permissions
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
