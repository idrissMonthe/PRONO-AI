#!/bin/sh
set -e

APP_DIR="/var/www/html"

# Assurer la création des sous-dossiers nécessaires au cache et aux sessions Laravel
mkdir -p "$APP_DIR/storage/framework/cache/data" \
         "$APP_DIR/storage/framework/sessions" \
         "$APP_DIR/storage/framework/views" \
         "$APP_DIR/storage/logs" \
         "$APP_DIR/bootstrap/cache" 2>/dev/null || true

# Attribuer la propriété à www-data (serveur Apache) et des droits complets
chown -R www-data:www-data "$APP_DIR/storage" "$APP_DIR/bootstrap/cache" 2>/dev/null || true
chmod -R 775 "$APP_DIR/storage" "$APP_DIR/bootstrap/cache" 2>/dev/null || true
chmod g+s "$APP_DIR/storage/framework/cache/data" 2>/dev/null || true
chmod g+s "$APP_DIR/storage/framework/sessions" 2>/dev/null || true
chmod g+s "$APP_DIR/storage/framework/views" 2>/dev/null || true

if [ -f "$APP_DIR/artisan" ] && [ -n "$DB_HOST" ]; then
    # Attente de la disponibilité de la base de données MySQL
    echo "[pronoia-entrypoint] Vérification de la connexion à MySQL ($DB_HOST)..."
    max_tries=30
    count=0
    # Test TCP pur : `db:show`/`db:monitor` dépendent d'extensions optionnelles
    # (intl) absentes de l'image et échoueraient même base disponible.
    until php -r 'exit(@fsockopen(getenv("DB_HOST"), (int) (getenv("DB_PORT") ?: 3306), $e, $s, 2) ? 0 : 1);' || [ $count -ge $max_tries ]; do
        sleep 2
        count=$((count + 1))
    done

    # Les migrations ne sont jouées que dans un seul conteneur (web) : lancer
    # `migrate` en parallèle depuis web et scheduler provoque des courses
    # (« Table 'cache' already exists »).
    if [ "${RUN_MIGRATIONS:-0}" = "1" ]; then
        echo "[pronoia-entrypoint] Exécution des migrations..."
        # Une migration échouée doit empêcher le démarrage : servir une
        # application dont le schéma est incomplet produit des erreurs plus
        # difficiles à diagnostiquer qu'un conteneur qui refuse de démarrer.
        if ! php "$APP_DIR/artisan" migrate --force; then
            echo "[pronoia-entrypoint] ERREUR: les migrations ont échoué, arrêt du conteneur." >&2
            exit 1
        fi
    fi

    # Libération des verrous sans chevauchement orphelins
    php "$APP_DIR/artisan" oracle:release-schedule-locks >/dev/null 2>&1 || true
fi

exec "$@"
