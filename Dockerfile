FROM ubuntu:24.04

ENV DEBIAN_FRONTEND=noninteractive \
    PHP_VER=8.3

# LAMP: Linux (ubuntu) + Apache + MariaDB (MySQL-compatible) + PHP
RUN apt-get update && apt-get install -y --no-install-recommends \
        apache2 \
        libapache2-mod-php \
        php-mysql \
        php-curl \
        ca-certificates \
        mariadb-server \
        openssl \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Apache: only keep what we need, listen on 8080, harden
RUN a2dismod -f autoindex status negotiation cgi >/dev/null 2>&1 || true \
    && a2enmod headers >/dev/null \
    && a2dissite 000-default >/dev/null \
    && sed -i 's/^Listen 80$/Listen 8080/' /etc/apache2/ports.conf \
    && sed -i 's/^\s*Listen 443/#Listen 443/' /etc/apache2/ports.conf

COPY docker/security.conf   /etc/apache2/conf-available/security-extra.conf
COPY docker/site.conf       /etc/apache2/sites-available/scanner.conf
COPY docker/hardening.ini   /etc/php/${PHP_VER}/apache2/conf.d/99-hardening.ini
RUN a2enconf security-extra >/dev/null && a2ensite scanner >/dev/null

# App code: only /public is web-accessible. /app sits outside the docroot.
RUN rm -f /var/www/html/index.html
COPY public/ /var/www/public/
COPY app/    /var/www/app/
RUN chown -R root:root /var/www && chmod -R go-w /var/www

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod 755 /usr/local/bin/entrypoint.sh

VOLUME /var/lib/mysql
EXPOSE 8080
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
