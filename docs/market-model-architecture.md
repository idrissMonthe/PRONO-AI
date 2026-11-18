# Architecture des moteurs de marchés

## 1. Principe

Le moteur ne crée pas un modèle par ligne. Il dispose de **distributions** par
famille et en dérive toutes les variantes de lignes et de sélections :

```
données Laravel ──► /v1/train ──► Dixon-Coles, Elo, forme, xG, périodes
                                        │
match à venir ────► /v1/predict ──► λ domicile/extérieur
                                        │
                              matrice de scores Dixon-Coles
                    ┌───────────────────┼────────────────────┐
                    ▼                   ▼                    ▼
            ScoreProbability      PeriodPrediction     CountPrediction
            (1X2, buts,           (mi-temps, HT/FT)    (corners, cartons,
             handicaps, scores)                          tirs, fautes)
                    └───────────────────┼────────────────────┘
                                        ▼
                              MarketRegistry (statuts)
                                        ▼
                          MarketEngine (élite + couverture)
                                        ▼
                        SelectionEngine (prudent/équilibré/audacieux)
                                        ▼
                        CombinationEngine (joint même match)
                                        ▼
                         Laravel : catégories + combinés
```

## 2. Composants Python

| Module | Rôle |
| --- | --- |
| `app/core/dixon_coles.py` | Matrice de scores, 1N2, totaux, handicaps, score exact, combinaisons jointes |
| `app/core/poisson_regressor.py` | Estimation des forces d'attaque/défense, ρ, intervalles bootstrap |
| `app/core/period_model.py` | Part réelle des buts en 1re mi-temps, matrices par période, joint HT/FT |
| `app/core/count_models.py` | Poisson / binomiale négative, lignes, règlement push/demi-gain |
| `app/core/time_events.py` | Premier/dernier but par intervalle, but avant une minute |
| `app/core/player_engine.py` | Taux individuels (buts, tirs, cartons), participation |
| `app/core/market_registry.py` | Catalogue central : id, famille, période, données, modèle, statut |
| `app/core/market_engine.py` | Génération de toutes les sélections calculables + rapport de couverture |
| `app/core/selection_engine.py` | Fiabilité et classement en trois catégories, anti-doublons/contradictions |
| `app/core/combination_engine.py` | Probabilité conjointe des combinés, refus des dépendances non résolues |

## 3. ScoreProbabilityEngine

Toutes les probabilités de buts proviennent d'une seule matrice
`P(i, j)` (Dixon-Coles, correction τ sur 0-0, 0-1, 1-0, 1-1). Aucune
probabilité de marché n'est calculée par multiplication de marges :

- 1X2 = masses triangulaires ;
- total = somme des diagonales ;
- handicap = différence de buts ;
- BTTS = i ≥ 1 et j ≥ 1 ;
- score exact = cellule ;
- résultat + total = cellule ∩ diagonale.

Les lignes sont générées autour de la moyenne et filtrées : une ligne dont une
seule face est publiable est écartée pour éviter une sélection sans complément.

## 4. PeriodPredictionEngine

1. La part de buts de la première période est **mesurée** :
   `Σ buts MT / Σ buts FT` sur les rencontres de la compétition ayant un score
   à la pause, bornée à [0,30 ; 0,62].
2. Chaque mi-temps reçoit ses propres λ (`λ × part`, `λ × (1 − part)`).
3. Deux matrices sont calculées, puis :
   - marchés de chaque période (1X2, DC, totaux, BTTS, score exact, handicap) ;
   - HT/FT par convolution jointe : `P(HT=r1, FT=r2)` ;
   - but dans les deux mi-temps, nul dans au moins une mi-temps, période la
     plus prolifique.

Sous 5 matchs avec score mi-temps, la famille n'est pas publiée.

## 5. CornersPredictionEngine / DisciplinePredictionEngine /
ShotsPredictionEngine

Un même module de comptage sert les quatre familles, avec des profils séparés :

- moyenne et variance observées par équipe, avec shrinkage vers la moyenne de
  la compétition (poids 6) ;
- binomiale négative si la variance dépasse la moyenne (surdispersion),
  Poisson sinon ;
- totaux par convolution des deux équipes ;
- handicap et « équipe avec le plus » par distribution de la différence ;
- lignes générées dynamiquement, publication des deux faces ensemble.

Le moteur ne mélange jamais tirs, fautes et corners : chaque famille a sa
propre moyenne, sa propre variance et son propre échantillon.

**Arbitre** : aucun profil arbitral n'est inventé. Le facteur arbitre n'est
appliqué que si une source fournit son identité et ses statistiques, ce qui
n'est pas le cas aujourd'hui.

## 6. PlayerPredictionEngine

Pour chaque joueur :

- identité obligatoire, équipe obligatoire ;
- échantillon ≥ 8 matchs ;
- titularisation ≥ 0,60 ou composition officielle ;
- taux ramené aux minutes attendues et à la force offensive de l'équipe
  (`λ équipe / 1,35`).

Produit : marque à tout moment, 2+ buts, premier/dernier buteur, tirs, tirs
cadrés, carton. Les événements rares (hat-trick, expulsion) restent refusés.

## 7. TimeEventsEngine

Sans horodatage, les buts sont supposés arriver à taux constant (Poisson) :

- P(premier but dans un intervalle) = `(1 − e^{−λ}) × durée/90` ;
- P(but avant m minutes) = `1 − e^{−λ m/90}` ;
- P(nombre de buts dans un intervalle) = loi de Poisson sur `λ × durée/90`.

La couverture est explicitement `thin` et `missing_data` contient
`timestamped_goal_events`. Les corners/cartons horodatés restent non supportés.

## 8. MarketRegistry et éligibilité

Chaque définition porte : identifiant stable, famille, période, sélections,
paramètres de ligne, données requises, modèle, statut, source
(documenté/observé/variante), indicateur live.

Statuts :

- `supported` : données et modèle disponibles pour toutes les rencontres
  couvertes ;
- `conditional` : calculable seulement avec un échantillon suffisant ;
- `insufficient` : données présentes mais couverture trop faible ;
- `unsupported` : variable absente de toute source intégrée.

Le moteur ne publie **jamais** un marché `unsupported`, ni une famille dont la
couverture est `none`.

## 9. SelectionEngine

Fiabilité (0–1) :

```
0,34 × couverture
+ 0,22 × échantillon (n/30)
+ 0,20 × calibration historique de la famille
+ 0,14 × (1 − incertitude)
+ 0,10 × facteur probabilité
```

Classement :

- **prudent** : p ≥ 0,62, fiabilité ≥ 0,68, couverture `full`/`partial`,
  marché `supported`/`conditional` ;
- **équilibré** : 0,42 ≤ p < 0,78, fiabilité ≥ 0,55 (ou p ≥ 0,55 avec
  fiabilité ≥ 0,45) ;
- **audacieux** : 0,15 ≤ p < 0,48, fiabilité ≥ 0,45.

Une probabilité de 90 % sur données faibles n'entre pas dans « prudent ».
Aucune sélection n'apparaît dans deux catégories et deux sélections
contradictoires du même match sont écartées.

## 10. CombinationEngine

- Sélections du **même match** : probabilité conjointe lue sur la matrice
  (`joint_score_matrix`), jamais un produit.
- Sélections de **matchs différents** : produit, avec indépendance déclarée.
- Sélection sans représentation matricielle (corners, cartons, périodes) :
  la combinaison intra-match est refusée (`unresolved_dependency`).
- Profils : sécurisé (2 sélections), équilibré (3), audacieux (3), avec seuils
  de probabilité et de fiabilité par profil.

## 11. Contrat API

`POST /v1/predict` ajoute à la réponse existante :

```json
{
  "market_predictions": [
    {
      "market_id": "total_corners",
      "family": "corners",
      "period": "full",
      "selection": "over:8.5",
      "selection_label": "Plus de 8,5 corners",
      "line": 8.5,
      "probability": 0.68,
      "fair_odds": 1.47,
      "coverage": "partial",
      "sample_size": 20,
      "reliability": 0.71,
      "uncertainty": 0.4,
      "model": "negative_binomial",
      "market_status": "conditional",
      "data_sources": ["team_match_stats"],
      "justification": "...",
      "missing_data": [],
      "settlement": {"win": 0.68, "push": 0.0, "half_win": 0.0, "half_loss": 0.0, "loss": 0.32}
    }
  ],
  "market_categories": {"prudent": [], "equilibre": [], "audacieux": []},
  "market_coverage": {"families": {}, "skipped": {}},
  "combinations": {},
  "market_registry": {"market_count": 86, "live_enabled": false},
  "period_profile": {"coverage": "partial", "sample_size": 120, "first_half_share": 0.44}
}
```

`GET /v1/markets/registry` expose le catalogue complet et ses statuts.

## 12. Intégration Laravel

1. `GeneratePredictionsCommand` transmet le contexte réel :
   - `period_share` : agrégat des scores mi-temps de la compétition ;
   - `counts` : moyennes/variances des `team_match_stats` des 30 derniers
     matchs de chaque équipe (colonnes réellement présentes).
2. La réponse est stockée dans `derived_markets_json` :
   `market_predictions`, `market_categories`, `market_coverage`,
   `combinations`, `period_profile`.
3. `DashboardController::extendedPicks()` injecte les sélections déjà classées
   dans **Les plus sûrs / Équilibrés / Audacieux**, sans nouvelle page ni
   sélection manuelle. Le pronostic officiel reste inchangé.
4. `ParlayService::extendedMarketCandidates()` ajoute corners/cartons/tirs aux
   candidats de combinés **uniquement si The Odds API fournit une cote réelle
   pour la même ligne**.

La présentation reste celle de PRONO-IA : aucune interface de laboratoire.

## 13. Limites d'architecture

- Les périodes supposent une indépendance conditionnelle entre mi-temps.
- Les marchés temporels supposent un taux de but constant.
- Les corners et cartons ne sont pas encore alimentés (voir
  `market-data-requirements.md`).
- Aucun modèle live n'est activé.
