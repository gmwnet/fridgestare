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

# Replace local config.php with empty defaults (never bake real API keys into the image)
RUN rm -f /var/www/html/config.php && \
    printf '<?php\nreturn array (\n  '"'"'upcitemdb_key'"'"' => '"'"''"'"',\n  '"'"'turnstile_site_key'"'"' => '"'"''"'"',\n  '"'"'turnstile_secret_key'"'"' => '"'"''"'"',\n  '"'"'timezone'"'"' => '"'"'America/New_York'"'"',\n  '"'"'session_timeout_days'"'"' => 30,\n  '"'"'pin_max_attempts'"'"' => 5,\n  '"'"'pin_lockout_hours'"'"' => 1,\n  '"'"'default_qty'"'"' => 1,\n  '"'"'emergency_unlock'"'"' => false,\n  '"'"'debug'"'"' => false,\n);\n' > /var/www/html/config.php

# Ensure DB is writable (created on first request)
RUN touch /var/www/html/fridgestare.db && chmod 666 /var/www/html/fridgestare.db

# Apache already listens on 80
EXPOSE 80
