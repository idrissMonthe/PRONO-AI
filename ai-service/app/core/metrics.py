"""
Evaluation Metrics & Statistical Tests (CDC Section 7.14, 26.5, 29.1)
Implements:
- Ranked Probability Score (RPS)
- Multiclass Log Loss & Brier Score
- Expected Calibration Error (ECE) with adaptive binning
- Diebold-Mariano test for predictive difference significance
- Skill scores relative to baselines
"""
from typing import Dict, List, Tuple
import numpy as np
from scipy import stats


def calculate_rps(p_home: float, p_draw: float, p_away: float, outcome: str) -> float:
    """
    RPS (Ranked Probability Score) for 1N2 (ordinal outcome: home < draw < away).
    outcome is one of: 'HOME', 'DRAW', 'AWAY' (or '1', 'N', '2').
    RPS = 0.5 * [ (p_dom - o_dom)^2 + (p_dom + p_nul - o_dom - o_nul)^2 ]
    """
    o_home = 1.0 if outcome in ["HOME", "1", "H"] else 0.0
    o_draw = 1.0 if outcome in ["DRAW", "N", "D"] else 0.0
    o_away = 1.0 if outcome in ["AWAY", "2", "A"] else 0.0

    term1 = (p_home - o_home) ** 2
    term2 = ((p_home + p_draw) - (o_home + o_draw)) ** 2

    return float(0.5 * (term1 + term2))


def calculate_log_loss(p_home: float, p_draw: float, p_away: float, outcome: str, eps: float = 1e-12) -> float:
    """
    Log loss = -ln(p_observed)
    """
    if outcome in ["HOME", "1", "H"]:
        p_obs = max(eps, min(1.0 - eps, p_home))
    elif outcome in ["DRAW", "N", "D"]:
        p_obs = max(eps, min(1.0 - eps, p_draw))
    else:
        p_obs = max(eps, min(1.0 - eps, p_away))

    return float(-np.log(p_obs))


def calculate_brier_multiclass(p_home: float, p_draw: float, p_away: float, outcome: str) -> float:
    """
    Multiclass Brier score = sum_k (p_k - o_k)^2
    """
    o_home = 1.0 if outcome in ["HOME", "1", "H"] else 0.0
    o_draw = 1.0 if outcome in ["DRAW", "N", "D"] else 0.0
    o_away = 1.0 if outcome in ["AWAY", "2", "A"] else 0.0

    return float((p_home - o_home) ** 2 + (p_draw - o_draw) ** 2 + (p_away - o_away) ** 2)


def calculate_ece(
    probs: List[float],
    outcomes: List[int],
    n_bins: int = 10,
    adaptive: bool = True
) -> Tuple[float, List[Dict[str, float]]]:
    """
    Expected Calibration Error (ECE) with adaptive (quantile) or uniform binning.
    probs: predicted probability for class
    outcomes: binary indicator (1 if occurred, 0 otherwise)
    Returns (ece_value, bin_details).
    """
    if len(probs) == 0:
        return 0.0, []

    p_arr = np.array(probs)
    y_arr = np.array(outcomes)
    n = len(p_arr)

    if adaptive:
        # Quantile binning ensures equal sample sizes in each bin
        quantiles = np.linspace(0, 100, n_bins + 1)
        bin_edges = np.percentile(p_arr, quantiles)
        bin_edges = np.unique(bin_edges)
        if len(bin_edges) < 2:
            bin_edges = np.array([0.0, 1.0])
    else:
        bin_edges = np.linspace(0.0, 1.0, n_bins + 1)

    ece = 0.0
    bin_details = []

    for i in range(len(bin_edges) - 1):
        low, high = bin_edges[i], bin_edges[i + 1]
        if i == len(bin_edges) - 2:
            mask = (p_arr >= low) & (p_arr <= high)
        else:
            mask = (p_arr >= low) & (p_arr < high)

        bin_count = int(np.sum(mask))
        if bin_count == 0:
            continue

        bin_conf = float(np.mean(p_arr[mask]))
        bin_acc = float(np.mean(y_arr[mask]))
        bin_error = abs(bin_acc - bin_conf)

        ece += (bin_count / n) * bin_error

        bin_details.append({
            "bin_low": round(float(low), 3),
            "bin_high": round(float(high), 3),
            "count": bin_count,
            "avg_confidence": round(bin_conf, 4),
            "observed_frequency": round(bin_acc, 4),
            "calibration_gap": round(bin_error, 4)
        })

    return float(round(ece, 4)), bin_details


def diebold_mariano_test(losses_a: List[float], losses_b: List[float]) -> Dict[str, float]:
    """
    Diebold-Mariano test for statistical significance between two predictive loss streams.
    d_t = loss_A,t - loss_B,t
    H0: E[d_t] == 0 (no difference)
    H1: E[d_t] != 0
    Returns:
      - mean_diff: float
      - dm_statistic: float
      - p_value: float
      - is_significant_5pct: bool
    """
    if len(losses_a) != len(losses_b):
        raise ValueError("Loss lists must have identical lengths.")
    n = len(losses_a)
    if n < 10:
        return {
            "mean_diff": 0.0,
            "dm_statistic": 0.0,
            "p_value": 1.0,
            "is_significant_5pct": False,
            "warning": "Sample too small (< 10)"
        }

    d = np.array(losses_a) - np.array(losses_b)
    mean_d = float(np.mean(d))
    var_d = float(np.var(d, ddof=1))

    if var_d < 1e-12:
        return {
            "mean_diff": mean_d,
            "dm_statistic": 0.0,
            "p_value": 1.0,
            "is_significant_5pct": False,
        }

    # Autocorrelation correction (lag 1 Newey-West)
    autocov = float(np.cov(d[:-1], d[1:])[0, 1]) if n > 2 else 0.0
    spectral_var = max(1e-12, (var_d + 2.0 * max(0.0, autocov)) / n)
    dm_stat = mean_d / np.sqrt(spectral_var)

    p_val = 2.0 * (1.0 - stats.norm.cdf(abs(dm_stat)))

    return {
        "mean_diff": round(mean_d, 5),
        "dm_statistic": round(float(dm_stat), 4),
        "p_value": round(float(p_val), 5),
        "is_significant_5pct": bool(p_val < 0.05),
        "sample_size": n
    }

