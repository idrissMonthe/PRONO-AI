# Rapport de backtesting walk-forward

Généré le 2026-11-17T13:15:00.186854+00:00 par `ai-service/scripts/backtest_walkforward.py`.

## Protocole

- Matchs disponibles : **380** (2025-08-15 → 2026-05-24).
- Matchs avec xG : **0**.
- Compétitions : PL.
- Entraînement initial : 120 matchs ; validation : 104 ; test : 156.
- Réentraînement tous les 20 matchs, uniquement sur les matchs antérieurs.
- Cotes historiques disponibles : non → ROI non calculé (aucun prix inventé).

> **xG indisponible pour ce jeu de données.** Aucune comparaison xG n'est
> donc publiée ici : le poids xG sélectionné est nécessairement 0 et le
> moteur se rabat sur Dixon-Coles. Pour produire la comparaison, importez
> les statistiques StatsBomb puis relancez :
>
> ```bash
> php artisan oracle:import-statsbomb --stats --limit=400
> php artisan oracle:export-xg-dataset --league=PL --output=storage/app/backtest/xg.json
> python -m scripts.backtest_walkforward --dataset ../storage/app/backtest/xg.json
> ```

## Sélection du poids xG (validation uniquement)

| Poids xG | Log Loss | Brier | RPS | ECE | Échantillon |
| --- | --- | --- | --- | --- | --- |
| 0.00 | 1.0495 | 0.6368 | 0.2012 | 0.1171 | 104 |
| 0.25 | 1.0495 | 0.6368 | 0.2012 | 0.1171 | 104 |
| 0.50 | 1.0495 | 0.6368 | 0.2012 | 0.1171 | 104 |
| 1.00 | 1.0495 | 0.6368 | 0.2012 | 0.1171 | 104 |

Poids retenu : **0.0**.

## Fenêtre de test

| Modèle | Log Loss | Brier | RPS | ECE | Précision 1N2 | Précision DC |
| --- | --- | --- | --- | --- | --- | --- |
| Dixon-Coles (référence) | 1.0604 | 0.6384 | 0.217 | 0.1002 | 0.4359 | 0.7372 |
| Modèle retenu (xG=0.0) | 1.0604 | 0.6384 | 0.217 | 0.1002 | 0.4359 | 0.7372 |
| Dixon-Coles + platt | 1.074 | 0.6466 | 0.2176 | 0.1164 | 0.3974 | 0.7372 |
| Dixon-Coles + isotonic | 1.0737 | 0.6463 | 0.2172 | 0.1017 | 0.4359 | 0.7372 |

## Comparaison chiffrée moteur initial / moteur amélioré

| Variante | Δ Log Loss | Δ Brier | Δ ECE | Décision |
| --- | --- | --- | --- | --- |
| Référence | 0 | 0 | 0 | conservée par défaut |
| calibration platt | +0.0136 | +0.0082 | +0.0162 | rejetée (pas de gain) |
| calibration isotonic | +0.0133 | +0.0079 | +0.0015 | rejetée (pas de gain) |

Une variante n'est retenue que si le log loss baisse **et** que le test de Diebold-Mariano donne p < 0,05. Sinon elle reste désactivée par défaut.

xG : **non évaluée** — aucun xG mesuré dans le jeu de données.

## Marchés dérivés (fenêtre de test, modèle de référence)

| Marché | Log Loss | Brier | Précision |
| --- | --- | --- | --- |
| Over/Under 2.5 | 0.7223 | 0.2641 | 0.4615 |
| BTTS | 0.6893 | 0.2479 | 0.5256 |
| Totaux équipe > 0.5 | 0.5443 | — | 0.7628 |

## Performance par type de rencontre (log loss, modèle de référence)

| Groupe | Échantillon | Log Loss |
| --- | --- | --- |
| Résultat HOME | 60 | 0.7954 |
| Résultat AWAY | 52 | 1.1918 |
| Résultat DRAW | 44 | 1.2664 |
| 4+_buts | 46 | 1.0509 |
| 2-3_buts | 76 | 1.0717 |
| 0-1_buts | 34 | 1.0479 |

## Usage réel des variables xG

- Prédictions évaluées : 260.
- Ajustement xG appliqué : 0.
- Règles de repli déclenchées : {}.
- Couverture xG observée : {}.

## Significativité

```json
{
  "platt_or_isotonic_vs_baseline": {
    "mean_diff": -0.01329,
    "dm_statistic": -0.8062,
    "p_value": 0.42012,
    "is_significant_5pct": false,
    "sample_size": 156
  },
  "selected": "isotonic"
}
```

## Limites

- Un écart de log loss n'est déclaré significatif que si le test de Diebold-Mariano donne p < 0,05.
- Les marchés dérivés sont lus sur la même matrice de scores que le 1N2 : aucune probabilité n'est estimée séparément.
- Aucun ROI n'est calculé sans cotes historiques vérifiables.
