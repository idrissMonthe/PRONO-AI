# PRONO-AI / ORACLE FOOT

PRONO-AI est une application personnelle de recherche statistique sur les matchs de football. Elle synchronise des rencontres et des cotes, produit des estimations multi-marchés et conserve les snapshots de données et de prédictions pour permettre une évaluation rétrospective.

Les prédictions sont expérimentales : elles ne garantissent ni résultat ni rentabilité et doivent être interprétées avec les indicateurs de qualité, les abstentions et les limites de couverture des fournisseurs.

## Architecture

- Laravel 12 / PHP 8.2+ : interface, persistance MySQL ou SQLite, commandes Artisan, snapshots et orchestration.
- FastAPI / Python 3.12 : Dixon-Coles/Poisson, Elo, forme récente, retrait de marge et têtes spécialisées par marché.
- Docker Compose : web, scheduler, ai-service, base de données et dépendances locales.

Le modèle central reste Dixon-Coles. Les marchés 1N2, buts, BTTS et score exact utilisent des têtes spécialisées, tandis que l'ensemble conserve ses poids existants. Le système peut s'abstenir lorsqu'un entraînement, une donnée ou une séparation de probabilités n'est pas suffisamment fiable.

## Sources de données

- football-data.org v4 : calendrier, statuts, scores et compétitions accessibles par le compte.
- API-Football : source séparée pour la CAN et les compétitions africaines
  configurées, avec identifiants externes préfixés pour éviter les collisions.
- The Odds API : snapshots de cotes lorsqu'une ligue et un marché sont disponibles.
- Gemini : explication ou assistance hybride selon la configuration ; il ne remplace pas les probabilités centrales du modèle.

La découverte internationale appelle /competitions, respecte le catalogue réel renvoyé par le compte et est mise en cache. Elle ne fabrique aucun code. Les amicaux ne sont synchronisés que si le catalogue du fournisseur expose réellement une compétition correspondante.

## Installation locale

~~~powershell
Copy-Item .env.example .env
php artisan key:generate
composer install
npm install
npm run build
php artisan migrate --seed
~~~

Pour le service Python :

~~~powershell
cd ai-service
python -m venv .venv
.venv\Scripts\Activate.ps1
pip install -r requirements.txt
pytest
~~~

## Variables importantes

Renseigner les clés dans .env sans les committer :

~~~dotenv
FOOTBALL_DATA_API_KEY=...
FOOTBALL_DATA_COMPETITIONS=PL,PD,BL1,SA,FL1,CL
FOOTBALL_DATA_DISCOVER_INTERNATIONAL=false
FOOTBALL_DATA_CATALOG_CACHE_MINUTES=1440
API_FOOTBALL_KEY=...
API_FOOTBALL_COMPETITIONS=6
API_FOOTBALL_SEASONS=2025
THE_ODDS_API_KEY=...
AI_SERVICE_URL=http://127.0.0.1:8001
AI_SERVICE_SECRET=...
GEMINI_API_KEY=...
PREDICTION_HYBRID_MODE=ASSISTED
PREDICTION_BOOTSTRAP_REPLICATES=0
PREDICTION_BOOTSTRAP_MIN_OBSERVATIONS=20
~~~

Le bootstrap d'incertitude est volontairement désactivé par défaut pour ne pas ralentir chaque prédiction. Une valeur comme 32 l'active ; les résultats sont réutilisés en mémoire et restent indisponibles si l'échantillon ou le nombre de réplications valides est insuffisant. Une absence d'intervalle est affichée comme non estimable, jamais comme une bande heuristique.

## Docker Compose

~~~powershell
docker compose up -d --build
docker compose exec web php artisan migrate --seed
docker compose exec web php artisan oracle:sync-matches
docker compose exec web php artisan oracle:sync-odds
docker compose exec web php artisan oracle:generate-predictions
~~~

Le scheduler exécute les synchronisations, les scores live, le règlement des prédictions, l'entraînement nocturne et l'évaluation de performance. Vérifier les journaux dans storage/logs.

API-Football utilise une requête live globale toutes les 30 minutes et une
synchronisation quotidienne des fixtures afin de rester compatible avec le
quota gratuit de 100 requêtes par jour. La CAN correspond au code 6 et la
saison active doit être ajustée dans API_FOOTBALL_SEASONS lorsqu'une nouvelle
saison est publiée.

## Commandes utiles

~~~text
oracle:sync-matches
oracle:sync-api-football
oracle:sync-api-football --live
oracle:sync-odds
oracle:sync-live-scores
oracle:train-model
oracle:generate-predictions
oracle:football-data-competitions --refresh
oracle:evaluate-prediction-performance
oracle:generate-parlays
~~~

oracle:football-data-competitions --refresh permet de vérifier le catalogue accessible avant d'activer la découverte internationale. La liste manuelle FOOTBALL_DATA_COMPETITIONS reste toujours le secours et les compétitions de clubs déjà configurées sont conservées.

## Tests

~~~powershell
pytest -q ai-service/tests
php artisan test
~~~

Les tests couvrent notamment la convergence réelle de l'optimiseur, la conservation d'un modèle valide après un échec, l'ECE multiclasses et les contrats de synchronisation football-data.org.
