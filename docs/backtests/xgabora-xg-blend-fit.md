# Blend 0,5 buts + 0,5 xG dans l'ajustement Dixon-Coles

Rapport généré par `ai-service/scripts/evaluate_xgabora_xg_blend_fit.py`.

Deux variantes xG comparées sur les **mêmes blocs** du même walk-forward :

```text
xg_adjust : Dixon-Coles sur les buts, puis lambda déplacés a posteriori (poids 0,25)
blend_fit : vraisemblance de Poisson sur 0,50 × buts + 0,50 × xG
```

## Source et protocole

- xG : **CSV public dérivé d'Understat/FotMob (dépôt xline8/footballprediction, code MIT, fichier xg_raw/xg_matches.csv)**.
- Divisions : E0, D1, SP1, I1, F1.
- Matchs de test : **3602** (2024-08-15 → 2026-09-03).
- Test : **2024-08-01 → 2026-09-04**, réentraînement tous les 100 matchs.
- `xg_adjust` : ajustement a posteriori des lambda par adjust_lambdas_with_xg, poids 0.25 (retenu sur 2023/24).
- `blend_fit` : vraisemblance de Poisson sur 0.50 * buts + 0.50 * xG ; tau indexée sur le score réel.
- Réentraînements : 38 sur buts, 38 sur pseudo-buts ; échecs : 0 et 0.
- xG disponible dans l'historique d'entraînement : **59.50%** des rencontres ; sur le test : **100.00%**.
- Ajustement a posteriori appliqué sur **3516 / 3602** prédictions.

## Résultat principal

| Modèle | Log loss | Brier | RPS | ECE | Précision 1N2 |
| --- | ---: | ---: | ---: | ---: | ---: |
| Dixon-Coles pur | 0.9885 | 0.5891 | 0.2015 | 0.0162 | 0.5211 |
| xG a posteriori (0,25) | 0.9863 | 0.5874 | 0.2007 | 0.0212 | 0.5250 |
| Blend dans le fit (0,5 xG) | 0.9852 | 0.5866 | 0.2003 | 0.0241 | 0.5289 |
| Marché dé-vigé | 0.9703 | 0.5770 | 0.1959 | 0.0220 | 0.5355 |

### Écarts et significativité

Un **gain positif** signifie que le premier modèle est meilleur que le second.

| Comparaison | Δ log loss (1er − 2e) | Gain (2e − 1er) | IC 95 % | DM p |
| --- | ---: | ---: | ---: | ---: |
| Blend vs xG a posteriori | -0.0011 | +0.0011 | [-0.0003 ; +0.0024] | 0.14914 |
| Blend vs Dixon-Coles | -0.0033 | +0.0033 | [+0.0012 ; +0.0054] | 0.00244 |
| xG a posteriori vs Dixon-Coles | -0.0022 | +0.0022 | [+0.0011 ; +0.0034] | 0.00019 |
| Blend vs marché | +0.0149 | -0.0149 | [-0.0195 ; -0.0102] | 0.0 |

## Par division

| Division | N | DC | xG a posteriori | Blend fit | Marché | Blend − xG a posteriori |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| D1 | 621 | 0.9912 | 0.9897 | 0.9864 | 0.9688 | -0.0033 |
| E0 | 780 | 1.0092 | 1.0061 | 1.0056 | 0.9941 | -0.0006 |
| F1 | 630 | 0.9921 | 0.9891 | 0.9871 | 0.9692 | -0.0021 |
| I1 | 780 | 0.9765 | 0.9759 | 0.9759 | 0.9624 | -0.0001 |
| SP1 | 791 | 0.9751 | 0.9720 | 0.9720 | 0.9566 | -0.0000 |

## Par saison

| Saison | N | DC | xG a posteriori | Blend fit | Marché | Blend − xG a posteriori |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| 2024/2025 | 1751 | 0.9851 | 0.9827 | 0.9820 | 0.9640 | -0.0007 |
| 2025/2026 | 1752 | 0.9948 | 0.9926 | 0.9911 | 0.9790 | -0.0015 |
| 2026/2027 | 99 | 0.9397 | 0.9371 | 0.9374 | 0.9285 | +0.0003 |

## Écart au marché

- Dixon-Coles pur : **+0.0182** de log loss.
- xG a posteriori : **+0.0160** (réduction de +0.0022, +12.27 % de l'écart initial).
- Blend dans le fit : **+0.0149** (réduction de +0.0033, +18.12 % de l'écart initial).

## Conclusion

Le blend dans le fit est légèrement meilleur (-0.0011 de log loss), mais l'écart n'est pas significatif (p = 0.14914).

Le blend rapproche un peu plus du marché : écart +0.0149 contre +0.0160. Le marché reste devant.

## Limites

- Le blend porte sur des pseudo-buts : la vraisemblance de Poisson est une quasi-vraisemblance, les intervalles de confiance des paramètres ne sont plus exacts.
- La correction tau reste indexée sur le score réel ; elle n'est pas redéfinie pour des scores fractionnaires.
- Les rencontres d'entraînement sans xG gardent leurs buts réels ; le blend ne porte donc que sur la part couverte.
- Le poids 0,5 est celui du dépôt source, pas un poids optimisé sur ce dataset ; le poids 0,25 de l'ajustement a posteriori a été choisi sur 2023/24.
- Si la période évaluée est celle qui a servi à choisir ce poids 0,25 (2023/24), l'ajustement a posteriori est en partie in-sample : la comparaison y est favorable à l'ajustement xG, donc défavorable au blend.
- Le CSV xG est un miroir public dérivé d'Understat/FotMob, pas une collecte officielle live.
- Les cotes sont des cotes Bet365 du dataset, pas nécessairement des cotes de clôture.
