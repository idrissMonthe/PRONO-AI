# Architecture de l'historisation des pronostics

## 1. Principe

Chaque pronostic **réellement publié** est enregistré tel qu'il a été produit. Une nouvelle génération crée de nouvelles lignes ; elle ne modifie ni ne supprime jamais une probabilité déjà publiée.

## 2. Schéma

Migration : `database/migrations/2026_11_18_000003_create_prediction_history_tables.php`

### `forecast_runs`

Une exécution de génération : rencontre, compétition, saison, date de génération, version du modèle, version de calibration, version du feature engineering, `features_as_of`, sources de données, `payload_hash`, nombre de pronostics, statut, contexte (`quality_tier`, `confidence_tier`, mode données limitées, avertissements).

### `prediction_snapshots`

Une sélection publiée :

- rencontre, exécution, prédiction d'origine ;
- `market_key`, `market_group`, `selection`, `line` ;
- `category` (`safe`, `balanced`, `bold`) ;
- `probability` (6 décimales), `fair_odds` ;
- `odds_at_publication`, `odds_provider`, `odds_timestamp_utc` ;
- `model_version`, `calibration_version`, `feature_version` ;
- `coverage_status`, `reliability`, `data_sources`, `context_json` ;
- `published_at_utc` et `content_hash` **unique**.

`content_hash = sha256(match_id | market_key | selection | probabilité à 6 décimales | version du modèle)`. Une republication strictement identique ne crée pas de doublon ; une probabilité différente crée un nouveau snapshot.

### `prediction_settlements`

Règlement séparé du snapshot : `outcome` (`WON`, `LOST`, `VOID`, `HALF_WON`, `HALF_LOST`, `PENDING`), libellé réel, score, date de règlement, source, note. Une ligne par snapshot.

### `combination_snapshots` et `combination_snapshot_legs`

Combiné publié : titre, niveau de risque, probabilité conjointe, cote, EV, version de modèle, date cible, nombre de sélections, statut, `content_hash` unique. Les jambes référencent le snapshot de prédiction correspondant lorsqu'il existe.

## 3. Immutabilité

- Aucune mise à jour de `prediction_snapshots` n'est effectuée après insertion : le service n'écrit que par `firstOrCreate` sur `content_hash`.
- Le règlement vit dans une table distincte : la probabilité annoncée reste intacte même après la fin du match.
- Le snapshot conserve la cote disponible **au moment de la publication** et son horodatage, jamais une cote obtenue plus tard.

Vérifié en production locale : 57 snapshots créés lors de la première génération sont restés identiques après une régénération forcée des mêmes rencontres, qui a ajouté 114 snapshots catégorisés.

## 4. Règlement automatique

`PredictionHistoryService::settlePending()`

- Résultat lu depuis la rencontre (`status = FINISHED` et scores non nuls). Aucune autre source n'est utilisée pour valider un pronostic.
- Règles réutilisées depuis `PredictionSettlementService::selectionOutcome()` : 1N2, double chance, draw no bet, BTTS, Plus/Moins, Plus/Moins asiatique, totaux par équipe, totaux asiatiques par équipe, score exact, combinés internes.
- Handicap asiatique traité dans le service d'historique, quarts de ligne compris : gain, demi-gain, remboursement, demi-perte, perte.
- **Résultat absent → `PENDING`**, avec note explicative. Un marché que les données ne permettent pas de régler reste également `PENDING`. Aucun résultat n'est inventé.
- Combinés : `WON` si toutes les jambes gagnent, `LOST` dès une perte, `VOID` si tout est remboursé, `PARTIAL` en cas de mélange, `PENDING` si une jambe n'est pas réglée.

## 5. Branchement dans le pipeline

- `GeneratePredictionsCommand` appelle `recordPrediction()` après l'enregistrement de la prédiction officielle et de son chaînage de hachage.
- `ParlayService` appelle `recordCombination()` à la création d'un combiné.
- `oracle:prediction-history --settle --report` règle et restitue les performances.

Ces appels sont enveloppés pour qu'une erreur d'historisation ne fasse jamais échouer la publication.

## 6. Interface préservée

Aucune route, aucun contrôleur et aucune vue n'ont été modifiés. L'historisation est purement backend ; les catégories et le dashboard restent inchangés.

## 7. Volume actuel

| Table | Lignes |
|---|---|
| `forecast_runs` | 65 |
| `prediction_snapshots` | 171 (safe 35 · balanced 39 · bold 40 · non catégorisés 57) |
| `prediction_settlements` | 171 (tous `PENDING`, rencontres à venir) |
| `combination_snapshots` | 1 |

## 8. Limites connues

- Les 57 snapshots créés avant la correction du mapping des catégories restent `category = NULL` : c'est le comportement voulu de l'immutabilité.
- `odds_at_publication` n'est renseigné que lorsque le moteur a réellement reçu une cote bookmaker ; sinon il reste nul.
- Aucun backfill rétroactif des pronostics antérieurs à cette mission : ils n'ont pas été publiés par ce dispositif.
