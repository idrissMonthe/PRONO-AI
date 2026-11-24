# Évaluation xG — cinq grands championnats, walk-forward

Rapport généré par `ai-service/scripts/evaluate_xgabora_xg_multileague.py`.

## Source et périmètre

- xG : **CSV public dérivé d'Understat/FotMob (dépôt xline8/footballprediction, code MIT, fichier xg_raw/xg_matches.csv)**.
- soccerdata a été essayé ; Understat renvoyait une page de maintenance et FBref bloquait les requêtes par Cloudflare au moment de l'exécution. Aucun xG n'a été inventé.
- Divisions : E0, D1, SP1, I1, F1.
- Matchs de test : **3602** (2024-08-16 → 2026-09-03).
- Validation du poids xG : **2023-08-01 → 2024-07-01** (réentraînement tous les 200 matchs).
- Test : **2024-08-01 → 2026-09-04**, réentraînement tous les 100 matchs.
- Réentraînements : 38 test, 0 échec(s) ; 10 validation, 0 échec(s).
- Poids xG candidats : [0.0, 0.25, 0.5, 1.0] ; poids retenu : **0.25**.
- xG appliqué sur **3516 / 3602** prédictions.
- Poids effectif moyen : **0.232641**.
- Couverture xG disponible : **{"full": 3516, "none": 86}**.

## Résultat principal

| Modèle | Log loss | Brier | RPS | ECE | Précision 1N2 |
| --- | ---: | ---: | ---: | ---: | ---: |
| Dixon-Coles pur | 0.9885 | 0.5891 | 0.2015 | 0.0162 | 0.5211 |
| Dixon-Coles + xG | 0.9863 | 0.5874 | 0.2007 | 0.0212 | 0.5250 |
| Marché dé-vigé | 0.9703 | 0.5770 | 0.1959 | 0.0220 | 0.5355 |

### Écarts et significativité

Un **gain positif** signifie que le premier modèle est meilleur que le second.

| Comparaison | Δ log loss (1er − 2e) | Gain (2e − 1er) | IC 95 % | DM p |
| --- | ---: | ---: | ---: | ---: |
| xG vs Dixon-Coles | -0.0022 | +0.0022 | [+0.0011 ; +0.0034] | 0.00019 |
| xG vs marché | +0.0160 | -0.0160 | [-0.0208 ; -0.0109] | 0.0 |
| Dixon-Coles vs marché | +0.0182 | -0.0182 | [-0.0235 ; -0.0128] | 0.0 |

## Sélection du poids xG

| Poids | Log loss validation | Brier | RPS |
| ---: | ---: | ---: | ---: |
| 0.00 | 0.9833 | 0.5866 | 0.1972 |
| 0.25 | 0.9823 | 0.5857 | 0.1967 |
| 0.50 | 0.9825 | 0.5857 | 0.1967 |
| 1.00 | 0.9842 | 0.5869 | 0.1972 |

## Sensibilité au poids xG (test)

Le poids est choisi uniquement sur la validation ; ce tableau montre ce que
chaque poids aurait donné sur le test, sans servir à le sélectionner.

| Poids | Log loss test | Brier | RPS |
| ---: | ---: | ---: | ---: |
| 0.00 | 0.9885 | 0.5891 | 0.2015 |
| 0.25 | 0.9863 | 0.5874 | 0.2007 |
| 0.50 | 0.9855 | 0.5868 | 0.2004 |
| 1.00 | 0.9855 | 0.5866 | 0.2003 |

Le poids 0.50 ferait légèrement mieux sur le test, mais il n'a pas été retenu : le choix est fait uniquement sur 2023/24.

## Par division

| Division | N | DC log loss | xG log loss | Marché log loss | xG − DC | xG − marché |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| D1 | 621 | 0.9912 | 0.9897 | 0.9688 | -0.0015 | +0.0209 |
| E0 | 780 | 1.0092 | 1.0061 | 0.9941 | -0.0030 | +0.0120 |
| F1 | 630 | 0.9921 | 0.9891 | 0.9692 | -0.0030 | +0.0199 |
| I1 | 780 | 0.9765 | 0.9759 | 0.9624 | -0.0005 | +0.0135 |
| SP1 | 791 | 0.9751 | 0.9720 | 0.9566 | -0.0031 | +0.0155 |

## Par saison

| Saison | N | DC log loss | xG log loss | Marché log loss | xG − DC | xG − marché |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| 2024/2025 | 1751 | 0.9851 | 0.9827 | 0.9640 | -0.0023 | +0.0188 |
| 2025/2026 | 1752 | 0.9948 | 0.9926 | 0.9790 | -0.0021 | +0.0137 |
| 2026/2027 | 99 | 0.9397 | 0.9371 | 0.9285 | -0.0026 | +0.0086 |

## Couverture xG

- Couverture : `{"full": 3516, "none": 86}`.
- Raisons de non-application : `{"xg_adjustment_applied": 3516, "missing_team_xg_coverage": 86}`.

## Conclusion

- Écart Dixon-Coles / marché : **+0.0182**.
- Écart xG / marché : **+0.0160**.
- Réduction de l'écart apportée par xG : **+0.0022**.

Le xG réduit significativement l'écart au marché sur cette période.

Le marché reste devant : log loss 0.9703 contre 0.9863 pour xG. L'ECE se dégrade de 0.0162 à 0.0212.

## Limites

- Les sources live Understat/FBref étaient inaccessibles ; le CSV xG est un miroir public dérivé d'Understat/FotMob.
- Le CSV xG est un miroir tiers (dépôt au code MIT), pas une collecte officielle Understat/FBref.
- Les cotes sont des cotes Bet365 du dataset, pas nécessairement des cotes de clôture.
- Les équipes promues sans historique xG dans leur division ne reçoivent aucun ajustement xG.
- Le poids xG est sélectionné sur 2023/24 ; le test 2024/25–2026/27 n'entre pas dans ce choix.
