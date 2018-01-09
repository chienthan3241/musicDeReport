#!/bin/sh
export PATH=/usr/local/bin:$PATH
source /etc/profile.d/mediacontrol.sh
php ../htdocs/cron.php
