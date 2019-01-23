#!/bin/bash

set -xe

cd /opt/ci123/www/html

if [ -f "composer.json" ]; then
  export HOME=/root
  export COMPOSER_HOME=/tmp/.composer

php /opt/ci123/www/html/composer.phar install
fi

php artisan migrate
