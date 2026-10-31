"""
Pydantic schemas for the AI Prediction & Statistics Service (CDC Section 14.6 & 29.4)
"""
from math import isclose
from typing import Any, Dict, List, Literal, Optional
from pydantic import BaseModel, Field, field_validator, model_validator


class ProbabilityInterval(BaseModel):
    p: float
    ci80: List[float]
    ci95: List[float]


class ProbabilitiesBlock(BaseModel):
    home: ProbabilityInterval
    draw: ProbabilityInterval
    away: ProbabilityInterval


class ExpectedGoalsBlock(BaseModel):
    home: float
    away: float
    total: float


class QualityBlock(BaseModel):
    score: float
    tier: Literal["usable", "degraded", "insufficient"]
    confidence_tier: Literal["low", "medium", "high"]


class MatrixMetaBlock(BaseModel):
    max_goals: int
    truncated_mass: float


class ScorePrediction(BaseModel):
    score: str
    p: float


class PredictRequest(BaseModel):
    match_id: Optional[str] = "demo-match-1"
    home_team: str = Field(min_length=1, max_length=100)
    away_team: str = Field(min_length=1, max_length=100)
    is_neutral_ground: bool = False
    quality_score: float = Field(default=100.0, ge=0.0, le=100.0)
    lambda_home_override: Optional[float] = Field(default=None, ge=0.1, le=8.0)
    lambda_away_override: Optional[float] = Field(default=None, ge=0.1, le=8.0)

    @field_validator("home_team", "away_team")
    @classmethod
    def team_name_must_not_be_blank(cls, value: str) -> str:
        value = value.strip()
        if not value:
            raise ValueError("Team name must not be blank.")
        return value

    @model_validator(mode="after")
    def lambda_overrides_must_be_paired(self):
        if (self.lambda_home_override is None) != (self.lambda_away_override is None):
            raise ValueError("Both lambda overrides must be provided together.")
        return self


class PredictResponse(BaseModel):
    match_id: str
    model: Dict[str, str]
    computed_at: str
    data_snapshot_hash: str
    expected_goals: ExpectedGoalsBlock
    probabilities: ProbabilitiesBlock
    markets: Dict[str, Any]
    top_scores: List[ScorePrediction]
    matrix: MatrixMetaBlock
    quality: QualityBlock
    confidence_tier: Literal["low", "medium", "high"]
    abstain: bool
    warnings: List[str]
    duration_ms: int


class DevigRequest(BaseModel):
    odds: List[float]
    method: Literal["shin", "power", "proportional"] = "shin"


class DevigResponse(BaseModel):
    method: str
    input_odds: List[float]
    overround: float
    true_probabilities: List[float]
    fair_odds: List[float]
    param: Dict[str, Any]


class EvaluateItem(BaseModel):
    p_home: float = Field(ge=0.0, le=1.0)
    p_draw: float = Field(ge=0.0, le=1.0)
    p_away: float = Field(ge=0.0, le=1.0)
    outcome: Literal["HOME", "DRAW", "AWAY", "1", "N", "2"]

    @model_validator(mode="after")
    def probabilities_must_sum_to_one(self):
        if not isclose(self.p_home + self.p_draw + self.p_away, 1.0, abs_tol=1e-3):
            raise ValueError("1N2 probabilities must sum to 1.")
        return self


class EvaluateRequest(BaseModel):
    items: List[EvaluateItem]


class EvaluateResponse(BaseModel):
    sample_size: int
    mean_rps: float
    mean_log_loss: float
    mean_brier: float
    ece: float
    details: Dict[str, Any]

