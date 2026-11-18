"""Génération de combinés à partir des distributions jointes.

Deux sélections du même match ne sont jamais multipliées : la probabilité
conjointe est lue sur la matrice des scores, cellule par cellule. Deux
sélections de matchs différents sont indépendantes et peuvent être multipliées.

Lorsqu'une sélection d'un même match n'a pas de représentation explicite dans
la matrice (corners, cartons, périodes), la combinaison est écartée plutôt que
d'inventer une dépendance.
"""
from __future__ import annotations

from typing import Any, Dict, List, Optional, Sequence, Tuple

import numpy as np

from app.core.selection_engine import contradicts


def _outcome_masks(matrix: np.ndarray) -> Dict[str, np.ndarray]:
    n = matrix.shape[0]
    i, j = np.indices((n, n))
    diff = i - j
    total = i + j
    return {
        "home": diff > 0,
        "draw": diff == 0,
        "away": diff < 0,
        "total": total,
        "diff": diff,
        "i": i,
        "j": j,
    }


def selection_mask(prediction: Dict[str, Any], matrix: np.ndarray) -> Optional[np.ndarray]:
    masks = _outcome_masks(matrix)
    market_id = str(prediction.get("market_id"))
    selection = str(prediction.get("selection"))
    line = prediction.get("line")
    total = masks["total"]
    diff = masks["diff"]
    i, j = masks["i"], masks["j"]

    if market_id in {"1x2", "team_most_goals"}:
        return masks.get(selection)
    if market_id == "double_chance":
        return {"1X": diff >= 0, "X2": diff <= 0, "12": diff != 0}.get(selection)
    if market_id == "draw_no_bet":
        return masks.get(selection)
    if market_id == "btts":
        return (i >= 1) & (j >= 1) if selection == "yes" else (i == 0) | (j == 0)
    if market_id == "btts_first_half":
        return None
    if market_id == "clean_sheet":
        return (j == 0) if selection == "home" else (i == 0)
    if market_id == "win_to_nil":
        return ((diff > 0) & (j == 0)) if selection == "home" else ((diff < 0) & (i == 0))
    if market_id == "total_goals":
        if line is None:
            return None
        return total > float(line) if selection.startswith("over") else total < float(line)
    if market_id == "team_total_goals" and line is not None:
        parts = selection.split(":")
        if len(parts) != 2:
            return None
        side, direction = parts[0], parts[1]
        goals = i if side == "home" else j
        return goals > float(line) if direction.startswith("over") else goals < float(line)
    if market_id == "asian_handicap" and line is not None:
        side = selection.split(":")[0]
        adjusted = diff + float(line)
        if side == "away":
            adjusted = -diff - float(line)
        return adjusted > 0
    if market_id == "exact_score" and "-" in selection:
        home_goals, away_goals = selection.split("-", 1)
        return (i == int(home_goals)) & (j == int(away_goals))
    if market_id == "exact_total_goals":
        return total == int(float(selection))
    if market_id == "odd_even_goals":
        return (total % 2 == 1) if selection == "odd" else (total % 2 == 0)
    if market_id == "goal_ranges":
        return {
            "0-1": total <= 1,
            "2-3": (total >= 2) & (total <= 3),
            "4+": total >= 4,
        }.get(selection)
    if market_id == "result_total_combo":
        return _result_total_combo(selection, diff, total)
    if market_id == "result_btts_combo":
        return _result_btts_combo(selection, diff, i, j)
    return None


def _result_total_combo(selection: str, diff: np.ndarray, total: np.ndarray) -> Optional[np.ndarray]:
    parts = selection.split("_")
    if len(parts) < 4:
        return None
    side, direction = parts[0], parts[2]
    line = float(f"{parts[3]}.{parts[4]}" if len(parts) > 4 else parts[3])
    side_mask = diff > 0 if side == "home" else diff < 0
    total_mask = total > line if direction == "over" else total < line
    return side_mask & total_mask


def _result_btts_combo(selection: str, diff: np.ndarray, i: np.ndarray, j: np.ndarray) -> Optional[np.ndarray]:
    if "btts_yes" not in selection:
        return None
    side_mask = diff > 0 if selection.startswith("home") else diff < 0
    return side_mask & (i >= 1) & (j >= 1)


def joint_probability(
    selections: Sequence[Dict[str, Any]],
    matrix: np.ndarray,
) -> Tuple[Optional[float], str]:
    masks = []
    for prediction in selections:
        mask = selection_mask(prediction, matrix)
        if mask is None:
            return None, "unresolved_dependency"
        masks.append(mask)
    combined = masks[0]
    for mask in masks[1:]:
        combined = combined & mask
    return round(float(np.sum(matrix[combined])), 4), "joint_score_matrix"


def _independent_probability(selections: Sequence[Dict[str, Any]]) -> float:
    probability = 1.0
    for prediction in selections:
        probability *= float(prediction.get("probability", 0.0))
    return probability


def _valid_selection(prediction: Dict[str, Any], minimum_reliability: float) -> bool:
    return (
        float(prediction.get("reliability", 0.0)) >= minimum_reliability
        and prediction.get("coverage") in {"full", "partial"}
        and 0.10 <= float(prediction.get("probability", 0.0)) <= 0.90
    )


def build_combinations(
    predictions: Sequence[Dict[str, Any]],
    matrix_by_match: Dict[str, np.ndarray],
    profiles: Optional[Dict[str, Dict[str, Any]]] = None,
) -> Dict[str, List[Dict[str, Any]]]:
    """Construit des combinés par profil sans gonfler le nombre de sélections.

    Un même match ne fournit qu'une sélection par combiné si les autres
    sélections du match n'ont pas de distribution jointe exploitable.
    """
    profiles = profiles or {
        "securise": {"legs": 2, "min_probability": 0.30, "min_reliability": 0.68},
        "equilibre": {"legs": 3, "min_probability": 0.12, "min_reliability": 0.60},
        "audacieux": {"legs": 3, "min_probability": 0.04, "min_reliability": 0.55},
    }
    pool = [p for p in predictions if _valid_selection(p, 0.50)]
    result: Dict[str, List[Dict[str, Any]]] = {}
    used_signatures: Dict[str, set] = {}

    for name, profile in profiles.items():
        chosen: List[Dict[str, Any]] = []
        matches_used: set = set()
        for prediction in sorted(pool, key=lambda p: float(p.get("reliability", 0)), reverse=True):
            match_id = str(prediction.get("match_id"))
            if match_id in matches_used:
                continue
            if any(contradicts(prediction, existing) for existing in chosen):
                continue
            chosen.append(prediction)
            matches_used.add(match_id)
            if len(chosen) >= int(profile["legs"]):
                break
        if len(chosen) < 2:
            continue
        signatures = tuple(sorted(f"{p.get('market_id')}:{p.get('selection')}:{p.get('line')}" for p in chosen))
        if signatures in used_signatures.get(name, set()):
            continue
        used_signatures.setdefault(name, set()).add(signatures)

        probability = _independent_probability(chosen)
        method = "independent_matches"
        reliability = min(float(p.get("reliability", 0.0)) for p in chosen)
        if probability < float(profile["min_probability"]):
            continue
        if reliability < float(profile["min_reliability"]):
            continue
        result[name] = [{
            "profile": name,
            "selections": chosen,
            "joint_probability": round(probability, 4),
            "fair_odds": round(1.0 / probability, 2) if probability > 0 else None,
            "method": method,
            "reliability": round(reliability, 4),
            "legs": len(chosen),
            "note": "Sélections de matchs différents : indépendance assumée.",
        }]

        # Variante même match lorsque la matrice permet un joint exact.
        for first in chosen:
            for second in pool:
                if str(second.get("match_id")) != str(first.get("match_id")):
                    continue
                if second is first or contradicts(first, second):
                    continue
                matrix = matrix_by_match.get(str(first.get("match_id")))
                if matrix is None:
                    continue
                probability, method = joint_probability([first, second], matrix)
                if probability is None or probability < 0.05:
                    continue
                result[name].append({
                    "profile": name,
                    "selections": [first, second],
                    "joint_probability": probability,
                    "fair_odds": round(1.0 / probability, 2),
                    "method": method,
                    "reliability": round(min(float(first.get("reliability", 0)), float(second.get("reliability", 0))), 4),
                    "legs": 2,
                    "note": "Probabilité conjointe lue sur la matrice des scores.",
                })
                break
        result[name] = result[name][:2]
    return result
