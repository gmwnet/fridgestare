FROM php:8.2-apache

# Install SQLite support and zbar-tools
RUN apt-get update && apt-get install -y \
    libsqlite3-dev \
    zbar-tools \
    && docker-php-ext-install pdo_sqlite \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Enable Apache rewrite module
RUN a2enmod rewrite

# Copy app files
COPY . /var/www/html/

# Set permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod 666 /var/www/html/groscan.db || true

# Apache already listens on 80
EXPOSE 80
