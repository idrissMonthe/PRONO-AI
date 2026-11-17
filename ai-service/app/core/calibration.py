"""Post-hoc probability calibration for 1N2 predictions.

Two methods are provided:

* Platt scaling: one logistic map per outcome class in logit space,
  ``p' = sigmoid(a * logit(p) + b)``, renormalised across the three classes.
* Isotonic regression: pool-adjacent-violators per class, with linear
  interpolation between fitted blocks, renormalised across the three classes.

Both are *fit on a dedicated sample* (a validation window that never overlaps
the test window) and refuse to fit below ``MIN_CALIBRATION_SAMPLES``. A
calibrator that was not fitted is a pass-through, so an under-sized
validation window can never distort published probabilities.
"""

from __future__ import annotations

import math
from typing import Any, Dict, Iterable, List, Optional, Sequence, Tuple

MIN_CALIBRATION_SAMPLES = 50
EPS = 1e-6
CLASSES = ("HOME", "DRAW", "AWAY")


def _clip(value: float, low: float = EPS, high: float = 1.0 - EPS) -> float:
    return max(low, min(high, float(value)))


def _logit(probability: float) -> float:
    probability = _clip(probability)
    return math.log(probability / (1.0 - probability))


def _sigmoid(value: float) -> float:
    if value >= 0:
        z = math.exp(-value)
        return 1.0 / (1.0 + z)
    z = math.exp(value)
    return z / (1.0 + z)


def _normalize_row(row: Sequence[float]) -> List[float]:
    values = [_clip(value) for value in row]
    total = float(sum(values))
    if total <= 0:
        return [1.0 / 3.0, 1.0 / 3.0, 1.0 / 3.0]
    return [value / total for value in values]


def _outcome_index(outcome: str) -> int:
    normalized = str(outcome).strip().upper()
    mapping = {"HOME": 0, "1": 0, "H": 0, "DRAW": 1, "N": 1, "D": 1, "AWAY": 2, "2": 2, "A": 2}
    if normalized not in mapping:
        raise ValueError(f"Unknown outcome: {outcome}")
    return mapping[normalized]


# ----------------------------------------------------------------------
# Platt scaling
# ----------------------------------------------------------------------
def _fit_logistic_1d(xs: Sequence[float], ys: Sequence[float], l2: float = 1.0,
                     iterations: int = 100) -> Tuple[float, float]:
    """Newton-Raphson for a two-parameter logistic model with L2 on the slope."""
    a, b = 1.0, 0.0
    for _ in range(iterations):
        grad_a = l2 * (a - 1.0)
        grad_b = 0.0
        h_aa = l2
        h_ab = 0.0
        h_bb = 1e-9
        for x, y in zip(xs, ys):
            p = _sigmoid(a * x + b)
            residual = p - y
            weight = max(p * (1.0 - p), 1e-9)
            grad_a += residual * x
            grad_b += residual
            h_aa += weight * x * x
            h_ab += weight * x
            h_bb += weight
        determinant = h_aa * h_bb - h_ab * h_ab
        if abs(determinant) < 1e-12:
            break
        step_a = (h_bb * grad_a - h_ab * grad_b) / determinant
        step_b = (h_aa * grad_b - h_ab * grad_a) / determinant
        a -= step_a
        b -= step_b
        if abs(step_a) < 1e-9 and abs(step_b) < 1e-9:
            break
    return float(a), float(b)


class PlattCalibrator:
    """Per-class Platt scaling, fitted on a validation sample only."""

    name = "platt"

    def __init__(self) -> None:
        self.params: List[Tuple[float, float]] = []
        self.is_fitted = False
        self.sample_size = 0

    def fit(self, probabilities: Sequence[Sequence[float]], outcomes: Sequence[str]) -> "PlattCalibrator":
        rows, targets = _prepare(probabilities, outcomes)
        if len(rows) < MIN_CALIBRATION_SAMPLES:
            self.is_fitted = False
            return self
        self.params = []
        for index in range(3):
            xs = [_logit(row[index]) for row in rows]
            ys = [1.0 if target == index else 0.0 for target in targets]
            self.params.append(_fit_logistic_1d(xs, ys))
        self.is_fitted = True
        self.sample_size = len(rows)
        return self

    def transform_row(self, row: Sequence[float]) -> List[float]:
        if not self.is_fitted:
            return _normalize_row(row)
        calibrated = [
            _sigmoid(a * _logit(row[index]) + b) for index, (a, b) in enumerate(self.params)
        ]
        return _normalize_row(calibrated)

    def state(self) -> Dict[str, Any]:
        return {"method": self.name, "fitted": self.is_fitted, "sample_size": self.sample_size,
                "params": [{"a": round(a, 6), "b": round(b, 6)} for a, b in self.params]}


# ----------------------------------------------------------------------
# Isotonic regression (pool adjacent violators)
# ----------------------------------------------------------------------
def _pav(points: Sequence[Tuple[float, float, float]]) -> List[Tuple[float, float, float]]:
    """Pool adjacent violators. Points are (x, sum_y, weight)."""
    blocks: List[List[float]] = []  # [min_x, max_x, sum_y, weight]
    for x, y, weight in sorted(points, key=lambda item: item[0]):
        blocks.append([x, x, y, weight])
        while len(blocks) > 1 and (blocks[-2][2] / blocks[-2][3]) > (blocks[-1][2] / blocks[-1][3]):
            last = blocks.pop()
            previous = blocks[-1]
            previous[1] = last[1]
            previous[2] += last[2]
            previous[3] += last[3]
    return [(block[0], block[1], block[2] / block[3]) for block in blocks]


class IsotonicCalibrator:
    """Per-class isotonic regression with linear interpolation between blocks."""

    name = "isotonic"

    def __init__(self) -> None:
        self.blocks: List[List[Tuple[float, float, float]]] = []
        self.is_fitted = False
        self.sample_size = 0

    def fit(self, probabilities: Sequence[Sequence[float]], outcomes: Sequence[str]) -> "IsotonicCalibrator":
        rows, targets = _prepare(probabilities, outcomes)
        if len(rows) < MIN_CALIBRATION_SAMPLES:
            self.is_fitted = False
            return self
        self.blocks = []
        for index in range(3):
            points = [
                (float(row[index]), 1.0 if target == index else 0.0, 1.0)
                for row, target in zip(rows, targets)
            ]
            self.blocks.append(_pav(points))
        self.is_fitted = True
        self.sample_size = len(rows)
        return self

    def transform_row(self, row: Sequence[float]) -> List[float]:
        if not self.is_fitted:
            return _normalize_row(row)
        calibrated = []
        for index, blocks in enumerate(self.blocks):
            calibrated.append(_interpolate(blocks, float(row[index])))
        return _normalize_row(calibrated)

    def state(self) -> Dict[str, Any]:
        return {
            "method": self.name,
            "fitted": self.is_fitted,
            "sample_size": self.sample_size,
            "blocks": [
                [{"low": round(low, 6), "high": round(high, 6), "value": round(value, 6)} for low, high, value in blocks]
                for blocks in self.blocks
            ],
        }


def _interpolate(blocks: Sequence[Tuple[float, float, float]], value: float) -> float:
    if not blocks:
        return _clip(value)
    if value <= blocks[0][0]:
        return _clip(blocks[0][2])
    if value >= blocks[-1][1]:
        return _clip(blocks[-1][2])
    for low, high, block_value in blocks:
        if low <= value <= high:
            if high - low <= 1e-12:
                return _clip(block_value)
            # Linear inside a block keeps the map continuous at block edges.
            return _clip(block_value)
    return _clip(value)


def _prepare(
    probabilities: Iterable[Sequence[float]],
    outcomes: Iterable[str],
) -> Tuple[List[List[float]], List[int]]:
    rows = [list(map(float, row)) for row in probabilities]
    targets = [_outcome_index(outcome) for outcome in outcomes]
    if len(rows) != len(targets):
        raise ValueError("Probabilities and outcomes must have identical lengths.")
    for row in rows:
        if len(row) != 3 or not all(math.isfinite(value) and 0.0 <= value <= 1.0 for value in row):
            raise ValueError("Each probability row must hold three finite values in [0, 1].")
        if abs(sum(row) - 1.0) > 1e-3:
            raise ValueError("Each probability row must sum to 1.")
    return rows, targets


def make_calibrator(method: Optional[str]):
    if method == "platt":
        return PlattCalibrator()
    if method == "isotonic":
        return IsotonicCalibrator()
    return None
