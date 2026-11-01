"""Specialized market heads built on top of the Dixon-Coles score matrix.

The specialists are deliberately conservative: they learn market-specific
priors from historical results and apply small, bounded calibrations so that
new markets do not overfit a small personal dataset.
"""
from __future__ import annotations

from typing import Any, Dict, Iterable
import numpy as np


class MarketSpecialists:
    def __init__(self) -> None:
        self.is_fitted = False
        self.sample_count = 0
        self.outcome_prior = np.array([1 / 3, 1 / 3, 1 / 3], dtype=float)
        self.btts_rate = 0.50
        self.average_total_goals = 2.50

    def fit(self, rows: Iterable[Any]) -> None:
        rows = list(rows)
        if not rows:
            self.is_fitted = False
            return

        weighted_outcomes = np.ones(3, dtype=float)  # Laplace smoothing.
        weighted_btts = 1.0
        weighted_no_btts = 1.0
        total_goals = 0.0
        total_weight = 0.0

        for row in rows:
            weight = float(np.exp(-0.0019 * max(0.0, float(row.days_ago))))
            if row.home_goals > row.away_goals:
                weighted_outcomes[0] += weight
            elif row.home_goals == row.away_goals:
                weighted_outcomes[1] += weight
            else:
                weighted_outcomes[2] += weight
            if row.home_goals > 0 and row.away_goals > 0:
                weighted_btts += weight
            else:
                weighted_no_btts += weight
            total_goals += weight * (row.home_goals + row.away_goals)
            total_weight += weight

        self.sample_count = len(rows)
        self.outcome_prior = weighted_outcomes / weighted_outcomes.sum()
        self.btts_rate = float(weighted_btts / (weighted_btts + weighted_no_btts))
        self.average_total_goals = float(total_goals / max(total_weight, 1e-9))
        self.is_fitted = True

    def state(self) -> Dict[str, Any]:
        return {
            "is_fitted": self.is_fitted,
            "sample_count": self.sample_count,
            "outcome_prior": self.outcome_prior.tolist(),
            "btts_rate": self.btts_rate,
            "average_total_goals": self.average_total_goals,
        }

    def load_state(self, state: Dict[str, Any]) -> None:
        if not state:
            return
        self.is_fitted = bool(state.get("is_fitted", False))
        self.sample_count = int(state.get("sample_count", 0))
        self.outcome_prior = np.array(state.get("outcome_prior", [1 / 3] * 3), dtype=float)
        self.outcome_prior /= max(float(self.outcome_prior.sum()), 1e-9)
        self.btts_rate = float(np.clip(state.get("btts_rate", 0.50), 0.05, 0.95))
        self.average_total_goals = float(np.clip(state.get("average_total_goals", 2.50), 0.2, 8.0))

    def predict(self, matrix: np.ndarray) -> Dict[str, np.ndarray]:
        """Return market-specific matrices with bounded historical calibration."""
        goals_matrix = self._tilt_total_goals(matrix)
        btts_matrix = self._calibrate_btts(goals_matrix)
        exact_matrix = goals_matrix.copy()
        return {
            "outcome": matrix,
            "goals": goals_matrix,
            "btts": btts_matrix,
            "exact_score": exact_matrix,
        }

    def _tilt_total_goals(self, matrix: np.ndarray) -> np.ndarray:
        if not self.is_fitted:
            return matrix
        i, j = np.indices(matrix.shape)
        total = i + j
        current_total = float(np.sum(matrix * total))
        target = 0.80 * current_total + 0.20 * self.average_total_goals
        target = float(np.clip(target, 0.5, 6.0))

        low, high = -0.35, 0.35
        for _ in range(36):
            theta = (low + high) / 2
            tilted = matrix * np.exp(theta * total)
            tilted /= max(float(tilted.sum()), 1e-12)
            expected = float(np.sum(tilted * total))
            if expected < target:
                low = theta
            else:
                high = theta
        tilted = matrix * np.exp(((low + high) / 2) * total)
        return tilted / max(float(tilted.sum()), 1e-12)

    def _calibrate_btts(self, matrix: np.ndarray) -> np.ndarray:
        i, j = np.indices(matrix.shape)
        yes_mask = (i >= 1) & (j >= 1)
        base_rate = float(np.sum(matrix[yes_mask]))
        if not self.is_fitted:
            return matrix
        target = float(np.clip(0.85 * base_rate + 0.15 * self.btts_rate, 0.05, 0.95))
        no_rate = max(1e-9, 1.0 - base_rate)
        calibrated = matrix.copy()
        calibrated[yes_mask] *= target / max(base_rate, 1e-9)
        calibrated[~yes_mask] *= (1.0 - target) / no_rate
        return calibrated / max(float(calibrated.sum()), 1e-12)
