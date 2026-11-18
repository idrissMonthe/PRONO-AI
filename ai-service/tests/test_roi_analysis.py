"""Tests de la décomposition du ROI (lecture seule, sans réentraînement)."""

from __future__ import annotations

import pytest

from scripts.analyze_roi import (
    calibration,
    edge_profile,
    edge_selector,
    evaluate_bets,
    fair_probabilities,
    favourite_selector,
    overround,
)


def pick(p_home=0.5, p_draw=0.3, p_away=0.2, oh=2.0, od=3.3, oa=4.0, actual="home") -> dict:
    return {
        "p_home": p_home,
        "p_draw": p_draw,
        "p_away": p_away,
        "oh": oh,
        "od": od,
        "oa": oa,
        "actual": actual,
        "league": "PL",
        "date": "2026-01-01",
        "fold": 1,
    }


def test_overround_and_fair_probabilities_sum_to_one():
    sample = pick()
    assert overround(sample) == pytest.approx(1 / 2.0 + 1 / 3.3 + 1 / 4.0)
    fair = fair_probabilities(sample)
    assert sum(fair.values()) == pytest.approx(1.0)
    # Hors marge, la probabilité implicite baisse : la marge n'est pas offerte.
    assert fair["home"] < 1 / 2.0


def test_a_winning_bet_returns_the_decimal_odds():
    sample = pick(oh=2.5, actual="home")
    result = evaluate_bets([sample], lambda p: ("home", 2.5, 0.6))

    assert result["bets"] == 1
    assert result["staked"] == pytest.approx(1.0)
    assert result["returned"] == pytest.approx(2.5)
    assert result["roi"] == pytest.approx(1.5)


def test_a_losing_bet_loses_exactly_the_stake():
    sample = pick(oh=2.5, actual="away")
    result = evaluate_bets([sample], lambda p: ("home", 2.5, 0.6))

    assert result["returned"] == pytest.approx(0.0)
    assert result["roi"] == pytest.approx(-1.0)


def test_no_bet_produces_no_roi_instead_of_zero():
    result = evaluate_bets([pick()], lambda p: None)
    assert result["bets"] == 0
    assert result["roi"] is None


def test_one_bet_per_match_even_when_several_outcomes_show_an_edge():
    # Les trois issues dépassent leur implicite : le sélecteur n'en retient
    # qu'une, donc une seule mise.
    sample = pick(p_home=0.6, p_draw=0.6, p_away=0.6, actual="home")
    result = evaluate_bets([sample], edge_selector(0.05, use_fair=False))

    assert result["bets"] == 1
    assert result["staked"] == pytest.approx(1.0)


def test_missing_or_invalid_odds_never_produce_a_bet():
    sample = pick(oh=0.0)
    assert edge_selector(0.05, use_fair=False)(sample) is None
    assert evaluate_bets([sample], edge_selector(0.05, use_fair=False))["bets"] == 0


def test_the_raw_rule_is_stricter_than_the_fair_one():
    # Écart petit mais réel face à l'implicite brute : refusé.
    sample = pick(p_home=0.52, oh=2.0)
    assert edge_selector(0.05, use_fair=False)(sample) is None
    # Le même écart calculé hors marge peut franchir le seuil.
    implied = fair_probabilities(sample)["home"]
    tolerant = pick(p_home=implied + 0.06, oh=2.0)
    assert edge_selector(0.05, use_fair=True)(tolerant) is not None


def test_favourite_selector_always_bets_on_the_highest_probability():
    sample = pick(p_home=0.2, p_draw=0.3, p_away=0.5, od=3.3, oa=4.0, actual="away")
    outcome, odds, probability = favourite_selector(sample)
    assert outcome == "away"
    assert odds == 4.0
    assert probability == 0.5


def test_calibration_bands_report_the_observed_gap():
    picks = [pick(p_home=0.55, oh=2.0, actual="home") for _ in range(10)]
    rows = calibration(picks, favourite_selector)

    band = next(row for row in rows if row["band"] == "0.5-0.6")
    assert band["n"] == 10
    assert band["predicted"] == pytest.approx(0.55)
    assert band["observed"] == pytest.approx(1.0)
    assert band["gap"] == pytest.approx(-0.45)


def test_edge_profile_detects_a_calibrated_model_without_advantage():
    # Modèle égal au marché hors marge : écart moyen nul sur les trois issues.
    fair = fair_probabilities(pick())
    sample = pick(p_home=fair["home"], p_draw=fair["draw"], p_away=fair["away"])
    profile = edge_profile([sample])

    assert profile["mean_model_sum"] == pytest.approx(1.0)
    for outcome in ("home", "draw", "away"):
        assert profile["mean_edge"][outcome] == pytest.approx(0.0, abs=1e-6)
