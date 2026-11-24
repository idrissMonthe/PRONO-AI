# Évaluation xgabora — cœur Dixon-Coles vs marché dé-vigé

Rapport généré par `ai-service/scripts/evaluate_xgabora_market.py`.

Le modèle évalué est le **cœur statistique Dixon-Coles**. Le prédicteur de production
ajoute ensuite un blend Elo/forme et, si l'appelant les fournit, les cotes du marché.
Ce blend n'est pas inclus ici : injecter les cotes dans le modèle rendrait la
comparaison au marché circulaire.

## Données et protocole

- Source : **xgabora/Club-Football-Match-Data (Football-Data.co.uk + ClubElo)**.
- Divisions : E0, D1, SP1, I1, F1.
- Rencontres utilisées : **3602** (2024-08-16 → 2026-09-03).
- Cœur statistique : `DixonColesModel(xi=0.0019, l2_reg=0.01, rho initial=-0.04, rho réestimé à chaque fit)`, un modèle par division ; `rho` est réestimé à chaque fit.
- Réentraînement tous les **100** matchs, uniquement sur les rencontres strictement antérieures au bloc.
- Cotes : Bet365 1X2 du dataset (OddHome/OddDraw/OddAway), retrait de marge **shin**.
- Marge moyenne du bookmaker : **0.0517**.
- Réentraînements réussis : 38 ; échecs : 0.
- Prédictions avec historique d'équipe faible : 93 ; avec équipe inconnue : 62.

## Résultat principal

| Métrique | Dixon-Coles | Marché dé-vigé | Écart (modèle − marché) |
| --- | ---: | ---: | ---: |
| Log loss | 0.9897 | 0.9703 | +0.0194 |
| Brier multiclasse | 0.5896 | 0.5770 | +0.0126 |
| RPS | 0.2018 | 0.1959 | +0.0059 |
| ECE | 0.0153 | 0.0220 | -0.0067 |
| Précision 1N2 | 0.5203 | 0.5355 | -0.0153 |

**Gain de log loss (marché − modèle) : -0.0194** (-2.00 %). Un signe positif signifie que le modèle fait mieux que le marché.

- IC 95 % du gain moyen (bootstrap apparié) : [-0.0252 ; -0.0137].
- Diebold-Mariano : statistique = 6.6814, p = 0.0.

> Le modèle ne bat pas le marché dé-vigé sur cet échantillon. Le marché reste la meilleure baseline probabiliste.

## Par division

| Division | N | Log loss modèle | Log loss marché | Gain |
| --- | ---: | ---: | ---: | ---: |
| D1 | 621 | 0.9914 | 0.9688 | -0.0226 |
| E0 | 780 | 1.0145 | 0.9941 | -0.0204 |
| F1 | 630 | 0.9922 | 0.9692 | -0.0229 |
| I1 | 780 | 0.9765 | 0.9624 | -0.0140 |
| SP1 | 791 | 0.9751 | 0.9566 | -0.0186 |

## Par saison

| Saison | N | Log loss modèle | Log loss marché | Gain |
| --- | ---: | ---: | ---: | ---: |
| 2024/2025 | 1751 | 0.9873 | 0.9640 | -0.0234 |
| 2025/2026 | 1752 | 0.9949 | 0.9790 | -0.0160 |
| 2026/2027 | 99 | 0.9397 | 0.9285 | -0.0112 |

## Lecture

- Le marché dé-vigé est une baseline forte : il agrège l'information de plusieurs bookmakers et intègre les compositions, blessures et mouvements de cotes.
- Un modèle qui n'utilise que les buts et les dates ne peut pas battre le marché sur la seule base de ces features. Un gain proche de zéro est déjà un résultat honnête.
- L'ECE mesure la calibration globale ; un log loss proche du marché avec une ECE plus élevée signale des probabilités moins bien calibrées, même si le classement des issues est correct.
- Les cotes du dataset sont des cotes Bet365, pas nécessairement des cotes de clôture. Le résultat dépend donc de la qualité et du moment de ces cotes.
- Les équipes promues ou sans historique suffisant sont prédites avec une force ramenée vers la moyenne ; ces cas sont comptés dans le rapport.

## Limites

- Évaluation sur les cinq grands championnats, pas sur les 38 divisions du dataset.
- Le modèle est réentraîné par blocs de 100 matchs ; les prédictions en fin de bloc utilisent un modèle légèrement plus ancien, sans fuite temporelle.
- Aucun réglage d'hyperparamètre n'a été effectué sur la période de test.
- Le dataset ne contient pas les compositions, blessures ni xG événementiel : le modèle évalue uniquement la force offensive/défensive historique.
- Le blend Elo/forme/marché de `/v1/predict` n'est pas évalué ici. L'inclure avec les cotes rendrait la comparaison au marché circulaire ; l'évaluer sans cotes serait une autre expérience.
