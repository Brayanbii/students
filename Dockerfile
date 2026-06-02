# 1. Usamos la imagen oficial de PHP con Apache
FROM php:8.2-apache

# 2. Instalar dependencias del sistema necesarias para PostgreSQL y utilidades de Git/Zip
RUN apt-get update && apt-get install -y \
    libpq-dev \
    zip \
    unzip \
    git \
    && rm -rf /var/lib/apt/lists/*

# 3. Instalar la extensión pdo_pgsql de forma nativa en PHP
RUN docker-php-ext-install pdo pdo_pgsql

# 4. Instalar la extensión oficial de MongoDB mediante PECL y habilitarla
RUN pecl install mongodb \
    && docker-php-ext-enable mongodb

# 5. Habilitar el módulo de reescritura de Apache (buena práctica para PHP)
RUN a2enmod rewrite

# 6. Copiar Composer desde su imagen oficial para manejar las librerías
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 7. Establecer el directorio de trabajo de Apache
WORKDIR /var/www/html

# 8. Copiar todos los archivos de tu proyecto local al contenedor de Docker
COPY . /var/www/html/

# 9. Eliminar composer.lock local (si existe) y correr composer install de forma estricta.
# Al no ignorar los requisitos de plataforma, Composer detectará la extensión nativa instalada
# en el paso 4 y descargará la versión de la librería PHP que encaja perfectamente con ella.
RUN rm -f composer.lock && composer install --no-interaction --optimize-autoloader

# 10. Dar permisos de lectura/escritura a Apache sobre los archivos
RUN chown -R www-data:www-data /var/www/html

# 11. Exponer el puerto estándar 80
EXPOSE 80
