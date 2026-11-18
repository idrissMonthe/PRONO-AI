# Backtest chronologique — marchés spécialisés

- Rencontres évaluées : **2632** (dont 98 plis walk-forward)
- Entraînement minimal : 200 rencontres · pas : 25
- Dispersion (corners, binomiale négative) : 1.081

## Corners — Plus/Moins

| Modèle | N | Brier | Log Loss | ECE | Taux réel |
|---|---|---|---|---|---|
| poisson | 7296 | 0.2457 | 0.6876 | 0.0604 | 0.4956 |
| negbin | 7296 | 0.248 | 0.6892 | 0.0851 | 0.4956 |
| baseline | 7296 | 0.2441 | 0.6815 | 0.0484 | 0.4956 |

## Cartons — Plus/Moins

| Modèle | N | Brier | Log Loss | ECE | Taux réel |
|---|---|---|---|---|---|
| poisson | 7296 | 0.2237 | 0.6401 | 0.034 | 0.5322 |
| baseline | 7296 | 0.2247 | 0.6413 | 0.0303 | 0.5322 |

## 1N2 avec cotes de clôture réelles

- Rencontres avec cotes : 2252
- Brier multiclasse : 0.6106
- Log Loss : 1.0245
- Paris simulés (écart ≥ 0,05) : 959
- ROI : -0.2032

## 1N2 par compétition

| Compétition | N | Brier | Log Loss |
|---|---|---|---|
| BL1 | 387 | 0.5998 | 1.0129 |
| FL1 | 396 | 0.6121 | 1.023 |
| PD | 499 | 0.5947 | 0.9977 |
| PL | 480 | 0.6426 | 1.0762 |
| SA | 490 | 0.6026 | 1.0116 |
