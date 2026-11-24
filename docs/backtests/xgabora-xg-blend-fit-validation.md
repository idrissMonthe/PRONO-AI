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
- Matchs de test : **1752** (2023-08-11 → 2024-06-02).
- Test : **2023-08-01 → 2024-07-01**, réentraînement tous les 200 matchs.
- `xg_adjust` : ajustement a posteriori des lambda par adjust_lambdas_with_xg, poids 0.25 (retenu sur 2023/24).
- `blend_fit` : vraisemblance de Poisson sur 0.50 * buts + 0.50 * xG ; tau indexée sur le score réel.
- Réentraînements : 10 sur buts, 10 sur pseudo-buts ; échecs : 0 et 0.
- xG disponible dans l'historique d'entraînement : **54.61%** des rencontres ; sur le test : **99.94%**.
- Ajustement a posteriori appliqué sur **1688 / 1752** prédictions.

## Résultat principal

| Modèle | Log loss | Brier | RPS | ECE | Précision 1N2 |
| --- | ---: | ---: | ---: | ---: | ---: |
| Dixon-Coles pur | 0.9833 | 0.5866 | 0.1972 | 0.0241 | 0.5228 |
| xG a posteriori (0,25) | 0.9823 | 0.5857 | 0.1967 | 0.0305 | 0.5257 |
| Blend dans le fit (0,5 xG) | 0.9811 | 0.5847 | 0.1962 | 0.0328 | 0.5263 |
| Marché dé-vigé | 0.9556 | 0.5674 | 0.1879 | 0.0364 | 0.5502 |

### Écarts et significativité

Un **gain positif** signifie que le premier modèle est meilleur que le second.

| Comparaison | Δ log loss (1er − 2e) | Gain (2e − 1er) | IC 95 % | DM p |
| --- | ---: | ---: | ---: | ---: |
| Blend vs xG a posteriori | -0.0012 | +0.0012 | [-0.0007 ; +0.0030] | 0.19857 |
| Blend vs Dixon-Coles | -0.0022 | +0.0022 | [-0.0007 ; +0.0053] | 0.1417 |
| xG a posteriori vs Dixon-Coles | -0.0010 | +0.0010 | [-0.0007 ; +0.0028] | 0.2528 |
| Blend vs marché | +0.0255 | -0.0255 | [-0.0332 ; -0.0171] | 0.0 |

## Par division

| Division | N | DC | xG a posteriori | Blend fit | Marché | Blend − xG a posteriori |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| D1 | 306 | 0.9903 | 0.9905 | 0.9881 | 0.9468 | -0.0024 |
| E0 | 380 | 0.9305 | 0.9317 | 0.9325 | 0.9095 | +0.0008 |
| F1 | 306 | 1.0348 | 1.0333 | 1.0342 | 1.0142 | +0.0009 |
| I1 | 380 | 0.9940 | 0.9924 | 0.9909 | 0.9680 | -0.0015 |
| SP1 | 380 | 0.9785 | 0.9751 | 0.9714 | 0.9491 | -0.0037 |

## Par saison

| Saison | N | DC | xG a posteriori | Blend fit | Marché | Blend − xG a posteriori |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| 2023/2024 | 1752 | 0.9833 | 0.9823 | 0.9811 | 0.9556 | -0.0012 |

## Écart au marché

- Dixon-Coles pur : **+0.0277** de log loss.
- xG a posteriori : **+0.0267** (réduction de +0.0010, +3.71 % de l'écart initial).
- Blend dans le fit : **+0.0255** (réduction de +0.0022, +8.06 % de l'écart initial).

## Conclusion

Le blend dans le fit est légèrement meilleur (-0.0012 de log loss), mais l'écart n'est pas significatif (p = 0.19857).

Le blend rapproche un peu plus du marché : écart +0.0255 contre +0.0267. Le marché reste devant.

## Limites

- Le blend porte sur des pseudo-buts : la vraisemblance de Poisson est une quasi-vraisemblance, les intervalles de confiance des paramètres ne sont plus exacts.
- La correction tau reste indexée sur le score réel ; elle n'est pas redéfinie pour des scores fractionnaires.
- Les rencontres d'entraînement sans xG gardent leurs buts réels ; le blend ne porte donc que sur la part couverte.
- Le poids 0,5 est celui du dépôt source, pas un poids optimisé sur ce dataset ; le poids 0,25 de l'ajustement a posteriori a été choisi sur 2023/24.
- Si la période évaluée est celle qui a servi à choisir ce poids 0,25 (2023/24), l'ajustement a posteriori est en partie in-sample : la comparaison y est favorable à l'ajustement xG, donc défavorable au blend.
- Le CSV xG est un miroir public dérivé d'Understat/FotMob, pas une collecte officielle live.
- Les cotes sont des cotes Bet365 du dataset, pas nécessairement des cotes de clôture.
