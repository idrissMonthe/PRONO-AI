# Évaluation xgabora — blend Elo/forme sans cotes

Rapport généré par `ai-service/scripts/evaluate_xgabora_blend.py`.

Le blend reprend les poids fixes du prédicteur de production :

```text
0,65 × Dixon-Coles + 0,25 × Elo + 0,10 × forme
```

Aucune cote n'entre dans le calcul. Les cotes Bet365 sont uniquement la baseline
de marché, dé-vigée, évaluée sur les mêmes rencontres.

## Données et protocole

- Source : **xgabora/Club-Football-Match-Data (Football-Data.co.uk + ClubElo)**.
- Divisions : E0, D1, SP1, I1, F1.
- Rencontres de test : **3602** (2024-08-16 → 2026-09-03).
- Dixon-Coles : `DixonColesModel(xi=0.0019, l2_reg=0.01, rho initial=-0.04, rho réestimé)`, un modèle par division.
- Elo : EloEngine(k_factor=20.0, default_home_adv=65.0, default_elo=1500.0), reconstruit chronologiquement.
- Forme : points des 5 derniers matchs, même mapping que /v1/predict.
- Réentraînement tous les **100** matchs, sur les rencontres strictement antérieures au bloc.
- Cotes : Bet365 1X2, retrait de marge **shin**.
- Réentraînements réussis : 38 ; échecs : 0.
- Prédictions avec historique d'équipe faible : 93.

## Résultat principal

| Modèle | Log loss | Brier | RPS | ECE | Précision 1N2 |
| --- | ---: | ---: | ---: | ---: | ---: |
| Dixon-Coles pur | 0.9897 | 0.5896 | 0.2018 | 0.0153 | 0.5203 |
| Blend Elo/forme | 0.9885 | 0.5890 | 0.2015 | 0.0219 | 0.5214 |
| Marché dé-vigé | 0.9703 | 0.5770 | 0.1959 | 0.0220 | 0.5355 |

### Écarts et significativité

Un **gain positif** signifie que le premier modèle est meilleur que le second.

| Comparaison | Δ log loss (1er − 2e) | Gain (2e − 1er) | IC 95 % du gain | DM p |
| --- | ---: | ---: | ---: | ---: |
| Blend vs Dixon-Coles | -0.0013 | +0.0013 | [-0.0005 ; +0.0032] | 0.17805 |
| Blend vs marché | +0.0182 | -0.0182 | [-0.0235 ; -0.0129] | 0.0 |
| Dixon-Coles vs marché | +0.0194 | -0.0194 | [-0.0252 ; -0.0137] | 0.0 |

## Par division

| Division | N | DC log loss | Blend log loss | Marché log loss | Blend − DC | Blend − marché |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| D1 | 621 | 0.9914 | 0.9882 | 0.9688 | -0.0032 | +0.0195 |
| E0 | 780 | 1.0145 | 1.0100 | 0.9941 | -0.0044 | +0.0160 |
| F1 | 630 | 0.9922 | 0.9905 | 0.9692 | -0.0016 | +0.0213 |
| I1 | 780 | 0.9765 | 0.9781 | 0.9624 | +0.0017 | +0.0157 |
| SP1 | 791 | 0.9751 | 0.9759 | 0.9566 | +0.0008 | +0.0193 |

## Par saison

| Saison | N | DC log loss | Blend log loss | Marché log loss | Blend − DC | Blend − marché |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| 2024/2025 | 1751 | 0.9873 | 0.9846 | 0.9640 | -0.0027 | +0.0206 |
| 2025/2026 | 1752 | 0.9949 | 0.9951 | 0.9790 | +0.0002 | +0.0161 |
| 2026/2027 | 99 | 0.9397 | 0.9390 | 0.9285 | -0.0007 | +0.0105 |

## Conclusion

- Écart Dixon-Coles / marché : **+0.0194** de log loss.
- Écart blend / marché : **+0.0182** de log loss.
- Réduction de l'écart apportée par Elo/forme : **+0.0013** (+6.47 % de l'écart initial).

Le blend **réduit légèrement** l'écart au marché, mais cette réduction n'est pas statistiquement significative (test Blend vs Dixon-Coles). Le marché reste devant.

## Limites

- Le blend est appliqué au Dixon-Coles pur ; les spécialistes de marché et le blend marché optionnel de `/v1/predict` ne sont pas inclus.
- Les cotes du dataset sont des cotes Bet365, pas nécessairement des cotes de clôture.
- Elo et forme sont reconstruits uniquement sur les résultats antérieurs au bloc, sans utiliser les colonnes Elo précalculées du dataset.
- Aucun hyperparamètre n'a été réglé sur la période de test.
