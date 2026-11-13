#!/bin/sh
set -e

APP_DIR="/var/www/html"

if [ -d "$APP_DIR/storage" ]; then
    chown -R www-data:www-data "$APP_DIR/storage" "$APP_DIR/bootstrap/cache" 2>/dev/null || true
    chmod -R ug+rwX "$APP_DIR/storage" "$APP_DIR/bootstrap/cache" 2>/dev/null || true
fi

# Un conteneur arrêté en pleine tâche laisse des verrous `withoutOverlapping`
# qui bloquent la planification jusqu'à 24 h. Au démarrage, aucune tâche ne
# tourne : on les libère.
if [ -f "$APP_DIR/artisan" ]; then
    php "$APP_DIR/artisan" oracle:release-schedule-locks >/dev/null 2>&1 || true
fi

exec "$@"
