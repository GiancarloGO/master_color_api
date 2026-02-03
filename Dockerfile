# Usar PHP 8.3 con Apache
FROM php:8.3-apache

# Instalar dependencias del sistema
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libpq-dev \
    libzip-dev \
    zip \
    unzip \
    supervisor \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Instalar Node.js 20 LTS desde NodeSource
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs

# Instalar extensiones PHP necesarias para Laravel 12 y JWT
RUN docker-php-ext-install \
    pdo \
    pdo_pgsql \
    mbstring \
    exif \
    pcntl \
    bcmath \
    gd \
    zip \
    opcache

# Configurar opcache para producción
RUN echo 'opcache.memory_consumption=128' >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo 'opcache.interned_strings_buffer=8' >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo 'opcache.max_accelerated_files=4000' >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo 'opcache.revalidate_freq=2' >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo 'opcache.fast_shutdown=1' >> /usr/local/etc/php/conf.d/opcache.ini

# Instalar Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Configurar Apache: deshabilitar todos los MPM y habilitar solo mpm_prefork
RUN a2dismod mpm_event mpm_worker mpm_prefork || true && \
    a2enmod mpm_prefork && \
    a2enmod rewrite

# Configurar DocumentRoot
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Establecer directorio de trabajo
WORKDIR /var/www/html

# Copiar composer files primero para aprovechar cache de Docker
COPY composer.json composer.lock ./

# Instalar dependencias de Composer (sin vendor del host)
RUN composer install --no-dev --optimize-autoloader --no-scripts --no-autoloader

# Copiar el resto de archivos del proyecto (excluyendo vendor y node_modules)
COPY . /var/www/html

# Completar instalación de composer con scripts
RUN composer dump-autoload --optimize

# Instalar dependencias de NPM y hacer build
RUN if [ -f package.json ]; then \
    npm ci && \
    npm run build && \
    rm -rf node_modules; \
    fi

# Crear directorios necesarios y establecer permisos
RUN mkdir -p storage/logs storage/framework/{cache,sessions,views} bootstrap/cache \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html/storage \
    && chmod -R 755 /var/www/html/bootstrap/cache

# Copiar configuración de Supervisor
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Copiar script de entrypoint
COPY docker/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# Exponer puerto 80
EXPOSE 80

# Usar entrypoint script para iniciar Supervisor
ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
