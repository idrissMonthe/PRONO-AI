"""Tests for the post-hoc 1N2 calibrators."""

import pytest

from app.core.calibration import (
    MIN_CALIBRATION_SAMPLES,
    IsotonicCalibrator,
    PlattCalibrator,
    _interpolate,
)


def overconfident_sample(size: int = 300) -> tuple:
    """A model that is systematically overconfident on the home side."""
    probabilities = []
    outcomes = []
    for index in range(size):
        if index % 3 == 0:
            probabilities.append([0.80, 0.12, 0.08])
            outcomes.append("AWAY" if index % 2 == 0 else "HOME")
        elif index % 3 == 1:
            probabilities.append([0.45, 0.30, 0.25])
            outcomes.append("HOME" if index % 2 == 0 else "DRAW")
        else:
            probabilities.append([0.20, 0.30, 0.50])
            outcomes.append("AWAY" if index % 2 == 0 else "HOME")
    return probabilities, outcomes


@pytest.mark.parametrize("factory", [PlattCalibrator, IsotonicCalibrator])
def test_calibrator_returns_normalised_probabilities(factory):
    probabilities, outcomes = overconfident_sample()
    calibrator = factory().fit(probabilities, outcomes)
    assert calibrator.is_fitted

    for row in probabilities[:20]:
        transformed = calibrator.transform_row(row)
        assert len(transformed) == 3
        assert sum(transformed) == pytest.approx(1.0, abs=1e-6)
        assert all(0.0 < value < 1.0 for value in transformed)


def test_platt_reduces_overconfidence_on_its_own_sample():
    probabilities, outcomes = overconfident_sample()
    calibrator = PlattCalibrator().fit(probabilities, outcomes)

    before = sum(row[0] for row in probabilities) / len(probabilities)
    after = sum(calibrator.transform_row(row)[0] for row in probabilities) / len(probabilities)
    observed = sum(1 for outcome in outcomes if outcome == "HOME") / len(outcomes)

    # The map must move the average home probability toward the observed rate.
    assert abs(after - observed) <= abs(before - observed) + 1e-9


def test_isotonic_is_monotone_in_the_fitted_probability():
    probabilities, outcomes = overconfident_sample()
    calibrator = IsotonicCalibrator().fit(probabilities, outcomes)

    # The per-class map itself must be non-decreasing: PAV exists to enforce
    # exactly that. (The three maps are renormalised afterwards, so comparing
    # whole rows would measure the renormalisation, not the isotonic map.)
    for blocks in calibrator.blocks:
        values = [value for _, _, value in blocks]
        assert values == sorted(values)
    away_blocks = calibrator.blocks[2]
    assert _interpolate(away_blocks, 0.10) <= _interpolate(away_blocks, 0.90)


@pytest.mark.parametrize("factory", [PlattCalibrator, IsotonicCalibrator])
def test_calibrator_refuses_a_sample_below_the_minimum(factory):
    probabilities, outcomes = overconfident_sample(size=MIN_CALIBRATION_SAMPLES - 1)
    calibrator = factory().fit(probabilities, outcomes)
    assert calibrator.is_fitted is False
    # Pass-through: an unfitted calibrator must not distort probabilities.
    row = [0.6, 0.25, 0.15]
    assert calibrator.transform_row(row) == pytest.approx(row)


@pytest.mark.parametrize("factory", [PlattCalibrator, IsotonicCalibrator])
def test_calibrator_is_deterministic(factory):
    probabilities, outcomes = overconfident_sample()
    first = factory().fit(probabilities, outcomes)
    second = factory().fit(list(probabilities), list(outcomes))
    assert first.state() == second.state()


def test_platt_keeps_the_ranking_of_a_confident_prediction():
    probabilities, outcomes = overconfident_sample()
    calibrator = PlattCalibrator().fit(probabilities, outcomes)
    strong = calibrator.transform_row([0.9, 0.06, 0.04])
    weak = calibrator.transform_row([0.4, 0.35, 0.25])
    assert strong[0] > weak[0]


@pytest.mark.parametrize("factory", [PlattCalibrator, IsotonicCalibrator])
def test_invalid_probabilities_are_rejected(factory):
    with pytest.raises(ValueError):
        factory().fit([[0.5, 0.5, 0.5]] * MIN_CALIBRATION_SAMPLES, ["HOME"] * MIN_CALIBRATION_SAMPLES)
    with pytest.raises(ValueError):
        factory().fit([[0.5, 0.3, 0.2]] * MIN_CALIBRATION_SAMPLES, ["UNKNOWN"] * MIN_CALIBRATION_SAMPLES)


def test_outcome_aliases_are_accepted():
    probabilities = [[0.5, 0.3, 0.2]] * MIN_CALIBRATION_SAMPLES
    outcomes = ["1" if index % 2 == 0 else "2" for index in range(MIN_CALIBRATION_SAMPLES)]
    calibrator = PlattCalibrator().fit(probabilities, outcomes)
    assert calibrator.is_fitted
