FROM php:8.3-rc-apache

RUN a2enmod rewrite
RUN docker-php-ext-install mysqli sockets shmop

COPY ./Src /var/www/html/
