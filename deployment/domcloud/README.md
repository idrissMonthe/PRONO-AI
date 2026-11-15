# Déploiement PRONO IA / ORACLE FOOT sur DOM Cloud — forfait FREE

Ce dossier contient **uniquement** la configuration de déploiement. Aucune
logique métier (Dixon-Coles, Elo, calibration, marchés, abstention) n'a été
modifiée par cette préparation.

> Docker reste le mode de développement local : `docker-compose.yml` et
> `Dockerfile` n'ont pas été supprimés. DOM Cloud Free n'exécute pas Docker.

---

## 1. Architecture cible

Deux sites DOM Cloud distincts, sur le même dépôt GitHub :

| Site | Rôle | Runtime | Script |
|---|---|---|---|
| Laravel | interface + API + planificateur | PHP-FPM 8.3 | `domcloud.yml` (racine) |
| FastAPI | moteur statistique | Passenger + Uvicorn | `deployment/domcloud/ai-deploy.yml` |

Chaque site a son **propre quota de 1,5 Go de stockage** et **2 Go de trafic
sortant/mois**. Laravel appelle FastAPI en HTTPS via `AI_SERVICE_URL`.

```
Navigateur ──HTTPS──> Laravel (PHP-FPM)
                          │  X-Service-Secret + HTTPS
                          └────────> FastAPI (Uvicorn via Passenger)
                                          └── data/dixon_coles_model.json
```

---

## 2. Limites réelles du forfait gratuit (vérifiées dans la documentation)

| Contrainte | Conséquence pour le projet |
|---|---|
| **Pare-feu sortant avec liste blanche** | Gemini et PyPI passent. **football-data.org, API-Football et The Odds API sont bloqués** (voir §6). |
| **Docker interdit** (Kit/Pro seulement) | Déploiement natif PHP-FPM + Passenger. |
| **Processus détachés tués** (Sudokill) | `SCHEDULER_RUN_IN_BACKGROUND=false` : les tâches `schedule:run` s'exécutent dans le processus courant. |
| **3 instances web max** | Deux sites suffisent ; aucun besoin d'en créer d'autres. |
| **1,5 Go de stockage** | `node_modules` supprimé après build ; `vendor/` ~40 Mo ; dépendances Python ~250–350 Mo. |
| **2 Go de trafic sortant/mois** | Suffisant pour les cotes et Gemini ; à surveiller avec le panneau Info. |
| **Pas de cron système** | Cron utilisateur via SSH (voir §5) ; seules les commandes courtes sont lancées. |
| **Base MariaDB partagée** | `DB_HOST=localhost`, identifiants dans Manage → Database. |

---

## 3. Déployer Laravel (site principal)

### 3.1 Création du site

1. DOM Cloud → **Create a website** → *Upload or Clone*.
2. Coller `https://github.com/idrissMonthe/PRONO-AI`, branche `deployment/domcloud-free`.
3. Choisir le hostname, le domaine, un username.
4. Dans **Deploy → Add a deployment task**, coller le contenu de `domcloud.yml`.

### 3.2 Variables et clé (une seule fois, en SSH)

```bash
cd ~/public_html
cp .env.domcloud.example .env
nano .env                 # renseigner DB_*, APP_URL, AI_SERVICE_URL, clés fournisseurs
php artisan key:generate  # écrit APP_KEY uniquement dans le .env serveur
```

`APP_KEY` ne doit **jamais** être commité : elle vit uniquement dans le `.env`
du serveur (ou une variable d'environnement réelle, qui a priorité).

### 3.3 Base de données

```bash
# Créer la base dans Deploy (features: - mysql) puis :
php artisan migrate --force
```

Le script de déploiement rejoue `migrate --force` à chaque déploiement : les
migrations sont idempotentes, celles déjà appliquées sont ignorées.

### 3.4 Vérifications

```bash
php artisan oracle:check-providers     # pare-feu sortant
php artisan about                      # APP_ENV=production, cache actif
curl -I https://<domaine-laravel>/
```

---

## 4. Déployer FastAPI (second site)

1. Créer **un second site** DOM Cloud, même dépôt, même branche.
2. Coller `deployment/domcloud/ai-deploy.yml` dans son onglet Deploy.
3. En SSH, créer le fichier d'environnement **du service** (jamais commité) :

```bash
cd ~/public_html
cat > ai-service/.env <<'EOF'
AI_SERVICE_SECRET=<même valeur que Laravel>
PREDICTION_ALLOW_LIMITED_DATA_PREDICTIONS=true
EOF
```

4. Redémarrer Passenger après toute modification de code ou d'environnement :

```bash
restart
```

5. Vérifier :

```bash
curl https://<domaine-fastapi>/v1/health
curl https://<domaine-fastapi>/v1/ready
curl -i https://<domaine-fastapi>/v1/predict   # doit répondre 401 sans secret
```

`MODEL_STATE_PATH` pointe sur `~/public_html/ai-service/data/dixon_coles_model.json`.
Le fichier livré dans le dépôt est chargé au démarrage ; il est réécrit à chaque
entraînement réussi (`/v1/train`).

### Si Passenger renvoie 502 au démarrage

Uvicorn charge NumPy/SciPy/Pandas : le premier démarrage peut dépasser le délai
Passenger. Utiliser le mode NOHUP documenté par DOM Cloud :

```yaml
app_start_command: proxfix env PORT=$PORT python -m uvicorn app.main:app --host 127.0.0.1 --port $PORT
env_var_list:
  - NOHUP=1
```

Après changement, exécuter `restart` en SSH.

---

## 5. Planification (cron utilisateur)

Le forfait gratuit n'ouvre pas de crontab système, mais l'utilisateur peut créer
ses propres tâches via `crontab -e` (SSH) :

```cron
* * * * * cd ~/public_html && php artisan schedule:run >> ~/logs/scheduler.log 2>&1
```

Avec `SCHEDULER_RUN_IN_BACKGROUND=false`, chaque tâche s'exécute sans processus
détaché. Les tâches les plus lourdes (entraînement, backtest) restent
quotidiennes ; aucune boucle de ping n'a été ajoutée.

Alternative si les tâches longues sont interrompues : les lancer manuellement
depuis SSH, une fois par jour, dans cet ordre :

```bash
php artisan oracle:sync-matches
php artisan oracle:sync-odds
php artisan oracle:generate-predictions --limit=20
php artisan oracle:generate-parlays
php artisan oracle:train-prediction-model
```

---

## 6. Pare-feu sortant : les fournisseurs essentiels

La liste blanche officielle (`domcloud/bridge`, `sites.conf`) contient
`generativelanguage.googleapis.com`, `pypi.org`, `github.com`… mais **pas** :

| Fournisseur | Domaine exact à faire autoriser |
|---|---|
| football-data.org | `api.football-data.org` |
| API-Football | `v3.football.api-sports.io` |
| The Odds API | `api.the-odds-api.com` |

Conséquences sur le forfait gratuit, sans contournement :

- les synchronisations échouent avec une erreur réseau explicite, journalisée ;
- les pages continuent de fonctionner avec les données déjà en base ;
- Gemini, le microservice FastAPI et la base restent opérationnels ;
- `php artisan oracle:check-providers` affiche « bloqué » pour ces trois domaines.

**Pour les activer** : upgrade Lite (une fois, 4,5 $) puis désactiver le
pare-feu du site dans *Check → Check Firewall*, ou attendre l'ajout des domaines
à la liste blanche publique (DOM Cloud accepte les propositions via PR).

Aucune intégration n'a été supprimée ni remplacée par des données fictives.

---

## 7. Sauvegarde et restauration

### Base MariaDB

```bash
# Sauvegarde (SSH, hors du dossier public)
mysqldump -h localhost -u <utilisateur> -p <utilisateur>_db \
  --single-transaction --routines --triggers > ~/backup-prono-$(date +%F).sql

# Restauration
mysql -h localhost -u <utilisateur> -p <utilisateur>_db < ~/backup-prono-2026-11-14.sql
```

Depuis la machine locale, exporter la base de développement :

```bash
docker exec -e MYSQL_PWD="$(grep '^DB_PASSWORD=' .env | cut -d= -f2)" \
  pronoia-db mysqldump -uroot --single-transaction --routines prono \
  > prono-local.sql
```

Puis importer sur DOM Cloud (le fichier n'est jamais commité : `.gitignore`
exclut `*.sql`). Vérifier la compatibilité MariaDB 10.11 : toutes les migrations
utilisent des types standard MySQL 8 (`json`, `enum`, index composites).

### Modèle Python

```bash
cp ~/public_html/ai-service/data/dixon_coles_model.json \
   ~/backup-dixon-coles-$(date +%F).json
```

Le fichier est aussi versionné à titre de référence initiale.

---

## 8. Ce qui n'est pas faisable sur le forfait gratuit

| Fonctionnalité | Statut |
|---|---|
| Synchronisation football-data.org / API-Football / The Odds API | **bloquée par le pare-feu** tant que les domaines ne sont pas autorisés |
| Redémarrage automatique de FastAPI après modification | manuel (`restart`) |
| Processus permanents (workers de queue dédiés) | non — la queue reste `database`, traitée ponctuellement |
| Docker Compose | non (Kit/Pro uniquement) |
| Entraînement lourd (SciPy) | possible mais lent ; dépendances Python ~300 Mo |

---

## 9. Tests réalisés avant livraison

- `php artisan test` (PHPUnit complet).
- `pytest` du microservice.
- Démarrage Laravel en `APP_ENV=production` avec `config:cache`, `route:cache`.
- Démarrage Uvicorn sur un port dynamique, `/v1/health`, `/v1/ready`, refus 401
  sans `X-Service-Secret`.
- Sauvegarde/rechargement du modèle Dixon-Coles.
- Vérification qu'aucun secret n'est suivi par Git.

Ce qui **n'a pas été testé** : l'environnement DOM Cloud lui-même (pas d'accès),
et l'appel réel aux fournisseurs externes depuis son pare-feu — d'où la commande
`oracle:check-providers` à exécuter sur place.
