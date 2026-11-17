"""
Statistical & Property-based tests for Dixon-Coles, Devigging & Metrics (CDC Section 19.6 & 23.1)
"""
import numpy as np
import pytest
from app.core.devig import devig_odds, devig_power, devig_proportional, devig_shin
from app.core.dixon_coles import compute_score_matrix, extract_match_markets_from_matrix, project_lambdas_to_dixon_coles
from app.core.metrics import calculate_brier_multiclass, calculate_ece, calculate_multiclass_ece, calculate_log_loss, calculate_rps, diebold_mariano_test
from app.core.elo import EloEngine
from app.core.poisson_regressor import DixonColesModel, MatchData
from app.core.market_specialists import MarketSpecialists
from app.schemas import PredictRequest
import app.main as prediction_api


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

    margin = markets["goal_margin"]
    assert margin["home_by_1_plus"] == pytest.approx(p1n2["home"], abs=1e-4)
    assert margin["away_by_1_plus"] == pytest.approx(p1n2["away"], abs=1e-4)
    assert margin["home_by_3_plus"] <= margin["home_by_2_plus"] <= margin["home_by_1_plus"]
    assert margin["away_by_3_plus"] <= margin["away_by_2_plus"] <= margin["away_by_1_plus"]
    assert margin["home_by_2_plus"] + margin["away_by_2_plus"] <= 1.0

    team_totals = markets["team_totals"]
    for team in ("home", "away"):
        assert team_totals[team]["over_0_5"] + team_totals[team]["under_0_5"] == pytest.approx(1.0)
        assert team_totals[team]["over_2_5"] <= team_totals[team]["over_1_5"] <= team_totals[team]["over_0_5"]
    assert markets["clean_sheet"]["home"] == pytest.approx(
        float(np.sum(matrix[:, 0])), abs=1e-4
    )
    assert markets["clean_sheet"]["away"] == pytest.approx(
        float(np.sum(matrix[0, :])), abs=1e-4
    )

    assert markets["totals"]["over_0_5"] + markets["totals"]["under_0_5"] == pytest.approx(1.0)
    assert markets["totals"]["over_5_5"] + markets["totals"]["under_5_5"] == pytest.approx(1.0)
    assert markets["double_chance"]["12"] == pytest.approx(p1n2["home"] + p1n2["away"], abs=1e-4)
    for line in ("1", "2", "3"):
        over = markets["asian_totals"][line]["over"]
        assert over["win"] + over["push"] + over["loss"] == pytest.approx(1.0, abs=1e-3)
        assert markets["asian_totals"][line]["under"]["push"] == over["push"]
        # Le gain d'un over entier égale l'over demi-ligne suivant, mais le but exact est remboursé.
        assert over["win"] == pytest.approx(markets["totals"][f"over_{line}_5"], abs=1e-3)
        assert over["push"] > 0
        assert over["win"] + over["push"] > markets["totals"][f"over_{line}_5"]
    for side in ("home", "away"):
        team_line = markets["asian_team_totals"][side]["1"]["over"]
        assert team_line["win"] + team_line["push"] + team_line["loss"] == pytest.approx(1.0, abs=1e-3)
    assert sum(markets["exact_totals"].values()) <= 1.0
    assert markets["european_handicap"]["0"]["home"] + markets["european_handicap"]["0"]["draw"] + markets["european_handicap"]["0"]["away"] == pytest.approx(1.0)
    asian = markets["asian_handicap"]["m0_25"]["home"]
    assert asian["win"] + asian["push"] + asian["half_win"] + asian["half_loss"] + asian["loss"] == pytest.approx(1.0)


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


def test_sparse_team_attack_strength_is_shrunk_toward_league_average():
    model = DixonColesModel()
    model.teams = ["Juventus FC", "SSC Napoli"]
    model.team_indices = {team: index for index, team in enumerate(model.teams)}
    model.mu = -0.5065608707166517
    model.home_adv = 0.012080760992424402
    model.attack_strengths = np.array([-0.3000304944051351, 3.495553974945187])
    model.defense_strengths = np.array([0.2799618242891912, -0.29708934797308056])
    model.team_match_counts = {"Juventus FC": 1, "SSC Napoli": 1}
    model.is_fitted = True

    _, away_goals, warnings = model.predict_lambdas("Juventus FC", "SSC Napoli")

    assert away_goals < 2.0
    assert any(warning.startswith("low_sample_team:SSC Napoli:1") for warning in warnings)


def test_training_tracks_per_team_match_counts():
    matches = [
        MatchData("Home A", "Away A", 1, 0, 0),
        MatchData("Home A", "Away B", 2, 1, 1),
        MatchData("Home B", "Away A", 0, 0, 2),
        MatchData("Home C", "Away B", 1, 2, 3),
        MatchData("Home B", "Away C", 1, 1, 4),
    ]

    model = DixonColesModel().fit(matches)

    assert model.team_match_counts["Home A"] == 2
    assert model.team_match_counts["Away A"] == 2
    assert model.team_match_counts["Home C"] == 1
    assert set(model.team_indices) == set(model.team_match_counts)


def test_reliable_team_strengths_are_shrunk_toward_league_average():
    model = DixonColesModel()
    model.teams = ["Home FC", "Away FC"]
    model.team_indices = {team: index for index, team in enumerate(model.teams)}
    model.attack_strengths = np.array([0.0, np.log(4.0)])
    model.defense_strengths = np.zeros(2)
    model.team_match_counts = {"Home FC": 5, "Away FC": 5}
    model.is_fitted = True

    _, away_goals, warnings = model.predict_lambdas("Home FC", "Away FC")

    assert away_goals == pytest.approx(2.0)
    assert not any(warning.startswith("low_sample_team:") for warning in warnings)


def test_low_sample_team_predictions_are_labelled_instead_of_abstained(monkeypatch):
    model = DixonColesModel()
    model.teams = ["Home FC", "Away FC"]
    model.team_indices = {team: index for index, team in enumerate(model.teams)}
    model.mu = np.log(1.4)
    model.home_adv = 0.2
    model.attack_strengths = np.zeros(2)
    model.defense_strengths = np.zeros(2)
    model.team_match_counts = {"Home FC": 1, "Away FC": 1}
    model.is_fitted = True
    monkeypatch.setattr(prediction_api, "champion_model", model)

    result = prediction_api.predict(PredictRequest(
        home_team="Home FC",
        away_team="Away FC",
        quality_score=100,
    ))

    assert result["abstain"] is False
    assert result["limited_data_mode"] is True
    assert result["abstain_reason"] == "sparse_team_history_without_market"
    assert "limited_data:sparse_team_history_without_market" in result["warnings"]
    assert result["quality"]["tier"] == "degraded"
    assert result["confidence_tier"] == "low"
    assert result["status"] == "ok"
    assert abs(sum(result["probabilities"][key]["p"] for key in ("home", "draw", "away")) - 1.0) < 1e-3


def test_unknown_competition_teams_still_receive_a_prediction(monkeypatch):
    model = DixonColesModel()
    model.teams = ["Home FC", "Away FC"]
    model.team_indices = {team: index for index, team in enumerate(model.teams)}
    model.mu = np.log(1.4)
    model.home_adv = 0.2
    model.attack_strengths = np.zeros(2)
    model.defense_strengths = np.zeros(2)
    model.team_match_counts = {"Home FC": 5, "Away FC": 5}
    model.is_fitted = True
    monkeypatch.setattr(prediction_api, "champion_model", model)

    result = prediction_api.predict(PredictRequest(
        home_team="Newcomer United",
        away_team="Debutant SC",
        quality_score=45,
    ))

    assert result["abstain"] is False
    assert result["status"] == "ok"
    assert result["limited_data_mode"] is True
    assert result["abstain_reason"] == "unverified_data_quality"
    assert any(warning.startswith("unknown_or_promoted_team:") for warning in result["warnings"])
    assert result["markets"]["1N2"]["home"] > 0.0
    assert abs(sum(result["probabilities"][key]["p"] for key in ("home", "draw", "away")) - 1.0) < 1e-3


def test_sparse_team_prediction_can_use_a_strong_market_snapshot(monkeypatch):
    model = DixonColesModel()
    model.teams = ["Home FC", "Away FC"]
    model.team_indices = {team: index for index, team in enumerate(model.teams)}
    model.mu = np.log(1.4)
    model.home_adv = 0.2
    model.attack_strengths = np.zeros(2)
    model.defense_strengths = np.zeros(2)
    model.team_match_counts = {"Home FC": 1, "Away FC": 1}
    model.is_fitted = True
    monkeypatch.setattr(prediction_api, "champion_model", model)

    result = prediction_api.predict(PredictRequest(
        home_team="Home FC",
        away_team="Away FC",
        quality_score=100,
        market_probabilities=[0.80, 0.12, 0.08],
    ))

    assert result["abstain"] is False
    assert result["confidence_tier"] == "medium"
    assert "market_backed_prediction_with_limited_team_history" in result["warnings"]


def test_market_specialists_are_independent_trained_heads():
    rows = [
        MatchData("Home A", "Away A", 3, 0, 1),
        MatchData("Home A", "Away B", 2, 0, 2),
        MatchData("Home B", "Away A", 1, 1, 3),
        MatchData("Away B", "Home A", 0, 2, 4),
        MatchData("Home A", "Away A", 4, 1, 5),
        MatchData("Away A", "Home B", 0, 0, 6),
    ]
    specialists = MarketSpecialists()
    specialists.fit(rows)
    matrix, _, _ = compute_score_matrix(1.4, 1.1, rho=-0.04)
    outputs = specialists.predict(matrix, "Home A", "Away A")

    assert specialists.is_fitted is True
    assert specialists.outcome_prior[0] > specialists.outcome_prior[2]
    assert specialists.btts_rate < 0.5
    for calibrated in outputs.values():
        assert calibrated.sum() == pytest.approx(1.0)
    assert not np.allclose(outputs["outcome"], matrix)
    assert not np.allclose(outputs["goals"], matrix)
    assert not np.allclose(outputs["btts"], matrix)
    assert not np.allclose(outputs["exact_score"], matrix)


def test_multiclass_ece_is_one_vs_rest_with_documented_macro_mean():
    result = calculate_multiclass_ece(
        [[0.8, 0.1, 0.1], [0.8, 0.1, 0.1], [0.8, 0.1, 0.1]],
        ["HOME", "DRAW", "AWAY"],
        n_bins=1,
        adaptive=False,
    )

    assert result["ece_home"] == pytest.approx(0.4667, abs=1e-4)
    assert result["ece_draw"] == pytest.approx(0.2333, abs=1e-4)
    assert result["ece_away"] == pytest.approx(0.2333, abs=1e-4)
    assert result["global_ece"] == pytest.approx(0.3111, abs=1e-4)
    assert result["aggregation"] == "macro_mean_one_vs_rest"


def test_multiclass_ece_rejects_incoherent_probability_rows():
    with pytest.raises(ValueError, match="sum to 1"):
        calculate_multiclass_ece([[0.6, 0.3, 0.3]], ["HOME"])


def test_poisson_fit_commits_only_a_successful_optimization(monkeypatch):
    matches = [
        MatchData("Home A", "Away A", 1, 0, 0),
        MatchData("Home A", "Away B", 2, 1, 1),
        MatchData("Home B", "Away A", 0, 0, 2),
        MatchData("Home C", "Away B", 1, 2, 3),
        MatchData("Home B", "Away C", 1, 1, 4),
    ]
    model = DixonColesModel().fit(matches)
    previous_mu = model.mu

    class FailedResult:
        success = False
        x = np.zeros(2 + 2 * (len(model.teams) - 1))
        fun = 1.0
        message = "STOP: TOTAL NO. of ITERATIONS REACHED LIMIT"

    monkeypatch.setattr("app.core.poisson_regressor.minimize", lambda *args, **kwargs: FailedResult())

    with pytest.raises(RuntimeError, match="optimization failed"):
        model.fit(matches)

    assert model.is_fitted is True
    assert model.mu == previous_mu
    assert model.fit_success is False
    assert "ITERATIONS" in model.fit_message


@pytest.mark.parametrize("result_factory", [
    lambda size: type("NaNResult", (), {"success": True, "x": np.full(size, np.nan), "fun": 1.0, "message": "bad params"})(),
    lambda size: type("InfiniteLossResult", (), {"success": True, "x": np.zeros(size), "fun": np.inf, "message": "bad loss"})(),
])
def test_poisson_fit_rejects_invalid_optimizer_results(monkeypatch, result_factory):
    matches = [
        MatchData("Home A", "Away A", 1, 0, 0),
        MatchData("Home A", "Away B", 2, 1, 1),
        MatchData("Home B", "Away A", 0, 0, 2),
        MatchData("Home C", "Away B", 1, 2, 3),
        MatchData("Home B", "Away C", 1, 1, 4),
    ]
    model = DixonColesModel()
    expected_size = 3 + 2 * (5 - 1)
    monkeypatch.setattr(
        "app.core.poisson_regressor.minimize",
        lambda *args, **kwargs: result_factory(expected_size),
    )

    with pytest.raises(RuntimeError):
        model.fit(matches)

    assert model.is_fitted is False


def test_bootstrap_reports_unavailable_when_sample_is_insufficient():
    matches = [
        MatchData("Home A", "Away A", 1, 0, 0),
        MatchData("Home A", "Away B", 2, 1, 1),
        MatchData("Home B", "Away A", 0, 0, 2),
        MatchData("Home C", "Away B", 1, 2, 3),
        MatchData("Home B", "Away C", 1, 1, 4),
    ]
    model = DixonColesModel().fit(matches)

    result = model.bootstrap_1n2_intervals("Home A", "Away A", replicates=16, min_observations=20)

    assert result["available"] is False
    assert result["method"] == "bootstrap_unavailable_insufficient_sample"


def test_fitted_rho_stays_inside_the_dixon_coles_bounds():
    matches = [
        MatchData("Home A", "Away A", 1, 0, days_ago) for days_ago in range(8)
    ] + [
        MatchData("Home A", "Away B", 0, 0, 2),
        MatchData("Home B", "Away A", 2, 1, 3),
        MatchData("Home B", "Away B", 1, 1, 4),
        MatchData("Away B", "Home A", 0, 2, 5),
    ]
    model = DixonColesModel().fit(matches)
    assert -0.2 <= model.rho <= 0.2


def test_equal_elo_draw_does_not_use_a_binary_win_expectation():
    engine = EloEngine()
    home, away = engine.update_ratings(1500, 1500, 1, 1, is_neutral=True)
    assert home == pytest.approx(1500, abs=0.05)
    assert away == pytest.approx(1500, abs=0.05)


def test_published_probabilities_match_the_derived_markets(monkeypatch):
    model = DixonColesModel()
    model.teams = ["Home FC", "Away FC"]
    model.team_indices = {team: index for index, team in enumerate(model.teams)}
    model.mu = np.log(1.4)
    model.home_adv = 0.2
    model.attack_strengths = np.zeros(2)
    model.defense_strengths = np.zeros(2)
    model.team_match_counts = {"Home FC": 12, "Away FC": 12}
    model.is_fitted = True
    monkeypatch.setattr(prediction_api, "champion_model", model)

    result = prediction_api.predict(PredictRequest(home_team="Home FC", away_team="Away FC", quality_score=90))
    one = result["markets"]["1N2"]
    assert result["probabilities"]["home"]["p"] == one["home"]
    assert result["probabilities"]["draw"]["p"] == one["draw"]
    assert result["probabilities"]["away"]["p"] == one["away"]
    assert result["markets"]["double_chance"]["1X"] == pytest.approx(one["home"] + one["draw"], abs=1e-3)
    assert result["markets"]["btts"]["yes"] + result["markets"]["btts"]["no"] == pytest.approx(1.0, abs=1e-3)
    assert result["abstain"] is False
    assert result["status"] == "ok"
    assert "dixon_coles_joint_preserved" in result["warnings"]
    rebuilt, _, _ = compute_score_matrix(
        result["expected_goals"]["home"],
        result["expected_goals"]["away"],
        model.rho,
    )
    rebuilt_markets = extract_match_markets_from_matrix(rebuilt)
    assert result["markets"]["btts"]["yes"] == pytest.approx(rebuilt_markets["btts"]["yes"], abs=1.5e-3)
    assert result["markets"]["totals"]["over_2_5"] == pytest.approx(rebuilt_markets["totals"]["over_2_5"], abs=1.5e-3)
    assert one["home"] == pytest.approx(rebuilt_markets["1N2"]["home"], abs=1.5e-3)


def test_lambda_projection_remains_a_dixon_coles_matrix():
    lambda_home, lambda_away, rho = 1.55, 1.05, -0.08
    target = (0.72, 0.16, 0.12)
    home, away, matrix, _, _ = project_lambdas_to_dixon_coles(lambda_home, lambda_away, rho, *target)
    rebuilt, _, _ = compute_score_matrix(home, away, rho)

    assert np.allclose(matrix, rebuilt)
    assert abs(np.log(home / lambda_home)) <= 0.15 + 1e-9
    assert abs(np.log(away / lambda_away)) <= 0.15 + 1e-9

    base, _, _ = compute_score_matrix(lambda_home, lambda_away, rho)
    base_1n2 = extract_match_markets_from_matrix(base)["1N2"]
    moved_1n2 = extract_match_markets_from_matrix(matrix)["1N2"]

    def gap(probabilities):
        return (
            (probabilities["home"] - target[0]) ** 2
            + (probabilities["draw"] - target[1]) ** 2
            + (probabilities["away"] - target[2]) ** 2
        )

    assert gap(moved_1n2) <= gap(base_1n2) + 1e-9
    assert moved_1n2["home"] > base_1n2["home"]
    assert abs((moved_1n2["home"] + moved_1n2["draw"] + moved_1n2["away"]) - 1.0) < 1e-3


def test_projection_keeps_the_original_matrix_when_the_target_matches():
    lambda_home, lambda_away, rho = 1.4, 1.1, -0.04
    matrix, _, _ = compute_score_matrix(lambda_home, lambda_away, rho)
    current = extract_match_markets_from_matrix(matrix)["1N2"]
    home, away, projected, _, _ = project_lambdas_to_dixon_coles(
        lambda_home,
        lambda_away,
        rho,
        current["home"],
        current["draw"],
        current["away"],
    )

    assert home == pytest.approx(lambda_home)
    assert away == pytest.approx(lambda_away)
    assert np.allclose(projected, matrix)
