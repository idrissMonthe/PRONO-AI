"""Sélection et classement des prédictions en trois catégories.

La catégorie ne dépend pas du seul pourcentage. Elle combine :
- la probabilité calibrée ;
- la couverture réelle des données (complète, partielle, faible, absente) ;
- la fiabilité historique du modèle sur la famille ;
- l'incertitude de l'estimation.

Règles structurantes :
- une sélection dont la couverture est ``none`` n'est jamais publiée ;
- aucune sélection n'apparaît dans deux catégories ;
- deux sélections mutuellement exclusives du même match ne peuvent pas être
  présentées ensemble ;
- la catégorie ``prudent`` exige une couverture au moins partielle et une
  fiabilité élevée, pas seulement 65 % de probabilité.
"""
from __future__ import annotations

from typing import Any, Dict, Iterable, List, Optional, Sequence, Tuple

COVERAGE_WEIGHT = {"full": 1.0, "partial": 0.72, "thin": 0.38, "none": 0.0}
CATEGORY_ORDER = ("prudent", "equilibre", "audacieux")

# Paires incompatibles lorsqu'elles portent sur la même rencontre.
_CONTRADICTIONS: Tuple[Tuple[str, str], ...] = (
    ("1x2:home", "1x2:draw"),
    ("1x2:home", "1x2:away"),
    ("1x2:draw", "1x2:away"),
    ("1x2:home", "double_chance:X2"),
    ("1x2:away", "double_chance:1X"),
    ("btts:yes", "btts:no"),
    ("clean_sheet:home", "1x2:away"),
    ("clean_sheet:away", "1x2:home"),
    ("win_to_nil:home", "btts:yes"),
    ("win_to_nil:away", "btts:yes"),
)


def _signature(prediction: Dict[str, Any]) -> str:
    return f"{prediction.get('market_id')}:{prediction.get('selection')}"


def _same_family_exclusive(left: Dict[str, Any], right: Dict[str, Any]) -> bool:
    if left.get("market_id") != right.get("market_id"):
        return False
    if left.get("line") != right.get("line"):
        return False
    return left.get("selection") != right.get("selection")


def contradicts(left: Dict[str, Any], right: Dict[str, Any]) -> bool:
    if left.get("match_id") != right.get("match_id"):
        return False
    if _signature(left) == _signature(right):
        return True
    if _same_family_exclusive(left, right):
        return True
    pair = {_signature(left), _signature(right)}
    for first, second in _CONTRADICTIONS:
        if pair == {first, second}:
            return True
    return False


def reliability_score(
    probability: float,
    coverage: str,
    model_calibration: float,
    uncertainty: float,
    sample_size: int,
) -> float:
    """Fiabilité 0–1, distincte de la probabilité.

    ``model_calibration`` est le taux de réussite historique de la famille
    (0,5 = aucune information). ``uncertainty`` est une largeur d'intervalle
    normalisée (0 = précision parfaite).
    """
    coverage_factor = COVERAGE_WEIGHT.get(coverage, 0.0)
    sample_factor = min(1.0, max(0.0, sample_size / 30.0))
    calibration_factor = min(1.0, max(0.0, model_calibration))
    uncertainty_factor = 1.0 - min(0.6, max(0.0, uncertainty))
    # La probabilité n'entre que faiblement : 85 % mal couverts ne doivent pas
    # dépasser 75 % bien calibrés.
    probability_factor = 0.6 + 0.4 * min(1.0, max(0.0, probability))
    score = (
        0.34 * coverage_factor
        + 0.22 * sample_factor
        + 0.20 * calibration_factor
        + 0.14 * uncertainty_factor
        + 0.10 * probability_factor
    )
    return round(min(1.0, max(0.0, score)), 4)


TRIVIAL_SELECTIONS = {
    ("total_goals", "under_5_5"),
    ("total_goals", "over_0_5"),
    ("total_goals", "under_0_5"),
    ("total_goals", "over_5_5"),
    ("total_goals", "under_4_5"),
}


def is_trivial_market(prediction: Dict[str, Any]) -> bool:
    """Écarte les sélections parasites ou de cote dérisoire (< 1.22).

    Un pari à 95 % (ex: 'Moins de 5,5 buts') a une cote de ~1.04 sans aucune
    rentabilité. Les pronostics publiés doivent présenter un intérêt de pari réel.
    """
    market_id = str(prediction.get("market_id", ""))
    selection = str(prediction.get("selection", ""))
    line = prediction.get("line")
    prob = float(prediction.get("probability", 0.0))

    if (market_id, selection) in TRIVIAL_SELECTIONS:
        return True
    if market_id in {"total_goals", "totals"}:
        if line is not None and (float(line) in (0.5, 5.5) or (float(line) == 4.5 and prob > 0.80)):
            return True
    if market_id in {"team_total_goals", "team_totals"}:
        if line is not None and float(line) == 0.5 and prob > 0.80:
            return True
    # Plafond de publication : probabilité > 0.82 correspond à une cote < 1.22
    if prob > 0.82:
        return True
    return False


def category_for(prediction: Dict[str, Any]) -> Optional[str]:
    probability = float(prediction.get("probability", 0.0))
    coverage = str(prediction.get("coverage", "none"))
    reliability = float(prediction.get("reliability", 0.0))
    if coverage == "none" or reliability < 0.35:
        return None
    if probability < 0.15 or is_trivial_market(prediction):
        return None

    if (
        0.62 <= probability <= 0.82
        and reliability >= 0.68
        and coverage in {"full", "partial"}
        and str(prediction.get("market_status")) in {"supported", "conditional"}
    ):
        return "prudent"
    if 0.42 <= probability < 0.78 and reliability >= 0.55:
        return "equilibre"
    if 0.15 <= probability < 0.48 and reliability >= 0.45:
        return "audacieux"
    # Une probabilité élevée mais une fiabilité moyenne reste visible dans
    # Équilibrés plutôt que d'être promue en « plus sûr ».
    if 0.50 <= probability <= 0.82 and reliability >= 0.45:
        return "equilibre"
    return None


def _category_score(prediction: Dict[str, Any], category: str) -> float:
    probability = float(prediction.get("probability", 0.0))
    reliability = float(prediction.get("reliability", 0.0))
    uncertainty = float(prediction.get("uncertainty", 0.5))
    if category == "prudent":
        # Compromis optimal : forte probabilité (0.65-0.80), haute fiabilité et
        # incertitude faible, sans favoriser une quasi-certitude à cote nulle.
        prob_sweet_spot = 1.0 - abs(probability - 0.74)
        return 0.40 * probability + 0.35 * reliability + 0.15 * prob_sweet_spot - 0.10 * uncertainty
    if category == "equilibre":
        # Compromis : proximité de 60 % et fiabilité, sans récompenser une
        # quasi-certitude sans information.
        return 0.35 * probability + 0.50 * reliability + 0.15 * (1.0 - abs(probability - 0.60))
    return 0.30 * probability + 0.55 * reliability + 0.15 * (1.0 - abs(probability - 0.30))


def rank_categories(
    predictions: Iterable[Dict[str, Any]],
    limit_per_category: int = 6,
) -> Dict[str, List[Dict[str, Any]]]:
    grouped: Dict[str, List[Dict[str, Any]]] = {name: [] for name in CATEGORY_ORDER}
    seen_market_families: Dict[str, set] = {}

    eligible = [p for p in predictions if float(p.get("probability", 0.0)) > 0.0]
    eligible.sort(
        key=lambda p: (
            _category_score(p, category_for(p) or "equilibre"),
            float(p.get("probability", 0.0)),
        ),
        reverse=True,
    )

    for prediction in eligible:
        category = category_for(prediction)
        if category is None:
            continue
        if any(_signature(item) == _signature(prediction) for items in grouped.values() for item in items):
            continue
        if any(contradicts(item, prediction) for items in grouped.values() for item in items):
            continue
        # Un même match ne remplit pas une catégorie avec plusieurs variantes
        # de la même famille de marché.
        key = f"{prediction.get('match_id')}:{prediction.get('family')}"
        used = seen_market_families.setdefault(category, set())
        if key in used:
            continue
        used.add(key)
        grouped[category].append(prediction)
        if len(grouped[category]) >= limit_per_category:
            continue

    for category in CATEGORY_ORDER:
        grouped[category].sort(
            key=lambda p: _category_score(p, category),
            reverse=True,
        )
        grouped[category] = grouped[category][:limit_per_category]

    return grouped


def dedupe(predictions: Sequence[Dict[str, Any]]) -> List[Dict[str, Any]]:
    seen = set()
    result: List[Dict[str, Any]] = []
    for prediction in predictions:
        key = (
            prediction.get("match_id"),
            prediction.get("market_id"),
            prediction.get("selection"),
            prediction.get("line"),
        )
        if key in seen:
            continue
        seen.add(key)
        result.append(prediction)
    return result
