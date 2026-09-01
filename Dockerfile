FROM php:8.4-apache-bookworm

# Install system dependencies & CLI utilities
RUN apt-get update && apt-get install -y \
    zip \
    unzip \
    git \
    curl \
    imagemagick \
    && rm -rf /var/lib/apt/lists/*

# Configure git to trust the workspace directory to prevent dubious ownership errors
RUN git config --system --add safe.directory /var/www/cyberpunk

# Add docker-php-extension-installer to easily install PHP extensions with system dependencies
ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/

# Install PHP extensions
RUN chmod +x /usr/local/bin/install-php-extensions && \
    install-php-extensions \
        gd \
        pdo_mysql \
        mysqli \
        zip \
        opcache \
        pdo \
        curl \
        mbstring \
        readline

# Install Composer from official image
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/cyberpunk

# Adjust permissions so Apache can read/write files in development
RUN chown -R www-data:www-data /var/www/cyberpunk

# Apache mods (rewrite for clean URLs, the rest for content negotiation/caching)
RUN a2enmod rewrite negotiation headers deflate expires
