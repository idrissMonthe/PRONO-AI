"""Contrat API des marchés étendus (/v1/predict et /v1/markets/registry)."""

import pytest
from fastapi.testclient import TestClient

from app import main as prediction_api
from app.core.elo import EloEngine
from app.core.market_specialists import MarketSpecialists
from app.core.period_model import PeriodModel
from app.core.poisson_regressor import DixonColesModel
from app.main import app

HEADERS = {"X-Service-Secret": "test-internal-secret"}


def build_history(matches: int = 60) -> list:
    teams = ["Alpha FC", "Beta FC", "Gamma FC", "Delta FC"]
    rows = []
    day = 500
    for index in range(matches):
        home = teams[index % 4]
        away = teams[(index + 1) % 4]
        home_goals = 2 if home == "Alpha FC" else 1
        away_goals = 1 if away == "Beta FC" else 0
        rows.append({
            "home_team": home,
            "away_team": away,
            "home_goals": home_goals,
            "away_goals": away_goals,
            "days_ago": day,
            "is_neutral": False,
            "competition": "PL",
            "ht_home_score": 1 if home_goals else 0,
            "ht_away_score": 0,
        })
        day -= 6
    return rows


@pytest.fixture
def client(monkeypatch, tmp_path):
    monkeypatch.setenv("AI_SERVICE_SECRET", "test-internal-secret")
    monkeypatch.setenv("XG_ADJUSTMENT_WEIGHT", "0")
    monkeypatch.setattr(prediction_api, "MODEL_STATE_PATH", tmp_path / "model.json")
    monkeypatch.setattr(prediction_api, "champion_model", DixonColesModel())
    monkeypatch.setattr(prediction_api, "market_specialists", MarketSpecialists())
    monkeypatch.setattr(prediction_api, "elo_engine", EloEngine())
    monkeypatch.setattr(prediction_api, "period_profile", PeriodModel())
    return TestClient(app)


def train(client) -> None:
    response = client.post("/v1/train", json={"matches": build_history()}, headers=HEADERS)
    assert response.status_code == 200, response.text


def test_registry_endpoint_lists_statuses(client):
    response = client.get("/v1/markets/registry", headers=HEADERS)
    assert response.status_code == 200
    payload = response.json()
    assert payload["summary"]["market_count"] >= 60
    statuses = {market["status"] for market in payload["markets"]}
    assert statuses <= {"supported", "conditional", "insufficient", "unsupported"}
    assert payload["summary"]["live_enabled"] is False


def test_predict_returns_classified_market_predictions(client):
    train(client)
    response = client.post(
        "/v1/predict",
        json={"home_team": "Alpha FC", "away_team": "Beta FC", "quality_score": 95.0, "competition": "PL"},
        headers=HEADERS,
    )
    assert response.status_code == 200, response.text
    payload = response.json()
    assert payload["market_predictions"]
    assert set(payload["market_categories"]) == {"prudent", "equilibre", "audacieux"}
    signatures = []
    for name, items in payload["market_categories"].items():
        for item in items:
            assert 0.0 < item["probability"] < 1.0
            assert item["coverage"] != "none"
            assert item["reliability"] >= 0.35
            signatures.append((item["match_id"], item["market_id"], item["selection"], item["line"]))
    assert len(signatures) == len(set(signatures))
    assert payload["period_profile"]["coverage"] in {"thin", "partial", "full"}
    assert any(item["market_id"] == "ht_1x2" for item in payload["market_predictions"])


def test_predict_without_counts_does_not_invent_corners(client):
    train(client)
    payload = client.post(
        "/v1/predict",
        json={"home_team": "Alpha FC", "away_team": "Beta FC", "quality_score": 95.0},
        headers=HEADERS,
    ).json()
    assert not any(item["family"] == "corners" for item in payload["market_predictions"])


def test_predict_with_counts_publishes_corners_and_cards(client):
    train(client)
    counts = {
        "corners": {
            "home": {"mean": 6.4, "variance": 10.2, "sample_size": 20},
            "away": {"mean": 4.5, "variance": 7.1, "sample_size": 20},
        },
        "yellow_cards": {
            "home": {"mean": 2.0, "variance": 3.1, "sample_size": 20},
            "away": {"mean": 2.5, "variance": 3.6, "sample_size": 20},
        },
        "cards": {
            "home": {"mean": 2.2, "variance": 3.4, "sample_size": 20},
            "away": {"mean": 2.7, "variance": 3.9, "sample_size": 20},
        },
    }
    payload = client.post(
        "/v1/predict",
        json={"home_team": "Alpha FC", "away_team": "Beta FC", "quality_score": 95.0, "counts": counts},
        headers=HEADERS,
    ).json()
    families = {item["family"] for item in payload["market_predictions"]}
    assert {"corners", "cards"} <= families
    total = [item for item in payload["market_predictions"] if item["market_id"] == "total_corners"]
    assert total
    # Over et Under sont complémentaires. Sur une ligne quart, la masse
    # remboursée/demi-réglée explique l'écart à 1 : elle n'est pas comptée
    # comme un gain plein.
    for over in [item for item in total if item["selection"].startswith("over")][:4]:
        line = over["line"]
        under = next(item for item in total if item["selection"] == f"under:{line}")
        settlement = over["settlement"]
        compensated = settlement["push"] + 0.5 * (settlement["half_win"] + settlement["half_loss"])
        assert over["probability"] + under["probability"] + compensated == pytest.approx(1.0, abs=2e-2)
    assert payload["market_coverage"]["families"]["corners"]["generated"] > 0


def test_combinations_do_not_multiply_same_match_probabilities(client):
    train(client)
    payload = client.post(
        "/v1/predict",
        json={"home_team": "Alpha FC", "away_team": "Beta FC", "quality_score": 95.0},
        headers=HEADERS,
    ).json()
    for profile in payload["combinations"].values():
        for combination in profile:
            if combination["method"] == "joint_score_matrix":
                assert len(combination["selections"]) == 2
                leg_probabilities = [leg["probability"] for leg in combination["selections"]]
                assert combination["joint_probability"] <= min(leg_probabilities) + 1e-6
