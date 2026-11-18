# Évaluation des performances des pronostics

## 1. Deux mesures distinctes

| Objet | Données | État |
|---|---|---|
| Performance **historique du modèle** | backtest walk-forward sur rencontres terminées | mesurée |
| Performance **des pronostics publiés** | snapshots de `prediction_snapshots` | en attente de règlement |

Mélanger les deux reviendrait à compter deux fois les mêmes rencontres et à confondre validation et production. Les sections sont donc séparées.

## 2. Backtest du modèle (référence)

Protocole : walk-forward strict, réentraînement sur toutes les rencontres antérieures, évaluation par lots de 25 rencontres, entraînement minimal 200 rencontres. Aucune cote n'entre dans le calcul des probabilités.

Dataset : 2 632 rencontres avec corners et cartons (2 252 avec cotes de clôture Bet365).

### 1N2

| Mesure | Valeur |
|---|---|
| Brier multiclasse | 0,6106 |
| Log Loss | 1,0245 |
| Rencontres évaluées | 2 252 |

Par compétition : PD 0,5947 · BL1 0,5998 · SA 0,6026 · FL1 0,6121 · PL 0,6426 (Brier).

### Corners — Plus/Moins (8,5 / 9,5 / 10,5)

| Modèle | Brier | Log Loss | ECE |
|---|---|---|---|
| Poisson à effets d'équipe | 0,2457 | 0,6876 | 0,0604 |
| Binomiale négative | 0,2480 | 0,6892 | 0,0851 |
| Référence (moyenne de championnat) | **0,2441** | **0,6815** | **0,0484** |

**Conclusion honnête : le modèle à effets d'équipe ne bat pas la moyenne de championnat.** La dispersion estimée (1,08) est trop faible pour justifier la binomiale négative, qui est la plus mauvaise des trois. Aucun passage en production de ce modèle n'est justifié en l'état.

### Cartons — Plus/Moins (2,5 / 3,5 / 4,5)

| Modèle | Brier | Log Loss | ECE |
|---|---|---|---|
| Poisson à effets d'équipe | **0,2237** | **0,6401** | 0,0340 |
| Référence | 0,2247 | 0,6413 | 0,0303 |

Gain de 0,0010 de Brier : réel mais marginal, du même ordre que le bruit d'échantillonnage. À considérer comme « non démontré », pas comme une amélioration acquise.

Rapport complet : `docs/backtests/specialists-report.md`.

### ROI 1N2 avec cotes réelles

Cotes de clôture Bet365, mise uniquement lorsque la probabilité du modèle dépasse la probabilité implicite de 0,05 :

| Mesure | Valeur |
|---|---|
| Paris simulés | 959 |
| ROI | **−20,3 %** |

Le modèle ne bat pas le marché de clôture. C'est le résultat attendu d'un modèle de buts face à des cotes efficaces, et cela interdit de présenter ces marchés comme rentables.

**Aucun ROI n'est calculé pour les corners, les cartons ou les tirs** : aucune cote historique correspondante n'existe dans les sources disponibles.

## 3. Évaluation des pronostics publiés

Commande :

```bash
php artisan oracle:prediction-history --settle --report
```

Mesures produites, par marché, par catégorie et par version de modèle : nombre de pronostics, Brier, Log Loss, taux de réussite. Les états sont distingués : gagnés, perdus, remboursés, annulés, demi-gains, demi-pertes, non réglés.

### État actuel

| Indicateur | Valeur |
|---|---|
| Snapshots publiés | 171 |
| Réglés | 0 |
| En attente | 171 |
| Remboursés | 0 |
| Combinés suivis | 1 |

Les 171 snapshots portent sur les 57 rencontres à venir (20 au 24 novembre 2026). Ils resteront `PENDING` jusqu'à ce qu'un résultat définitif soit enregistré : c'est le comportement attendu, aucun résultat n'est simulé pour « remplir » le rapport.

Dès que ces rencontres seront terminées et synchronisées, un `oracle:prediction-history --settle` produira les premiers chiffres réels de performance en production.

## 4. Ce qui reste à mesurer

- Performance réelle des pronostics publiés : nécessite les résultats des rencontres à venir.
- ROI des marchés corners / cartons : bloqué faute de cotes historiques.
- Performance des marchés joueurs : 380 rencontres seulement, et aucune cote de marché joueur.

## 5. Règles de lecture

- Un Brier ou un Log Loss n'est affiché que si l'échantillon réglé est non nul.
- Un ROI n'est affiché qu'avec des cotes historiques réellement associées à la rencontre.
- Les périodes de validation (backtest) et de production (snapshots) ne sont jamais agrégées ensemble.
