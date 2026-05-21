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

# Use example config as default (no real API keys in the image)
RUN cp /var/www/html/config.example.php /var/www/html/config.php

# Ensure DB is writable by Apache (created on first request)
RUN touch /var/www/html/fridgestare.db && chown www-data:www-data /var/www/html/fridgestare.db && chmod 664 /var/www/html/fridgestare.db

# Apache already listens on 80
EXPOSE 80
