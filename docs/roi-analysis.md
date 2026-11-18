# Analyse du ROI négatif — 1N2

Source : `scripts/backtest_specialists.py` (walk-forward, cotes de clôture réelles).
Rencontres évaluées : **2632** · sélections 1N2 avec cotes : **2252** · plis : 98.

## 1. Qualité probabiliste (indépendante de la mise)

- Brier multiclasse : **0.6106**
- Log Loss : **1.0245**
- Marge moyenne du bookmaker (overround) : **1.0553**

| Plage de probabilité | N | Probabilité moyenne | Taux réel | Écart |
|---|---|---|---|---|
| 0.3-0.4 | 355 | 0.3784 | 0.3718 | 0.0065 |
| 0.4-0.5 | 861 | 0.4481 | 0.4297 | 0.0184 |
| 0.5-0.6 | 558 | 0.5458 | 0.5394 | 0.0064 |
| 0.6-0.7 | 303 | 0.646 | 0.6469 | -0.0009 |
| 0.7-0.8 | 126 | 0.7363 | 0.6984 | 0.0379 |
| 0.8-0.9 | 42 | 0.8356 | 0.8095 | 0.0261 |
| 0.9-1.0 | 7 | 0.9398 | 0.4286 | 0.5112 |

## 1 bis. Avantage réel du modèle sur le marché

Écart moyen entre la probabilité du modèle et la probabilité implicite **hors marge**, sur les trois issues (n = 2252). Un modèle sans avantage doit être proche de zéro.

| Issue | Écart moyen modèle − marché |
|---|---|
| home | 0.0046 |
| draw | 0.0134 |
| away | -0.018 |

- Somme moyenne des probabilités du modèle sur les trois issues : **1.0** (marge du bookmaker : 1.0553).

### Calibration conditionnelle aux paris de la règle du rapport

Cette table ne décrit **pas** la calibration du modèle : elle ne contient que les rencontres où le modèle dépassait déjà le marché. Elle montre comment la règle sélectionne l'excès de confiance.

| Plage de probabilité | N | Probabilité moyenne | Taux réel | Écart |
|---|---|---|---|---|
| 0.1-0.2 | 25 | 0.172 | 0.0 | 0.172 |
| 0.2-0.3 | 190 | 0.256 | 0.1579 | 0.0981 |
| 0.3-0.4 | 256 | 0.3533 | 0.1992 | 0.1541 |
| 0.4-0.5 | 225 | 0.4472 | 0.2578 | 0.1894 |
| 0.5-0.6 | 152 | 0.5452 | 0.3618 | 0.1833 |
| 0.6-0.7 | 70 | 0.6474 | 0.4429 | 0.2045 |
| 0.7-0.8 | 27 | 0.7299 | 0.3333 | 0.3965 |
| 0.8-0.9 | 10 | 0.8315 | 0.5 | 0.3315 |
| 0.9-1.0 | 4 | 0.9556 | 0.5 | 0.4556 |

## 2. Règle de pari telle qu'implémentée

Un pari est placé sur la **première** issue (domicile, nul, extérieur) dont la
probabilité du modèle dépasse la probabilité implicite **brute** de la cote de
clôture d'au moins 0,05. Mise fixe de 1 unité, une seule sélection par rencontre.

| Règle | Paris | Gagnés | Mise | Retour | ROI | Taux de réussite |
|---|---|---|---|---|---|---|
| Écart vs implicite brute ≥ 0,05 (règle du rapport) | 959 | 241 | 959.0 | 764.09 | -0.2032 | 0.2513 |
| Écart vs implicite hors marge ≥ 0,05 | 1291 | 372 | 1291.0 | 1077.77 | -0.1652 | 0.2881 |
| Favori du modèle, mise fixe (référence) | 2252 | 1124 | 2252.0 | 2126.68 | -0.0556 | 0.4991 |

## 3. Où se concentrent les pertes (règle du rapport)

### Par plage de probabilité du modèle

| Plage | Paris | Gagnés | ROI |
|---|---|---|---|
| 0.1-0.2 | 25 | 0 | -1.0 |
| 0.2-0.3 | 190 | 30 | -0.1404 |
| 0.3-0.4 | 256 | 51 | -0.215 |
| 0.4-0.5 | 225 | 58 | -0.2003 |
| 0.5-0.6 | 152 | 55 | -0.1381 |
| 0.6-0.7 | 70 | 31 | -0.1453 |
| 0.7-0.8 | 27 | 9 | -0.3307 |
| 0.8-0.9 | 10 | 5 | -0.167 |
| 0.9-1.0 | 4 | 2 | -0.3425 |

### Par plage de cote

| Cote | Paris | Gagnés | ROI |
|---|---|---|---|
| 1-2 | 90 | 40 | -0.2393 |
| 2-3 | 230 | 83 | -0.1253 |
| 3-4 | 281 | 67 | -0.1906 |
| 4-5 | 144 | 21 | -0.3576 |
| 5-6 | 91 | 18 | 0.0495 |
| 6-7 | 58 | 9 | -0.0259 |
| 7-8 | 30 | 2 | -0.5333 |
| 8-9 | 14 | 1 | -0.3929 |
| 9-10 | 6 | 0 | -1.0 |
| 11-12 | 5 | 0 | -1.0 |
| 12-13 | 7 | 0 | -1.0 |
| 13-14 | 1 | 0 | -1.0 |
| 17-18 | 1 | 0 | -1.0 |
| 19-20 | 1 | 0 | -1.0 |

### Par issue pariée

| Issue | Paris | Gagnés | ROI |
|---|---|---|---|
| away | 265 | 60 | -0.2025 |
| draw | 213 | 34 | -0.3138 |
| home | 481 | 147 | -0.1547 |

### Par compétition

| Compétition | Paris | Gagnés | ROI |
|---|---|---|---|
| BL1 | 120 | 34 | -0.204 |
| FL1 | 182 | 43 | -0.2623 |
| PD | 220 | 51 | -0.2376 |
| PL | 230 | 60 | -0.1638 |
| SA | 207 | 53 | -0.1582 |

## 4. Lecture

- La règle du rapport parie sur 959 rencontres et perd 79.7 % de la mise.
- Intervalle de confiance à 95 % du ROI de cette règle : **[-0.2996, -0.1069]** sur 959 paris (mise fixe, une sélection par rencontre).
- En comparant à l'implicite **hors marge**, la même règle place 1291 paris pour un ROI de -0.1652 : la marge du bookmaker explique une partie du déficit, pas la totalité.
- Le pari systématique sur le favori du modèle, sans filtre de valeur, donne -0.0556 sur 2252 paris — soit approximativement la marge du bookmaker (1.0553). C'est la mesure la plus honnête de l'avantage du modèle : **il n'en a pas**.
- La règle du rapport ne fait pas mieux : elle sélectionne 84 sélections annoncées au-delà de 75 % et amplifie l'excès de confiance au lieu de l'exploiter.

Conclusion : le ROI négatif n'est pas un défaut de la formule de ROI. Il vient de l'absence d'avantage probabiliste sur le marché et d'une règle de mise qui parie précisément là où le modèle s'écarte le plus du marché.

Aucune de ces variantes n'est retenue comme stratégie de production. Le backtest reste un outil de diagnostic, pas une promesse de rendement. Aucun paramètre n'a été réglé sur cet échantillon : les plis du walk-forward sont ceux du rapport existant.
