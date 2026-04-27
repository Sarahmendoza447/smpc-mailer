FROM php:8.2-cli

WORKDIR /app

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libcurl4-openssl-dev \
    && docker-php-ext-install curl \
    && rm -rf /var/lib/apt/lists/*

COPY composer.json composer.lock ./
COPY bin/composer.phar /usr/local/bin/composer.phar

RUN php /usr/local/bin/composer.phar install --no-dev --prefer-dist --no-interaction --optimize-autoloader

COPY . .

ENV PORT=10000

CMD ["sh", "-c", "php -S 0.0.0.0:${PORT} -t /app /app/index.php"]
