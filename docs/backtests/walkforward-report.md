# Rapport de backtesting walk-forward

Généré le 2026-11-18T04:24:58.173950+00:00 par `ai-service/scripts/backtest_walkforward.py`.

## Protocole

- Matchs disponibles : **407** (2015-08-08 → 2026-11-08).
- Matchs avec xG : **156**.
- Compétitions : PL.
- Entraînement initial : 120 matchs ; validation : 115 ; test : 172.
- Réentraînement tous les 20 matchs, uniquement sur les matchs antérieurs.
- Cotes historiques disponibles : non → ROI non calculé (aucun prix inventé).

## Sélection du poids xG (validation uniquement)

| Poids xG | Log Loss | Brier | RPS | ECE | Échantillon |
| --- | --- | --- | --- | --- | --- |
| 0.00 | 1.1186 | 0.6765 | 0.2309 | 0.1267 | 115 |
| 0.25 | 1.1049 | 0.6675 | 0.2273 | 0.106 | 115 |
| 0.50 | 1.0972 | 0.6622 | 0.2252 | 0.1152 | 115 |
| 1.00 | 1.0953 | 0.6601 | 0.2247 | 0.1086 | 115 |

Poids retenu : **1.0**.

## Fenêtre de test

| Modèle | Log Loss | Brier | RPS | ECE | Précision 1N2 | Précision DC |
| --- | --- | --- | --- | --- | --- | --- |
| Dixon-Coles (référence) | 1.0322 | 0.6072 | 0.2042 | 0.0865 | 0.4884 | 0.75 |
| Modèle retenu (xG=1.0) | 1.028 | 0.6063 | 0.2036 | 0.0854 | 0.5058 | 0.7442 |
| Dixon-Coles + platt | 1.0372 | 0.6216 | 0.2111 | 0.0874 | 0.5 | 0.75 |
| Dixon-Coles + isotonic | 1.2177 | 0.6211 | 0.2112 | 0.0807 | 0.5 | 0.75 |

## Comparaison chiffrée moteur initial / moteur amélioré

| Variante | Δ Log Loss | Δ Brier | Δ ECE | Décision |
| --- | --- | --- | --- | --- |
| Référence | 0 | 0 | 0 | conservée par défaut |
| xG | -0.0042 | -0.0009 | -0.0011 | rejetée (non significatif, p=0.565) |
| calibration platt | +0.005 | +0.0144 | +0.0009 | rejetée (pas de gain) |
| calibration isotonic | +0.1855 | +0.0139 | -0.0058 | rejetée (pas de gain) |

Une variante n'est retenue que si le log loss baisse **et** que le test de Diebold-Mariano donne p < 0,05. Sinon elle reste désactivée par défaut.

## Marchés dérivés (fenêtre de test, modèle de référence)

| Marché | Log Loss | Brier | Précision |
| --- | --- | --- | --- |
| Over/Under 2.5 | 0.7335 | 0.2621 | 0.5407 |
| BTTS | 0.7291 | 0.2622 | 0.5116 |
| Totaux équipe > 0.5 | 0.5729 | — | 0.7209 |

## Performance par type de rencontre (log loss, modèle de référence)

| Groupe | Échantillon | Log Loss |
| --- | --- | --- |
| Résultat AWAY | 47 | 0.9594 |
| Résultat DRAW | 47 | 1.3414 |
| Résultat HOME | 78 | 0.8897 |
| 0-1_buts | 42 | 1.0868 |
| 2-3_buts | 73 | 1.0524 |
| 4+_buts | 57 | 0.966 |

## Usage réel des variables xG

- Prédictions évaluées : 287.
- Ajustement xG appliqué : 269.
- Règles de repli déclenchées : {"xg_adjustment_applied": 269, "missing_team_xg_coverage": 18}.
- Couverture xG observée : {"partial": 23, "full": 246, "none": 18}.

## Significativité

```json
{
  "platt_or_isotonic_vs_baseline": {
    "mean_diff": -0.00502,
    "dm_statistic": -0.202,
    "p_value": 0.83992,
    "is_significant_5pct": false,
    "sample_size": 172
  },
  "selected": "platt",
  "xg_vs_dixon_coles": {
    "mean_diff": 0.00416,
    "dm_statistic": 0.5749,
    "p_value": 0.56536,
    "is_significant_5pct": false,
    "sample_size": 172
  }
}
```

## Limites

- Un écart de log loss n'est déclaré significatif que si le test de Diebold-Mariano donne p < 0,05.
- Les marchés dérivés sont lus sur la même matrice de scores que le 1N2 : aucune probabilité n'est estimée séparément.
- Aucun ROI n'est calculé sans cotes historiques vérifiables.
