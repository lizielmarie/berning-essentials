# ==============================================================================
# BERNING ESSENTIALS — DOCKERFILE FOR RENDER WEB SERVICES
# PHP 8.2 + Apache + PDO MySQL + TLS/SSL CA Support
# ==============================================================================

FROM php:8.2-apache

# Install system dependencies & CA certificates
RUN apt-get update && apt-get install -y --no-install-recommends \
    ca-certificates \
    curl \
    && update-ca-certificates \
    && rm -rf /var/lib/apt/lists/*

# Install and enable PDO MySQL extension
RUN docker-php-ext-install pdo pdo_mysql

# Enable Apache mod_rewrite and mod_headers for clean URLs and security headers
RUN a2enmod rewrite headers

# Configure Apache to allow .htaccess overrides
RUN sed -ri -e 's!AllowOverride None!AllowOverride All!g' /etc/apache2/apache2.conf

# Configure Apache to bind to the dynamic PORT provided by Render (defaults to 80)
ENV PORT=80
RUN sed -i 's/Listen 80/Listen ${PORT}/g' /etc/apache2/ports.conf \
    && sed -i 's/:80/:${PORT}/g' /etc/apache2/sites-available/000-default.conf

# Set working directory and copy application source code
WORKDIR /var/www/html
COPY . /var/www/html/

# Set ownership and permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Expose HTTP port
EXPOSE 80

# Start Apache in foreground
CMD ["apache2-foreground"]
