"""
FastAPI Prediction Service for ORACLE FOOT (CDC Version 2.0)
Listens strictly on local internal network (127.0.0.1:8001).
"""
import hashlib
import os
import secrets
import time
from datetime import datetime, timezone
from pathlib import Path
from typing import Optional
from dotenv import load_dotenv
from fastapi import Depends, FastAPI, Header, HTTPException, status
from fastapi.middleware.cors import CORSMiddleware

from app.core.devig import devig_odds
from app.core.dixon_coles import compute_score_matrix, extract_match_markets_from_matrix
from app.core.metrics import (
    calculate_brier_multiclass,
    calculate_ece,
    calculate_log_loss,
    calculate_rps,
)
from app.core.poisson_regressor import DixonColesModel
from app.schemas import (
    DevigRequest,
    DevigResponse,
    EvaluateRequest,
    EvaluateResponse,
    PredictRequest,
    PredictResponse,
)

load_dotenv(Path(__file__).resolve().parents[2] / ".env")

app = FastAPI(
    title="ORACLE FOOT Predictive API",
    version="2.0.0",
    docs_url="/docs",
    redoc_url="/redoc",
)

# CORS restrictive to local origin only (CDC 15.4)
app.add_middleware(
    CORSMiddleware,
    allow_origins=["http://127.0.0.1:8000", "http://localhost:8000"],
    allow_credentials=True,
    allow_methods=["GET", "POST"],
    allow_headers=["*"],
)

# Active internal model instance
champion_model = DixonColesModel(xi=0.0019, l2_reg=0.01, rho=-0.04)

def require_internal_secret(x_service_secret: Optional[str] = Header(default=None)) -> None:
    expected_secret = os.getenv("AI_SERVICE_SECRET")
    if not expected_secret:
        raise HTTPException(status_code=503, detail="Internal service secret is not configured.")
    if not x_service_secret or not secrets.compare_digest(x_service_secret, expected_secret):
        raise HTTPException(status_code=401, detail="Invalid service credentials.")


@app.get("/v1/health")
def health():
    return {"status": "ok", "timestamp": datetime.now(timezone.utc).isoformat()}


@app.get("/v1/ready")
def ready():
    return {
        "status": "ready" if champion_model.is_fitted else "degraded",
        "model_loaded": champion_model.is_fitted,
        "model_version": "M3_DIXON_COLES_v2.0" if champion_model.is_fitted else "M3_UNTRAINED_FALLBACK",
        "family": "pure",
        "timestamp": datetime.now(timezone.utc).isoformat(),
    }


@app.get("/v1/model/info", dependencies=[Depends(require_internal_secret)])
def model_info():
    return {
        "version": "M3_DIXON_COLES_v2.0" if champion_model.is_fitted else "M3_UNTRAINED_FALLBACK",
        "model_loaded": champion_model.is_fitted,
        "family": "pure",
        "algorithm": "Dixon-Coles Bivariate Poisson with Time Decay and Low-Score Tau Correction",
        "hyperparameters": {
            "xi_time_decay": champion_model.xi,
            "half_life_days": round(0.693147 / champion_model.xi, 1),
            "l2_regularization": champion_model.l2_reg,
            "rho_tau_correction": champion_model.rho,
            "home_advantage_mu": round(champion_model.home_adv, 3),
        },
        "identifiability_constraint": "sum(att) == 0, sum(def) == 0",
        "matrix_sum_tolerance": 1e-9,
        "max_residual_truncation": 1e-6,
    }


@app.post("/v1/predict", response_model=PredictResponse, dependencies=[Depends(require_internal_secret)])
def predict(req: PredictRequest):
    t0 = time.perf_counter()

    # Calculate lambdas
    if req.lambda_home_override is not None and req.lambda_away_override is not None:
        lh = max(0.1, req.lambda_home_override)
        la = max(0.1, req.lambda_away_override)
        warnings = ["custom_lambdas_override"]
    else:
        lh, la, warnings = champion_model.predict_lambdas(
            req.home_team, req.away_team, req.is_neutral_ground
        )

    # Compute score matrix
    matrix, residual_mass, n = compute_score_matrix(lh, la, champion_model.rho)
    markets = extract_match_markets_from_matrix(matrix)

    p_home = markets["1N2"]["home"]
    p_draw = markets["1N2"]["draw"]
    p_away = markets["1N2"]["away"]

    # Credibility intervals
    unc_scale = max(0.02, (100.0 - req.quality_score) / 300.0 + 0.035)
    ci80_home = [round(max(0.01, p_home - 1.28 * unc_scale), 4), round(min(0.99, p_home + 1.28 * unc_scale), 4)]
    ci95_home = [round(max(0.01, p_home - 1.96 * unc_scale), 4), round(min(0.99, p_home + 1.96 * unc_scale), 4)]

    ci80_draw = [round(max(0.01, p_draw - 1.28 * unc_scale * 0.7), 4), round(min(0.99, p_draw + 1.28 * unc_scale * 0.7), 4)]
    ci95_draw = [round(max(0.01, p_draw - 1.96 * unc_scale * 0.7), 4), round(min(0.99, p_draw + 1.96 * unc_scale * 0.7), 4)]

    ci80_away = [round(max(0.01, p_away - 1.28 * unc_scale), 4), round(min(0.99, p_away + 1.28 * unc_scale), 4)]
    ci95_away = [round(max(0.01, p_away - 1.96 * unc_scale), 4), round(min(0.99, p_away + 1.96 * unc_scale), 4)]

    # Quality & confidence tier
    if not champion_model.is_fitted:
        quality_tier = "insufficient"
        confidence_tier = "low"
        is_abstained = True
        warnings.append("model_not_trained_prediction_abstained")
    elif req.quality_score < 40.0:
        quality_tier = "insufficient"
        confidence_tier = "low"
        is_abstained = True
        warnings.append("abstain_due_to_insufficient_quality")
    elif req.quality_score < 70.0:
        quality_tier = "degraded"
        confidence_tier = "medium"
        is_abstained = False
    else:
        quality_tier = "usable"
        confidence_tier = "high"
        is_abstained = False

    duration_ms = int((time.perf_counter() - t0) * 1000)

    # Cryptographic snapshot hash
    data_str = f"{req.match_id}|{req.home_team}|{req.away_team}|{lh:.3f}|{la:.3f}|{req.quality_score}"
    snapshot_hash = hashlib.sha256(data_str.encode("utf-8")).hexdigest()

    return {
        "match_id": req.match_id or "match-sim",
        "model": {
            "version": "M3_DIXON_COLES_v2.0" if champion_model.is_fitted else "M3_UNTRAINED_FALLBACK",
            "family": "pure",
            "calibration_version": "calib_v1_beta",
        },
        "computed_at": datetime.now(timezone.utc).isoformat(),
        "data_snapshot_hash": snapshot_hash,
        "expected_goals": {
            "home": round(lh, 3),
            "away": round(la, 3),
            "total": round(lh + la, 3),
        },
        "probabilities": {
            "home": {"p": p_home, "ci80": ci80_home, "ci95": ci95_home},
            "draw": {"p": p_draw, "ci80": ci80_draw, "ci95": ci95_draw},
            "away": {"p": p_away, "ci80": ci80_away, "ci95": ci95_away},
        },
        "markets": markets,
        "top_scores": markets["top_scores"],
        "matrix": {
            "max_goals": n,
            "truncated_mass": float(residual_mass),
        },
        "quality": {
            "score": req.quality_score,
            "tier": quality_tier,
            "confidence_tier": confidence_tier,
        },
        "confidence_tier": confidence_tier,
        "abstain": is_abstained,
        "warnings": warnings,
        "duration_ms": duration_ms,
    }


@app.post("/v1/odds/devig", response_model=DevigResponse, dependencies=[Depends(require_internal_secret)])
def remove_margin(req: DevigRequest):
    try:
        res = devig_odds(req.odds, req.method)
        return res
    except ValueError as e:
        raise HTTPException(
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            detail=str(e),
        )


@app.post("/v1/evaluate", response_model=EvaluateResponse, dependencies=[Depends(require_internal_secret)])
def evaluate_predictions(req: EvaluateRequest):
    if not req.items:
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail="Evaluation list cannot be empty.",
        )

    rps_list = []
    log_loss_list = []
    brier_list = []
    home_probs = []
    home_outcomes = []

    for it in req.items:
        rps = calculate_rps(it.p_home, it.p_draw, it.p_away, it.outcome)
        ll = calculate_log_loss(it.p_home, it.p_draw, it.p_away, it.outcome)
        br = calculate_brier_multiclass(it.p_home, it.p_draw, it.p_away, it.outcome)

        rps_list.append(rps)
        log_loss_list.append(ll)
        brier_list.append(br)

        home_probs.append(it.p_home)
        home_outcomes.append(1 if it.outcome in ["HOME", "1", "H"] else 0)

    ece, ece_bins = calculate_ece(home_probs, home_outcomes, n_bins=10, adaptive=True)

    return {
        "sample_size": len(req.items),
        "mean_rps": round(float(sum(rps_list) / len(rps_list)), 5),
        "mean_log_loss": round(float(sum(log_loss_list) / len(log_loss_list)), 5),
        "mean_brier": round(float(sum(brier_list) / len(brier_list)), 5),
        "ece": round(ece, 5),
        "details": {
            "bins": ece_bins,
            "min_rps": round(float(min(rps_list)), 5),
            "max_rps": round(float(max(rps_list)), 5),
        },
    }

