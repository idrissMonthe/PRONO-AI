"""Marchés joueurs à partir des taux individuels observés.

Un marché joueur n'est produit que si :
1. l'identité du joueur est connue ;
2. sa participation est probable (titularisation ≥ 0,60 ou composition
   officielle confirmée) ;
3. un historique de minutes et d'événements existe (échantillon ≥ 8).

Le taux de but est ramené au temps de jeu attendu et à la force offensive de
son équipe (λ équipe / 1,35 buts de référence). Aucun nom n'est inventé et
aucun joueur sans historique n'est publié.
"""
from __future__ import annotations

import math
from typing import Any, Dict, List, Mapping

import numpy as np
from scipy.stats import poisson

from app.core.count_models import expected_win_probability, generate_lines, settle_over_under
from app.core.market_registry import market
from app.core.selection_engine import reliability_score

MIN_PLAYER_SAMPLE = 8
REFERENCE_TEAM_GOALS = 1.35


def _get(player: Mapping[str, Any], key: str, default: Any = None) -> Any:
    return player.get(key, default)


def _base(match_id: str, market_id: str, selection: str, label: str, probability: float,
          coverage: str, sample: int, uncertainty: float, justification: str,
          line: float | None = None) -> Dict[str, Any] | None:
    definition = market(market_id)
    if definition is None or not (0.01 <= probability <= 0.99):
        return None
    reliability = reliability_score(probability, coverage, 0.5, uncertainty, sample)
    return {
        "match_id": match_id,
        "market_id": market_id,
        "market_name": definition.name,
        "family": definition.family,
        "family_label": definition.family,
        "period": definition.period,
        "selection": selection,
        "selection_label": label,
        "line": line,
        "probability": round(float(probability), 4),
        "fair_odds": round(1.0 / probability, 3) if probability > 0 else None,
        "coverage": coverage,
        "sample_size": int(sample),
        "reliability": reliability,
        "uncertainty": uncertainty,
        "model": definition.model,
        "market_status": "conditional",
        "data_sources": ["player_statistics", "lineups"],
        "justification": justification,
        "missing_data": [] if _get({}, "x") is None else [],
        "settlement": None,
    }


def player_markets(
    match_id: str,
    players: List[Mapping[str, Any]],
    lambda_home: float,
    lambda_away: float,
) -> List[Dict[str, Any]]:
    predictions: List[Dict[str, Any]] = []
    total_rate = max(1e-6, lambda_home + lambda_away)
    for player in players:
        name = str(_get(player, "name", "") or "").strip()
        side = str(_get(player, "team", "") or "").lower()
        if not name or side not in {"home", "away"}:
            continue
        sample = int(_get(player, "sample_size", 0) or 0)
        if sample < MIN_PLAYER_SAMPLE:
            continue
        confirmed = bool(_get(player, "lineup_confirmed", False))
        start_probability = float(_get(player, "start_probability", 0.0) or 0.0)
        if not confirmed and start_probability < 0.60:
            continue
        expected_minutes = float(_get(player, "expected_minutes", 0.0) or 0.0)
        if expected_minutes <= 0:
            expected_minutes = 90.0 * max(start_probability, 0.6)
        minutes_share = min(1.0, expected_minutes / 90.0)
        team_lambda = lambda_home if side == "home" else lambda_away
        scale = max(0.35, min(2.5, team_lambda / REFERENCE_TEAM_GOALS))
        coverage = "partial" if confirmed or start_probability >= 0.85 else "thin"
        uncertainty = 0.30 if confirmed else 0.48

        goals_per90 = float(_get(player, "goals_per90", 0.0) or 0.0)
        goal_mean = goals_per90 * minutes_share * scale
        if goal_mean > 0:
            anytime = 1.0 - math.exp(-goal_mean)
            two_plus = 1.0 - math.exp(-goal_mean) * (1.0 + goal_mean)
            item = _base(match_id, "player_anytime_scorer", name,
                         f"{name} marque à tout moment", anytime, coverage, sample, uncertainty,
                         f"Taux observé {goals_per90:.2f} but/90, temps de jeu attendu "
                         f"{expected_minutes:.0f} min, contexte offensif ×{scale:.2f}.")
            if item:
                predictions.append(item)
            item = _base(match_id, "player_two_goals", name, f"{name} inscrit au moins deux buts",
                         two_plus, coverage, sample, uncertainty + 0.12,
                         "Loi de Poisson sur le taux individuel ; événement rare signalé.")
            if item:
                predictions.append(item)
            first = goal_mean / total_rate
            for market_id, label in (
                ("player_first_scorer", f"{name} marque le premier but"),
                ("player_last_scorer", f"{name} marque le dernier but"),
            ):
                item = _base(match_id, market_id, name, label, first, coverage, sample,
                             uncertainty + 0.10,
                             "Part du taux de but individuel dans le taux total du match.")
                if item:
                    predictions.append(item)

        shots_per90 = float(_get(player, "shots_per90", 0.0) or 0.0)
        if shots_per90 > 0:
            mean_shots = max(0.2, shots_per90 * minutes_share * scale)
            distribution = poisson.pmf(np.arange(16), mean_shots)
            for line in generate_lines(mean_shots, max(mean_shots, mean_shots * 1.05), max_lines=3):
                settlement = settle_over_under(distribution, line)
                for direction, entry in (
                    ("over", settlement),
                    ("under", {"win": settlement["loss"], "push": settlement["push"],
                               "half_win": settlement["half_loss"], "half_loss": settlement["half_win"],
                               "loss": settlement["win"]}),
                ):
                    item = _base(match_id, "player_total_shots", f"{name}:{direction}:{line}",
                                 f"{name} : {'plus' if direction == 'over' else 'moins'} de {line:g} tirs",
                                 expected_win_probability(entry), coverage, sample, uncertainty, "", line)
                    if item:
                        item["settlement"] = entry
                        predictions.append(item)

        sot_per90 = float(_get(player, "shots_on_target_per90", 0.0) or 0.0)
        if sot_per90 > 0:
            mean_sot = max(0.2, sot_per90 * minutes_share * scale)
            distribution = poisson.pmf(np.arange(12), mean_sot)
            for line in generate_lines(mean_sot, max(mean_sot, mean_sot * 1.05), max_lines=2):
                settlement = settle_over_under(distribution, line)
                item = _base(match_id, "player_shots_on_target", f"{name}:over:{line}",
                             f"{name} : plus de {line:g} tir(s) cadré(s)",
                             expected_win_probability(settlement), coverage, sample, uncertainty, "", line)
                if item:
                    item["settlement"] = settlement
                    predictions.append(item)

        cards_per90 = float(_get(player, "cards_per90", 0.0) or 0.0)
        if cards_per90 > 0:
            mean_cards = cards_per90 * minutes_share
            probability = 1.0 - math.exp(-mean_cards)
            item = _base(match_id, "player_carded", name, f"{name} reçoit un carton",
                         probability, coverage, sample, uncertainty + 0.10,
                         "Taux de carton observé, sans profil arbitral inventé.")
            if item:
                predictions.append(item)
    return predictions
