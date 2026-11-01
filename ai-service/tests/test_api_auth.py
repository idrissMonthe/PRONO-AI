import pytest
from fastapi.testclient import TestClient

from app.main import app
from app import main as prediction_api
from app.core.elo import EloEngine
from app.core.market_specialists import MarketSpecialists
from app.core.poisson_regressor import DixonColesModel


@pytest.fixture
def client(monkeypatch):
    monkeypatch.setenv("AI_SERVICE_SECRET", "test-internal-secret")
    monkeypatch.setattr(prediction_api, "champion_model", DixonColesModel())
    return TestClient(app)


def test_health_is_public(client):
    response = client.get("/v1/health")

    assert response.status_code == 200


def test_prediction_requires_the_internal_secret(client):
    payload = {"home_team": "Home FC", "away_team": "Away FC"}

    response = client.post("/v1/predict", json=payload)

    assert response.status_code == 401


def test_prediction_accepts_the_internal_secret(client):
    payload = {"home_team": "Home FC", "away_team": "Away FC"}

    response = client.post(
        "/v1/predict",
        json=payload,
        headers={"X-Service-Secret": "test-internal-secret"},
    )

    assert response.status_code == 200
    assert response.json()["abstain"] is True
    assert response.json()["model"]["version"] == "M3_UNTRAINED_FALLBACK"


def test_ready_reports_the_untrained_fallback(client):
    response = client.get("/v1/ready")

    assert response.status_code == 200
    assert response.json()["model_loaded"] is False
    assert response.json()["status"] == "degraded"


def test_model_info_does_not_claim_an_untrained_version(client):
    response = client.get(
        "/v1/model/info",
        headers={"X-Service-Secret": "test-internal-secret"},
    )

    assert response.status_code == 200
    assert response.json()["model_loaded"] is False
    assert response.json()["version"] == "M3_UNTRAINED_FALLBACK"


def test_training_updates_and_persists_every_python_model(client, monkeypatch, tmp_path):
    monkeypatch.setattr(prediction_api, "champion_model", DixonColesModel())
    monkeypatch.setattr(prediction_api, "elo_engine", EloEngine())
    monkeypatch.setattr(prediction_api, "market_specialists", MarketSpecialists())
    monkeypatch.setattr(prediction_api, "elo_ratings", {})
    monkeypatch.setattr(prediction_api, "recent_form", {})
    monkeypatch.setattr(prediction_api, "MODEL_STATE_PATH", tmp_path / "model-state.json")
    matches = [
        {
            "home_team": "Home FC" if index % 2 == 0 else "Away FC",
            "away_team": "Away FC" if index % 2 == 0 else "Home FC",
            "home_goals": 2 if index % 3 == 0 else 1,
            "away_goals": 0 if index % 3 == 0 else 1,
            "days_ago": float(index),
            "is_neutral": False,
        }
        for index in range(6)
    ]

    response = client.post(
        "/v1/train",
        json={"matches": matches},
        headers={"X-Service-Secret": "test-internal-secret"},
    )

    assert response.status_code == 200
    result = response.json()
    assert result["trained"] is True
    assert all(
        result["models"][key]["trained"]
        for key in ("dixon_coles", "elo", "market_specialists")
    )
    assert result["models"]["dixon_coles"]["item_count"] == 2
    assert result["models"]["dixon_coles"]["observed_count"] == 2
    saved_state = prediction_api.MODEL_STATE_PATH.read_text(encoding="utf-8")
    assert '"market_specialists"' in saved_state
    assert '"elo_ratings"' in saved_state


def test_prediction_rejects_a_single_lambda_override(client):
    response = client.post(
        "/v1/predict",
        json={"home_team": "Home FC", "away_team": "Away FC", "lambda_home_override": 1.5},
        headers={"X-Service-Secret": "test-internal-secret"},
    )

    assert response.status_code == 422


def test_evaluation_rejects_probabilities_that_do_not_sum_to_one(client):
    response = client.post(
        "/v1/evaluate",
        json={"items": [{"p_home": 0.5, "p_draw": 0.3, "p_away": 0.3, "outcome": "HOME"}]},
        headers={"X-Service-Secret": "test-internal-secret"},
    )

    assert response.status_code == 422


@pytest.mark.parametrize("path", ["/v1/model/info", "/v1/odds/devig", "/v1/evaluate"])
def test_internal_operations_require_the_secret(client, path):
    response = client.get(path) if path.endswith("/info") else client.post(path, json={})

    assert response.status_code == 401