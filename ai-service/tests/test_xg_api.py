"""API-level checks for the optional xG adjustment."""

import json

import pytest
from fastapi.testclient import TestClient

from app import main as prediction_api
from app.core.elo import EloEngine
from app.core.market_specialists import MarketSpecialists
from app.core.poisson_regressor import DixonColesModel
from app.main import app

HEADERS = {"X-Service-Secret": "test-internal-secret"}


def build_training_payload(with_xg: bool = True) -> list:
    """Six weeks of results for four teams, optionally carrying measured xG."""
    teams = ["Alpha FC", "Beta FC", "Gamma FC", "Delta FC"]
    rows = []
    day = 60
    for round_index in range(6):
        for index in range(2):
            home = teams[(round_index + index * 2) % 4]
            away = teams[(round_index + 1 + index * 2) % 4]
            home_goals = 2 if home == "Alpha FC" else 1
            away_goals = 1 if away == "Beta FC" else 0
            row = {
                "home_team": home,
                "away_team": away,
                "home_goals": home_goals,
                "away_goals": away_goals,
                "days_ago": day,
                "is_neutral": False,
                "competition": "PL",
            }
            if with_xg:
                row["home_xg"] = 2.4 if home == "Alpha FC" else 1.0
                row["away_xg"] = 1.4 if away == "Beta FC" else 0.6
                row["home_shots"] = 15
                row["away_shots"] = 8
            rows.append(row)
            day -= 7
    return rows


def build_history(with_xg: bool = True, matches: int = 30) -> list:
    teams = ["Alpha FC", "Beta FC", "Gamma FC", "Delta FC"]
    rows = []
    day = 400
    for index in range(matches):
        home = teams[index % 4]
        away = teams[(index + 1) % 4]
        row = {
            "home_team": home,
            "away_team": away,
            "home_goals": 2 if home == "Alpha FC" else 1,
            "away_goals": 1 if away == "Beta FC" else 0,
            "days_ago": day,
            "is_neutral": False,
            "competition": "PL",
        }
        if with_xg:
            row["home_xg"] = 2.5 if home == "Alpha FC" else 0.9
            row["away_xg"] = 1.5 if away == "Beta FC" else 0.5
        rows.append(row)
        day -= 10
    return rows


@pytest.fixture
def client(monkeypatch, tmp_path):
    monkeypatch.setenv("AI_SERVICE_SECRET", "test-internal-secret")
    monkeypatch.setattr(prediction_api, "MODEL_STATE_PATH", tmp_path / "model.json")
    monkeypatch.setattr(prediction_api, "champion_model", DixonColesModel())
    monkeypatch.setattr(prediction_api, "market_specialists", MarketSpecialists())
    monkeypatch.setattr(prediction_api, "elo_engine", EloEngine())
    monkeypatch.setattr(prediction_api, "xg_builder", prediction_api.XgFeatureBuilder())
    return TestClient(app)


def train(client, with_xg: bool = True):
    response = client.post("/v1/train", json={"matches": build_history(with_xg=with_xg, matches=40)},
                           headers=HEADERS)
    assert response.status_code == 200, response.text
    return response.json()


def predict(client, weight: str):
    return client.post(
        "/v1/predict",
        json={"home_team": "Alpha FC", "away_team": "Beta FC", "quality_score": 95.0, "competition": "PL"},
        headers=HEADERS,
    )


def test_xg_adjustment_is_disabled_by_default(client, monkeypatch):
    monkeypatch.setenv("XG_ADJUSTMENT_WEIGHT", "0")
    train(client)

    payload = predict(client, "0").json()
    assert payload["xg"] is not None
    assert payload["xg"]["applied"] is False
    assert payload["xg"]["reason"] == "xg_adjustment_disabled"
    assert "xg_not_applied:xg_adjustment_disabled" in payload["warnings"]


def test_enabled_xg_adjustment_changes_expected_goals(client, monkeypatch):
    train(client)

    monkeypatch.setenv("XG_ADJUSTMENT_WEIGHT", "0")
    baseline = predict(client, "0").json()

    monkeypatch.setenv("XG_ADJUSTMENT_WEIGHT", "1.0")
    adjusted = predict(client, "1.0").json()

    assert adjusted["xg"]["applied"] is True
    assert "xg_adjustment_applied" in adjusted["warnings"]
    assert adjusted["expected_goals"]["home"] != pytest.approx(baseline["expected_goals"]["home"])
    assert abs(adjusted["xg"]["log_shift_home"]) <= 0.12 + 1e-9
    assert abs(adjusted["xg"]["log_shift_away"]) <= 0.12 + 1e-9


def test_xg_block_exposes_coverage_and_profiles(client, monkeypatch):
    monkeypatch.setenv("XG_ADJUSTMENT_WEIGHT", "1.0")
    train(client)

    payload = predict(client, "1.0").json()
    xg = payload["xg"]
    assert xg["coverage"] in {"thin", "partial", "full"}
    assert xg["home_profile"]["matches_with_xg"] > 0
    assert xg["away_profile"]["matches_with_xg"] > 0
    assert xg["coverage_detail"]["league_matches"] > 0


def test_training_without_xg_reports_no_block_and_keeps_lambdas(client, monkeypatch):
    monkeypatch.setenv("XG_ADJUSTMENT_WEIGHT", "1.0")
    train(client, with_xg=False)

    payload = predict(client, "1.0").json()
    # No measured xG in training: the service reports the blocker and must
    # never fabricate a profile or a baseline.
    xg = payload["xg"]
    assert xg["applied"] is False
    assert xg["reason"] == "league_xg_baseline_unavailable"
    assert xg["home_profile"]["matches_with_xg"] == 0
    assert xg["home_profile"]["xg_for"] is None
    assert "xg_not_applied:league_xg_baseline_unavailable" in payload["warnings"]


def test_model_state_round_trip_keeps_xg_history(client, monkeypatch, tmp_path):
    train(client)
    state_path = prediction_api.MODEL_STATE_PATH
    assert state_path.exists()

    state = json.loads(state_path.read_text(encoding="utf-8"))
    assert state["xg_matches"]
    assert any(row["home_xg"] is not None for row in state["xg_matches"])

    # Reloading the persisted state must restore identical features.
    monkeypatch.setattr(prediction_api, "xg_builder", prediction_api.XgFeatureBuilder())
    prediction_api.load_model_state()
    restored = prediction_api.xg_builder.features("Alpha FC", prediction_api.date.today())
    assert restored.matches_with_xg > 0
