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

# Symlink the public disk. Idempotent, and a no-op for apps that serve uploads from a bucket —
# but without it an app storing media on the public disk returns 404s for every file it wrote.
php artisan storage:link || true

php artisan deploy:notify-telegram succeeded --stage=deploy || true
