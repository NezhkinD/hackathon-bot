FROM php:8.4.14-fpm-alpine3.21

WORKDIR /

RUN apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        autoconf \
        g++ \
        make \
        linux-headers \
    && apk add --no-cache \
        zip \
        vim \
        unzip \
        git \
        nano \
        wget \
        curl \
        icu-dev \
    && pecl install xdebug-3.4.2 \
    && docker-php-ext-enable xdebug \
    && docker-php-ext-configure intl \
    && docker-php-ext-install intl \
    && apk del .build-deps

RUN curl -sSLf \
        -o /usr/local/bin/install-php-extensions \
        https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions && \
    chmod +x /usr/local/bin/install-php-extensions && \
    install-php-extensions pdo_pgsql redis mongodb intl gd zip pcntl

COPY .docker/configs/php.ini /usr/local/etc/php/php.ini
COPY .docker/configs/xdebug.ini /usr/local/etc/php/conf.d/xdebug.ini

RUN rm -f /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Установка часового пояса
ENV TZ=Europe/Moscow
RUN apk add --no-cache tzdata \
    && ln -snf /usr/share/zoneinfo/$TZ /etc/localtime \
    && echo $TZ > /etc/timezone

# Настройка часового пояса в PHP
RUN echo "date.timezone = Europe/Moscow" > /usr/local/etc/php/conf.d/timezone.ini

WORKDIR /home/app

RUN chown -R www-data:www-data /var/cache
RUN chmod -R 775 /var/cache

RUN chown -R root:root /var/cache
RUN chmod -R 775 /var/cache

EXPOSE 9000
CMD ["php-fpm"]