# Validation de l'historisation et des 171 snapshots

## 1. État de départ annoncé

- 171 snapshots
- 35 `safe`, 39 `balanced`, 40 `bold`
- total catégorisé : 114 → **57 éléments inexpliqués**

## 2. Cause exacte des 57 éléments

La ventilation était correcte mais incomplète. `PredictionHistoryService::publishedEntries()`
construit toujours **une entrée de base** — le pronostic officiellement publié — puis y
ajoute les sélections des trois catégories de risque :

```php
$entries = [[
    'market_key' => $market,
    'selection' => $selection,
    'probability' => $probability,
    // aucune clé 'category' avant correction
]];
```

L'entrée de base n'avait pas de `category`. Les 57 lignes sans catégorie sont donc
**exactement une par rencontre** (57 rencontres publiées), et non :

- des doublons — non, l'unicité est garantie par `content_hash` ;
- des prédictions rejetées — non, seules les publications officielles sont enregistrées ;
- des snapshots techniques — non, chaque ligne porte un marché et une sélection réels ;
- une erreur de comptage — non, la somme était juste, la catégorie manquait.

Vérification en base :

```
SELECT COALESCE(category,'(null)'), COUNT(*) FROM prediction_snapshots GROUP BY 1;
(NULL) 57 | balanced 39 | bold 40 | safe 35
```

```
SELECT COUNT(DISTINCT forecast_run_id) runs, COUNT(DISTINCT match_id) matches
FROM prediction_snapshots;   -- 65 runs, 57 matches
```

Ventilation exacte des 171 snapshots :

| Catégorie | Snapshots | Nature |
|---|---|---|
| `published` | 57 | Pronostic officiellement publié (1 par rencontre) |
| `safe` | 35 | Sélection « plus sûre » du moteur Python |
| `balanced` | 39 | Sélection « équilibrée » du moteur Python |
| `bold` | 40 | Sélection « audacieuse » du moteur Python |
| **Total** | **171** | |

Certaines rencontres n'ont pas les trois catégories (marchés non calculables), ce qui
explique 35/39/40 plutôt que 57/57/57. Aucune valeur n'a été inventée pour compléter.

## 3. Correction

1. L'entrée publiée porte désormais `category = 'published'`
   (`PredictionHistoryService::CATEGORY_PUBLISHED`).
2. `performance()` relit les lignes historiques à `category = NULL` comme `published`.
   **Aucune donnée historique n'est réécrite** : la correction est dans la lecture.
3. `performance()` expose `category_counts` pour permettre la réconciliation
   `publié + safe + balanced + bold = total`.
4. La commande affiche désormais `catégorie:published` au lieu d'un `unspecified` opaque.

## 4. Immutabilité vérifiée

- Un nouveau `recordPrediction` avec une probabilité différente crée **une nouvelle ligne** :
  l'ancienne probabilité publiée est conservée (`PredictionHistoryTest`).
- Une republication identique est idempotente (`content_hash`).
- Le règlement vit dans `prediction_settlements` et ne touche jamais aux probabilités.
- Une revue Gemini plus récente ne remplace pas la précédente
  (`GeminiValidationHistoryTest`).

## 5. Règlement réel au 18 novembre 2026

Exécution effective :

```
php artisan oracle:prediction-history --settle --report
```

Résultat :

```
Snapshots : 171 · en attente : 171 · remboursés : 0 · demi-gains : 0 · demi-pertes : 0
```

Aucun règlement anticipé. Les 57 rencontres concernées se jouent du **20 au 24 novembre
2026** ; leurs résultats définitifs n'existent pas encore. Les 171 snapshots restent
`PENDING`, ce qui est le comportement attendu. Aucun résultat n'a été simulé.

## 6. Tests

- `PredictionHistoryTest::test_category_breakdown_reconciles_with_the_snapshot_total`
  (4 snapshots : 1 publié + 3 catégories, somme = total).
- `PredictionHistoryTest::test_legacy_null_category_is_reported_as_published`
  (une ligne historique `NULL` est relue comme `published`, sans réécriture).
- Tests existants conservés : immutabilité, idempotence, règlement gagné/perdu/remboursé,
  résultat manquant → `PENDING`, combiné gagné si toutes les jambes gagnent.

## 7. Prochaine étape

Après le 24 novembre 2026 :

```
php artisan oracle:prediction-history --settle --report
```

Les premiers Brier / Log Loss réels par marché et par catégorie seront alors calculés sur
des résultats définitifs.
