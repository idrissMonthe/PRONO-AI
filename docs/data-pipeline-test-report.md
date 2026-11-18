# Rapport de tests du pipeline de données

Exécution : 18 novembre 2026. Toutes les valeurs ci-dessous proviennent de commandes
réellement exécutées ; aucune n'est estimée.

## 1. Tests automatisés

### PHP / Laravel

```
php artisan test --without-tty
Tests: 137 passed (727 assertions) — 70,64 s
```

Dont les nouveaux tests de cette mission (`tests/Feature/StatsBombEnrichmentTest.php`) :

| Test | Ce qu'il prouve |
|---|---|
| `event_stats_extract_counts_lineups_and_minutes` | corners, fautes, hors-jeu, cartons, compositions, remplacements, minutes (90/60/30), buts, score MT dérivés du fichier d'événements |
| `import_persists_counts_players_and_halftime_score_idempotently` | persistance en base, 4 lignes joueurs, score MT 1-0, **seconde exécution sans doublon** (1 match, 2 lignes stats, 4 lignes joueurs) |
| `missing_events_do_not_create_artificial_zeros` | fichier d'événements absent → `home/away = null`, aucun zéro inventé |

Les 8 tests existants de `MultiSourceImportTest` restent verts (dont la reprise après 503 et
la pagination `--offset`).

### Python / FastAPI

```
cd ai-service && ./venv/Scripts/python.exe -m pytest -q
1 failed, 94 passed — 15,22 s
```

Échec unique et **préexistant** : `test_statistical_core.py::test_sparse_team_prediction_can_use_a_strong_market_snapshot`
(attend `medium`, obtient `low`). Sans lien avec ce travail : il porte sur le niveau de
confiance d'une prédiction à historique sparse, code non modifié ici.

Nouveaux tests Python :

| Test | Ce qu'il prouve |
|---|---|
| `test_predict_publishes_statistics_markets_from_fouls_and_offsides` | `counts.fouls/offsides/shots` → marchés `fouls`, `offsides` avec `data_sources = ["team_match_stats"]` |
| `test_predict_publishes_player_markets_from_real_player_history` | payload `players` (issu de `player_match_stats`) → `player_anytime_scorer`, `player_carded`, famille `players` dans la couverture |

## 2. Validation MySQL / MariaDB (base isolée)

Environnement : Docker Desktop actif, conteneurs `pronoia-db` (MySQL 8.0), `pronoia-laravel`,
`pronoia-ai`. Base de test dédiée **`prono_test`** créée pour l'occasion ; la base `prono`
n'a reçu **aucun import de test**.

| Étape | Commande | Résultat |
|---|---|---|
| Création base isolée | `CREATE DATABASE prono_test` | OK |
| Migrations | `DB_DATABASE=prono_test php artisan migrate --force` | OK, nouvelle migration `2026_11_18_000001` en 588 ms |
| Import réel StatsBomb | `DB_DATABASE=prono_test php artisan oracle:import-statsbomb --stats --limit=3` | 380 reçus, 0 importés, 380 mis à jour, 0 rejeté, 0 divergent, 3 matchs avec statistiques |
| Écritures vérifiées | `SELECT ... FROM team_match_stats` | 6 lignes enrichies : corners 4-8, fautes 7-20, hors-jeu 0-5, jaunes 0-4, rouges 0-2 |
| Joueurs | `SELECT ... FROM player_match_stats` | 84 lignes, minutes 0-95, buts/tirs/xG/cartons renseignés |
| Score MT dérivé | `SELECT ht_home_score, ht_away_score` | renseigné pour les 3 rencontres |
| Contraintes | `unique(match_id, provider, external_player_id)` | respectée (aucun doublon après réimport) |
| Base utilisateur intacte | `SELECT COUNT(*) FROM prono.matches` | 582 avant et après (aucune écriture de test) |

Note : `prono_test` contenait déjà des rencontres d'une session antérieure ; l'import a donc
mis à jour 380 lignes au lieu d'en créer. C'est précisément le comportement idempotent attendu.

## 3. Chaîne de bout en bout sur données réelles

Script : `storage/tmp/e2e_real_data.php` (hors dépôt, outil de vérification).

1. Lecture de `team_match_stats` en MySQL pour un match réel (Arsenal FC – Aston Villa, 2016-05-15).
2. Construction du contexte par les **méthodes réelles** de `GeneratePredictionsCommand`
   (`countContext`, `periodContext`, `playerContext`) via réflexion.
3. Appel HTTP réel à `POST /v1/predict` du service FastAPI local (aucun mock).

Résultat observé :

```
Comptages transmis: shots, shots_on_target
Periodes: absent | Joueurs: 0
Marchés publiés: 0
Statut: abstain=false raison="sparse_team_history_without_market" qualite="degraded"
```

Interprétation honnête : la donnée arrive bien jusqu'au service, mais le modèle Dixon-Coles
du service n'avait pas ces clubs dans son état entraîné, donc **aucun marché n'est publié** —
c'est le comportement anti-invention attendu, pas une réussite de bout en bout.

Deux corrections ont été apportées pendant cette vérification :

- `countContext()` plantait en SQL (`Unknown column 's.offsides'`) sur une base non migrée →
  filtrage par `Schema::hasColumn` avec cache statique ;
- `POST /v1/train` rejetait en 422 toute rencontre de plus de 3 650 jours
  (`days_ago le=3650`), ce qui rendait **impossible** l'entraînement sur StatsBomb 2015-16 →
  borne portée à 36 500 jours (`ai-service/app/schemas.py`).

Après correction, l'entraînement sur 300 rencontres réelles a été lancé ; il dépasse la limite
de 5 minutes d'une commande interactive et doit être exécuté en tâche de fond (voir §6).

## 4. Tests de reprise et d'erreur

| Scénario | Comportement vérifié |
|---|---|
| HTTP 503 sur un fichier d'événements | nouvelle tentative, puis succès (test existant) |
| Fichier d'événements 404 | `home/away = null`, import du match conservé, statut `partial` |
| Match non lié en base | `importStats()` retourne `false`, aucune écriture |
| Réimport du même match | `updateOrCreate` / `updateOrInsert`, aucun doublon |
| Base sans colonne `offsides` | requête de comptage sans la colonne, pas d'erreur SQL |

## 5. Ce qui n'a pas été testé

- Import massif des 380 matchs StatsBomb avec événements sur MySQL (volumétrie réseau :
  plusieurs centaines de Mo). Seul un échantillon de 3 matchs a été validé.
- MariaDB (seul MySQL 8.0 est présent dans `docker-compose.yml`).
- Tests de bout en bout avec génération réelle de pronostics officiels et affichage dashboard :
  bloqué par l'absence d'historique des clubs à venir dans le modèle (voir §3).
- Live : aucun flux temps réel intégré.

## 6. Commandes de reproduction

```bash
# Tests
php artisan test --without-tty
cd ai-service && ./venv/Scripts/python.exe -m pytest -q

# Validation MySQL isolée
docker exec pronoia-db sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "CREATE DATABASE IF NOT EXISTS prono_test"'
docker exec -e DB_DATABASE=prono_test pronoia-laravel php artisan migrate --force
docker exec -e DB_DATABASE=prono_test pronoia-laravel php artisan oracle:import-statsbomb --stats --limit=3

# Enrichissement de la base de développement (à lancer par le propriétaire)
php artisan oracle:import-statsbomb --stats --limit=50 --offset=0
php artisan oracle:import-statsbomb --stats --limit=50 --offset=50   # etc.

# Entraînement (long : > 5 min, à lancer en tâche de fond)
php artisan oracle:train-model --limit=600 > storage/logs/train.log 2>&1 &
```
