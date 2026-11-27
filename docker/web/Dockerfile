# Local development image for the Drupal 7 site that hosts the myapi module.
#
# Mirrors production (checked on the server, not assumed):
#   Ubuntu 22.04, PHP 8.3 from the ondrej/php (Sury) PPA running as PHP-FPM,
#   Apache 2.4 with mpm_event + proxy_fcgi, Drush 8.4.12.
# The package list is the one installed in production (see
# docs/migracion-php83-drupal7103.md); prod-php-modules.txt is the output of
# `php-fpm8.3 -m` there and is compared against this image at build time.
FROM ubuntu:22.04

ENV DEBIAN_FRONTEND=noninteractive \
    LANG=C.UTF-8

RUN apt-get update \
 && apt-get install -y --no-install-recommends ca-certificates curl gnupg software-properties-common \
 && add-apt-repository -y ppa:ondrej/php \
 && apt-get update \
 && apt-get install -y --no-install-recommends \
      apache2 \
      php8.3-cli php8.3-common php8.3-curl php8.3-fpm php8.3-gd php8.3-intl \
      php8.3-mbstring php8.3-mysql php8.3-opcache php8.3-readline php8.3-soap \
      php8.3-xml php8.3-zip \
      mariadb-client msmtp less unzip \
 && apt-get purge -y software-properties-common \
 && apt-get autoremove -y \
 && rm -rf /var/lib/apt/lists/*

# Same Apache layout as production: event MPM, PHP through proxy_fcgi to the
# FPM unix socket, and the docroot block with CGIPassAuth (the API reads the
# Authorization header).
RUN a2dismod -q mpm_prefork || true \
 && a2enmod -q mpm_event proxy proxy_fcgi setenvif rewrite headers deflate \
 && a2enconf -q php8.3-fpm
COPY drupal-docroot.conf /etc/apache2/conf-available/drupal-docroot.conf
COPY myapi-local.conf /etc/apache2/conf-available/myapi-local.conf
RUN a2enconf -q drupal-docroot myapi-local \
 && echo "ServerName localhost" > /etc/apache2/conf-available/servername.conf \
 && a2enconf -q servername

# 99-crespcord.ini is a copy of the production file; 99-local.ini holds the
# few values that must differ in local (mail transport, visible errors).
COPY 99-crespcord.ini 99-local.ini /etc/php/8.3/mods-available/
RUN for sapi in fpm cli; do \
      ln -s /etc/php/8.3/mods-available/99-crespcord.ini /etc/php/8.3/$sapi/conf.d/99-crespcord.ini; \
      ln -s /etc/php/8.3/mods-available/99-local.ini /etc/php/8.3/$sapi/conf.d/99-zz-local.ini; \
    done \
 # Production CLI runs without a memory limit (cli/php.ini default).
 && printf 'memory_limit = -1\n' > /etc/php/8.3/cli/conf.d/99-zzz-cli.ini \
 && touch /var/log/php8.3-fpm-errors.log \
 && chown www-data:www-data /var/log/php8.3-fpm-errors.log \
 && mkdir -p /run/php

# Every mail sent with PHP mail() (DefaultMailSystem) goes to Mailpit too.
COPY msmtprc /etc/msmtprc

# Drush 8.4.12, the version on production. /usr/local/bin/drush is a wrapper
# that always runs it as www-data: see drush-wrapper.sh for why that matters.
RUN mkdir -p /usr/local/lib/drush /var/www/.drush \
 && curl -fsSL -o /usr/local/lib/drush/drush.phar \
      https://github.com/drush-ops/drush/releases/download/8.4.12/drush.phar \
 && chown -R www-data:www-data /var/www/.drush
COPY drush-wrapper.sh /usr/local/bin/drush
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod 755 /usr/local/bin/drush /usr/local/bin/entrypoint.sh

# Fail the build if the extension set drifts from production.
COPY prod-php-modules.txt /usr/local/share/prod-php-modules.txt
RUN php-fpm8.3 -m | grep -v '^\[' | grep -v '^$' | sort -uf > /tmp/local-modules.txt \
 && grep -v '^#' /usr/local/share/prod-php-modules.txt | sort -uf > /tmp/prod-modules.txt \
 && diff /tmp/prod-modules.txt /tmp/local-modules.txt \
 && rm /tmp/local-modules.txt /tmp/prod-modules.txt

WORKDIR /var/www/html
EXPOSE 80
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
