# PRONO-IA — Performance de l'entraînement

## 1. Symptôme initial

Sur la base complète (534 rencontres, 114 équipes), `oracle:train-model` échouait :

```
Dixon-Coles optimization failed: STOP: TOTAL NO. OF F/G EVALUATIONS EXCEEDS LIMIT
```

et, lorsqu'il n'échouait pas, dépassait dix minutes. Seul `--limit=30` aboutissait.

## 2. Diagnostic

Le goulot n'était ni le réseau, ni la base, ni le budget d'itérations. Trois causes cumulées :

1. **Vraisemblance en boucle Python.** `nll()` parcourait les 534 rencontres une par une, avec des accès dictionnaire et des appels `np.exp` scalaires.
2. **Gradient par différences finies.** `minimize` était appelé sans `jac` : L-BFGS-B approxime le gradient en `2 × paramètres + 1` évaluations. Sur ~400 paramètres, cela représente environ 800 évaluations de la vraisemblance **par itération**.
3. **Budget d'évaluations sous-dimensionné**, conséquence directe du point 2 : le budget était consommé avant toute convergence.

Le nombre de paramètres croît en `2 × (équipes − 1) + 3` : passer de 30 à 534 rencontres multiplie les paramètres, donc le coût du gradient, bien plus vite que le coût d'une évaluation.

## 3. Corrections apportées

- **Vraisemblance vectorisée** : indices d'équipes, buts, poids temporels et masque terrain neutre précalculés une fois ; la correction `tau` n'est évaluée que sur les quatre cases concernées (0-0, 0-1, 1-0, 1-1).
- **Gradient analytique exact**, avec la contrainte d'identifiabilité `Σatt = Σdef = 0` reportée sur les coefficients libres, et la dérivée de `tau` prise par rapport à `log λ` (et non à `λ`).
- **Budget exprimé en itérations** (`maxiter = 3000`) plutôt qu'en évaluations, puisque chaque itération ne coûte plus qu'une évaluation et un gradient.

Vérification du gradient : erreur relative maximale de **6,5 × 10⁻⁹** face aux différences finies (`ai-service/tests/test_poisson_gradient.py`).

## 4. Mesures

| Scénario | Avant | Après |
|---|---|---|
| 534 rencontres / 200 équipes (banc d'essai) | échec « budget dépassé » | **0,56 s**, convergence |
| 534 rencontres réelles / 114 équipes | échec ou > 10 min | **5 s**, code 0 |
| 2 751 rencontres réelles / 186 équipes | non exécutable | **20 s**, code 0 |

Commande réelle exécutée :

```bash
php artisan oracle:train-model --limit=5000
# Matchs avec xG mesuré : 880 / 2751.
# Dixon-Coles : entraînement réussi (186 équipes modélisées sur 186 observées).
# Elo : entraînement réussi (186 équipes).
# Spécialistes de marchés : entraînement réussi (2751 matchs).
# Hash d'entraînement : 4efcc2de1ac7ccdf645218096922110c86ff202181f447434961269ee17ae324
```

Le temps inclut la sérialisation de 2 751 rencontres vers le service Python, le contrôle chronologique de sureté (`probe`), la reconstruction Elo/forme, le profil par période et l'historique xG.

## 5. Approche par compétition

Les performances par compétition ont été mesurées (`docs/backtests/specialists-report.md`), sur un protocole walk-forward strict :

| Compétition | N | Brier 1N2 | Log Loss |
|---|---|---|---|
| PD | 499 | 0,5947 | 0,9977 |
| BL1 | 387 | 0,5998 | 1,0129 |
| SA | 490 | 0,6026 | 1,0116 |
| FL1 | 396 | 0,6121 | 1,0230 |
| PL | 480 | 0,6426 | 1,0762 |

**Décision : pas de modèle séparé par compétition.** Les écarts (0,595 à 0,643 de Brier) restent dans la variabilité d'échantillons de 387 à 499 rencontres ; découper l'historique multiplierait les paramètres par cinq pour un gain non démontré, et ferait perdre les rencontres inter-championnats (Champions League) qui n'appartiennent à aucune des cinq. Un modèle global avec amortissement vers la moyenne reste préférable tant qu'un test apparié ne montre pas de gain significatif.

Une hiérarchie partielle (pente par compétition sur un modèle commun) serait l'étape suivante, à condition de disposer d'au moins deux saisons complètes par championnat.

## 6. Garde-fous conservés

- `XG_ADJUSTMENT_WEIGHT` reste désactivé : aucun gain statistiquement démontré n'a été observé (p = 0,565 sur le test de Diebold-Mariano précédent).
- Un ajustement échoué ne remplace jamais un modèle valide : les paramètres ne sont commités qu'après validation complète.
- Le contrôle chronologique (`probe` sur les rencontres récentes) peut refuser un réentraînement et conserver les paramètres précédents.
