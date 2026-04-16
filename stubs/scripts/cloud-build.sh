#!/bin/sh
set -eu

notify_failed() {
    php artisan deploy:notify-telegram failed --stage=build --reason="Build command failed" || true
}

ensure_bun() {
    if command -v bun >/dev/null 2>&1; then
        return 0
    fi

    echo "bun not found; installing Bun runtime..."

    if command -v bash >/dev/null 2>&1 && command -v curl >/dev/null 2>&1; then
        curl -fsSL https://bun.sh/install | bash
    elif command -v npm >/dev/null 2>&1; then
        npm install -g bun
    else
        echo "Cannot install bun: need either bash+curl or npm."
        return 1
    fi

    BUN_INSTALL="${BUN_INSTALL:-$HOME/.bun}"
    export BUN_INSTALL
    export PATH="$BUN_INSTALL/bin:$PATH"

    command -v bun >/dev/null 2>&1
}

composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader || {
    notify_failed
    exit 1
}

php artisan deploy:notify-telegram started --stage=build || true

ensure_bun || {
    notify_failed
    exit 1
}

bun install --frozen-lockfile || {
    notify_failed
    exit 1
}

bun run --bun vp build || {
    notify_failed
    exit 1
}
