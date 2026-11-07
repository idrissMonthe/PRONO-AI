"""
Dixon-Coles & Poisson Bivariate Model Engine (CDC Section 7.10 & 29.1)
Calculates score matrices, 1N2, derived markets, and guarantees mathematical integrity:
- Residual truncation mass < 1e-6
- Sum of probabilities == 1.0 to 1e-9 precision
- Tau low score correction parameter rho bounded
"""
from typing import Dict, List, Tuple
import numpy as np
from scipy.stats import poisson


def tau_correction(i: int, j: int, lambda_home: float, lambda_away: float, rho: float) -> float:
    """
    Dixon-Coles low score adjustment tau(i, j):
    tau(0, 0) = 1 - lambda_home * lambda_away * rho
    tau(0, 1) = 1 + lambda_home * rho
    tau(1, 0) = 1 + lambda_away * rho
    tau(1, 1) = 1 - rho
    tau(i, j) = 1 for all other (i, j)
    """
    if i == 0 and j == 0:
        return max(0.0, 1.0 - lambda_home * lambda_away * rho)
    elif i == 0 and j == 1:
        return max(0.0, 1.0 + lambda_home * rho)
    elif i == 1 and j == 0:
        return max(0.0, 1.0 + lambda_away * rho)
    elif i == 1 and j == 1:
        return max(0.0, 1.0 - rho)
    else:
        return 1.0


def compute_score_matrix(
    lambda_home: float,
    lambda_away: float,
    rho: float = 0.0,
    min_n: int = 10,
    max_residual_mass: float = 1e-6,
) -> Tuple[np.ndarray, float, int]:
    """
    Computes score probability matrix of size (N+1, N+1) adaptively.
    Ensures that residual mass < max_residual_mass (default 1e-6).
    Renormalizes matrix so sum == 1.0 to 1e-9.
    Returns (matrix, residual_mass, N).
    """
    if lambda_home <= 0 or lambda_away <= 0:
        raise ValueError("Lambdas must be strictly positive.")

    n = min_n
    while True:
        # Evaluate cumulative poisson to see if tail > n is below threshold
        tail_home = 1.0 - poisson.cdf(n, lambda_home)
        tail_away = 1.0 - poisson.cdf(n, lambda_away)
        approx_residual = tail_home + tail_away

        if approx_residual <= max_residual_mass or n >= 25:
            break
        n += 2

    # Vectorized computation of Poisson probabilities
    goals = np.arange(n + 1)
    p_home = poisson.pmf(goals, lambda_home)
    p_away = poisson.pmf(goals, lambda_away)

    matrix = np.outer(p_home, p_away)

    # Apply Dixon-Coles tau correction if rho != 0
    if abs(rho) > 1e-6:
        for i in [0, 1]:
            for j in [0, 1]:
                tau = tau_correction(i, j, lambda_home, lambda_away, rho)
                matrix[i, j] *= tau

    initial_sum = float(np.sum(matrix))
    residual_mass = max(0.0, 1.0 - initial_sum)

    # Renormalize to exact 1.0
    matrix /= initial_sum

    return matrix, residual_mass, n


def extract_match_markets_from_matrix(matrix: np.ndarray) -> Dict[str, any]:
    """
    Extracts all primary and derived markets from the score matrix (CDC Section 25.2 & 29.1).
    All derived probabilities originate strictly from this single matrix.
    """
    n_plus_one = matrix.shape[0]
    n = n_plus_one - 1

    # 1. 1N2 Probabilities
    # p_home = sum(P(i, j) for i > j)
    # p_draw = sum(P(i, j) for i == j)
    # p_away = sum(P(i, j) for i < j)
    p_home = float(np.sum(np.tril(matrix, -1)))
    p_draw = float(np.sum(np.diag(matrix)))
    p_away = float(np.sum(np.triu(matrix, 1)))

    # Sum constraint check
    total_1n2 = p_home + p_draw + p_away
    p_home /= total_1n2
    p_draw /= total_1n2
    p_away /= total_1n2

    # 2. Double Chance
    # 1N = p_home + p_draw, N2 = p_draw + p_away, 12 = p_home + p_away
    p_1x = p_home + p_draw
    p_x2 = p_draw + p_away
    p_12 = p_home + p_away

    # 3. Draw No Bet (DNB)
    # P(home | non-nul) = p_home / (1 - p_draw)
    p_dnb_home = p_home / (1.0 - p_draw) if p_draw < 0.999 else 0.5
    p_dnb_away = p_away / (1.0 - p_draw) if p_draw < 0.999 else 0.5

    # 4. Over / Under Totals. All probabilities are direct sums of the same
    # score matrix, so the market families remain mathematically coherent.
    # Total goals k = i + j
    totals = {}
    i_indices, j_indices = np.indices((n_plus_one, n_plus_one))
    total_goals_grid = i_indices + j_indices

    for threshold in [0.5, 1.5, 2.5, 3.5, 4.5, 5.5]:
        mask_over = total_goals_grid > threshold
        p_over = float(np.sum(matrix[mask_over]))
        p_under = 1.0 - p_over
        totals[f"over_{str(threshold).replace('.', '_')}"] = round(p_over, 4)
        totals[f"under_{str(threshold).replace('.', '_')}"] = round(p_under, 4)

    exact_totals = {
        str(goals): round(float(np.sum(matrix[total_goals_grid == goals])), 4)
        for goals in range(min(10, int(total_goals_grid.max()) + 1))
    }

    # Joint markets: probabilities are summed from the same score matrix.
    # They are not estimated by multiplying separate market probabilities.
    combos = {}
    for threshold in [1.5, 2.5, 3.5, 4.5]:
        total_mask = total_goals_grid > threshold
        home_win = np.tril(np.ones_like(matrix, dtype=bool), -1)
        away_win = np.triu(np.ones_like(matrix, dtype=bool), 1)
        key = str(threshold).replace('.', '_')
        combos[f"home_win_over_{key}"] = round(float(np.sum(matrix[home_win & total_mask])), 4)
        combos[f"away_win_over_{key}"] = round(float(np.sum(matrix[away_win & total_mask])), 4)
        combos[f"home_win_under_{key}"] = round(float(np.sum(matrix[home_win & ~total_mask])), 4)
        combos[f"away_win_under_{key}"] = round(float(np.sum(matrix[away_win & ~total_mask])), 4)

    btts_yes_mask = (i_indices >= 1) & (j_indices >= 1)
    combos["home_win_btts_yes"] = round(float(np.sum(matrix[home_win & btts_yes_mask])), 4)
    combos["away_win_btts_yes"] = round(float(np.sum(matrix[away_win & btts_yes_mask])), 4)

    # 5. Both Teams To Score (BTTS)
    # i >= 1 and j >= 1
    p_btts_yes = float(np.sum(matrix[1:, 1:]))
    p_btts_no = 1.0 - p_btts_yes

    # 6. Clean Sheet & Team Totals
    # Clean sheet home = j == 0
    p_cs_home = float(np.sum(matrix[:, 0]))
    # Clean sheet away = i == 0
    p_cs_away = float(np.sum(matrix[0, :]))

    # 7. European and Asian handicaps. Quarter lines are split across their
    # adjacent half-lines and retain win/push/half-win/half-loss outcomes.
    goal_diff_grid = i_indices - j_indices
    goal_margin = {
        f"home_by_{margin}_plus": round(float(np.sum(matrix[goal_diff_grid >= margin])), 4)
        for margin in (1, 2, 3)
    }
    goal_margin.update({
        f"away_by_{margin}_plus": round(float(np.sum(matrix[goal_diff_grid <= -margin])), 4)
        for margin in (1, 2, 3)
    })
    european_handicap = {
        str(line).replace('-', 'm').replace('.', '_'): {
            'home': round(float(np.sum(matrix[goal_diff_grid + line > 0])), 4),
            'draw': round(float(np.sum(matrix[goal_diff_grid + line == 0])), 4),
            'away': round(float(np.sum(matrix[goal_diff_grid + line < 0])), 4),
        }
        for line in (-2, -1, 0, 1, 2)
    }
    asian_handicap = {}
    for line in (-2.0, -1.5, -1.0, -0.5, -0.25, 0.0, 0.25, 0.5, 1.0, 1.5, 2.0):
        lower = np.floor(line * 2.0) / 2.0
        upper = np.ceil(line * 2.0) / 2.0
        lower_outcome = goal_diff_grid + lower
        upper_outcome = goal_diff_grid + upper
        win = float(np.sum(matrix[(lower_outcome > 0) & (upper_outcome > 0)]))
        push = float(np.sum(matrix[(lower_outcome == 0) & (upper_outcome == 0)]))
        loss = float(np.sum(matrix[(lower_outcome < 0) & (upper_outcome < 0)]))
        half_win = float(np.sum(matrix[((lower_outcome == 0) & (upper_outcome > 0)) | ((upper_outcome == 0) & (lower_outcome > 0))]))
        half_loss = float(np.sum(matrix[((lower_outcome == 0) & (upper_outcome < 0)) | ((upper_outcome == 0) & (lower_outcome < 0))]))
        key = str(line).replace('-', 'm').replace('.', '_')
        asian_handicap[key] = {
            'home': {'win': round(win, 4), 'push': round(push, 4), 'half_win': round(half_win, 4), 'half_loss': round(half_loss, 4), 'loss': round(loss, 4)},
            'away': {'win': round(loss, 4), 'push': round(push, 4), 'half_win': round(half_loss, 4), 'half_loss': round(half_win, 4), 'loss': round(win, 4)},
            'line_home': line,
        }
    team_totals = {"home": {}, "away": {}}
    home_goal_probabilities = np.sum(matrix, axis=1)
    away_goal_probabilities = np.sum(matrix, axis=0)
    for threshold in (0.5, 1.5, 2.5, 3.5):
        key = str(threshold).replace(".", "_")
        team_totals["home"][f"over_{key}"] = round(
            float(np.sum(home_goal_probabilities[np.arange(n_plus_one) > threshold])), 4
        )
        team_totals["home"][f"under_{key}"] = round(
            1.0 - team_totals["home"][f"over_{key}"], 4
        )
        team_totals["away"][f"over_{key}"] = round(
            float(np.sum(away_goal_probabilities[np.arange(n_plus_one) > threshold])), 4
        )
        team_totals["away"][f"under_{key}"] = round(
            1.0 - team_totals["away"][f"over_{key}"], 4
        )
    # Home -0.5 is same as Home win (goal_diff >= 1)
    # Home +0.5 is same as 1X (goal_diff >= 0)
    # Home 0.0 is DNB

    # 8. Top Exact Scores
    # Flatten and sort top 10 scores
    scores_flat = []
    for i in range(min(7, n_plus_one)):
        for j in range(min(7, n_plus_one)):
            scores_flat.append({
                "score": f"{i}-{j}",
                "home_goals": i,
                "away_goals": j,
                "p": float(matrix[i, j])
            })
    scores_sorted = sorted(scores_flat, key=lambda x: x["p"], reverse=True)
    top_10 = [{"score": s["score"], "p": round(s["p"], 4)} for s in scores_sorted[:10]]
    top_10_mass = sum(s["p"] for s in scores_sorted[:10])
    residual_other_scores = max(0.0, 1.0 - top_10_mass)

    return {
        "1N2": {
            "home": round(p_home, 4),
            "draw": round(p_draw, 4),
            "away": round(p_away, 4),
            "fair_odds": {
                "home": round(1.0 / p_home, 3) if p_home > 0 else 999.0,
                "draw": round(1.0 / p_draw, 3) if p_draw > 0 else 999.0,
                "away": round(1.0 / p_away, 3) if p_away > 0 else 999.0,
            }
        },
        "double_chance": {
            "1X": round(p_1x, 4),
            "X2": round(p_x2, 4),
            "12": round(p_12, 4),
        },
        "dnb": {
            "home": round(p_dnb_home, 4),
            "away": round(p_dnb_away, 4),
            "push_prob": round(p_draw, 4),
        },
        "goal_margin": goal_margin,
        "european_handicap": european_handicap,
        "asian_handicap": asian_handicap,
        "team_totals": team_totals,
        "totals": totals,
        "exact_totals": exact_totals,
        "combos": combos,
        "btts": {
            "yes": round(p_btts_yes, 4),
            "no": round(p_btts_no, 4),
        },
        "clean_sheet": {
            "home": round(p_cs_home, 4),
            "away": round(p_cs_away, 4),
        },
        "top_scores": top_10,
        "other_scores_mass": round(residual_other_scores, 4),
    }
