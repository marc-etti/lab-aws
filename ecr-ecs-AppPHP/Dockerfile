FROM php:8.3-apache

# Estensione per MariaDB/MySQL
RUN docker-php-ext-install pdo_mysql

# Configurazione PHP di produzione e hardening Apache
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
 && echo "expose_php = Off" > "$PHP_INI_DIR/conf.d/99-custom.ini" \
 && printf "ServerTokens Prod\nServerSignature Off\n" > /etc/apache2/conf-available/security-custom.conf \
 && a2enconf security-custom

WORKDIR /var/www/html

RUN curl -fsSL https://truststore.pki.rds.amazonaws.com/global/global-bundle.pem \
      -o /etc/ssl/certs/rds-global-bundle.pem \
      && chmod 644 /etc/ssl/certs/rds-global-bundle.pem

# Copia del codice con i permessi corretti
COPY --chown=www-data:www-data app/ /var/www/html/

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
  CMD curl -fs http://localhost/health.php || exit 1