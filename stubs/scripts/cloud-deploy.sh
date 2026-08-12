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

# No `storage:link` here on purpose. Laravel Cloud builds a fresh image per deploy and replaces
# the running container, so anything written to the local public disk is gone on the next ship
# and is not shared between replicas. Linking it would make the deploy log read as though uploads
# were handled when they are not. Serve user uploads from an object storage bucket instead — see
# the provision-laravel-cloud skill.

php artisan deploy:notify-telegram succeeded --stage=deploy || true
