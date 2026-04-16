#!/bin/sh
set -eu

notify_failed() {
    php artisan deploy:notify-telegram failed --stage=deploy --reason="Deploy command failed" || true
}

php artisan migrate --force || {
    notify_failed
    exit 1
}

composer optimize || {
    notify_failed
    exit 1
}

php artisan deploy:notify-telegram succeeded --stage=deploy || true
