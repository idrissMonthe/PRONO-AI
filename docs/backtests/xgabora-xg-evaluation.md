# Évaluation xG — Premier League, walk-forward

Rapport généré par `ai-service/scripts/evaluate_xgabora_xg.py`.

## Source et périmètre

- xG : **CSV public dérivé de FBref, EPL, CC BY-NC 4.0**.
- Understat renvoyait une page de maintenance et FBref bloquait les requêtes par Cloudflare au moment de l'exécution. Aucun xG n'a été inventé.
- Périmètre : Premier League uniquement (E0), faute d'accès live aux cinq ligues.
- Matchs de test : **760** (2024-08-16 → 2026-05-24).
- Validation du poids xG : **2023-08-01 → 2024-07-01**.
- Test : **2024-08-01 → 2026-07-01**.
- Poids xG candidats : [0.0, 0.25, 0.5, 1.0] ; poids retenu : **0.0**.
- Réentraînement tous les **100** matchs, sur l'historique strictement antérieur.
- xG appliqué sur **0 / 760** prédictions.
- Poids effectif moyen : **0.0** (plafonné par la couverture et la fiabilité).
- Couverture xG disponible : **{"full": 738, "none": 22}**.
- Un poids retenu de 0 signifie que le modèle final est exactement le Dixon-Coles pur.

## Résultat principal

| Modèle | Log loss | Brier | RPS | ECE | Précision 1N2 |
| --- | ---: | ---: | ---: | ---: | ---: |
| Dixon-Coles pur | 1.0093 | 0.6047 | 0.2068 | 0.0393 | 0.4987 |
| Dixon-Coles + xG | 1.0093 | 0.6047 | 0.2068 | 0.0393 | 0.4987 |
| Marché dé-vigé | 0.9945 | 0.5952 | 0.2016 | 0.0360 | 0.5158 |

### Écarts et significativité

Un **gain positif** signifie que le premier modèle est meilleur que le second.

| Comparaison | Δ log loss (1er − 2e) | Gain (2e − 1er) | IC 95 % | DM p |
| --- | ---: | ---: | ---: | ---: |
| xG vs Dixon-Coles | +0.0000 | +0.0000 | [+0.0000 ; +0.0000] | 1.0 |
| xG vs marché | +0.0148 | -0.0148 | [-0.0271 ; -0.0027] | 0.0255 |
| Dixon-Coles vs marché | +0.0148 | -0.0148 | [-0.0271 ; -0.0027] | 0.0255 |

## Sélection du poids xG

| Poids | Log loss validation | Brier | RPS |
| ---: | ---: | ---: | ---: |
| 0.00 | 0.9331 | 0.5501 | 0.1913 |
| 0.25 | 0.9426 | 0.5559 | 0.1942 |
| 0.50 | 0.9444 | 0.5572 | 0.1948 |
| 1.00 | 0.9450 | 0.5577 | 0.1950 |

## Sensibilité au poids xG (test)

Le poids est choisi uniquement sur la validation ; ce tableau montre ce que
chaque poids aurait donné sur le test, sans servir à le sélectionner.

| Poids | Log loss test | Brier | RPS |
| ---: | ---: | ---: | ---: |
| 0.00 | 1.0093 | 0.6047 | 0.2068 |
| 0.25 | 1.0131 | 0.6068 | 0.2080 |
| 0.50 | 1.0167 | 0.6092 | 0.2091 |
| 1.00 | 1.0179 | 0.6101 | 0.2095 |

## Par saison

| Saison | N | DC log loss | xG log loss | Marché log loss | xG − DC | xG − marché |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| 2024/2025 | 380 | 0.9942 | 0.9942 | 0.9703 | +0.0000 | +0.0239 |
| 2025/2026 | 380 | 1.0243 | 1.0243 | 1.0186 | +0.0000 | +0.0057 |

## Couverture xG

- Couverture : `{"full": 738, "none": 22}`.
- Raisons de non-application : `{"xg_adjustment_disabled": 760}`.

## Conclusion

- Écart Dixon-Coles / marché : **+0.0148**.
- Écart xG / marché : **+0.0148**.
- Réduction de l'écart apportée par xG : **+0.0000**.

Le xG n'améliore pas le Dixon-Coles sur cette période : l'écart au marché ne se réduit pas.

## Limites

- Une seule ligue (EPL) à cause de l'indisponibilité des sources live ; les autres championnats ne sont pas couverts.
- Le CSV xG est dérivé de FBref et sous licence CC BY-NC 4.0 ; il ne remplace pas une collecte live.
- Les cotes sont des cotes Bet365 du dataset, pas nécessairement des cotes de clôture.
- Les équipes promues sans historique xG en Premier League ne reçoivent aucun ajustement xG.
