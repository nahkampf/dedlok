FROM php:8.4-apache-bookworm

# Install system dependencies & CLI utilities
RUN apt-get update && apt-get install -y \
    zip \
    unzip \
    git \
    curl \
    imagemagick \
    cron \
    && rm -rf /var/lib/apt/lists/*

# Configure git to trust the workspace directory to prevent dubious ownership errors
RUN git config --system --add safe.directory /var/www/cyberpunk

# Add docker-php-extension-installer to easily install PHP extensions with system dependencies
ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/

# Install PHP extensions
#   intl   - required for i18n: ICU plural rules, locale-aware dates/numbers
#   bcmath - arbitrary-precision arithmetic for game economy maths
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
        readline \
        intl \
        bcmath

# Install Composer from official image
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# The repo root is bind-mounted, so files the container writes (vendor/, storage/,
# bootstrap/cache/) land on the host owned by whoever Apache runs as. Align
# www-data with the host user's uid/gid so both sides can write the same tree —
# otherwise `composer install` produces root-owned vendor/ that the host cannot
# delete, and Laravel cannot write its logs. Override at build time if your host
# uid differs: docker compose build --build-arg HOST_UID=$(id -u) web
ARG HOST_UID=1000
ARG HOST_GID=1000
RUN usermod  -u ${HOST_UID} www-data \
 && groupmod -g ${HOST_GID} www-data \
 && chown -R www-data:www-data /var/www

# Set working directory
WORKDIR /var/www/cyberpunk

# Adjust permissions so Apache can read/write files in development
RUN chown -R www-data:www-data /var/www/cyberpunk

# Laravel's scheduler, used by the `scheduler` service. Installed in the shared
# image but NOT started here — the web container must not run cron, or the
# heartbeat would only beat while someone is browsing.
COPY docker/cron/cyberpunk /etc/cron.d/cyberpunk
RUN chmod 0644 /etc/cron.d/cyberpunk

# Composer needs more headroom than the 128M runtime limit when resolving Laravel
ENV COMPOSER_MEMORY_LIMIT=-1

# Apache mods (rewrite for clean URLs, the rest for content negotiation/caching)
RUN a2enmod rewrite negotiation headers deflate expires
