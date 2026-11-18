# Gemini comme assistant de décision — état réel et corrections

## 1. Ce qui existait avant l'intervention

L'intégration n'était pas absente. Le projet contenait déjà :

| Composant | Rôle |
|---|---|
| `GeminiExplanationService` | Appels HTTP à `generativelanguage.googleapis.com`, prompt système, vérification anti-hallucination des nombres, gabarit déterministe de secours. |
| `PredictionRevisionService` (`hybrid_revision_v4`) | Contrat de revue : `validated`, `not_validated`, `insufficient_information`, `failed` ; résolution de l'alternative contre les marchés **calculés par Python**. |
| `GeneratePredictionsCommand` | Persiste `review_decision` et `market_metrics_json.gemini_review` à la génération. |
| `GeminiController` | `/gemini/status`, `/gemini/test`, `/gemini/explain`, `/gemini/ask`, `/gemini/history`. |
| `partials/gemini-review.blade.php` | Affichage « Pronostic Python / Décision Gemini / Proposition de Gemini ». |
| `ParlayService` | `alternativeForParlay()` sous interrupteur de configuration. |

Le problème n'était donc pas l'absence de Gemini, mais **deux défauts de restitution** :

1. le dashboard **supprimait** les pronostics dont Gemini n'avait pas validé la proposition,
   y compris en cas d'échec technique ;
2. les libellés stockés (`Validé`, `Échec de l'analyse`) ne correspondaient pas aux trois
   états attendus et divergeaient entre le dashboard et la fiche match.

## 2. Corrections

### 2.1 Trois états explicites

`PredictionRevisionService::statusLabel()` produit désormais :

| Statut interne | Libellé affiché |
|---|---|
| `VALIDATED` | Validé par Gemini |
| `NOT_VALIDATED_WITH_ALTERNATIVE`, `NOT_VALIDATED_WITHOUT_ALTERNATIVE` | Non validé par Gemini |
| `INSUFFICIENT_INFORMATION` | Non évalué par Gemini (informations insuffisantes) |
| `REVIEW_FAILED` | Non évalué par Gemini (service indisponible) |
| statut absent | Non évalué par Gemini |

Aucune confusion possible entre un rejet statistique et une indisponibilité technique.

### 2.2 Libellé dérivé du statut, jamais recopié

`presentationFromPrediction()` recalcule `status_label` à partir du `status` enregistré.
Les revues historiques conservent leur statut d'origine mais adoptent le vocabulaire
courant. Aucune donnée n'est réécrite.

### 2.3 Python reste affiché quoi qu'il arrive

Le filtre `presentsAsValidated()` a été retiré de `DashboardController::buildDailyPicks()`.
Chaque carte porte l'indicateur Gemini et, le cas échéant, la suggestion alternative.
`presentsAsValidated()` reste utilisée là où elle est légitime : l'éligibilité d'un
pronostic dans un combiné bookmaker (`ParlayService`).

## 3. Preuve de souveraineté de Python

### 3.1 Au niveau du contrat

- `assemble()` fixe `final_candidate` et `published_selection` sur le candidat Python,
  jamais sur l'alternative.
- `prediction_origin` est forcé à `PYTHON_ESTIMATE`.
- Une alternative de type `AI_ESTIMATE` a `probability = null` et n'obtient jamais de
  probabilité Python.
- Une alternative `PYTHON` relit la probabilité **déjà calculée** par le moteur ; elle ne
  peut pas en créer une.
- `selectionIsRecognized()` rejette tout marché ou sélection hors catalogue.

### 3.2 Vérifié en conditions réelles (18 novembre 2026, vraie clé API)

Revue sur Arsenal–Chelsea :

```
provider=google_gemini  model=gemini-flash-lite-latest  decision=validated
confidence=medium  latence=1672 ms
```

Revue sur la rencontre #217 :

```
DÉCISION GEMINI : Non validé par Gemini
PROPOSITION DE GEMINI : Double chance X2 (Nul ou Extérieur)
  marché double_chance · sélection X2 · probabilité Python 46,1 % · origine Python
```

Le pronostic Python (`Victoire domicile`, 53,9 %) reste affiché intact ; l'alternative est
présentée à côté, avec une probabilité issue du moteur Python et non inventée par Gemini.

## 4. Gros combinés

- Python construit le combiné et calcule la probabilité conjointe.
- `alternativeForParlay()` n'entre dans un combiné que si
  `PREDICTION_ALLOW_GEMINI_ALTERNATIVE_IN_PARLAYS=true`, **et** après contrôle du marché,
  **et** seulement si le pronostic Python n'est pas éligible.
- Valeur par défaut : `false`. Une suggestion Gemini ne peut donc pas être ajoutée
  automatiquement à un combiné publié.
- Vérifié en production : 27 sélections réparties sur trois versions, aucune suggestion
  Gemini injectée.

## 5. Limite importante : le texte d'explication est presque toujours le gabarit

`verifyNumbersInResponse()` rejette la réponse entière dès qu'un nombre absent du contexte
apparaît. Sur trois appels réels consécutifs, les trois réponses ont été rejetées pour des
nombres anodins (`100`, `2`) :

```
CALL0 verified=no fallback=yes notes=Unsupported number detected: 100
CALL1 verified=no fallback=yes notes=Unsupported number detected: 2
```

Conséquence : la **revue de décision** (verdict + alternative) est bien produite par
Gemini, mais le **texte explicatif** affiché est en pratique le gabarit déterministe, et
l'interface l'indique honnêtement (« Gabarit de secours » / « Gabarit de repli »).

Ce comportement est conforme à l'exigence anti-hallucination et n'a pas été assoupli :
relâcher la vérification est une décision de produit, pas une correction de bug.

## 6. Coûts et robustesse

- Une revue par pronostic retenu, jamais par marché brut.
- Délais, nombre de tentatives et quotas gérés dans le service.
- En cas d'échec, `REVIEW_FAILED` : Python publie quand même.
- Les évaluations sont historisées (`llm_interactions`, `market_metrics_json.gemini_review`)
  et une nouvelle revue n'écrase pas la précédente
  (`GeminiValidationHistoryTest`).

## 7. Tests

- `tests/Unit/PredictionRevisionServiceTest.php` (22 tests) : validé, refusé avec et sans
  alternative, informations insuffisantes, API indisponible, alternative au prix Python,
  alternative sans prix Python, pronostic Python visible pour chaque verdict, combinés.
- `tests/Feature/GeminiControllerTest.php` : statut, test de connexion, explication,
  question, historique.
- `tests/Feature/ParlayGeminiEligibilityTest.php` : les combinés bookmaker ignorent un
  pronostic non validé ; l'alternative n'est candidate que si elle est activée.
- `tests/Feature/DashboardWatchlistTest.php` : un échec Gemini ne retire jamais la carte.

## 8. Action propriétaire

- `GEMINI_API_KEY` est configurée et fonctionnelle ; `GEMINI_MODEL=gemini-flash-lite-latest`.
- Pour activer les alternatives dans les combinés :
  `PREDICTION_ALLOW_GEMINI_ALTERNATIVE_IN_PARLAYS=true` (désactivé par défaut).
- Décider si le gabarit explicatif doit rester la référence en cas de nombre non vérifié,
  ou si la vérification doit tolérer les entiers de contexte narratif.
