# Backtesting et validation par marché

## 1. Principe

Un marché n'est publié que si sa distribution est calculable **et** si le
modèle qui le produit est évalué séparément. Une bonne performance globale du
1X2 ne valide pas les corners, les cartons ou les périodes.

Le protocole reste walk-forward et chronologique :

1. tri strict par date ;
2. entraînement sur les `--min-train` premiers matchs uniquement ;
3. validation pour choisir les poids et calibrer ;
4. test unique sur la fenêtre restante, sans re-choix ;
5. réentraînement périodique sur le passé uniquement.

Aucun match futur n'entre dans les features d'un match passé.

## 2. Métriques

| Métrique | Usage |
| --- | --- |
| Log Loss | Qualité probabiliste multiclasses |
| Brier Score | Erreur quadratique multiclasses |
| RPS | Rang des probabilités 1X2 |
| ECE / courbes de fiabilité | Calibration par classe |
| Précision | Lecture secondaire, jamais un critère de qualité seul |
| Taille d'échantillon | Affichée avec chaque résultat |
| Par compétition / saison / ligne | Détection des segments fragiles |

Le taux de pronostics gagnants n'est **pas** une mesure de qualité
probabiliste : un modèle peut gagner souvent en étant mal calibré.

## 3. Règlement des marchés asiatiques

Le backtest doit régler les lignes selon la distribution réelle :

- ligne entière (3,0) : total exact = remboursement ;
- ligne demi (2,5) : pas de remboursement ;
- ligne quart (2,75) : moitié sur 2,5 et moitié sur 3,0 → demi-gain ou
  demi-perte sur le total exact.

`settle_over_under()` et `margin_settlement()` implémentent ces cas et sont
testés (`test_quarter_line_half_win_is_not_a_full_win`,
`test_asian_handicap_settlement_is_exhaustive`). La probabilité affichée est la
probabilité effective de règlement (demi-gain compté 0,5), pas la probabilité
de gagner la mise entière.

## 4. Résultats déjà disponibles (1X2 et buts)

Rapport complet : [`docs/backtests/walkforward-report.md`](backtests/walkforward-report.md).

Dataset : Premier League, 407 matchs, 156 avec xG (StatsBomb 2015-2016).
Fenêtre de test : 156 matchs.

| Modèle | Log Loss | Brier | ECE |
| --- | --- | --- | --- |
| Dixon-Coles (référence) | 1,0322 | 0,6072 | 0,0865 |
| Dixon-Coles + xG (poids 1,0) | 1,0280 | 0,6063 | 0,0854 |
| Dixon-Coles + Platt | 1,0372 | — | — |
| Dixon-Coles + Isotonic | 1,2177 | — | — |

- Diebold-Mariano xG vs Dixon-Coles : **p = 0,565** → gain non significatif.
- `XG_ADJUSTMENT_WEIGHT` reste donc à **0**.
- Marchés dérivés de la matrice (O/U 2,5, BTTS, totaux équipe) : évalués dans le
  même rapport ; aucune variante n'est activée sans baisse de log loss **et**
  p < 0,05.

## 5. Nouveaux marchés : ce qui est validé et ce qui ne l'est pas

| Famille | Backtest historique réalisé | Décision actuelle |
| --- | --- | --- |
| 1X2, double chance, DNB | oui (rapport ci-dessus) | publié, calibration par défaut |
| Totaux, BTTS, totaux équipe, score exact | partiellement (dérivés de la même matrice) | publié, calibration refusée sous 50 observations |
| Handicaps européens/asiatiques | cohérence mathématique testée ; pas de backtest de règlement sur données réelles | publié, mais pas de ROI |
| Mi-temps / HT-FT | **non** : la base ne contient pas assez de scores mi-temps exploitables pour un backtest séparé | conditionnel, fiabilité réduite |
| Corners | **non** : aucune valeur corners en base (extracteur StatsBomb non étendu) | conditionnel, non publié aujourd'hui |
| Cartons | **non** : idem | conditionnel, non publié aujourd'hui |
| Tirs / fautes | **non** : échantillon trop faible | conditionnel |
| Joueurs | **non** : compositions et événements non ingérés | conditionnel, non publié |
| Temporels (premier but…) | **non** : hypothèse de taux constant non validée sur événements | conditionnel, couverture `thin` |
| Live | **non** | désactivé |

**Aucun de ces marchés n'est déclaré « meilleur ».** Ils sont calculés, testés
mathématiquement, et leur couverture est exposée. Le backtest par famille est
le prérequis avant toute promotion en « calibré ».

## 6. Seuils et justification

| Seuil | Valeur | Justification |
| --- | --- | --- |
| Échantillon de calibration | ≥ 30 évaluations par famille | En dessous, l'ECE est instable |
| Calibration statistique (Platt/isotonic) | ≥ 50 observations | Déjà appliqué au moteur xG |
| Périodes | ≥ 5 matchs avec score MT, `full` à 80 | Une part mesurée sur 5 matchs reste fragile |
| Comptages | ≥ 3 valeurs par équipe, `full` à 25 | Variance non estimable sur 1-2 matchs |
| Joueurs | ≥ 8 matchs | Taux individuel dominé par le temps de jeu |
| Publication | 0,10 ≤ p ≤ 0,94 | Évite les quasi-certitudes et les improbables |
| Fiabilité « prudent » | ≥ 0,68 | Une probabilité élevée ne suffit pas |

Ces seuils sont des garde-fous de couverture, pas des objectifs de volume.

## 7. Ce qui manque pour un backtest complet

1. **Extraire corners, cartons, fautes et compositions** depuis StatsBomb, puis
   reconstituer un dataset historique par famille.
2. **Stocker les cotes historiques** par marché pour mesurer le ROI. Sans prix
   vérifiable, aucun ROI n'est calculé.
3. **Ajouter les scores mi-temps** aux exports de backtest pour évaluer les
   marchés de période.
4. **Ajouter un backtest de règlement asiatique** aux rapports (gains,
   remboursements, demi-gains), pas seulement la log loss.

Tant que ces points ne sont pas faits, les nouveaux marchés restent
**conditionnels** et leur fiabilité est plafonnée.
