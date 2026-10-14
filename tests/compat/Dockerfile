# Disposable Apache 2.4 fixture for the eFiction maintenance fork release
# gate. Built from the official PHP-Apache image with mysqli and mod_rewrite,
# mirroring an ordinary shared-host LAMP stack. tests/compat/apache.sh builds
# this image, extracts the release ZIP into the document root, and drives the
# same browser-install assertions as tests/compat/http.sh against Apache.
#
# ARG php_version selects the PHP minor (8.3 or 8.4).
ARG php_version=8.3
FROM php:${php_version}-apache

# mysqli for the database wrapper; pdo_mysql is not used by eFiction.
RUN docker-php-ext-install mysqli

# mod_rewrite ships enabled in the base image; keep it explicit so the gate
# fails loudly if the image changes.
RUN a2enmod rewrite

# The maintenance fork ships .htaccess with a deny rule for config.php. Allow
# per-directory overrides so it takes effect, like a typical shared host.
RUN { echo '<Directory /var/www/html>'; echo '    AllowOverride All'; echo '</Directory>'; } \
	> /etc/apache2/conf-available/efiction-override.conf \
	&& a2enconf efiction-override

# The private directory the installer reads its owner secret from is the
# parent of the document root.
RUN mkdir -p /var/www && chown www-data:www-data /var/www
