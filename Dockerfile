# 1. Usamos la imagen oficial de PHP 8.2 con Apache integrado
FROM php:8.2-apache

# 2. Instalar dependencias esenciales del sistema para PostgreSQL y utilidades de descompresión
RUN apt-get update && apt-get install -y \
    libpq-dev \
    zip \
    unzip \
    git \
    && rm -rf /var/lib/apt/lists/*

# 3. Instalar la extensión nativa de PostgreSQL (pdo_pgsql) en PHP
RUN docker-php-ext-install pdo pdo_pgsql

# 4. Instalar de forma fija la versión compatible 1.19.4 de MongoDB desde PECL
# Esto previene el error "must be compatible with MongoDB\BSON\Serializable" de las versiones 1.20+
RUN pecl install mongodb-1.19.4 \
    && docker-php-ext-enable mongodb

# 5. Habilitar la reescritura de URLs en Apache (necesario para frameworks y enrutamiento en PHP)
RUN a2enmod rewrite

# 6. Traer e instalar Composer de manera global desde su imagen oficial
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 7. Definir el directorio público por defecto del servidor Apache
WORKDIR /var/www/html

# 8. Copiar absolutamente todos los archivos de tu proyecto local al contenedor
COPY . /var/www/html/

# 9. Eliminar el archivo lock local antiguo y realizar una instalación fresca de dependencias
# Esto asegura que Composer descargue las versiones de la librería PHP que encajan perfectamente con el driver 1.19.4
RUN rm -f composer.lock && composer install --no-interaction --optimize-autoloader

# 10. Asignar los permisos correctos de lectura y escritura al servidor web (Apache)
RUN chown -R www-data:www-data /var/www/html

# ---------------------------------------------------------------------------------
# CAMBIO CLAVE: Configurar PHP para que acepte fotos pesadas de celulares (Hasta 20MB)
# Esto soluciona el aviso amarillo que decía que el archivo era obligatorio.
# ---------------------------------------------------------------------------------
RUN echo "upload_max_filesize = 20M" > /usr/local/etc/php/conf.d/uploads.ini \
    && echo "post_max_size = 20M" >> /usr/local/etc/php/conf.d/uploads.ini
# ---------------------------------------------------------------------------------

# 11. Indicar que el contenedor escuchará en el puerto 80 estándar
EXPOSE 80
