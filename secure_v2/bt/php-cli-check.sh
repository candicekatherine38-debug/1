#!/bin/bash
cd /www/wwwroot/admin_secure || exit 1
php -v
php -m | grep -E 'pdo_mysql|fileinfo|json'
php -l src/bootstrap.php
php -l src/controllers.php
