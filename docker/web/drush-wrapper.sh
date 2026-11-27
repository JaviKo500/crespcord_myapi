#!/bin/sh
#
# Runs Drush as www-data, whoever calls it.
#
# The mailsystem module checks class_exists() for every class named in the
# 'mail_system' variable on bootstrap, and deletes the entries whose class it
# cannot load. When Drush ran as a user that could not read the myapi module,
# that wiped every myapi_* key and all mail from the module stopped. Running
# as the web server user is the rule on production; here it is enforced.
if [ "$(id -u)" = "0" ]; then
  exec runuser -u www-data -- "$0" "$@"
fi
if [ "$(id -un)" != "www-data" ]; then
  echo "drush must run as www-data (current user: $(id -un))." >&2
  exit 1
fi
export HOME=/var/www
exec php /usr/local/lib/drush/drush.phar --root=/var/www/html "$@"
