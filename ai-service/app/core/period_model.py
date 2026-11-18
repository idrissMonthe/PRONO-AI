"""Modélisation des marchés par période.

Les marchés de mi-temps ne sont pas obtenus en divisant les buts attendus par
deux. La part de buts marquée en première période est estimée sur les rencontres
disposant réellement d'un score à la mi-temps (``matches.ht_home_score``). Sans
échantillon suffisant, le moteur applique une valeur de repli explicitement
marquée ``thin``/``none`` et la fiabilité des marchés est abaissée.

Le score final est la convolution des deux mi-temps (indépendance conditionnelle
documentée). Le joint HT/FT est donc calculé sur la distribution jointe, pas par
produit de deux probabilités marginales.
"""
from __future__ import annotations

from dataclasses import dataclass
from typing import Any, Dict, Iterable, Mapping, Optional, Tuple

import numpy as np

from app.core.dixon_coles import compute_score_matrix, extract_match_markets_from_matrix

DEFAULT_FIRST_HALF_SHARE = 0.45
MIN_PERIOD_SAMPLE = 20
FULL_PERIOD_SAMPLE = 80


def _get(row: Any, key: str) -> Any:
    if isinstance(row, Mapping):
        return row.get(key)
    return getattr(row, key, None)


def result_index(home_goals: int, away_goals: int) -> int:
    if home_goals > away_goals:
        return 0
    if home_goals == away_goals:
        return 1
    return 2


HTFT_KEYS = ("1/1", "1/X", "1/2", "X/1", "X/X", "X/2", "2/1", "2/X", "2/2")


@dataclass
class PeriodShare:
    """Part observée des buts inscrits avant la pause."""

    share: float = DEFAULT_FIRST_HALF_SHARE
    sample_size: int = 0
    first_half_goals: int = 0
    full_time_goals: int = 0
    source: str = "prior"

    def coverage(self) -> str:
        if self.sample_size < 5:
            return "none"
        if self.sample_size < MIN_PERIOD_SAMPLE:
            return "thin"
        if self.sample_size < FULL_PERIOD_SAMPLE:
            return "partial"
        return "full"

    def reliability_factor(self) -> float:
        return {"none": 0.0, "thin": 0.35, "partial": 0.7, "full": 1.0}[self.coverage()]

    def to_dict(self) -> Dict[str, Any]:
        return {
            "first_half_share": round(float(self.share), 4),
            "sample_size": int(self.sample_size),
            "first_half_goals": int(self.first_half_goals),
            "full_time_goals": int(self.full_time_goals),
            "coverage": self.coverage(),
            "source": self.source,
        }


class PeriodModel:
    """Estime la répartition des buts entre les deux mi-temps."""

    def __init__(self) -> None:
        self.state = PeriodShare()

    def fit(self, rows: Iterable[Any]) -> PeriodShare:
        ht_goals = 0
        ft_goals = 0
        sample = 0
        for row in rows:
            ht_home = _get(row, "ht_home_score")
            ht_away = _get(row, "ht_away_score")
            # Les lignes d'entraînement exposent home_goals/away_goals, tandis
            # que les résultats stockés exposent home_score/away_score.
            home = _get(row, "home_score")
            away = _get(row, "away_score")
            if home is None:
                home = _get(row, "home_goals")
            if away is None:
                away = _get(row, "away_goals")
            if None in (ht_home, ht_away, home, away):
                continue
            if int(ht_home) < 0 or int(ht_away) < 0:
                continue
            ht_goals += int(ht_home) + int(ht_away)
            ft_goals += int(home) + int(away)
            sample += 1
        if sample > 0 and ft_goals > 0:
            share = float(np.clip(ht_goals / ft_goals, 0.30, 0.62))
            self.state = PeriodShare(
                share=share,
                sample_size=sample,
                first_half_goals=ht_goals,
                full_time_goals=ft_goals,
                source="observed_first_half_scores",
            )
        else:
            self.state = PeriodShare()
        return self.state

    def load(self, payload: Optional[Dict[str, Any]]) -> None:
        if not payload:
            return
        sample = int(payload.get("sample_size", 0) or 0)
        if sample <= 0:
            return
        self.state = PeriodShare(
            share=float(np.clip(float(payload.get("first_half_share", DEFAULT_FIRST_HALF_SHARE)), 0.30, 0.62)),
            sample_size=sample,
            first_half_goals=int(payload.get("first_half_goals", 0) or 0),
            full_time_goals=int(payload.get("full_time_goals", 0) or 0),
            source=str(payload.get("source") or "observed_first_half_scores"),
        )

    def lambdas(self, lambda_home: float, lambda_away: float) -> Dict[str, float]:
        share = float(self.state.share)
        return {
            "first_half_home": float(lambda_home) * share,
            "first_half_away": float(lambda_away) * share,
            "second_half_home": float(lambda_home) * (1.0 - share),
            "second_half_away": float(lambda_away) * (1.0 - share),
            "first_half_share": share,
        }


def _halves(lambda_home: float, lambda_away: float, rho: float, share: float) -> Tuple[np.ndarray, np.ndarray]:
    first, _, _ = compute_score_matrix(max(0.02, lambda_home * share), max(0.02, lambda_away * share), rho)
    second, _, _ = compute_score_matrix(max(0.02, lambda_home * (1.0 - share)), max(0.02, lambda_away * (1.0 - share)), rho)
    return first, second


def htft_joint(first_half: np.ndarray, second_half: np.ndarray) -> Dict[str, float]:
    """Distribution jointe (résultat mi-temps, résultat final).

    Chaque cellule de la première mi-temps est combinée à chaque cellule de la
    seconde. Le total HT/FT vaut 1 et chaque résultat final retrouve bien sa
    probabilité marginale.
    """
    joint = np.zeros((3, 3), dtype=float)
    first_n = first_half.shape[0]
    second_n = second_half.shape[0]
    for i in range(first_n):
        for j in range(first_n):
            p_first = float(first_half[i, j])
            if p_first <= 0.0:
                continue
            ht_result = result_index(i, j)
            for k in range(second_n):
                for l in range(second_n):
                    p_second = float(second_half[k, l])
                    if p_second <= 0.0:
                        continue
                    joint[ht_result, result_index(i + k, j + l)] += p_first * p_second
    total = float(joint.sum())
    if total <= 0:
        return {key: 0.0 for key in HTFT_KEYS}
    joint /= total
    return {
        HTFT_KEYS[ht * 3 + ft]: round(float(joint[ht, ft]), 4)
        for ht in range(3)
        for ft in range(3)
    }


def period_markets(
    lambda_home: float,
    lambda_away: float,
    rho: float,
    share: float,
) -> Dict[str, Any]:
    """Marchés des deux mi-temps construits sur leurs matrices respectives."""
    first, second = _halves(lambda_home, lambda_away, rho, share)
    first_markets = extract_match_markets_from_matrix(first)
    second_markets = extract_match_markets_from_matrix(second)
    first_totals = first_markets["totals"]
    second_totals = second_markets["totals"]

    joint = htft_joint(first, second)
    # P(nul dans aucune mi-temps) : aucune des deux colonnes du joint n'est X.
    both_not_draw = sum(
        value
        for key, value in joint.items()
        if key.split("/")[0] != "X" and key.split("/")[1] != "X"
    )
    first_counts = np.arange(first.shape[0])
    second_counts = np.arange(second.shape[0])
    # Une mi-temps sans but correspond exactement à la cellule 0-0.
    no_goal_first = float(first[0, 0])
    no_goal_second = float(second[0, 0])
    first_total_goals = float(np.sum(
        (first_counts[:, None] + first_counts[None, :]) * first
    ))
    second_total_goals = float(np.sum(
        (second_counts[:, None] + second_counts[None, :]) * second
    ))

    return {
        "first_half": {
            "markets": first_markets,
            "expected_goals": round(first_total_goals, 3),
        },
        "second_half": {
            "markets": second_markets,
            "expected_goals": round(second_total_goals, 3),
        },
        "htft": joint,
        "goal_in_both_halves": round((1.0 - no_goal_first) * (1.0 - no_goal_second), 4),
        "draw_in_at_least_one_half": round(1.0 - both_not_draw, 4),
        "no_draw_in_any_half": round(both_not_draw, 4),
        "most_prolific_half": most_prolific_half_probabilities(first, second),
        "first_half_totals": first_totals,
        "second_half_totals": second_totals,
        "share": round(float(share), 4),
    }


def most_prolific_half_probabilities(first: np.ndarray, second: np.ndarray) -> Dict[str, float]:
    """Compare les totaux de buts des deux mi-temps (distribution jointe)."""
    first_totals = np.zeros(first.shape[0] * 2)
    second_totals = np.zeros(second.shape[0] * 2)
    for i in range(first.shape[0]):
        for j in range(first.shape[0]):
            first_totals[i + j] += float(first[i, j])
    for i in range(second.shape[0]):
        for j in range(second.shape[0]):
            second_totals[i + j] += float(second[i, j])
    more_first = 0.0
    equal = 0.0
    more_second = 0.0
    for a, p_first in enumerate(first_totals):
        if p_first <= 0:
            continue
        for b, p_second in enumerate(second_totals):
            if p_second <= 0:
                continue
            probability = p_first * p_second
            if a > b:
                more_first += probability
            elif a == b:
                equal += probability
            else:
                more_second += probability
    return {
        "first_half": round(more_first, 4),
        "tie": round(equal, 4),
        "second_half": round(more_second, 4),
    }
