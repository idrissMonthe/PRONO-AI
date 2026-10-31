"""
Devigging module: Margin removal algorithms (Shin, Power, Proportional).
Implements formulas from CDC Section 25.3 & 29.1.
"""
from typing import Dict, List, Literal, Tuple
import numpy as np
from scipy.optimize import brentq


def devig_proportional(odds: List[float]) -> List[float]:
    """
    Proportional margin removal:
    q_i = 1 / o_i, S = sum(q_i)
    p_i = q_i / S
    """
    _validate_odds(odds)
    inv_odds = [1.0 / o for o in odds]
    s = sum(inv_odds)
    probs = [q / s for q in inv_odds]
    return probs


def devig_power(odds: List[float]) -> List[float]:
    """
    Power margin removal:
    p_i = q_i^c such that sum(p_i) = 1.
    """
    _validate_odds(odds)
    inv_odds = np.array([1.0 / o for o in odds], dtype=np.float64)
    s = np.sum(inv_odds)

    # If already sum to 1 within tolerance
    if abs(s - 1.0) < 1e-9:
        return (inv_odds / s).tolist()

    def objective(c: float) -> float:
        return float(np.sum(np.power(inv_odds, c)) - 1.0)

    # c will typically be > 1 when margin > 0 (s > 1)
    try:
        c_opt = brentq(objective, 0.1, 10.0)
    except ValueError:
        # Fallback to proportional if root finding fails in range
        return devig_proportional(odds)

    probs = np.power(inv_odds, c_opt)
    probs /= np.sum(probs)
    return probs.tolist()


def devig_shin(odds: List[float]) -> Tuple[List[float], float]:
    """
    Shin's margin removal method (CDC Section 25.3 & 29.1).
    Models the proportion of insider/informed traders z in [0, 1).
    p_i = (sqrt(z^2 + 4*(1-z)*(q_i^2 / S)) - z) / (2*(1-z))
    Returns (normalized_probabilities, estimated_z).
    """
    _validate_odds(odds)
    inv_odds = np.array([1.0 / o for o in odds], dtype=np.float64)
    s = float(np.sum(inv_odds))

    if abs(s - 1.0) < 1e-9:
        return (inv_odds / s).tolist(), 0.0

    def shin_probs(z: float) -> np.ndarray:
        if abs(z) < 1e-12:
            return inv_odds / s
        nom = np.sqrt(z**2 + 4.0 * (1.0 - z) * (inv_odds**2) / s) - z
        denom = 2.0 * (1.0 - z)
        return nom / denom

    def objective(z: float) -> float:
        return float(np.sum(shin_probs(z)) - 1.0)

    try:
        # z must be strictly in [0, 0.99]
        z_opt = brentq(objective, 0.0, 0.95)
    except ValueError:
        # If no root in standard range, fallback to proportional
        return devig_proportional(odds), 0.0

    probs = shin_probs(z_opt)
    # Renormalize to exact 1.0 within 1e-9
    probs = probs / np.sum(probs)
    return probs.tolist(), float(z_opt)


def devig_odds(
    odds: List[float],
    method: Literal["shin", "power", "proportional"] = "shin",
) -> Dict[str, any]:
    """
    Unified devigging entrypoint.
    Returns:
      - true_probabilities: List[float]
      - fair_odds: List[float] (1 / p)
      - overround: float (sum(1/o) - 1)
      - method: str
      - param: extra parameter like z if applicable
    """
    _validate_odds(odds)
    inv_odds = [1.0 / o for o in odds]
    overround = sum(inv_odds) - 1.0

    if method == "shin":
        probs, z = devig_shin(odds)
        param = {"z_informed_fraction": round(z, 5)}
    elif method == "power":
        probs = devig_power(odds)
        param = {}
    elif method == "proportional":
        probs = devig_proportional(odds)
        param = {}
    else:
        raise ValueError(f"Unknown devig method: {method}")

    # Renormalize strictly
    p_sum = sum(probs)
    probs = [round(p / p_sum, 6) for p in probs]
    fair_odds = [round(1.0 / p, 4) if p > 0 else 999.0 for p in probs]

    return {
        "method": method,
        "input_odds": odds,
        "overround": round(overround, 4),
        "true_probabilities": probs,
        "fair_odds": fair_odds,
        "param": param,
    }


def _validate_odds(odds: List[float]) -> None:
    if not odds or len(odds) < 2:
        raise ValueError("At least 2 odds are required for margin removal.")
    for o in odds:
        if o <= 1.0:
            raise ValueError(f"Odds must be strictly greater than 1.0, got {o}")

