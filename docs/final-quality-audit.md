# Audit final de qualité — PRONO-IA

Branche : `feature/final-quality-gemini-watchlist`, créée depuis
`feature/production-data-readiness` (`e115125`). `main` n'a pas été touchée. Aucun push,
aucun merge, aucune PR.

## 1. Périmètre

Quatre axes vérifiés sur données réelles, sans refonte :

1. qualité statistique réelle des prédictions et origine du ROI négatif ;
2. fonctionnement de la rubrique « Les matchs à surveiller » ;
3. rôle effectif de Gemini dans la validation des pronostics ;
4. fonctionnement visuel et fonctionnel du dashboard.

## 2. Contrôles Git initiaux

| Contrôle | Résultat |
|---|---|
| Branche active au départ | `feature/production-data-readiness` |
| Commit `e115125` | présent (`git cat-file -t` → `commit`) |
| Graphe | 10 commits linéaires au-dessus de `d053a79` |
| `git status` | seulement des `__pycache__` et `storage/tmp` non suivis |
| Travaux précédents | présents (connecteurs, snapshots, backtests, documents) |
| `main` | intacte, non modifiée |

## 3. Qualité statistique — ce qui est vrai, ce qui ne l'est pas

| Indicateur (1N2, walk-forward, 2 252 rencontres cotées) | Valeur |
|---|---|
| Brier multiclasse | 0,6106 |
| Log Loss | 1,0245 |
| Somme moyenne des probabilités du modèle | 1,000 |
| Écart moyen modèle − marché hors marge | +0,005 (dom.) / +0,013 (nul) / −0,018 (ext.) |
| Overround moyen du bookmaker | 1,0553 |
| ROI, pari systématique sur le favori du modèle | **−5,56 %** [−9,82 ; −1,31] |
| ROI, règle d'écart du rapport (959 paris) | **−20,32 %** [−29,96 ; −10,69] |

Lecture : le modèle est **correctement calibré** (écarts de calibration ≤ 0,038 entre
30 % et 90 % de probabilité) mais **n'a aucun avantage** sur la ligne de clôture. Le
−5,56 % du pari systématique correspond exactement à la marge du bookmaker. Le −20,32 %
supplémentaire vient de la règle de mise, qui parie précisément là où le modèle s'écarte
le plus du marché — donc là où il a le plus tendance à se tromper.

Détail complet : `docs/roi-analysis.md`.

## 4. Corrections apportées

### 4.1 ROI — instrumentation, pas d'optimisation

- `scripts/backtest_specialists.py` exporte désormais les sélections individuelles
  (date, pli, cote, probabilités, résultat) pour rendre le ROI décomposable.
- Nouveau `scripts/analyze_roi.py` : ROI, intervalle de confiance à 95 %, calibration
  globale **et** conditionnelle, écart modèle/marché, ventilation par compétition,
  plage de probabilité, plage de cote et issue pariée.
- Aucun paramètre de modèle n'a été ajusté sur l'échantillon d'évaluation.
- Garde-fou ajouté : une cote absente ou invalide ne produit jamais de pari.

### 4.2 Snapshots — 57 lignes expliquées

Les 57 « non classés » sont les pronostics publiés, un par rencontre. Catégorie explicite
`published` + réconciliation `published + safe + balanced + bold = total`. Aucune donnée
historique réécrite. Détail : `docs/prediction-history-validation.md`.

### 4.3 Matchs à surveiller — cause double, corrigée

1. Le bassin ne couvrait que 6 rencontres → élargi à 40.
2. Un verdict Gemini défavorable **ou un échec technique** supprimait le pronostic Python
   → filtre retiré, indicateur ajouté.
3. Nouveau `WatchlistService` : vraie logique métier de surveillance (issue ouverte,
   divergence modèle/marché hors marge, contestation Gemini, validation en attente,
   couverture limitée, intervalle large).
4. État vide explicite. Détail : `docs/watchlist-diagnostic.md`.

### 4.4 Gemini — trois états, libellés unifiés

`Validé par Gemini` / `Non validé par Gemini` / `Non évalué par Gemini`, dérivés du statut
et identiques sur le dashboard et la fiche match. Détail :
`docs/gemini-decision-support.md`.

## 5. Vérification visuelle réelle

Navigateur ouvert sur `http://127.0.0.1:8000` (application réelle, données réelles).

| Contrôle | Résultat |
|---|---|
| Dashboard, code HTTP | 200 |
| Erreurs console | aucune |
| Requêtes réseau échouées | aucune (polices 200) |
| Cartes de pronostics | 18 (4 + 4 + 4 + 6 signaux) |
| Catégories préservées | Les plus sûrs / Équilibrés / Audacieux |
| Indicateurs Gemini | 7 « Validé par Gemini », 5 « Non validé par Gemini » |
| Gros combinés | 27 sélections : 7 / 10 / 10 sur trois versions |
| Probabilités et cotes | affichées (ex. 66,1 %, 66,4 %, 67,3 %) |
| Débordement horizontal (1440 px) | aucun |
| Responsive 390 px | aucun débordement de page (`scrollWidth` 385 ≤ 385) |
| État vide | rendu explicite, couvert par test |
| Fiche match #217 | « Non validé par Gemini » + « Proposition de Gemini : Double chance X2 », probabilité Python 46,1 %, origine Python |
| Fiche match #634 | « Pronostic Python » + « Décision Gemini : Validé », justification chiffrée par les xG |

**Limite** : les captures d'écran n'ont pas pu être produites — la fenêtre du navigateur
n'était pas dessinée (`preview_screenshot` a refusé la capture). La vérification a donc
porté sur le DOM, les styles calculés, la console et le réseau. Aucun rendu pixel n'a été
jugé.

## 6. Tests

| Suite | Résultat |
|---|---|
| PHP (`php artisan test`) | voir rapport de fin de mission |
| Python (`pytest`) | voir rapport de fin de mission |
| Nouveaux tests PHP | `WatchlistServiceTest` (8), `DashboardWatchlistTest` (5), 2 tests de réconciliation dans `PredictionHistoryTest` |
| Nouveaux tests Python | `tests/test_roi_analysis.py` (10) |

## 7. Erreurs rencontrées et corrigées pendant la mission

| Erreur | Cause | Correction |
|---|---|---|
| `Table 'prono.football_matches' doesn't exist` | Le modèle `FootballMatch` utilise la table `matches` | Requêtes de diagnostic corrigées |
| `Access denied for user 'root'` | Identifiants MySQL non ceux du conteneur | Diagnostic via `artisan tinker` |
| `NOT NULL constraint failed: odds_snapshots.bookmaker_id` | `bookmaker_id` obligatoire | Fixture de test complétée |
| `market_disagreement` non déclenché dans le test | Écart réel de 5 points < seuil de 12 | Fixture rendue réellement divergente |
| `slices := 0` (expression invalide) | Brouillon de calcul de cote moyenne | Réécriture propre de `evaluate_bets` |
| Calibration calculée sur l'échantillon parieur | Biais de sélection | Séparation calibration globale / conditionnelle |
| Ventilation des catégories incohérente | Entrée publiée sans catégorie | `category = 'published'` + lecture des `NULL` |

## 8. Ce qui n'a pas été fait, volontairement

- Aucune optimisation de modèle pour obtenir un ROI positif.
- Aucun règlement anticipé de pronostics.
- Aucune réécriture de données historiques.
- Aucun assouplissement de la vérification anti-hallucination de Gemini.
- Aucune refonte du design ni des trois catégories.
- Aucune rencontre fictive créée.

## 9. Actions du propriétaire

1. Après le 24 novembre 2026 : `php artisan oracle:prediction-history --settle --report`.
2. Décider du sort du modèle corners : il ne bat pas la référence
   (Brier 0,2457 contre 0,2441) et ne justifie pas une mise en production.
3. Décider si le texte explicatif Gemini doit rester remplacé par le gabarit lorsque la
   vérification numérique échoue (3 cas sur 3 en test réel).
4. Ne pas pousser la branche sans validation : `feature/final-quality-gemini-watchlist`
   reste locale.
