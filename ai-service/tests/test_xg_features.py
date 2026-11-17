"""Tests for the leakage-safe xG feature engine and its fallback rules."""

from datetime import date, timedelta

import pytest

from app.core.xg_features import (
    MAX_LOG_SHIFT,
    XgFeatureBuilder,
    XgMatch,
    adjust_lambdas_with_xg,
    coverage_tier,
    describe_coverage,
)

BASE = date(2026, 1, 1)


def make_history(team: str, matches: int, xg_for: float = 1.5, xg_against: float = 1.0,
                 start: date = BASE, opponent: str = "Opponent") -> list:
    """Chronological history: one match every 7 days for ``team`` at home."""
    rows = []
    for index in range(matches):
        rows.append(
            XgMatch(
                match_date=start + timedelta(days=7 * index),
                home_team=team,
                away_team=opponent,
                home_goals=2,
                away_goals=1,
                home_xg=xg_for,
                away_xg=xg_against,
                home_shots=12,
                away_shots=8,
                competition="PL",
            )
        )
    return rows


def future_date(matches: int, start: date = BASE) -> date:
    return start + timedelta(days=7 * matches)


# ----------------------------------------------------------------------
# Leakage
# ----------------------------------------------------------------------
def test_a_match_never_feeds_its_own_features():
    builder = XgFeatureBuilder()
    builder.add(XgMatch(BASE, "Arsenal", "Chelsea", 3, 0, home_xg=2.9, away_xg=0.2, competition="PL"))

    before = builder.features("Arsenal", BASE)
    assert before.matches_played == 0
    assert before.matches_with_xg == 0
    assert before.xg_for is None

    after = builder.features("Arsenal", BASE + timedelta(days=1))
    assert after.matches_played == 1
    assert after.matches_with_xg == 1
    assert after.xg_for == pytest.approx(2.9)


def test_future_matches_do_not_change_a_past_prediction():
    builder = XgFeatureBuilder().extend(make_history("Arsenal", 12))
    as_of = future_date(12)
    snapshot = builder.features("Arsenal", as_of).to_dict()

    builder.add(XgMatch(as_of + timedelta(days=1), "Arsenal", "Chelsea", 0, 5, home_xg=0.1, away_xg=4.0,
                        competition="PL"))
    builder.add(XgMatch(as_of + timedelta(days=8), "Arsenal", "Chelsea", 0, 5, home_xg=0.1, away_xg=4.0,
                        competition="PL"))

    assert builder.features("Arsenal", as_of).to_dict() == snapshot


def test_same_day_match_is_excluded_from_prediction_inputs():
    builder = XgFeatureBuilder()
    builder.add(XgMatch(BASE, "Lyon", "Marseille", 1, 1, home_xg=1.2, away_xg=0.9, competition="FL1"))
    # A match kicking off the same calendar day as the prediction is not known.
    assert builder.features("Lyon", BASE).matches_with_xg == 0


# ----------------------------------------------------------------------
# Windowing, venue splits, trend
# ----------------------------------------------------------------------
def test_time_decay_favours_recent_matches():
    builder = XgFeatureBuilder(half_life_days=30.0)
    builder.add(XgMatch(BASE, "Lyon", "A", 1, 0, home_xg=0.2, away_xg=1.0, competition="FL1"))
    builder.add(XgMatch(BASE + timedelta(days=300), "Lyon", "A", 1, 0, home_xg=3.0, away_xg=1.0, competition="FL1"))

    features = builder.features("Lyon", BASE + timedelta(days=301))
    # The recent 3.0 dominates a 0.2 observation from 300 days earlier.
    assert features.xg_for > 2.0


def test_home_and_away_splits_are_separate():
    # Decay disabled: this test isolates the venue split, not the weighting.
    builder = XgFeatureBuilder(half_life_days=1e9)
    builder.add(XgMatch(BASE, "Lyon", "A", 2, 0, home_xg=2.0, away_xg=0.5, competition="FL1"))
    builder.add(XgMatch(BASE + timedelta(days=7), "A", "Lyon", 1, 0, home_xg=1.0, away_xg=0.4, competition="FL1"))
    builder.add(XgMatch(BASE + timedelta(days=14), "Lyon", "B", 3, 0, home_xg=3.0, away_xg=0.2, competition="FL1"))

    features = builder.features("Lyon", BASE + timedelta(days=20))
    assert features.home_matches_with_xg == 2
    assert features.away_matches_with_xg == 1
    assert features.xg_for_home == pytest.approx(2.5)
    assert features.xg_for_away == pytest.approx(0.4)


def test_trend_compares_recent_and_previous_blocks():
    builder = XgFeatureBuilder()
    for index in range(5):
        builder.add(XgMatch(BASE + timedelta(days=index), "Lyon", "A", 1, 1, home_xg=1.0, away_xg=1.0,
                            competition="FL1"))
    for index in range(5, 10):
        builder.add(XgMatch(BASE + timedelta(days=index), "Lyon", "A", 1, 1, home_xg=2.0, away_xg=1.0,
                            competition="FL1"))

    features = builder.features("Lyon", BASE + timedelta(days=30))
    assert features.xg_trend == pytest.approx(1.0)


def test_goals_minus_xg_exposes_finishing_drift():
    builder = XgFeatureBuilder()
    builder.add(XgMatch(BASE, "Lyon", "A", 3, 0, home_xg=1.0, away_xg=1.0, competition="FL1"))
    features = builder.features("Lyon", BASE + timedelta(days=1))
    assert features.goals_minus_xg == pytest.approx(2.0)
    assert features.conceded_minus_xg_against == pytest.approx(-1.0)


# ----------------------------------------------------------------------
# Missing data
# ----------------------------------------------------------------------
def test_missing_xg_is_never_replaced_by_zero_or_an_estimate():
    builder = XgFeatureBuilder()
    builder.add(XgMatch(BASE, "Nantes", "A", 2, 2, home_xg=None, away_xg=1.4, competition="FL1"))
    builder.add(XgMatch(BASE + timedelta(days=7), "Nantes", "A", 1, 0, home_xg=None, away_xg=1.0,
                        competition="FL1"))

    features = builder.features("Nantes", BASE + timedelta(days=10))
    assert features.matches_played == 2          # played matches are counted
    assert features.matches_with_xg == 0         # but none was measured
    assert features.coverage == "none"
    assert features.xg_for is None
    assert features.goals_minus_xg is None


def test_negative_and_non_finite_xg_are_treated_as_missing():
    builder = XgFeatureBuilder()
    builder.add(XgMatch(BASE, "Nantes", "A", 1, 0, home_xg=-0.5, away_xg=float("nan"), competition="FL1"))
    features = builder.features("Nantes", BASE + timedelta(days=1))
    assert features.matches_with_xg == 0


def test_coverage_tiers_follow_measured_sample():
    assert coverage_tier(0) == "none"
    assert coverage_tier(2) == "none"
    assert coverage_tier(3) == "thin"
    assert coverage_tier(6) == "partial"
    assert coverage_tier(10) == "full"


def test_unknown_team_returns_an_empty_profile():
    features = XgFeatureBuilder().features("Inconnu", BASE)
    assert features.coverage == "none"
    assert features.matches_played == 0


# ----------------------------------------------------------------------
# Adjustment behaviour
# ----------------------------------------------------------------------
def build_builder(team_matches: int = 12) -> XgFeatureBuilder:
    rows = make_history("Arsenal", team_matches, xg_for=2.0, xg_against=0.7)
    rows += make_history("Chelsea", team_matches, xg_for=1.2, xg_against=1.6, opponent="Other")
    return XgFeatureBuilder().extend(rows)


def test_adjustment_is_disabled_by_default():
    builder = build_builder()
    as_of = future_date(12)
    result = adjust_lambdas_with_xg(
        1.40, 1.10,
        builder.features("Arsenal", as_of),
        builder.features("Chelsea", as_of),
        builder.baseline(as_of, "PL"),
        weight=0.0,
    )
    assert result.applied is False
    assert result.reason == "xg_adjustment_disabled"
    assert result.lambda_home == pytest.approx(1.40)
    assert result.lambda_away == pytest.approx(1.10)


def test_adjustment_moves_lambdas_toward_the_xg_profile():
    builder = build_builder()
    as_of = future_date(12)
    result = adjust_lambdas_with_xg(
        1.40, 1.10,
        builder.features("Arsenal", as_of),
        builder.features("Chelsea", as_of),
        builder.baseline(as_of, "PL"),
        weight=1.0,
    )
    assert result.applied is True
    assert result.effective_weight > 0
    # Arsenal generate far more xG than Chelsea concede-adjusted expectation.
    assert result.lambda_home > 1.40
    assert result.lambda_away < 1.10


def test_adjustment_never_exceeds_the_log_shift_cap():
    builder = build_builder()
    as_of = future_date(12)
    result = adjust_lambdas_with_xg(
        0.30, 2.80,
        builder.features("Arsenal", as_of),
        builder.features("Chelsea", as_of),
        builder.baseline(as_of, "PL"),
        weight=1.0,
    )
    assert abs(result.log_shift_home) <= MAX_LOG_SHIFT + 1e-9
    assert abs(result.log_shift_away) <= MAX_LOG_SHIFT + 1e-9


def test_thin_coverage_reduces_the_effective_weight():
    thin = build_builder(team_matches=3)
    full = build_builder(team_matches=12)
    as_of = future_date(12)

    thin_result = adjust_lambdas_with_xg(
        1.4, 1.1,
        thin.features("Arsenal", as_of), thin.features("Chelsea", as_of),
        thin.baseline(as_of, "PL"), weight=1.0,
    )
    full_result = adjust_lambdas_with_xg(
        1.4, 1.1,
        full.features("Arsenal", as_of), full.features("Chelsea", as_of),
        full.baseline(as_of, "PL"), weight=1.0,
    )
    assert thin_result.coverage == "thin"
    assert full_result.coverage == "full"
    assert thin_result.effective_weight < full_result.effective_weight
    assert abs(thin_result.log_shift_home) < abs(full_result.log_shift_home)


def test_missing_league_baseline_blocks_the_adjustment():
    builder = XgFeatureBuilder()
    builder.add(XgMatch(BASE, "Arsenal", "Chelsea", 2, 1, home_xg=2.0, away_xg=1.0, competition="PL"))
    as_of = BASE + timedelta(days=1)
    result = adjust_lambdas_with_xg(
        1.4, 1.1,
        builder.features("Arsenal", as_of), builder.features("Chelsea", as_of),
        builder.baseline(as_of, "PL"),
        weight=1.0,
    )
    # One measured match is below the minimum sample, so no baseline exists.
    assert result.applied is False
    assert result.reason == "league_xg_baseline_unavailable"


def test_team_without_xg_keeps_dixon_coles_lambdas():
    builder = build_builder()
    as_of = future_date(12)
    empty = builder.features("Team Without Data", as_of)
    result = adjust_lambdas_with_xg(
        1.4, 1.1,
        builder.features("Arsenal", as_of), empty,
        builder.baseline(as_of, "PL"),
        weight=1.0,
    )
    assert result.applied is False
    assert result.reason == "missing_team_xg_coverage"
    assert result.lambda_home == pytest.approx(1.4)
    assert result.lambda_away == pytest.approx(1.1)


def test_coverage_report_exposes_the_weaker_side():
    builder = build_builder()
    as_of = future_date(12)
    report = describe_coverage(
        builder.features("Arsenal", as_of),
        builder.features("Team Without Data", as_of),
        builder.baseline(as_of, "PL"),
    )
    assert report["combined"] == "none"
    assert report["home"] == "full"
    assert report["away"] == "none"
