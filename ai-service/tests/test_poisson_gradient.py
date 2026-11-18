"""Le gradient analytique doit correspondre aux différences finies.

Sans gradient exact, L-BFGS-B consommait tout son budget d'évaluations avant
de converger sur un historique de plusieurs centaines de matchs.
"""
import random

import numpy as np

import app.core.poisson_regressor as poisson_regressor
from app.core.poisson_regressor import DixonColesModel, MatchData


def _training_objects():
    random.seed(11)
    teams = [f"T{index}" for index in range(6)]
    return [
        MatchData(
            home_team=random.sample(teams, 1)[0],
            away_team=random.sample([team for team in teams if team], 1)[0],
            home_goals=random.randint(0, 3),
            away_goals=random.randint(0, 3),
            days_ago=random.uniform(1, 400),
        )
        for _ in range(40)
    ]


def _capture_objectives(monkeypatch):
    captured = {}
    original = poisson_regressor.minimize

    def fake_minimize(fun, x0, **kwargs):
        captured["fun"] = fun
        captured["jac"] = kwargs["jac"]
        captured["x0"] = np.array(x0, dtype=float)

        class _Result:
            success = False
            message = "probe"
            x = np.array(x0, dtype=float)

            @staticmethod
            def fun_value():
                return fun(np.array(x0, dtype=float))

        result = _Result()
        result.fun = result.fun_value()
        return result

    monkeypatch.setattr(poisson_regressor, "minimize", fake_minimize)
    try:
        DixonColesModel().fit(_training_objects())
    except (RuntimeError, ValueError):
        pass
    monkeypatch.setattr(poisson_regressor, "minimize", original)
    return captured


def test_analytic_gradient_matches_finite_differences(monkeypatch):
    captured = _capture_objectives(monkeypatch)
    assert "jac" in captured and "fun" in captured

    fun = captured["fun"]
    jac = captured["jac"]
    point = captured["x0"] + np.random.default_rng(0).normal(0, 0.05, captured["x0"].shape)

    analytic = jac(point)
    numeric = np.zeros_like(point)
    epsilon = 1e-6
    for index in range(point.size):
        up = point.copy()
        up[index] += epsilon
        down = point.copy()
        down[index] -= epsilon
        numeric[index] = (fun(up) - fun(down)) / (2 * epsilon)

    error = np.max(np.abs(analytic - numeric) / np.maximum(1.0, np.abs(numeric)))
    assert error < 1e-4, f"Gradient incohérent (erreur relative max {error:.2e})."


def test_fit_handles_a_large_history_within_budget():
    random.seed(5)
    teams = [f"Team {index}" for index in range(120)]
    matches = []
    for _ in range(400):
        home, away = random.sample(teams, 2)
        matches.append(
            MatchData(
                home_team=home,
                away_team=away,
                home_goals=random.randint(0, 4),
                away_goals=random.randint(0, 4),
                days_ago=random.uniform(1, 900),
            )
        )

    model = DixonColesModel()
    model.fit(matches)

    assert model.is_fitted
    assert model.fit_success
    assert len(model.teams) > 100
    assert np.all(np.isfinite(model.attack_strengths))
