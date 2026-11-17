# Moteur xG, couverture statistique et calibration

Ce document décrit l'exploitation des données Expected Goals (xG) dans
PRONO-IA, les garde-fous contre les fuites temporelles, la stratégie de repli
quand les données manquent, la calibration des probabilités et le protocole de
backtesting walk-forward.

Il complète [multi-source-football-data.md](multi-source-football-data.md), qui
couvre l'import (OpenFootball, OpenFootball World, StatsBomb).

## 1. D'où vient le xG réellement disponible

| Source | xG | Portée | Remarque |
| --- | --- | --- | --- |
| StatsBomb Open Data | Oui, par tir (`shot.statsbomb_xg`) | Compétitions/saisons publiées, historiques | Attribution « data source: StatsBomb » obligatoire |
| OpenFootball (football.json) | Non | Résultats et calendrier | Aucun xG, aucun tir |
| OpenFootball World (Football.TXT) | Non | Résultats et calendrier | Aucun xG, aucun tir |
| football-data.org / API-Football | Non | Live et cotes | Le xG n'est jamais inventé à partir des cotes |

Conséquence : une compétition peut avoir des résultats, des cotes et un
classement **sans aucun xG**. Le moteur doit fonctionner dans les deux cas, ce
qui est le cœur de la section 4.

Le seul chemin d'alimentation du xG est :

```
StatsBomb events (JSON) → StatsBombConnector::shotStats()
    → team_match_stats (match_id, team_id, is_home, shots, shots_on_target, xg)
```

`team_match_stats` conserve la provenance par le biais de `matches.data_source`
et de `provider_entity_maps` : aucune valeur xG n'est écrite sans match lié.

## 2. Variables construites (avant match uniquement)

Module : `ai-service/app/core/xg_features.py`.

Pour chaque équipe et pour une date de référence `as_of` :

| Variable | Calcul | Usage |
| --- | --- | --- |
| `xg_for` | Moyenne pondérée des xG marqués | Force offensive |
| `xg_against` | Moyenne pondérée des xG concédés | Solidité défensive |
| `goals_for`, `goals_against` | Moyennes pondérées des buts réels | Référence |
| `goals_minus_xg` | `goals_for − xg_for` | Sur/sous-performance à la finition |
| `conceded_minus_xg_against` | `goals_against − xg_against` | Sur/sous-performance défensive ou gardien |
| `xg_for_home`, `xg_against_home` | Split domicile | Avantage du terrain réel |
| `xg_for_away`, `xg_against_away` | Split extérieur | Performance hors domicile |
| `xg_trend` | Moyenne des 5 derniers xG marqués − les 5 précédents | Tendance offensive |
| `xg_per_shot`, `shots_per_match` | Somme xG / somme tirs ; tirs par match | Qualité des occasions |
| `coverage`, `reliability` | Palier et `n / (n + 6)` | Confiance à accorder au profil |

Pondération temporelle : `w = exp(-xi · jours)`, avec `xi = ln(2)/365`, la même
décroissance que Dixon-Coles. Une observation d'il y a un an pèse moitié moins
qu'une observation du jour.

Shrinkage empirique-bayésien : chaque taux est ramené vers la moyenne de la
compétition avec un prior de 6 matchs (`(valeur · poids + prior · 6) / (poids + 6)`).
Une équipe avec 3 matchs ne peut donc pas produire un profil extrême.

## 3. Absence de fuite temporelle (data leakage)

Règles appliquées dans le code, et vérifiées par des tests :

1. `features(team, as_of)` ne retient que les matchs dont
   `match_date < as_of` (**inégalité stricte**). Un match du même jour est
   exclu : l'heure de coup d'envoi peut être inconnue.
2. Le match à prédire n'entre jamais dans ses propres variables, même s'il est
   présent dans l'historique chargé.
3. Les matchs postérieurs à `as_of` sont ignorés, même s'ils sont déjà stockés
   (test : ajouter deux matchs futurs ne modifie pas l'instantané de features).
4. `train()` reconstruit l'historique avec `match_date = aujourd'hui − days_ago`,
   donc l'entraînement n'expose que le passé du snapshot.
5. Le backtest utilise le même constructeur : chaque prédiction d'un match ne
   voit que les matchs strictement antérieurs, y compris pendant les fenêtres
   de validation et de test.

Limite explicite : le service de prédiction suppose que le match demandé est
**futur** par rapport au snapshot d'entraînement. Rejouer une prédiction sur un
match déjà terminé et déjà présent dans l'historique n'est pas un cas
d'usage garanti.

## 4. Intégration à Dixon-Coles (bornée, désactivée par défaut)

`adjust_lambdas_with_xg()` mélange les buts attendus Dixon-Coles avec des buts
attendus issus du xG, **dans l'espace logarithmique** :

```
λ_xg_domicile = base_domicile · (attaque_domicile / base_domicile) · (défense_extérieur / base_domicile)
log λ = (1 − w_eff) · log λ_DC + w_eff · log λ_xG
|Δ log λ| ≤ 0.12   (MAX_LOG_SHIFT)
```

- Les forces Dixon-Coles ne sont **pas** remplacées : les paramètres
  (`attack_strengths`, `defense_strengths`, `rho`, `mu`, `home_adv`) et leurs
  pondérations restent inchangés.
- `w_eff = XG_ADJUSTMENT_WEIGHT · palier_min · fiabilité_min`, où
  `palier_min` vaut 0 (aucun), 0,35 (thin), 0,70 (partial), 1,0 (full).
- `XG_ADJUSTMENT_WEIGHT` vaut **0 par défaut** : sans preuve expérimentale, le
  moteur publie exactement Dixon-Coles. Le backtest ne l'active qu'en cas de
  gain significatif.

Le déplacement est ensuite encore borné par `project_lambdas_to_dixon_coles`
(±0,15 en log) : le xG ne peut donc jamais imposer à lui seul un changement
majeur de la matrice de scores.

## 5. Données manquantes : repli et niveau de couverture

| Situation | Comportement | `reason` |
| --- | --- | --- |
| Poids xG à 0 | Lambdas Dixon-Coles inchangés | `xg_adjustment_disabled` |
| Aucun xG mesuré dans la compétition | Baseline indisponible (< 3 observations) | `league_xg_baseline_unavailable` |
| Une des deux équipes sans xG | Aucun ajustement, pas de pénalité | `missing_team_xg_coverage` |
| Profil incomplet | Aucun ajustement | `incomplete_team_xg_profile` |
| Couverture trop faible | Poids effectif ~0 | `effective_xg_weight_negligible` |
| Couverture suffisante | Ajustement borné appliqué | `xg_adjustment_applied` |

Chaque réponse `/v1/predict` expose désormais un bloc `xg` :

```json
{
  "applied": false,
  "reason": "xg_adjustment_disabled",
  "coverage": "full",
  "coverage_detail": {"home": "full", "away": "partial", "combined": "partial",
                       "home_matches": 14, "away_matches": 8, "league_matches": 44},
  "home_profile": {"xg_for": 1.62, "goals_minus_xg": -0.11, "..." : 0},
  "competition_reference": "PL"
}
```

Une prédiction sans xG reste donc **affichable et honnête** : elle indique sa
couverture au lieu de la masquer. Aucun xG nul, moyen ou estimé n'est injecté
pour « faire fonctionner » le modèle.

## 6. Calibration

Module : `ai-service/app/core/calibration.py`.

- **Platt scaling** : `p' = σ(a · logit(p) + b)` par classe, puis
  renormalisation. Le coefficient est régularisé (L2) vers l'identité.
- **Isotonic regression** : PAV par classe, valeurs non décroissantes, puis
  renormalisation.
- Les deux refusent de s'ajuster sous **50 observations** et se comportent
  alors comme une fonction identité : un petit échantillon de validation ne
  peut pas déformer les probabilités publiées.
- L'ajustement est fait **uniquement sur la fenêtre de validation**, jamais sur
  le test.

## 7. Protocole de backtesting walk-forward

Script : `ai-service/scripts/backtest_walkforward.py`.

1. Tri chronologique strict des matchs.
2. Les `--min-train` premiers matchs servent uniquement à entraîner.
3. Fenêtre de validation : 40 % des matchs restants → choix du poids xG et
   ajustement de la calibration.
4. Fenêtre de test : le reste → une seule évaluation, sans re-choix.
5. Réentraînement tous les `--refit-every` matchs (20 par défaut), toujours sur
   les matchs strictement antérieurs.
6. Métriques : Log Loss, Brier, RPS, ECE (une-vs-reste par classe), précision
   1N2 et double chance, Over/Under 2.5, BTTS, totaux par équipe.
7. Significativité : test de Diebold-Mariano sur les pertes par match.
8. ROI : **non calculé**, car le jeu de données ne contient pas de cotes
   historiques vérifiables. Aucun prix n'est inventé.

### Commandes

```bash
# 1. Importer un jeu de données réel (MySQL/MariaDB)
php artisan oracle:import-openfootball --season=2025-26 --competition=en.1.json
php artisan oracle:import-statsbomb --stats --limit=400

# 2. Exporter un jeu chronologique (xG inclus quand il existe)
php artisan oracle:export-xg-dataset --league=PL --output=storage/app/backtest/pl.json

# 3. Backtest
cd ai-service
python -m scripts.backtest_walkforward \
  --dataset ../storage/app/backtest/pl.json \
  --output-json ../storage/app/backtest/report.json \
  --output-md ../docs/backtests/walkforward-report.md \
  --min-train 120 --xg-weights 0,0.25,0.5,1.0
```

### Résultats obtenus (Premier League 2025-2026, 380 matchs)

Rapport complet et reproductible : [backtests/walkforward-report.md](backtests/walkforward-report.md).

Fenêtre de test : 156 matchs.

| Modèle | Log Loss | Brier | RPS | ECE | Précision 1N2 | Précision DC |
| --- | --- | --- | --- | --- | --- | --- |
| Dixon-Coles (référence) | 1,060 | 0,638 | 0,217 | 0,100 | 43,6 % | 73,7 % |
| Dixon-Coles + Platt | 1,074 | 0,647 | 0,218 | 0,116 | 39,7 % | 73,7 % |
| Dixon-Coles + Isotonic | 1,074 | 0,646 | 0,217 | 0,102 | 43,6 % | 73,7 % |

- Diebold-Mariano référence vs meilleure calibration : `p = 0,42` →
  **aucun gain significatif**. La calibration reste donc désactivée par défaut.
- Marchés dérivés (référence) : Over/Under 2.5 log loss 0,722 (précision 46,2 %),
  BTTS 0,689 (52,6 %), totaux équipe > 0,5 : 0,544 (76,3 %).
- **Comparaison xG : non concluante et non publiée.** Aucun xG n'était présent
  dans ce jeu de données (import StatsBomb non abouti, voir section 8). Le poids
  xG sélectionné est donc 0 et le moteur reste Dixon-Coles.

## 8. Limites connues

- **Le xG n'a pas pu être validé sur données réelles dans cet environnement.**
  Le téléchargement des événements StatsBomb (≈ 3,1 Mo par match) a plafonné
  entre 16 et 24 Ko/s, soit plusieurs heures pour une seule saison. Le code
  n'a donc **pas** été déclaré meilleur : il est implémenté, testé
  unitairement, désactivé par défaut, et la commande de comparaison est
  fournie. Ne pas activer `XG_ADJUSTMENT_WEIGHT` sans avoir produit le rapport.
- Le backtest ne porte que sur une saison et une compétition : 156 matchs de
  test. Un écart de 0,01 de log loss reste dans le bruit.
- Aucune cote historique fiable : le ROI n'est pas mesuré.
- Le modèle spécialisé par marché (`market_specialists`) n'est pas isolé dans le
  backtest, qui évalue Dixon-Coles pur + xG + calibration.
- Les statistiques autres que xG (tirs, corners, possession) ne sont pas
  encore injectées : `shots` et `shots_on_target` alimentent uniquement
  `xg_per_shot`, qui sert au diagnostic de qualité des occasions. Aucune
  variable n'est ajoutée « parce qu'elle est disponible ».
- Attribution StatsBomb obligatoire pour toute publication dérivée.

## 9. Variables et fichiers

| Fichier | Rôle |
| --- | --- |
| `ai-service/app/core/xg_features.py` | Fenêtres glissantes, splits domicile/extérieur, couverture, ajustement borné |
| `ai-service/app/core/calibration.py` | Platt scaling, isotonic regression, garde-fous d'échantillon |
| `ai-service/scripts/backtest_walkforward.py` | Protocole walk-forward, métriques, Diebold-Mariano, rapport Markdown |
| `ai-service/app/main.py` | `/v1/train` accepte le xG, `/v1/predict` expose le bloc `xg` |
| `app/Console/Commands/ExportXgDatasetCommand.php` | Export chronologique vers JSON |
| `app/Console/Commands/TrainPredictionModelCommand.php` | Envoie xG, tirs et compétition au service Python |
| `tests/Feature/ExportXgDatasetCommandTest.php` | Vérifie xG exporté, valeurs manquantes nulles |
| `ai-service/tests/test_xg_features.py` | Fuite temporelle, fenêtres, splits, repli, plafond |
| `ai-service/tests/test_calibration.py` | Normalisation, monotonie, seuil minimal |
| `ai-service/tests/test_xg_api.py` | Poids 0 par défaut, activation, couverture, persistance |
