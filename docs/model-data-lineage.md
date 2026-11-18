# Traçabilité données → modèles → marchés

Chaque ligne décrit un chemin **vérifié dans le code**, pas une intention.
Format : source → connecteur → stockage → feature → modèle → marché.

## 1. Résultats et scores (Dixon-Coles, Elo)

| Élément | Détail |
|---|---|
| Source | football-data.org, API-Football, OpenFootball, OpenFootball World, StatsBomb |
| Connecteur | `SyncMatchesCommand`, `SyncApiFootballCommand`, `OpenFootballConnector`, `OpenFootballWorldConnector`, `StatsBombConnector` |
| Stockage | `matches` (`home_score`, `away_score`, `match_date_utc`, `data_source`, `external_id`) + `provider_entity_maps` |
| Feature | `TrainPredictionModelCommand` → lignes `home_team/away_team/home_goals/away_goals/days_ago/is_neutral` |
| Modèle | `DixonColesModel` (`ai-service/app/core/poisson_regressor.py`), `EloEngine` |
| Marchés | `1x2`, `double_chance`, `btts`, `total_goals`, `team_totals`, `asian_handicap` |

Preuve : 534 rencontres terminées en base, 407 dans le dataset de backtest, log loss 1,0322.

## 2. Scores à la mi-temps (périodes)

| Élément | Détail |
|---|---|
| Source | football-data.org (`score.halfTime`), OpenFootball (`score.ht`), StatsBomb (dérivé des événements, période 1) |
| Connecteur | `NormalizedMatch::htHomeScore/htAwayScore`, `StatsBombConnector::eventStats()['ht_goals']` |
| Stockage | `matches.ht_home_score`, `matches.ht_away_score` |
| Feature | `GeneratePredictionsCommand::periodContext()` → `first_half_share`, `sample_size` (≥ 5 matchs, borné 0,30–0,62) |
| Modèle | `PeriodModel` (`period_model.py`), matrice jointe MT/FT |
| Marchés | `ht_1x2`, `htft`, `ht_over_under`, `goal_in_both_halves`, `draw_in_at_least_one_half` |

Preuve : 154 rencontres avec score MT ; `period_share` transmis dans `/v1/predict` ;
`test_market_engine.py::test_engine_uses_period_and_count_data_when_available` vérifie `ht_1x2` et `htft`.

## 3. Tirs, tirs cadrés, xG

| Élément | Détail |
|---|---|
| Source | StatsBomb (`Shot`, `shot.statsbomb_xg`, `shot.outcome`) |
| Connecteur | `StatsBombConnector::eventStats()` (agrégation par équipe et par joueur) |
| Stockage | `team_match_stats.shots/shots_on_target/xg`, `player_match_stats.shots/shots_on_target/xg` |
| Feature | `countContext()` (moyenne + variance, ≥ 3 observations), `XgFeatureBuilder` |
| Modèle | `count_models.CountProfile` (Poisson/binomiale négative), `xg_features.adjust_lambdas_with_xg` |
| Marchés | `total_shots`, `team_total_shots`, `shots_on_target`, `player_total_shots`, `player_shots_on_target` |

Preuve : 312 lignes renseignées ; `test_predict_publishes_statistics_markets_from_fouls_and_offsides`
vérifie la publication à partir de `counts`.

## 4. Corners

| Élément | Détail |
|---|---|
| Source | StatsBomb (`Pass` + `pass.type.name = Corner`) |
| Connecteur | `StatsBombConnector::eventStats()` → `corners` par équipe |
| Stockage | `team_match_stats.corners` |
| Feature | `countContext()` → `counts.corners.{home,away}.{mean,variance,sample_size}` |
| Modèle | `count_models` (binomiale négative si variance > moyenne, sinon Poisson) |
| Marchés | `total_corners`, `team_total_corners`, `corners_handicap`, `most_corners` |

Preuve : import MySQL réel → 4 à 8 corners par équipe et par match ; marchés publiés dès que
les deux équipes ont ≥ 3 observations (`test_predict_with_counts_publishes_corners_and_cards`).

## 5. Cartons et discipline

| Élément | Détail |
|---|---|
| Source | StatsBomb (`Foul Committed` + `foul_committed.card`, `Bad Behaviour` + `bad_behaviour.card`) |
| Connecteur | `StatsBombConnector::applyCard()` (second jaune = jaune + rouge) |
| Stockage | `team_match_stats.yellow_cards/red_cards`, `player_match_stats.yellow_cards/red_cards` |
| Feature | `countContext()` → `counts.yellow_cards`, `counts.red_cards`, `counts.cards` (jaune + rouge, uniquement si les deux sont présents) |
| Modèle | `count_models` |
| Marchés | `total_yellow_cards`, `total_red_cards`, `total_cards`, `team_total_cards`, `cards_handicap`, `sending_off`, `player_carded` |

Preuve : 0 à 4 jaunes et 0 à 2 rouges par équipe sur l'échantillon MySQL ; `player_carded`
alimenté par `cards_per90` (voir §7).

## 6. Fautes et hors-jeu

| Élément | Détail |
|---|---|
| Source | StatsBomb (`Foul Committed`, `Pass` + `pass.outcome.name = Pass Offside`) |
| Connecteur | `StatsBombConnector::eventStats()` |
| Stockage | `team_match_stats.fouls`, `team_match_stats.offsides` (nouvelle colonne) |
| Feature | `countContext()` (colonnes filtrées par `Schema::hasColumn`, donc une base non migrée continue de fonctionner) |
| Modèle | `count_models` |
| Marchés | `fouls`, `offsides` |

Preuve : `test_predict_publishes_statistics_markets_from_fouls_and_offsides` vérifie que les
marchés `fouls` et `offsides` sont publiés avec `data_sources = ["team_match_stats"]`.

## 7. Joueurs

| Élément | Détail |
|---|---|
| Source | StatsBomb (`Starting XI` → `tactics.lineup`, `Substitution`, `Shot`, cartons) |
| Connecteur | `StatsBombConnector::eventStats()['players']` (identité, poste, titularisation, minutes, buts, tirs, xG, cartons) |
| Stockage | `player_match_stats` (unique `match_id + provider + external_player_id`) |
| Feature | `GeneratePredictionsCommand::playerContext()` → `players[]` avec `sample_size`, `start_probability`, `expected_minutes`, `goals_per90`, `shots_per90`, `shots_on_target_per90`, `cards_per90`, `lineup_confirmed` |
| Modèle | `player_engine.player_markets()` (≥ 8 matchs, titularisation ≥ 0,60 ou composition confirmée) |
| Marchés | `player_anytime_scorer`, `player_two_goals`, `player_first_scorer`, `player_last_scorer`, `player_total_shots`, `player_shots_on_target`, `player_carded` |

Preuve : 84 lignes joueurs importées en MySQL ; `test_predict_publishes_player_markets_from_real_player_history`
vérifie `player_anytime_scorer` et `player_carded` à partir du payload réel.

## 8. Cotes

| Élément | Détail |
|---|---|
| Source | The Odds API |
| Connecteur | `SyncOddsCommand`, `UpdateOddsMovementCommand` |
| Stockage | `odds_snapshots` (4 278 lignes), `bookmakers` |
| Feature | `OddsService::proportionalProbabilities`, `latestComplete1N2Set` |
| Modèle | probabilités implicites dévigorisées (`devig.py`) |
| Marchés | 1X2, totals, BTTS, double chance, handicaps ; combinés uniquement avec cote bookmaker réelle |

## 9. Ce qui n'est **pas** consommé (assumé)

- `possession_pct` : colonne existante, jamais alimentée par les connecteurs actuels → aucun
  marché ne l'utilise.
- `player_availabilities` : alimenté manuellement (30 lignes), utilisé pour la pénalité
  d'absence et `lineup_confirmed`, pas pour les marchés joueurs historiques.
- Tracking (OpenPlay/Metrica) : non intégré, aucun marché correspondant.
- Arbitres : aucune source intégrée ne les fournit → aucun modèle disciplinaire arbitral.

## 10. Règle de non-invention

Toute valeur absente reste `NULL` en base et **n'est pas transmise** au service Python.
`countContext()` exige ≥ 3 observations par équipe et par colonne ; `playerContext()` exige
≥ 5 matchs avec temps de jeu connu ; `periodContext()` exige ≥ 5 scores de mi-temps.
Sans ces seuils, la famille correspondante n'est pas publiée (statut `conditional`,
`insufficient` ou `unsupported` du registre).
