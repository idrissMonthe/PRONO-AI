"""Independent, regularized heads for 1N2, totals, BTTS and exact score.

Each head is trained against its own historical target. Dixon-Coles remains
the structural prior, while the specialists apply bounded empirical-Bayes
calibration. This keeps the model stable when the local history is small.
"""
from __future__ import annotations

from math import factorial
from typing import Any, Dict, Iterable

import numpy as np


MAX_EXACT_GOALS = 6
DECAY = 0.0019
SMOOTHING = 6.0


class MarketSpecialists:
    def __init__(self) -> None:
        self.is_fitted = False
        self.sample_count = 0
        self.outcome_prior = np.array([1 / 3, 1 / 3, 1 / 3], dtype=float)
        self.total_goal_prior = np.full((MAX_EXACT_GOALS * 2) + 1, 1 / ((MAX_EXACT_GOALS * 2) + 1), dtype=float)
        self.btts_rate = 0.50
        self.exact_score_prior = np.full((MAX_EXACT_GOALS + 1, MAX_EXACT_GOALS + 1), 1 / ((MAX_EXACT_GOALS + 1) ** 2), dtype=float)
        self.team_profiles: Dict[str, Dict[str, float]] = {}
        self.head_versions = {
            "1n2": "outcome_empirical_bayes_v2",
            "goals": "goals_team_rates_v2",
            "btts": "btts_scoring_conceding_v2",
            "exact_score": "exact_score_frequency_prior_v2",
        }

    def fit(self, rows: Iterable[Any]) -> None:
        rows = list(rows)
        if not rows:
            self.is_fitted = False
            return

        outcome = np.ones(3, dtype=float)
        totals = np.ones_like(self.total_goal_prior)
        exact = np.ones_like(self.exact_score_prior)
        weighted_btts, weighted_no_btts = 1.0, 1.0
        profiles: Dict[str, Dict[str, float]] = {}

        for row in rows:
            weight = float(np.exp(-DECAY * max(0.0, float(row.days_ago))))
            home_goals, away_goals = int(row.home_goals), int(row.away_goals)
            outcome[0 if home_goals > away_goals else (1 if home_goals == away_goals else 2)] += weight
            totals[min(home_goals + away_goals, len(totals) - 1)] += weight
            exact[min(home_goals, MAX_EXACT_GOALS), min(away_goals, MAX_EXACT_GOALS)] += weight
            if home_goals > 0 and away_goals > 0:
                weighted_btts += weight
            else:
                weighted_no_btts += weight
            self._update_profile(profiles, row.home_team, home_goals, away_goals, weight, home_goals > away_goals, home_goals == away_goals)
            self._update_profile(profiles, row.away_team, away_goals, home_goals, weight, away_goals > home_goals, home_goals == away_goals)

        self.sample_count = len(rows)
        self.outcome_prior = outcome / outcome.sum()
        self.total_goal_prior = totals / totals.sum()
        self.exact_score_prior = exact / exact.sum()
        self.btts_rate = float(weighted_btts / (weighted_btts + weighted_no_btts))
        self.team_profiles = profiles
        self.is_fitted = True

    def state(self) -> Dict[str, Any]:
        return {
            "is_fitted": self.is_fitted,
            "sample_count": self.sample_count,
            "outcome_prior": self.outcome_prior.tolist(),
            "total_goal_prior": self.total_goal_prior.tolist(),
            "btts_rate": self.btts_rate,
            "exact_score_prior": self.exact_score_prior.tolist(),
            "team_profiles": self.team_profiles,
            "head_versions": self.head_versions,
        }

    def load_state(self, state: Dict[str, Any]) -> None:
        if not state:
            return
        self.is_fitted = bool(state.get("is_fitted", False))
        self.sample_count = int(state.get("sample_count", 0))
        self.outcome_prior = self._normalized_vector(state.get("outcome_prior"), 3)
        self.total_goal_prior = self._normalized_vector(state.get("total_goal_prior"), (MAX_EXACT_GOALS * 2) + 1)
        self.btts_rate = float(np.clip(state.get("btts_rate", 0.50), 0.05, 0.95))
        exact = np.asarray(state.get("exact_score_prior", []), dtype=float)
        if exact.shape != (MAX_EXACT_GOALS + 1, MAX_EXACT_GOALS + 1) or exact.sum() <= 0:
            exact = np.ones((MAX_EXACT_GOALS + 1, MAX_EXACT_GOALS + 1), dtype=float)
        self.exact_score_prior = exact / exact.sum()
        self.team_profiles = {
            str(team): {str(key): float(value) for key, value in profile.items()}
            for team, profile in (state.get("team_profiles") or {}).items()
            if isinstance(profile, dict)
        }
        self.head_versions = {str(key): str(value) for key, value in (state.get("head_versions") or self.head_versions).items()}

    def predict(self, matrix: np.ndarray, home_team: str, away_team: str) -> Dict[str, np.ndarray]:
        """Produces separate calibrated matrices for each market family."""
        return {
            "outcome": self._calibrate_outcome(matrix, home_team, away_team),
            "goals": self._calibrate_totals(matrix, home_team, away_team),
            "btts": self._calibrate_btts(matrix, home_team, away_team),
            "exact_score": self._calibrate_exact_score(matrix, home_team, away_team),
        }

    def _calibrate_outcome(self, matrix: np.ndarray, home_team: str, away_team: str) -> np.ndarray:
        if not self.is_fitted:
            return matrix
        home_mask, draw_mask, away_mask = self._outcome_masks(matrix)
        base = np.array([float(matrix[home_mask].sum()), float(matrix[draw_mask].sum()), float(matrix[away_mask].sum())])
        delta = np.clip(self._points_per_match(home_team) - self._points_per_match(away_team), -1.0, 1.0)
        team_target = self._softmax(np.array([0.18 + delta, 0.10 - abs(delta) * 0.10, -delta]))
        target = self._normalize(0.55 * base + 0.30 * self.outcome_prior + 0.15 * team_target)
        return self._rescale_regions(matrix, [home_mask, draw_mask, away_mask], target)

    def _calibrate_totals(self, matrix: np.ndarray, home_team: str, away_team: str) -> np.ndarray:
        if not self.is_fitted:
            return matrix
        total_grid = np.indices(matrix.shape).sum(axis=0)
        base = self._total_distribution(matrix, total_grid)
        target = self._normalize(
            0.50 * base
            + 0.25 * self._resize_total_prior(len(base))
            + 0.25 * self._poisson_distribution(self._team_total_mean(home_team, away_team), len(base))
        )
        calibrated = matrix.copy()
        for total, mass in enumerate(target):
            mask = total_grid == total
            current = float(matrix[mask].sum())
            if current > 0:
                calibrated[mask] *= mass / current
        return self._normalize_matrix(calibrated)

    def _calibrate_btts(self, matrix: np.ndarray, home_team: str, away_team: str) -> np.ndarray:
        if not self.is_fitted:
            return matrix
        i, j = np.indices(matrix.shape)
        yes_mask = (i >= 1) & (j >= 1)
        base_rate = float(matrix[yes_mask].sum())
        team_target = self._scoring_chance(home_team, away_team) * self._scoring_chance(away_team, home_team)
        target = float(np.clip(0.45 * base_rate + 0.25 * self.btts_rate + 0.30 * team_target, 0.03, 0.97))
        calibrated = matrix.copy()
        calibrated[yes_mask] *= target / max(base_rate, 1e-12)
        calibrated[~yes_mask] *= (1.0 - target) / max(1.0 - base_rate, 1e-12)
        return self._normalize_matrix(calibrated)

    def _calibrate_exact_score(self, matrix: np.ndarray, home_team: str, away_team: str) -> np.ndarray:
        if not self.is_fitted:
            return matrix
        team_distribution = self._score_distribution(
            self._expected_goals(home_team, away_team),
            self._expected_goals(away_team, home_team),
            matrix.shape,
        )
        return self._normalize_matrix(0.35 * matrix + 0.40 * self._resize_exact_prior(matrix.shape) + 0.25 * team_distribution)

    def _update_profile(self, profiles: Dict[str, Dict[str, float]], team: str, goals_for: int, goals_against: int, weight: float, won: bool, drew: bool) -> None:
        profile = profiles.setdefault(team, {"matches": 0.0, "points": 0.0, "goals_for": 0.0, "goals_against": 0.0, "scored": 0.0, "conceded": 0.0})
        profile["matches"] += weight
        profile["points"] += weight * (3.0 if won else (1.0 if drew else 0.0))
        profile["goals_for"] += weight * goals_for
        profile["goals_against"] += weight * goals_against
        profile["scored"] += weight * float(goals_for > 0)
        profile["conceded"] += weight * float(goals_against > 0)

    def _profile(self, team: str) -> Dict[str, float]:
        return self.team_profiles.get(team, {"matches": 0.0, "points": 1.0, "goals_for": 1.25, "goals_against": 1.25, "scored": 0.5, "conceded": 0.5})

    def _shrink(self, value: float, matches: float, prior: float) -> float:
        return (value * matches + prior * SMOOTHING) / max(matches + SMOOTHING, 1e-12)

    def _points_per_match(self, team: str) -> float:
        profile = self._profile(team)
        return self._shrink(profile["points"] / max(profile["matches"], 1e-12), profile["matches"], 1.0)

    def _team_total_mean(self, home_team: str, away_team: str) -> float:
        home, away = self._profile(home_team), self._profile(away_team)
        home_for = self._shrink(home["goals_for"] / max(home["matches"], 1e-12), home["matches"], 1.35)
        home_against = self._shrink(home["goals_against"] / max(home["matches"], 1e-12), home["matches"], 1.15)
        away_for = self._shrink(away["goals_for"] / max(away["matches"], 1e-12), away["matches"], 1.15)
        away_against = self._shrink(away["goals_against"] / max(away["matches"], 1e-12), away["matches"], 1.35)
        return float(np.clip((home_for + away_against + away_for + home_against) / 2.0, 0.5, 6.0))

    def _expected_goals(self, scorer: str, opponent: str) -> float:
        own, opposition = self._profile(scorer), self._profile(opponent)
        own_rate = self._shrink(own["goals_for"] / max(own["matches"], 1e-12), own["matches"], 1.25)
        conceded_rate = self._shrink(opposition["goals_against"] / max(opposition["matches"], 1e-12), opposition["matches"], 1.25)
        return float(np.clip((own_rate + conceded_rate) / 2.0, 0.1, 4.5))

    def _scoring_chance(self, scorer: str, opponent: str) -> float:
        own, opposition = self._profile(scorer), self._profile(opponent)
        own_rate = self._shrink(own["scored"] / max(own["matches"], 1e-12), own["matches"], 0.68)
        conceded_rate = self._shrink(opposition["conceded"] / max(opposition["matches"], 1e-12), opposition["matches"], 0.68)
        return float(np.clip((own_rate + conceded_rate) / 2.0, 0.05, 0.95))

    def _resize_total_prior(self, size: int) -> np.ndarray:
        prior = np.zeros(size, dtype=float)
        for index, mass in enumerate(self.total_goal_prior):
            prior[min(index, size - 1)] += mass
        return self._normalize(prior)

    def _resize_exact_prior(self, shape: tuple[int, int]) -> np.ndarray:
        prior = np.zeros(shape, dtype=float)
        for i in range(self.exact_score_prior.shape[0]):
            for j in range(self.exact_score_prior.shape[1]):
                prior[min(i, shape[0] - 1), min(j, shape[1] - 1)] += self.exact_score_prior[i, j]
        return self._normalize_matrix(prior)

    def _score_distribution(self, home_mean: float, away_mean: float, shape: tuple[int, int]) -> np.ndarray:
        home = np.array([np.exp(-home_mean) * (home_mean ** goals) / factorial(goals) for goals in range(shape[0])])
        away = np.array([np.exp(-away_mean) * (away_mean ** goals) / factorial(goals) for goals in range(shape[1])])
        return self._normalize_matrix(np.outer(home, away))

    @staticmethod
    def _outcome_masks(matrix: np.ndarray) -> tuple[np.ndarray, np.ndarray, np.ndarray]:
        return (
            np.tril(np.ones_like(matrix, dtype=bool), -1),
            np.eye(matrix.shape[0], dtype=bool),
            np.triu(np.ones_like(matrix, dtype=bool), 1),
        )

    @staticmethod
    def _rescale_regions(matrix: np.ndarray, masks: list[np.ndarray], target: np.ndarray) -> np.ndarray:
        calibrated = matrix.copy()
        for mask, mass in zip(masks, target):
            current = float(matrix[mask].sum())
            if current > 0:
                calibrated[mask] *= mass / current
        return MarketSpecialists._normalize_matrix(calibrated)

    @staticmethod
    def _total_distribution(matrix: np.ndarray, total_grid: np.ndarray) -> np.ndarray:
        return np.array([float(matrix[total_grid == value].sum()) for value in range(int(total_grid.max()) + 1)])

    @staticmethod
    def _poisson_distribution(mean: float, size: int) -> np.ndarray:
        return MarketSpecialists._normalize(np.array([np.exp(-mean) * (mean ** value) / factorial(value) for value in range(size)]))

    @staticmethod
    def _softmax(values: np.ndarray) -> np.ndarray:
        return MarketSpecialists._normalize(np.exp(values - np.max(values)))

    @staticmethod
    def _normalized_vector(value: Any, size: int) -> np.ndarray:
        vector = np.asarray(value if value is not None else [], dtype=float)
        if vector.shape != (size,) or vector.sum() <= 0:
            vector = np.ones(size, dtype=float)
        return MarketSpecialists._normalize(vector)

    @staticmethod
    def _normalize(values: np.ndarray) -> np.ndarray:
        return values / max(float(values.sum()), 1e-12)

    @staticmethod
    def _normalize_matrix(matrix: np.ndarray) -> np.ndarray:
        return matrix / max(float(matrix.sum()), 1e-12)
