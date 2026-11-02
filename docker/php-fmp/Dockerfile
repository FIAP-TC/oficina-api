FROM php:8.3-fpm-alpine AS builder

RUN apk add --no-cache \
    curl \
    unzip \
    libzip-dev \
    icu-dev

RUN docker-php-ext-install bcmath intl zip pdo pdo_mysql

COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www

COPY . .

RUN composer install --optimize-autoloader --no-interaction

FROM php:8.3-fpm-alpine

# fcgi traz o cgi-fcgi (131 KiB), um cliente FastCGI de linha de comando.
#
# É o que permite às probes do Kubernetes consultarem as sondas de saúde: este container fala
# FastCGI na porta 9000, não HTTP, então uma probe `httpGet` não consegue conversar com ele —
# era por isso que as probes aqui eram `tcpSocket`, que só confirma que alguém aceita conexão
# na porta. Com o cgi-fcgi a probe executa a requisição de verdade, passando pelo PHP.
RUN apk add --no-cache libzip icu-libs fcgi

COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer

COPY --from=builder /usr/local/lib/php/extensions /usr/local/lib/php/extensions
COPY --from=builder /usr/local/etc/php/conf.d /usr/local/etc/php/conf.d
COPY --from=builder /var/www /var/www

WORKDIR /var/www

RUN chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache

COPY docker/php-fmp/zz-tuning.conf /usr/local/etc/php-fpm.d/zz-tuning.conf

USER www-data

CMD ["php-fpm"]

EXPOSE 9000
