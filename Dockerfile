FROM php:8.3-fpm-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    curl \
    ca-certificates \
    gnupg \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    libicu-dev \
    libsqlite3-dev \
    unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
    pdo_mysql \
    pdo_sqlite \
    mbstring \
    exif \
    pcntl \
    bcmath \
    gd \
    zip \
    intl \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/php/docker-entrypoint.sh /usr/local/bin/docker-entrypoint-famedic.sh
RUN chmod +x /usr/local/bin/docker-entrypoint-famedic.sh

WORKDIR /var/www/html

ENTRYPOINT ["docker-entrypoint-famedic.sh"]
CMD ["php-fpm"]
