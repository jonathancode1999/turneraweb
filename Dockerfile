FROM php:8.2-apache

# Habilitar mod_rewrite (por si usás .htaccess)
RUN a2enmod rewrite

# Copiar el proyecto al contenedor
COPY . /var/www/html/

# Permisos
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
