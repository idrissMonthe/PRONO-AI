import numpy as np
import pytest
from scipy.stats import poisson

from app.core.combination_engine import build_combinations, joint_probability
from app.core.count_models import (
    count_distribution,
    expected_win_probability,
    generate_lines,
    settle_over_under,
)
from app.core.dixon_coles import compute_score_matrix, extract_match_markets_from_matrix
from app.core.market_engine import MarketEngine
from app.core.market_registry import MARKETS, REGISTRY, by_status, registry_summary
from app.core.period_model import PeriodModel, period_markets
from app.core.player_engine import player_markets
from app.core.selection_engine import category_for, contradicts, rank_categories
from app.core.time_events import goal_time_markets


@pytest.fixture
def matrix():
    matrix, _, _ = compute_score_matrix(1.55, 1.05, -0.04)
    return matrix


@pytest.fixture
def period_model():
    model = PeriodModel()
    model.fit([
        {"ht_home_score": 1, "ht_away_score": 0, "home_score": 2, "away_score": 1}
        for _ in range(40)
    ])
    return model


def test_exclusive_markets_sum_to_one(matrix):
    markets = extract_match_markets_from_matrix(matrix)
    one_x_two = markets["1N2"]
    assert one_x_two["home"] + one_x_two["draw"] + one_x_two["away"] == pytest.approx(1.0, abs=1e-3)
    totals = markets["totals"]
    for line in ["0_5", "1_5", "2_5", "3_5"]:
        assert totals[f"over_{line}"] + totals[f"under_{line}"] == pytest.approx(1.0, abs=1e-3)
    assert markets["btts"]["yes"] + markets["btts"]["no"] == pytest.approx(1.0, abs=1e-3)
    assert sum(markets["exact_totals"].values()) == pytest.approx(1.0, abs=2e-2)
    assert markets["double_chance"]["1X"] == pytest.approx(one_x_two["home"] + one_x_two["draw"], abs=1e-3)


def test_asian_handicap_settlement_is_exhaustive(matrix):
    markets = extract_match_markets_from_matrix(matrix)
    for entry in markets["asian_handicap"].values():
        for side in ("home", "away"):
            settlement = entry[side]
            total = sum(settlement[key] for key in ("win", "push", "half_win", "half_loss", "loss"))
            assert total == pytest.approx(1.0, abs=2e-3)
            assert expected_win_probability(settlement) <= 1.0


def test_quarter_line_half_win_is_not_a_full_win():
    distribution = poisson.pmf(np.arange(20), 2.9)
    settlement = settle_over_under(distribution, 2.75)
    full_win = float(distribution[np.arange(20) > 3].sum())
    exact_three = float(distribution[3])
    assert settlement["win"] == pytest.approx(full_win, abs=1e-4)
    assert settlement["half_win"] == pytest.approx(exact_three, abs=1e-4)
    assert settlement["win"] < float(distribution[np.arange(20) > 2.5].sum())


def test_negative_binomial_captures_overdispersion():
    mean, variance = 5.0, 12.0
    distribution = count_distribution(mean, variance)
    observed_mean = float(np.sum(np.arange(len(distribution)) * distribution))
    observed_variance = float(np.sum((np.arange(len(distribution)) - observed_mean) ** 2 * distribution))
    assert observed_mean == pytest.approx(mean, abs=0.1)
    assert observed_variance > mean
    poisson_variance = float(np.var(np.random.default_rng(0).poisson(mean, 20000)))
    assert observed_variance > poisson_variance


def test_generated_lines_stay_informative():
    lines = generate_lines(5.0, 9.0)
    assert lines
    assert all(line >= 0.25 for line in lines)
    distribution = count_distribution(5.0, 9.0)
    for line in lines:
        probability = expected_win_probability(settle_over_under(distribution, line))
        assert 0.05 <= probability <= 0.95


def test_period_joint_is_coherent_and_not_a_naive_split():
    payload = period_markets(1.6, 1.1, -0.04, 0.45)
    assert sum(payload["htft"].values()) == pytest.approx(1.0, abs=2e-3)
    first = payload["first_half"]["markets"]["1N2"]
    htft = payload["htft"]
    ht_home = sum(value for key, value in htft.items() if key.startswith("1/"))
    assert ht_home == pytest.approx(first["home"], abs=2e-3)
    assert payload["first_half"]["expected_goals"] < payload["second_half"]["expected_goals"]
    assert payload["goal_in_both_halves"] <= 1.0
    assert payload["draw_in_at_least_one_half"] + payload["no_draw_in_any_half"] == pytest.approx(1.0, abs=2e-3)
    assert sum(payload["most_prolific_half"].values()) == pytest.approx(1.0, abs=2e-3)


def test_period_model_uses_observed_scores():
    model = PeriodModel()
    share = model.fit([
        {"ht_home_score": 2, "ht_away_score": 1, "home_score": 4, "away_score": 2}
        for _ in range(60)
    ])
    assert share.sample_size == 60
    assert share.share == pytest.approx(0.5, abs=1e-3)
    assert share.coverage() == "partial"


def test_period_share_is_bounded():
    model = PeriodModel()
    share = model.fit([
        {"ht_home_score": 1, "ht_away_score": 1, "home_score": 1, "away_score": 1}
        for _ in range(30)
    ])
    assert share.share == pytest.approx(0.62, abs=1e-6)


def test_registry_is_complete_and_unique():
    ids = [definition.market_id for definition in MARKETS]
    assert len(ids) == len(set(ids))
    assert set(by_status()) <= {"supported", "conditional", "insufficient", "unsupported"}
    summary = registry_summary()
    assert summary["market_count"] == len(MARKETS)
    assert summary["live_enabled"] is False
    assert REGISTRY["next_goal"].live is True
    assert REGISTRY["total_corners"].status == "conditional"


def test_engine_does_not_publish_markets_without_data(matrix):
    engine = MarketEngine()
    result = engine.generate("m1", matrix, 1.55, 1.05, -0.04, context={"team_names": {"home": "A", "away": "B"}})
    families = {prediction["family"] for prediction in result["predictions"]}
    assert "corners" not in families
    assert "players" not in families
    assert "periods" not in families
    ids = {prediction["market_id"] for prediction in result["predictions"]}
    assert "1x2" in ids
    assert "total_goals" in ids
    for prediction in result["predictions"]:
        assert 0.0 < prediction["probability"] < 1.0
        assert prediction["coverage"] != "none"


def test_engine_uses_period_and_count_data_when_available(matrix, period_model):
    counts = {
        "corners": {
            "home": {"mean": 6.2, "variance": 9.5, "sample_size": 20},
            "away": {"mean": 4.6, "variance": 7.0, "sample_size": 20},
        },
        "yellow_cards": {
            "home": {"mean": 1.9, "variance": 2.9, "sample_size": 20},
            "away": {"mean": 2.4, "variance": 3.4, "sample_size": 20},
        },
        "cards": {
            "home": {"mean": 2.1, "variance": 3.2, "sample_size": 20},
            "away": {"mean": 2.6, "variance": 3.9, "sample_size": 20},
        },
    }
    engine = MarketEngine()
    result = engine.generate(
        "m2", matrix, 1.6, 1.0, -0.04,
        context={"team_names": {"home": "A", "away": "B"}, "counts": counts},
        period_model=period_model,
    )
    ids = {prediction["market_id"] for prediction in result["predictions"]}
    assert {"total_corners", "team_total_corners", "most_corners", "total_yellow_cards", "total_cards"} <= ids
    assert "ht_1x2" in ids and "htft" in ids
    assert "corners" in result["coverage"]["families"]


def test_categories_do_not_promote_poorly_covered_high_probability():
    strong_but_thin = {
        "match_id": "m1", "market_id": "1x2", "family": "result", "selection": "home",
        "probability": 0.90, "coverage": "thin", "reliability": 0.42,
        "market_status": "conditional", "uncertainty": 0.5,
    }
    calibrated = {
        "match_id": "m2", "market_id": "btts", "family": "goals", "selection": "yes",
        "probability": 0.74, "coverage": "full", "reliability": 0.81,
        "market_status": "supported", "uncertainty": 0.2,
    }
    assert category_for(strong_but_thin) != "prudent"
    assert category_for(calibrated) == "prudent"
    categories = rank_categories([strong_but_thin, calibrated], limit_per_category=5)
    signatures = [f"{p['market_id']}:{p['selection']}" for values in categories.values() for p in values]
    assert len(signatures) == len(set(signatures))


def test_contradictions_are_detected():
    home = {"match_id": "m1", "market_id": "1x2", "selection": "home"}
    away = {"match_id": "m1", "market_id": "1x2", "selection": "away"}
    other_match = {"match_id": "m2", "market_id": "1x2", "selection": "away"}
    assert contradicts(home, away)
    assert not contradicts(home, other_match)


def test_combination_uses_joint_matrix_and_rejects_unresolved_dependency(matrix):
    home_win = {"match_id": "m1", "market_id": "1x2", "selection": "home", "probability": 0.52}
    over = {"match_id": "m1", "market_id": "total_goals", "selection": "over_2_5", "line": 2.5, "probability": 0.55}
    probability, method = joint_probability([home_win, over], matrix)
    assert method == "joint_score_matrix"
    assert probability < min(home_win["probability"], over["probability"])
    corners = {"match_id": "m1", "market_id": "total_corners", "selection": "over_9_5", "line": 9.5, "probability": 0.5}
    assert joint_probability([home_win, corners], matrix) == (None, "unresolved_dependency")


def test_combinations_respect_independence_and_quality():
    predictions = [
        {"match_id": "m1", "market_id": "1x2", "selection": "home", "family": "result",
         "probability": 0.72, "reliability": 0.8, "coverage": "full", "line": None},
        {"match_id": "m2", "market_id": "btts", "selection": "yes", "family": "goals",
         "probability": 0.66, "reliability": 0.75, "coverage": "full", "line": None},
        {"match_id": "m3", "market_id": "total_goals", "selection": "under_2_5", "family": "goals",
         "probability": 0.61, "reliability": 0.7, "coverage": "full", "line": 2.5},
    ]
    matrix, _, _ = compute_score_matrix(1.5, 1.0, -0.04)
    combos = build_combinations(predictions, {"m1": matrix})
    assert "securise" in combos
    first = combos["securise"][0]
    assert first["method"] == "independent_matches"
    expected = 0.72 * 0.66
    assert first["joint_probability"] == pytest.approx(expected, abs=1e-3)


def test_player_markets_require_sample_and_participation():
    players = [
        {"name": "Sans historique", "team": "home", "start_probability": 0.9, "sample_size": 2,
         "goals_per90": 0.8, "expected_minutes": 90},
        {"name": "Remplaçant incertain", "team": "home", "start_probability": 0.3, "sample_size": 20,
         "goals_per90": 0.8, "expected_minutes": 30},
        {"name": "Titulaire confirmé", "team": "home", "start_probability": 0.95, "sample_size": 20,
         "lineup_confirmed": True, "goals_per90": 0.6, "expected_minutes": 85,
         "shots_per90": 2.4, "shots_on_target_per90": 1.1, "cards_per90": 0.18},
    ]
    predictions = player_markets("m1", players, 1.6, 1.0)
    names = {prediction["selection"] for prediction in predictions}
    assert "Sans historique" not in names
    assert "Remplaçant incertain" not in names
    assert any("Titulaire confirmé" in name for name in names)
    for prediction in predictions:
        assert prediction["coverage"] in {"partial", "thin"}
        assert 0.0 < prediction["probability"] < 1.0


def test_time_markets_are_flagged_as_assumption_based():
    predictions = goal_time_markets("m1", 1.6, 1.0)
    assert predictions
    first_goal = [p for p in predictions if p["market_id"] == "first_goal_interval"]
    assert first_goal
    total = sum(p["probability"] for p in first_goal)
    assert total == pytest.approx(1.0, abs=2e-3)
    assert all(p["coverage"] == "thin" for p in predictions)
    assert all("timestamped_goal_events" in p["missing_data"] for p in predictions)
