"""Marchés temporels dérivés des taux de buts.

Sans horodatage des événements, le moteur suppose un taux de but constant sur
90 minutes (processus de Poisson). Cette hypothèse est explicite : la couverture
est ``thin`` et la justification le rappelle. Les marchés de corners, cartons
et remplacements horodatés restent ``unsupported`` tant qu'aucune source
d'événements ne les fournit.
"""
from __future__ import annotations

import math
from typing import Any, Dict, List

from app.core.market_registry import market
from app.core.selection_engine import reliability_score

INTERVALS = [(0, 15), (15, 30), (30, 45), (45, 60), (60, 75), (75, 90)]


def _prediction(match_id: str, market_id: str, selection: str, label: str, probability: float,
                uncertainty: float = 0.60) -> Dict[str, Any] | None:
    definition = market(market_id)
    if definition is None or probability <= 0.02 or probability >= 0.97:
        return None
    reliability = reliability_score(probability, "thin", 0.5, uncertainty, 10)
    return {
        "match_id": match_id,
        "market_id": market_id,
        "market_name": definition.name,
        "family": definition.family,
        "family_label": definition.family,
        "period": definition.period,
        "selection": selection,
        "selection_label": label,
        "line": None,
        "probability": round(float(probability), 4),
        "fair_odds": round(1.0 / probability, 3) if probability > 0 else None,
        "coverage": "thin",
        "sample_size": 0,
        "reliability": reliability,
        "uncertainty": uncertainty,
        "model": definition.model,
        "market_status": "conditional",
        "data_sources": ["score_matrix"],
        "justification": "Taux de but supposé constant sur 90 minutes ; aucune "
                         "donnée horodatée ne vient valider cette répartition.",
        "missing_data": ["timestamped_goal_events"],
        "settlement": None,
    }


def _interval_label(start: int, end: int) -> str:
    return f"{start + 1}-{end}e minute"


def goal_time_markets(match_id: str, lambda_home: float, lambda_away: float) -> List[Dict[str, Any]]:
    total_rate = max(1e-6, float(lambda_home) + float(lambda_away))
    no_goal = math.exp(-total_rate)
    predictions: List[Dict[str, Any]] = []

    # Premier but par intervalle : uniforme conditionnellement à un but.
    for start, end in INTERVALS:
        probability = (1.0 - no_goal) * (end - start) / 90.0
        item = _prediction(match_id, "first_goal_interval", f"{start + 1}-{end}",
                           f"Premier but entre la {_interval_label(start, end)}", probability)
        if item:
            predictions.append(item)
    no_goal_item = _prediction(match_id, "first_goal_interval", "no_goal", "Aucun but dans le match", no_goal)
    if no_goal_item:
        predictions.append(no_goal_item)

    # Dernier but par intervalle (même hypothèse de taux constant).
    for start, end in INTERVALS:
        probability = (1.0 - no_goal) * (end - start) / 90.0
        item = _prediction(match_id, "last_goal_interval", f"{start + 1}-{end}",
                           f"Dernier but entre la {_interval_label(start, end)}", probability)
        if item:
            predictions.append(item)

    for minute in (15, 30, 45):
        probability = 1.0 - math.exp(-total_rate * minute / 90.0)
        for selection, value, label in (
            ("yes", probability, f"Au moins un but avant la {minute}e minute"),
            ("no", 1.0 - probability, f"Aucun but avant la {minute}e minute"),
        ):
            item = _prediction(match_id, "goal_before_minute", f"{selection}:{minute}", label, value)
            if item:
                predictions.append(item)

    for start, end in INTERVALS:
        interval_rate = total_rate * (end - start) / 90.0
        over = 1.0 - math.exp(-interval_rate)
        for selection, value, label in (
            ("over_0_5", over, f"Au moins un but entre la {_interval_label(start, end)}"),
            ("under_0_5", 1.0 - over, f"Aucun but entre la {_interval_label(start, end)}"),
        ):
            item = _prediction(match_id, "goals_by_interval", f"{start + 1}-{end}:{selection}", label, value)
            if item:
                predictions.append(item)

    return predictions


def goal_first_team_markets(match_id: str, lambda_home: float, lambda_away: float) -> List[Dict[str, Any]]:
    total_rate = max(1e-6, float(lambda_home) + float(lambda_away))
    no_goal = math.exp(-total_rate)
    home_share = float(lambda_home) / total_rate
    away_share = float(lambda_away) / total_rate
    predictions: List[Dict[str, Any]] = []
    for market_id, prefix in (("first_team_to_score", "Première équipe à marquer"), ("last_team_to_score", "Dernière équipe à marquer")):
        for selection, value, label in (
            ("home", home_share * (1.0 - no_goal), f"{prefix} : domicile"),
            ("away", away_share * (1.0 - no_goal), f"{prefix} : extérieur"),
            ("no_goal", no_goal, f"{prefix} : aucun but"),
        ):
            item = _prediction(match_id, market_id, selection, label, value, uncertainty=0.65)
            if item:
                predictions.append(item)
    return predictions
