import pytest
from fastapi.testclient import TestClient

from app.main import app


@pytest.fixture
def client(monkeypatch):
    monkeypatch.setenv("AI_SERVICE_SECRET", "test-internal-secret")
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