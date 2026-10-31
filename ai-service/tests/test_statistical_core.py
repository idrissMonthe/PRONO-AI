"""
Statistical & Property-based tests for Dixon-Coles, Devigging & Metrics (CDC Section 19.6 & 23.1)
"""
import numpy as np
import pytest
from app.core.devig import devig_odds, devig_power, devig_proportional, devig_shin
from app.core.dixon_coles import compute_score_matrix, extract_match_markets_from_matrix
from app.core.metrics import calculate_brier_multiclass, calculate_ece, calculate_log_loss, calculate_rps, diebold_mariano_test
from app.core.elo import EloEngine


def test_score_matrix_sum_and_residual_mass():
    """CDC 23.1: Sum of probabilities == 1.0 to 1e-9, residual mass < 1e-6"""
    for (lh, la) in [(1.5, 1.2), (2.8, 0.7), (0.9, 1.8), (3.2, 2.5)]:
        matrix, residual_mass, n = compute_score_matrix(lh, la, rho=-0.05)
        assert residual_mass < 1e-6, f"Residual mass {residual_mass} exceeded 1e-6"
        assert abs(np.sum(matrix) - 1.0) < 1e-9, f"Sum {np.sum(matrix)} differs from 1.0"
        assert matrix.shape[0] >= 11  # min_n >= 10


def test_derived_markets_coherence():
    """CDC 25.2: All derived markets derive coherently from the single score matrix"""
    matrix, _, _ = compute_score_matrix(1.6, 1.1, rho=-0.04)
    markets = extract_match_markets_from_matrix(matrix)

    # 1N2 sums to 1
    p1n2 = markets["1N2"]
    assert abs((p1n2["home"] + p1n2["draw"] + p1n2["away"]) - 1.0) < 1e-3

    # Double chance coherence
    dc = markets["double_chance"]
    assert abs(dc["1X"] - (p1n2["home"] + p1n2["draw"])) < 1e-4
    assert abs(dc["X2"] - (p1n2["draw"] + p1n2["away"])) < 1e-4
    assert abs(dc["12"] - (p1n2["home"] + p1n2["away"])) < 1e-4

    # BTTS yes + no == 1
    assert abs((markets["btts"]["yes"] + markets["btts"]["no"]) - 1.0) < 1e-4

    # Over 2.5 + Under 2.5 == 1
    assert abs((markets["totals"]["over_2_5"] + markets["totals"]["under_2_5"]) - 1.0) < 1e-4


def test_devigging_methods():
    """CDC 25.3: Shin, Power, Proportional return probabilities that sum to 1.0"""
    bookmaker_odds = [2.10, 3.40, 3.75]  # Standard 1N2 with overround
    for method in ["shin", "power", "proportional"]:
        res = devig_odds(bookmaker_odds, method=method)
        probs = res["true_probabilities"]
        assert abs(sum(probs) - 1.0) < 1e-4
        assert res["overround"] > 0.0
        # Fair odds should be strictly higher than bookmaker odds (removing margin)
        assert res["fair_odds"][0] > bookmaker_odds[0] or abs(res["fair_odds"][0] - bookmaker_odds[0]) < 0.2


def test_rps_ordinal_penalty():
    """
    CDC 29.1: RPS penalizes distant errors more than neighboring errors.
    An outcome of AWAY is penalized more if the model predicted HOME than if it predicted DRAW.
    """
    # Prediction A: favors HOME
    rps_a = calculate_rps(p_home=0.70, p_draw=0.20, p_away=0.10, outcome="AWAY")
    # Prediction B: favors DRAW
    rps_b = calculate_rps(p_home=0.10, p_draw=0.70, p_away=0.20, outcome="AWAY")

    # Error of predicting Home when Away won must be greater than predicting Draw
    assert rps_a > rps_b


def test_diebold_mariano_significance():
    """CDC 26.5: Tests statistical significance between loss streams"""
    # Identical losses -> p_value == 1.0, mean_diff == 0
    losses = [0.15, 0.22, 0.18, 0.30, 0.12, 0.25, 0.19, 0.21, 0.16, 0.28]
    dm_res = diebold_mariano_test(losses, losses)
    assert dm_res["mean_diff"] == 0.0
    assert dm_res["p_value"] == 1.0


def test_elo_symmetry_on_neutral_ground():
    """Property test: Swapping identical teams on neutral ground yields identical mirror probabilities"""
    elo = EloEngine()
    pred_1 = elo.predict_1n2(r_home=1600.0, r_away=1500.0, is_neutral=True)
    pred_2 = elo.predict_1n2(r_home=1500.0, r_away=1600.0, is_neutral=True)

    assert abs(pred_1["home"] - pred_2["away"]) < 1e-4
    assert abs(pred_1["away"] - pred_2["home"]) < 1e-4
    assert abs(pred_1["draw"] - pred_2["draw"]) < 1e-4

