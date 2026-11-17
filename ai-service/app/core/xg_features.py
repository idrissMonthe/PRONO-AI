"""Leakage-safe expected-goals (xG) features.

Every figure produced here is built exclusively from matches that kicked off
**strictly before** the requested ``as_of`` date. A match's own xG can therefore
never feed its own prediction: the builder filters on ``match_date < as_of``
before any weighted aggregation happens.

Two hard rules:

* No xG value is ever invented. A match without a measured xG stays in the
  sample as "played but not measured" and only dilutes the coverage ratio.
* Missing coverage never penalises a team's rating. When the coverage is
  insufficient the adjustment returns the untouched Dixon-Coles lambdas and
  says so explicitly, so the caller can report the statistical coverage of the
  prediction instead of hiding it.
"""

from __future__ import annotations

import math
from dataclasses import dataclass, field
from datetime import date
from typing import Dict, Iterable, List, Optional, Sequence, Tuple

HALF_LIFE_DAYS = 365.0
# Same time decay as Dixon-Coles (xi = 0.0019 / day, half-life ~365 days).
DEFAULT_DECAY = math.log(2.0) / HALF_LIFE_DAYS

# Shrinkage prior, expressed in "matches" of league-average evidence.
PRIOR_MATCHES = 6.0
# Below this the xG sample is noise for a single team.
MIN_MATCHES_FOR_XG = 3
PARTIAL_COVERAGE_MATCHES = 6
FULL_COVERAGE_MATCHES = 10

# Maximum log-space movement an xG adjustment may apply to a lambda.
MAX_LOG_SHIFT = 0.12

COVERAGE_WEIGHTS = {"none": 0.0, "thin": 0.35, "partial": 0.7, "full": 1.0}
COVERAGE_ORDER = ("none", "thin", "partial", "full")

TREND_RECENT_MATCHES = 5


def _is_valid_xg(value: Optional[float]) -> bool:
    """A usable xG is a finite, non-negative number."""
    if value is None:
        return False
    try:
        number = float(value)
    except (TypeError, ValueError):
        return False
    return math.isfinite(number) and number >= 0.0


@dataclass(frozen=True)
class XgMatch:
    """One played match, with xG when the provider measured it."""

    match_date: date
    home_team: str
    away_team: str
    home_goals: int
    away_goals: int
    home_xg: Optional[float] = None
    away_xg: Optional[float] = None
    home_shots: Optional[int] = None
    away_shots: Optional[int] = None
    home_shots_on_target: Optional[int] = None
    away_shots_on_target: Optional[int] = None
    competition: str = "unknown"


@dataclass(frozen=True)
class _TeamRow:
    match_date: date
    competition: str
    is_home: bool
    goals_for: int
    goals_against: int
    xg_for: Optional[float]
    xg_against: Optional[float]
    shots_for: Optional[int]
    shots_on_target_for: Optional[int]


@dataclass
class TeamXgFeatures:
    """Weighted xG profile of one team, as known before ``as_of``."""

    team: str
    as_of: date
    matches_played: int = 0
    matches_with_xg: int = 0
    coverage: str = "none"
    reliability: float = 0.0
    weight_total: float = 0.0

    xg_for: Optional[float] = None
    xg_against: Optional[float] = None
    goals_for: Optional[float] = None
    goals_against: Optional[float] = None
    goals_minus_xg: Optional[float] = None
    conceded_minus_xg_against: Optional[float] = None

    xg_for_home: Optional[float] = None
    xg_against_home: Optional[float] = None
    xg_for_away: Optional[float] = None
    xg_against_away: Optional[float] = None
    home_matches_with_xg: int = 0
    away_matches_with_xg: int = 0

    xg_trend: Optional[float] = None
    xg_per_shot: Optional[float] = None
    shots_per_match: Optional[float] = None

    @property
    def has_xg(self) -> bool:
        return self.coverage != "none"

    def to_dict(self) -> Dict[str, object]:
        return {
            "team": self.team,
            "as_of": self.as_of.isoformat(),
            "matches_played": self.matches_played,
            "matches_with_xg": self.matches_with_xg,
            "coverage": self.coverage,
            "reliability": round(self.reliability, 4),
            "xg_for": _round(self.xg_for),
            "xg_against": _round(self.xg_against),
            "goals_for": _round(self.goals_for),
            "goals_against": _round(self.goals_against),
            "goals_minus_xg": _round(self.goals_minus_xg),
            "conceded_minus_xg_against": _round(self.conceded_minus_xg_against),
            "xg_for_home": _round(self.xg_for_home),
            "xg_against_home": _round(self.xg_against_home),
            "xg_for_away": _round(self.xg_for_away),
            "xg_against_away": _round(self.xg_against_away),
            "home_matches_with_xg": self.home_matches_with_xg,
            "away_matches_with_xg": self.away_matches_with_xg,
            "xg_trend": _round(self.xg_trend),
            "xg_per_shot": _round(self.xg_per_shot),
            "shots_per_match": _round(self.shots_per_match),
        }


@dataclass
class XgAdjustment:
    """Result of the bounded xG adjustment of Dixon-Coles lambdas."""

    lambda_home: float
    lambda_away: float
    applied: bool
    effective_weight: float
    log_shift_home: float = 0.0
    log_shift_away: float = 0.0
    lambda_home_xg: Optional[float] = None
    lambda_away_xg: Optional[float] = None
    coverage: str = "none"
    reason: str = ""
    coverage_detail: Dict[str, object] = field(default_factory=dict)

    def to_dict(self) -> Dict[str, object]:
        return {
            "applied": self.applied,
            "effective_weight": round(self.effective_weight, 4),
            "log_shift_home": round(self.log_shift_home, 4),
            "log_shift_away": round(self.log_shift_away, 4),
            "lambda_home_xg": _round(self.lambda_home_xg),
            "lambda_away_xg": _round(self.lambda_away_xg),
            "coverage": self.coverage,
            "reason": self.reason,
            "coverage_detail": self.coverage_detail,
        }


def _round(value: Optional[float], digits: int = 4) -> Optional[float]:
    return None if value is None else round(float(value), digits)


def _weighted_mean(values: Sequence[float], weights: Sequence[float]) -> Optional[float]:
    total = float(sum(weights))
    if total <= 0.0 or not values:
        return None
    return float(sum(value * weight for value, weight in zip(values, weights)) / total)


def _shrink(value: Optional[float], weight_total: float, prior: float) -> Optional[float]:
    """Empirical-Bayes shrinkage of a per-match rate toward a league prior."""
    if value is None:
        return None
    return (value * weight_total + prior * PRIOR_MATCHES) / (weight_total + PRIOR_MATCHES)


class XgFeatureBuilder:
    """Chronological xG history that can only answer questions about the past."""

    def __init__(self, half_life_days: float = HALF_LIFE_DAYS) -> None:
        self.half_life_days = float(half_life_days)
        self.decay = math.log(2.0) / self.half_life_days if self.half_life_days > 0 else 0.0
        self._teams: Dict[str, List[_TeamRow]] = {}
        self._league: List[_TeamRow] = []
        self._matches: List[XgMatch] = []

    # ------------------------------------------------------------------
    # Ingestion
    # ------------------------------------------------------------------
    def reset(self) -> "XgFeatureBuilder":
        self._teams.clear()
        self._league.clear()
        self._matches.clear()
        return self

    def matches(self) -> List[XgMatch]:
        """The ingested matches, for compact persistence."""
        return list(self._matches)

    def add(self, match: XgMatch) -> None:
        if not match.home_team or not match.away_team:
            raise ValueError("An xG match needs both team names.")
        if not isinstance(match.match_date, date):
            raise ValueError("An xG match needs a match date.")
        self._matches.append(match)

        home_xg = float(match.home_xg) if _is_valid_xg(match.home_xg) else None
        away_xg = float(match.away_xg) if _is_valid_xg(match.away_xg) else None

        self._teams.setdefault(match.home_team, []).append(
            _TeamRow(
                match_date=match.match_date,
                competition=match.competition,
                is_home=True,
                goals_for=int(match.home_goals),
                goals_against=int(match.away_goals),
                xg_for=home_xg,
                xg_against=away_xg,
                shots_for=match.home_shots,
                shots_on_target_for=match.home_shots_on_target,
            )
        )
        self._teams.setdefault(match.away_team, []).append(
            _TeamRow(
                match_date=match.match_date,
                competition=match.competition,
                is_home=False,
                goals_for=int(match.away_goals),
                goals_against=int(match.home_goals),
                xg_for=away_xg,
                xg_against=home_xg,
                shots_for=match.away_shots,
                shots_on_target_for=match.away_shots_on_target,
            )
        )
        # League baseline keeps the home/away split, hence two rows.
        self._league.append(
            _TeamRow(
                match_date=match.match_date,
                competition=match.competition,
                is_home=True,
                goals_for=int(match.home_goals),
                goals_against=int(match.away_goals),
                xg_for=home_xg,
                xg_against=None,
                shots_for=match.home_shots,
                shots_on_target_for=match.home_shots_on_target,
            )
        )
        self._league.append(
            _TeamRow(
                match_date=match.match_date,
                competition=match.competition,
                is_home=False,
                goals_for=int(match.away_goals),
                goals_against=int(match.home_goals),
                xg_for=away_xg,
                xg_against=None,
                shots_for=match.away_shots,
                shots_on_target_for=match.away_shots_on_target,
            )
        )

    def extend(self, matches: Iterable[XgMatch]) -> "XgFeatureBuilder":
        for match in matches:
            self.add(match)
        return self

    def __len__(self) -> int:
        return len(self._league) // 2

    # ------------------------------------------------------------------
    # Past-only queries
    # ------------------------------------------------------------------
    def _past_rows(
        self,
        team: Optional[str],
        as_of: date,
        competition: Optional[str] = None,
    ) -> List[Tuple[_TeamRow, float]]:
        source = self._league if team is None else self._teams.get(team, [])
        rows: List[Tuple[_TeamRow, float]] = []
        for row in source:
            # Strict inequality: a match kicking off on ``as_of`` is not known.
            if row.match_date >= as_of:
                continue
            if competition is not None and row.competition != competition:
                continue
            age_days = max(0, (as_of - row.match_date).days)
            rows.append((row, math.exp(-self.decay * age_days)))
        rows.sort(key=lambda item: item[0].match_date)
        return rows

    def features(self, team: str, as_of: date) -> TeamXgFeatures:
        rows = self._past_rows(team, as_of)
        features = TeamXgFeatures(team=team, as_of=as_of, matches_played=len(rows))
        if not rows:
            return features

        measured = [(row, weight) for row, weight in rows if row.xg_for is not None]
        features.matches_with_xg = len(measured)
        features.coverage = coverage_tier(len(measured))
        features.reliability = len(measured) / (len(measured) + PRIOR_MATCHES)
        if not measured:
            return features

        weights = [weight for _, weight in measured]
        features.weight_total = float(sum(weights))
        features.xg_for = _weighted_mean([row.xg_for for row, _ in measured], weights)
        features.goals_for = _weighted_mean([float(row.goals_for) for row, _ in measured], weights)

        against = [(row, weight) for row, weight in measured if row.xg_against is not None]
        if against:
            against_weights = [weight for _, weight in against]
            features.xg_against = _weighted_mean([row.xg_against for row, _ in against], against_weights)
            features.goals_against = _weighted_mean(
                [float(row.goals_against) for row, _ in against], against_weights
            )

        if features.xg_for is not None and features.goals_for is not None:
            features.goals_minus_xg = features.goals_for - features.xg_for
        if features.xg_against is not None and features.goals_against is not None:
            features.conceded_minus_xg_against = features.goals_against - features.xg_against

        home = [(row, weight) for row, weight in measured if row.is_home]
        away = [(row, weight) for row, weight in measured if not row.is_home]
        features.home_matches_with_xg = len(home)
        features.away_matches_with_xg = len(away)
        if home:
            features.xg_for_home = _weighted_mean(
                [row.xg_for for row, _ in home], [weight for _, weight in home]
            )
            features.xg_against_home = _weighted_mean(
                [row.xg_against for row, _ in home if row.xg_against is not None],
                [weight for row, weight in home if row.xg_against is not None],
            )
        if away:
            features.xg_for_away = _weighted_mean(
                [row.xg_for for row, _ in away], [weight for _, weight in away]
            )
            features.xg_against_away = _weighted_mean(
                [row.xg_against for row, _ in away if row.xg_against is not None],
                [weight for row, weight in away if row.xg_against is not None],
            )

        features.xg_trend = self._trend(measured)
        shots = [(row, weight) for row, weight in measured if row.shots_for is not None and row.shots_for > 0]
        if shots:
            features.shots_per_match = _weighted_mean(
                [float(row.shots_for) for row, _ in shots], [weight for _, weight in shots]
            )
            total_xg = float(sum(row.xg_for * weight for row, weight in shots))
            total_shots = float(sum(row.shots_for * weight for row, weight in shots))
            if total_shots > 0:
                features.xg_per_shot = total_xg / total_shots

        return features

    @staticmethod
    def _trend(measured: List[Tuple[_TeamRow, float]]) -> Optional[float]:
        """Recent xG for minus the preceding block, unweighted to stay legible."""
        ordered = sorted(measured, key=lambda item: item[0].match_date, reverse=True)
        recent = [row.xg_for for row, _ in ordered[:TREND_RECENT_MATCHES]]
        previous = [row.xg_for for row, _ in ordered[TREND_RECENT_MATCHES: TREND_RECENT_MATCHES * 2]]
        if not recent or not previous:
            return None
        return float(sum(recent) / len(recent) - sum(previous) / len(previous))

    def baseline(self, as_of: date, competition: Optional[str] = None) -> Dict[str, object]:
        """League scoring baselines from measured matches only, before ``as_of``."""
        rows = self._past_rows(None, as_of, competition)
        home = [(row, weight) for row, weight in rows if row.is_home and row.xg_for is not None]
        away = [(row, weight) for row, weight in rows if not row.is_home and row.xg_for is not None]

        baseline_home = _weighted_mean([row.xg_for for row, _ in home], [weight for _, weight in home])
        baseline_away = _weighted_mean([row.xg_for for row, _ in away], [weight for _, weight in away])

        matches_with_xg = len(home) + len(away)
        tier = coverage_tier(matches_with_xg)
        overall_parts = [value for value in (baseline_home, baseline_away) if value is not None]
        overall = float(sum(overall_parts) / len(overall_parts)) if overall_parts else None

        # A baseline estimated on one or two measured team-innings is noise, so
        # it is reported as unavailable rather than silently trusted.
        return {
            "home": baseline_home,
            "away": baseline_away,
            "overall": overall,
            "matches_with_xg": matches_with_xg,
            "coverage": tier,
            "available": baseline_home is not None and baseline_away is not None and tier != "none",
        }


def coverage_tier(matches_with_xg: int) -> str:
    """Statistical coverage label for a sample of measured xG matches."""
    if matches_with_xg >= FULL_COVERAGE_MATCHES:
        return "full"
    if matches_with_xg >= PARTIAL_COVERAGE_MATCHES:
        return "partial"
    if matches_with_xg >= MIN_MATCHES_FOR_XG:
        return "thin"
    return "none"


def _worse_tier(first: str, second: str) -> str:
    return COVERAGE_ORDER[min(COVERAGE_ORDER.index(first), COVERAGE_ORDER.index(second))]


def describe_coverage(
    home: TeamXgFeatures,
    away: TeamXgFeatures,
    baseline: Dict[str, object],
) -> Dict[str, object]:
    """Coverage report attached to a prediction so a weak sample is visible."""
    combined = _worse_tier(home.coverage, away.coverage)
    if baseline.get("available") is not True:
        combined = "none"
    return {
        "home": home.coverage,
        "away": away.coverage,
        "combined": combined,
        "home_matches": home.matches_with_xg,
        "away_matches": away.matches_with_xg,
        "league_matches": int(baseline.get("matches_with_xg") or 0),
        "league_available": bool(baseline.get("available")),
    }


def adjust_lambdas_with_xg(
    lambda_home: float,
    lambda_away: float,
    home: TeamXgFeatures,
    away: TeamXgFeatures,
    baseline: Dict[str, object],
    weight: float = 0.0,
    max_log_shift: float = MAX_LOG_SHIFT,
) -> XgAdjustment:
    """Blend xG-implied lambdas into Dixon-Coles lambdas, in log space, bounded.

    ``weight`` is the caller's chosen maximum influence (0 disables the feature
    completely). The effective weight is scaled down by the weaker of the two
    coverage tiers and by each team's reliability, so a thin sample can only
    nudge the model. The total movement is clipped to ``max_log_shift``.
    """
    coverage = describe_coverage(home, away, baseline)
    if baseline.get("available") is not True:
        return XgAdjustment(lambda_home, lambda_away, False, 0.0, coverage=coverage["combined"],
                            reason="league_xg_baseline_unavailable", coverage_detail=coverage)
    if weight <= 0:
        return XgAdjustment(lambda_home, lambda_away, False, 0.0, coverage=coverage["combined"],
                            reason="xg_adjustment_disabled", coverage_detail=coverage)
    if not home.has_xg or not away.has_xg:
        return XgAdjustment(lambda_home, lambda_away, False, 0.0, coverage=coverage["combined"],
                            reason="missing_team_xg_coverage", coverage_detail=coverage)

    tier_weight = min(COVERAGE_WEIGHTS[home.coverage], COVERAGE_WEIGHTS[away.coverage])
    reliability = min(home.reliability, away.reliability)
    effective_weight = max(0.0, min(1.0, float(weight) * tier_weight * reliability))
    if effective_weight <= 1e-6:
        return XgAdjustment(lambda_home, lambda_away, False, 0.0, coverage=coverage["combined"],
                            reason="effective_xg_weight_negligible", coverage_detail=coverage)

    baseline_home = float(baseline["home"])
    baseline_away = float(baseline["away"])
    baseline_overall = float(baseline.get("overall") or (baseline_home + baseline_away) / 2.0)
    if baseline_home <= 0 or baseline_away <= 0:
        return XgAdjustment(lambda_home, lambda_away, False, 0.0, coverage=coverage["combined"],
                            reason="invalid_xg_baseline", coverage_detail=coverage)

    # Attack ratios use the venue the team actually plays in this fixture;
    # defence ratios use the opponent's venue baseline, so both sides of the
    # multiplication refer to the same reference population.
    home_attack_rate = home.xg_for_home if home.home_matches_with_xg >= 2 else home.xg_for
    home_defence_rate = home.xg_against_home if home.home_matches_with_xg >= 2 else home.xg_against
    away_attack_rate = away.xg_for_away if away.away_matches_with_xg >= 2 else away.xg_for
    away_defence_rate = away.xg_against_away if away.away_matches_with_xg >= 2 else away.xg_against

    home_attack = _shrink(home_attack_rate, home.weight_total, baseline_home)
    away_attack = _shrink(away_attack_rate, away.weight_total, baseline_away)
    home_defence = _shrink(home_defence_rate, home.weight_total, baseline_away)
    away_defence = _shrink(away_defence_rate, away.weight_total, baseline_home)
    if None in (home_attack, away_attack, home_defence, away_defence):
        return XgAdjustment(lambda_home, lambda_away, False, 0.0, coverage=coverage["combined"],
                            reason="incomplete_team_xg_profile", coverage_detail=coverage)

    lambda_home_xg = max(0.1, baseline_home * (home_attack / baseline_home) * (away_defence / baseline_home))
    lambda_away_xg = max(0.1, baseline_away * (away_attack / baseline_away) * (home_defence / baseline_away))
    _ = baseline_overall  # documented for callers reading the baseline block

    log_home = (1.0 - effective_weight) * math.log(max(lambda_home, 1e-9)) + effective_weight * math.log(lambda_home_xg)
    log_away = (1.0 - effective_weight) * math.log(max(lambda_away, 1e-9)) + effective_weight * math.log(lambda_away_xg)

    shift_home = max(-max_log_shift, min(max_log_shift, log_home - math.log(max(lambda_home, 1e-9))))
    shift_away = max(-max_log_shift, min(max_log_shift, log_away - math.log(max(lambda_away, 1e-9))))

    return XgAdjustment(
        lambda_home=float(math.exp(math.log(max(lambda_home, 1e-9)) + shift_home)),
        lambda_away=float(math.exp(math.log(max(lambda_away, 1e-9)) + shift_away)),
        applied=True,
        effective_weight=effective_weight,
        log_shift_home=shift_home,
        log_shift_away=shift_away,
        lambda_home_xg=lambda_home_xg,
        lambda_away_xg=lambda_away_xg,
        coverage=coverage["combined"],
        reason="xg_adjustment_applied",
        coverage_detail=coverage,
    )
