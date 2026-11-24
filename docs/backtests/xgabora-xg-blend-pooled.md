# Diebold-Mariano cumulé — blend vs ajustement xG

Rapport généré par `ai-service/scripts/evaluate_xgabora_xg_blend_pooled.py`.

Les deux périodes du walk-forward sont rejouées avec leurs réentraînements d'origine, puis leurs pertes par rencontre sont concaténées pour un seul test.

## Périmètre

- Validation : **2023-08-01 → 2024-07-01**, réentraînement tous les 200 matchs — 1752 rencontres.
- Test : **2024-08-01 → 2026-09-04**, réentraînement tous les 100 matchs — 3602 rencontres.
- Cumul : **5354** rencontres disjointes (2023-08-11 → 2026-09-03), doublons : 0.
- Divisions : E0, D1, SP1, I1, F1.
- `blend_fit` : 0.50 × xG dans la vraisemblance ; `xg_adjust` : poids 0.25.
- Ajustements : validation 10 + test 38 ; 0 échec(s) ; 0 repli(s) du fit blend.

## Résultat cumulé

| Modèle | Log loss | Brier | RPS | ECE | Précision 1N2 |
| --- | ---: | ---: | ---: | ---: | ---: |
| Dixon-Coles pur | 0.9868 | 0.5883 | 0.2001 | 0.0162 | 0.5217 |
| xG a posteriori (0,25) | 0.9850 | 0.5869 | 0.1994 | 0.0229 | 0.5252 |
| Blend dans le fit (0,5 xG) | 0.9839 | 0.5860 | 0.1990 | 0.0228 | 0.5280 |
| Marché dé-vigé | 0.9655 | 0.5739 | 0.1933 | 0.0209 | 0.5403 |

### Blend vs ajustement xG

- Gain cumulé (xG − blend) : **+0.001114** de log loss.
- IC 95 % bootstrap : [+0.000004 ; +0.002251].
- DM Newey-West lag 1 (convention du projet) : statistique -1.9187, p = 0.05502.
- DM Newey-West lag 5 : statistique -1.96, p = 0.05.

| Période | N | Gain (xG − blend) | IC 95 % | DM lag 1 p | DM lag 5 p |
| --- | ---: | ---: | ---: | ---: | ---: |
| validation | 1752 | +0.001209 | [-0.000658 ; +0.003015] | 0.19857 | 0.188806 |
| test | 3602 | +0.001067 | [-0.000294 ; +0.002436] | 0.14914 | 0.136223 |

### Autres comparaisons cumulées

Gain = perte du second modèle − perte du premier ; positif = le second est meilleur.

| Comparaison | Gain | IC 95 % | DM lag 1 p |
| --- | ---: | ---: | ---: |
| Blend vs Dixon-Coles | +0.002956 | [+0.001237 ; +0.004703] | 0.00084 |
| xG a posteriori vs Dixon-Coles | +0.001842 | [+0.000914 ; +0.002808] | 0.00023 |
| Blend vs marché | -0.018396 | [-0.022361 ; -0.014243] | 0.0 |

## Par division (cumul)

| Division | N | DC | xG a posteriori | Blend | Marché | Blend − xG a posteriori |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| D1 | 927 | 0.9909 | 0.9900 | 0.9870 | 0.9615 | -0.0030 |
| E0 | 1160 | 0.9834 | 0.9817 | 0.9816 | 0.9664 | -0.0001 |
| F1 | 936 | 1.0061 | 1.0036 | 1.0025 | 0.9839 | -0.0011 |
| I1 | 1160 | 0.9822 | 0.9813 | 0.9808 | 0.9643 | -0.0005 |
| SP1 | 1171 | 0.9762 | 0.9730 | 0.9718 | 0.9542 | -0.0012 |

## Par saison (cumul)

| Saison | N | DC | xG a posteriori | Blend | Marché | Blend − xG a posteriori |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| 2023/2024 | 1752 | 0.9833 | 0.9823 | 0.9811 | 0.9556 | -0.0012 |
| 2024/2025 | 1751 | 0.9851 | 0.9827 | 0.9820 | 0.9640 | -0.0007 |
| 2025/2026 | 1752 | 0.9948 | 0.9926 | 0.9911 | 0.9790 | -0.0015 |
| 2026/2027 | 99 | 0.9397 | 0.9371 | 0.9374 | 0.9285 | +0.0003 |

## Écart au marché (cumul)

- Dixon-Coles pur : **+0.0214**.
- xG a posteriori : **+0.0195** (+8.63 % de réduction).
- Blend dans le fit : **+0.0184** (+13.84 % de réduction).

## Conclusion

L'avance cumulée du blend reste **à la frontière** : +0.001114 de log loss, IC 95 % bootstrap [+0.000004 ; +0.002251], DM p = 0.05502 (lag 1) et p = 0.05 (lag 5). Selon la correction d'autocorrélation retenue, le seuil de 5 % est manqué de peu ou tout juste atteint : l'effet est cohérent mais trop petit pour être conclu sur ce seul cumul.

## Limites

- Le cumul additionne deux régimes de marché différents (2023/24 et 2024/25–2026/27) : un seul effet moyen est estimé, l'hétérogénéité entre périodes n'est pas modélisée.
- Les paramètres sont partagés par blocs de 100 à 200 rencontres, donc les pertes sont corrélées au-delà du lag 1 ; d'où le contrôle à lag 5. Ici l'autocovariance estimée est légèrement négative : le lag 5 donne une variance un peu plus petite que le lag 1 (p = 0,050 contre 0,055), pas l'inverse.
- Les rencontres d'un même jour restent corrélées entre elles (même journée, mêmes informations) ; ni l'un ni l'autre lag ne corrige cette dépendance transversale.
- Le poids 0,25 de l'ajustement a posteriori a été choisi sur 2023/24 : la moitié validation du cumul est donc in-sample pour lui, ce qui défavorise le blend.
- Le CSV xG est un miroir public dérivé d'Understat/FotMob, pas une collecte officielle live.
