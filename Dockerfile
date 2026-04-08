FROM php:8.4-cli-bookworm

WORKDIR /var/www/html

ENV COMPOSER_ALLOW_SUPERUSER=1
ENV APP_ENV=production
ENV LOG_CHANNEL=stderr
# Dummy key so artisan can boot during image build (overridden at runtime via env).
ENV APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=

# Install system deps for PHP extensions + Node.js 22 LTS
RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    unzip \
    curl \
    libzip-dev \
    libicu-dev \
    libonig-dev \
    libxml2-dev \
    libcurl4-openssl-dev \
    pkg-config \
    ca-certificates \
    && docker-php-ext-install \
        intl \
        pcntl \
        bcmath \
        curl \
    && curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Copy dependency manifests first for better layer caching
COPY composer.json composer.lock package.json package-lock.json ./

# Install PHP deps without running post-install scripts (artisan doesn't exist yet).
# Install Node deps without running lifecycle scripts.
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --prefer-dist \
    --no-scripts \
    && npm install --ignore-scripts

# Copy the rest of the application source (includes artisan).
# node_modules is excluded via .dockerignore.
COPY . .

# Create required directories before artisan runs anything.
RUN mkdir -p storage/framework/cache \
    storage/framework/sessions \
    storage/framework/testing \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

# Re-run composer scripts now that artisan and bootstrap/cache are present.
RUN composer run-script post-autoload-dump --no-interaction

# Build frontend assets then discard node_modules.
RUN npm run build \
    && rm -rf node_modules

RUN php artisan config:cache \
    && php artisan route:cache \
    && php artisan view:cache \
    && chown -R www-data:www-data storage bootstrap/cache public/build

EXPOSE 8080

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8080"]
