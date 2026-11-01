"""
FastAPI Prediction Service for ORACLE FOOT (CDC Version 2.0)
Listens strictly on local internal network (127.0.0.1:8001).
"""
import hashlib
import json
import os
import secrets
import time
from datetime import datetime, timezone
from pathlib import Path
from typing import Dict, List, Optional
from dotenv import load_dotenv
import numpy as np
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
from app.core.poisson_regressor import DixonColesModel, MIN_TEAM_MATCHES_FOR_OFFICIAL_PREDICTIONS
from app.core.elo import EloEngine
from app.core.market_specialists import MarketSpecialists
from app.schemas import (
    DevigRequest,
    DevigResponse,
    EvaluateRequest,
    EvaluateResponse,
    PredictRequest,
    PredictResponse,
    TrainRequest,
    TrainResponse,
)
from app.core.poisson_regressor import MatchData

load_dotenv(Path(__file__).resolve().parents[2] / ".env")
MODEL_STATE_PATH = Path(os.getenv("MODEL_STATE_PATH", "/app/data/dixon_coles_model.json"))
MODEL_VERSION = "M3_MARKET_SPECIALISTS_v3"
CALIBRATION_VERSION = "calib_v5_market_backed_sparse_teams"

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
elo_engine = EloEngine(k_factor=20.0, default_home_adv=65.0)
elo_ratings: Dict[str, float] = {}
recent_form: Dict[str, List[float]] = {}
market_specialists = MarketSpecialists()


def load_model_state() -> None:
    if not MODEL_STATE_PATH.exists():
        return
    try:
        state = json.loads(MODEL_STATE_PATH.read_text(encoding="utf-8"))
        team_match_counts = state.get("team_match_counts")
        if (
            state.get("model_version") != MODEL_VERSION
            or
            state.get("team_strength_min_matches") != MIN_TEAM_MATCHES_FOR_OFFICIAL_PREDICTIONS
            or not isinstance(team_match_counts, dict)
            or not team_match_counts
        ):
            champion_model.is_fitted = False
            return
        champion_model.teams = state["teams"]
        champion_model.team_indices = {name: index for index, name in enumerate(champion_model.teams)}
        champion_model.mu = float(state["mu"])
        champion_model.home_adv = float(state["home_adv"])
        champion_model.attack_strengths = np.array(state["attack_strengths"], dtype=float)
        champion_model.defense_strengths = np.array(state["defense_strengths"], dtype=float)
        champion_model.team_match_counts = {team: int(count) for team, count in team_match_counts.items()}
        champion_model.training_hash = state.get("training_hash", "")
        champion_model.is_fitted = bool(state.get("is_fitted", False))
        elo_ratings.clear()
        elo_ratings.update({k: float(v) for k, v in state.get("elo_ratings", {}).items()})
        recent_form.clear()
        recent_form.update({k: [float(x) for x in v] for k, v in state.get("recent_form", {}).items()})
        market_specialists.load_state(state.get("market_specialists", {}))
    except (OSError, KeyError, TypeError, ValueError, json.JSONDecodeError):
        champion_model.is_fitted = False


def save_model_state() -> None:
    MODEL_STATE_PATH.parent.mkdir(parents=True, exist_ok=True)
    MODEL_STATE_PATH.write_text(json.dumps({
        "teams": champion_model.teams,
        "model_version": MODEL_VERSION,
        "mu": champion_model.mu,
        "home_adv": champion_model.home_adv,
        "attack_strengths": champion_model.attack_strengths.tolist(),
        "defense_strengths": champion_model.defense_strengths.tolist(),
        "team_match_counts": champion_model.team_match_counts,
        "team_strength_min_matches": MIN_TEAM_MATCHES_FOR_OFFICIAL_PREDICTIONS,
        "training_hash": champion_model.training_hash,
        "is_fitted": champion_model.is_fitted,
        "elo_ratings": elo_ratings,
        "recent_form": recent_form,
        "market_specialists": market_specialists.state(),
    }), encoding="utf-8")


load_model_state()

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
        "model_version": MODEL_VERSION if champion_model.is_fitted else "M3_UNTRAINED_FALLBACK",
        "family": "hybrid",
        "timestamp": datetime.now(timezone.utc).isoformat(),
    }


@app.get("/v1/model/info", dependencies=[Depends(require_internal_secret)])
def model_info():
    return {
            "version": MODEL_VERSION if champion_model.is_fitted else "M3_UNTRAINED_FALLBACK",
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


@app.post("/v1/train", response_model=TrainResponse, dependencies=[Depends(require_internal_secret)])
def train(req: TrainRequest):
    training_rows = [
        MatchData(
            home_team=item.home_team,
            away_team=item.away_team,
            home_goals=item.home_goals,
            away_goals=item.away_goals,
            days_ago=item.days_ago,
            is_neutral=item.is_neutral,
        )
        for item in req.matches
    ]
    champion_model.fit(training_rows)
    market_specialists.fit(training_rows)
    elo_ratings.clear()
    recent_form.clear()
    # Rebuild signals chronologically so no future result leaks into an earlier rating.
    for item in sorted(training_rows, key=lambda row: row.days_ago, reverse=True):
        home_rating = elo_ratings.get(item.home_team, elo_engine.default_elo)
        away_rating = elo_ratings.get(item.away_team, elo_engine.default_elo)
        new_home, new_away = elo_engine.update_ratings(
            home_rating, away_rating, item.home_goals, item.away_goals, item.is_neutral
        )
        elo_ratings[item.home_team] = new_home
        elo_ratings[item.away_team] = new_away
        home_points = 3.0 if item.home_goals > item.away_goals else (1.0 if item.home_goals == item.away_goals else 0.0)
        away_points = 3.0 if item.away_goals > item.home_goals else (1.0 if item.home_goals == item.away_goals else 0.0)
        recent_form.setdefault(item.home_team, []).append(home_points)
        recent_form.setdefault(item.away_team, []).append(away_points)
    for team in recent_form:
        recent_form[team] = recent_form[team][-5:]
    champion_model.training_hash = hashlib.sha256(
        json.dumps([item.model_dump() for item in req.matches], sort_keys=True).encode('utf-8')
    ).hexdigest()
    save_model_state()
    return {
        "trained": champion_model.is_fitted,
        "match_count": len(training_rows),
        "team_count": len(champion_model.team_match_counts),
        "training_hash": champion_model.training_hash,
        "models": {
            "dixon_coles": {
                "trained": champion_model.is_fitted,
                "item_count": len(champion_model.teams),
                "observed_count": len(champion_model.team_match_counts),
            },
            "elo": {
                "trained": len(elo_ratings) >= 2,
                "item_count": len(elo_ratings),
            },
            "market_specialists": {
                "trained": market_specialists.is_fitted,
                "item_count": market_specialists.sample_count,
            },
        },
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

    # Availability and schedule adjustments are applied only when sourced data exists.
    if req.absence_penalty_home or req.absence_penalty_away:
        lh *= max(0.70, 1.0 - req.absence_penalty_home)
        la *= max(0.70, 1.0 - req.absence_penalty_away)
        warnings.append("availability_adjustment_applied")
    if req.rest_days_home < 3 or req.rest_days_away < 3:
        if req.rest_days_home < 3:
            lh *= 0.95
        if req.rest_days_away < 3:
            la *= 0.95
        warnings.append("short_rest_adjustment_applied")

    # Compute score matrix
    matrix, residual_mass, n = compute_score_matrix(lh, la, champion_model.rho)
    specialist_matrices = market_specialists.predict(matrix)
    outcome_markets = extract_match_markets_from_matrix(specialist_matrices["outcome"])
    goals_markets = extract_match_markets_from_matrix(specialist_matrices["goals"])
    btts_markets = extract_match_markets_from_matrix(specialist_matrices["btts"])
    exact_markets = extract_match_markets_from_matrix(specialist_matrices["exact_score"])
    markets = {
        **outcome_markets,
        "totals": goals_markets["totals"],
        "btts": btts_markets["btts"],
        "combos": goals_markets["combos"],
        "goal_margin": goals_markets["goal_margin"],
        "top_scores": exact_markets["top_scores"],
        "other_scores_mass": exact_markets["other_scores_mass"],
    }
    warnings.append("specialized_market_models_applied")

    p_home = markets["1N2"]["home"]
    p_draw = markets["1N2"]["draw"]
    p_away = markets["1N2"]["away"]

    # Blend only with a complete, already de-vigged market snapshot.
    # The model remains dominant; the market is a controlled sanity check.
    model_probs = [p_home, p_draw, p_away]
    elo_probs = elo_engine.predict_1n2(
        elo_ratings.get(req.home_team, elo_engine.default_elo),
        elo_ratings.get(req.away_team, elo_engine.default_elo),
        req.is_neutral_ground,
    )
    home_form = np.mean(recent_form.get(req.home_team, [1.0])) / 3.0
    away_form = np.mean(recent_form.get(req.away_team, [1.0])) / 3.0
    form_delta = float(np.clip(home_form - away_form, -1.0, 1.0))
    form_home = float(np.clip(0.33 + 0.18 * form_delta, 0.10, 0.70))
    form_away = float(np.clip(0.33 - 0.18 * form_delta, 0.10, 0.70))
    form_probs = [form_home, max(0.10, 1.0 - form_home - form_away), form_away]
    p_home = round(0.65 * model_probs[0] + 0.25 * elo_probs["home"] + 0.10 * form_probs[0], 4)
    p_draw = round(0.65 * model_probs[1] + 0.25 * elo_probs["draw"] + 0.10 * form_probs[1], 4)
    p_away = round(0.65 * model_probs[2] + 0.25 * elo_probs["away"] + 0.10 * form_probs[2], 4)
    warnings.append("elo_form_ensemble_applied")
    has_unknown_team = any(w.startswith("unknown_or_promoted_team:") for w in warnings)
    has_low_sample_team = any(w.startswith("low_sample_team:") for w in warnings)
    has_sparse_team_history = has_unknown_team or has_low_sample_team
    if req.market_probabilities is not None:
        market_weight = 0.60 if has_sparse_team_history else 0.25
        model_weight = 1.0 - market_weight
        p_home = round(model_weight * p_home + market_weight * req.market_probabilities[0], 4)
        p_draw = round(model_weight * p_draw + market_weight * req.market_probabilities[1], 4)
        p_away = round(model_weight * p_away + market_weight * req.market_probabilities[2], 4)
        total = p_home + p_draw + p_away
        p_home, p_draw, p_away = [round(p / total, 4) for p in (p_home, p_draw, p_away)]
        markets["1N2"].update({"home": p_home, "draw": p_draw, "away": p_away})
        warnings.append("market_sanity_blend_applied")
        if has_sparse_team_history:
            warnings.append("market_weight_increased_due_to_sparse_history")

    # Credibility intervals
    unc_scale = max(0.02, (100.0 - req.quality_score) / 300.0 + 0.035)
    ci80_home = [round(max(0.01, p_home - 1.28 * unc_scale), 4), round(min(0.99, p_home + 1.28 * unc_scale), 4)]
    ci95_home = [round(max(0.01, p_home - 1.96 * unc_scale), 4), round(min(0.99, p_home + 1.96 * unc_scale), 4)]

    ci80_draw = [round(max(0.01, p_draw - 1.28 * unc_scale * 0.7), 4), round(min(0.99, p_draw + 1.28 * unc_scale * 0.7), 4)]
    ci95_draw = [round(max(0.01, p_draw - 1.96 * unc_scale * 0.7), 4), round(min(0.99, p_draw + 1.96 * unc_scale * 0.7), 4)]

    ci80_away = [round(max(0.01, p_away - 1.28 * unc_scale), 4), round(min(0.99, p_away + 1.28 * unc_scale), 4)]
    ci95_away = [round(max(0.01, p_away - 1.96 * unc_scale), 4), round(min(0.99, p_away + 1.96 * unc_scale), 4)]

    ordered_probs = sorted([p_home, p_draw, p_away], reverse=True)
    max_probability = ordered_probs[0]
    probability_margin = ordered_probs[0] - ordered_probs[1]

    # Conservative selection policy: official picks must clear all gates.
    if not champion_model.is_fitted:
        quality_tier = "insufficient"
        confidence_tier = "low"
        is_abstained = True
        warnings.append("model_not_trained_prediction_abstained")
    elif req.quality_score < 90.0:
        quality_tier = "insufficient"
        confidence_tier = "low"
        is_abstained = True
        warnings.append("abstain_due_to_unverified_data_quality")
    elif has_sparse_team_history and req.market_probabilities is None:
        quality_tier = "degraded"
        confidence_tier = "low"
        is_abstained = True
        warnings.append("abstain_due_to_insufficient_team_history_without_market")
    elif "expected_goals_clamped" in warnings:
        quality_tier = "insufficient"
        confidence_tier = "low"
        is_abstained = True
        warnings.append("abstain_due_to_extreme_expected_goals")
    elif has_sparse_team_history:
        if max_probability < 0.60 or probability_margin < 0.18:
            quality_tier = "degraded"
            confidence_tier = "low"
            is_abstained = True
            warnings.append("abstain_due_to_low_market_backed_separation")
        else:
            quality_tier = "degraded"
            confidence_tier = "medium"
            is_abstained = False
            warnings.append("market_backed_prediction_with_limited_team_history")
    elif max_probability < 0.60 or probability_margin < 0.18:
        quality_tier = "degraded"
        confidence_tier = "low"
        is_abstained = True
        warnings.append("abstain_due_to_low_separation")
    elif max_probability < 0.65 or probability_margin < 0.25:
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
            "version": MODEL_VERSION if champion_model.is_fitted else "M3_UNTRAINED_FALLBACK",
            "family": "pure",
            "calibration_version": CALIBRATION_VERSION,
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
