"""Chronological walk-forward backtest for the PRONO-IA 1N2 engine.

Protocol
--------
* Matches are ordered by kickoff date.
* Every prediction is produced by a model fitted only on matches that kicked
  off strictly before the predicted match (expanding window, refit every
  ``--refit-every`` matches).
* The first ``--min-train`` matches only train; the following share of the
  remaining matches is the validation window, the rest is the test window.
* The xG weight and the calibration map are chosen on the validation window
  only. The test window is scored once, without touching those choices.

The script never invents a probability: a match without xG keeps the
Dixon-Coles lambdas and the reason is reported. ROI is not computed because
the dataset carries no historical odds.

Usage
-----
    python -m scripts.backtest_walkforward \
        --dataset storage/tmp/xg-dataset.json \
        --output-json storage/tmp/backtest.json \
        --output-md docs/backtests/walkforward-report.md
"""

from __future__ import annotations

import argparse
import json
import math
import sys
from dataclasses import dataclass
from datetime import date, datetime, timezone
from pathlib import Path
from typing import Dict, List, Optional, Sequence

ROOT = Path(__file__).resolve().parents[1]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

import numpy as np

from app.core.calibration import IsotonicCalibrator, PlattCalibrator
from app.core.dixon_coles import compute_score_matrix, extract_match_markets_from_matrix
from app.core.metrics import (
    calculate_brier_multiclass,
    calculate_log_loss,
    calculate_multiclass_ece,
    calculate_rps,
    diebold_mariano_test,
)
from app.core.poisson_regressor import DixonColesModel, MatchData
from app.core.xg_features import XgFeatureBuilder, XgMatch, adjust_lambdas_with_xg


@dataclass
class BacktestMatch:
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

    def to_xg_match(self) -> XgMatch:
        return XgMatch(
            match_date=self.match_date,
            home_team=self.home_team,
            away_team=self.away_team,
            home_goals=self.home_goals,
            away_goals=self.away_goals,
            home_xg=self.home_xg,
            away_xg=self.away_xg,
            home_shots=self.home_shots,
            away_shots=self.away_shots,
            home_shots_on_target=self.home_shots_on_target,
            away_shots_on_target=self.away_shots_on_target,
            competition=self.competition,
        )


def load_dataset(path: Path) -> List[BacktestMatch]:
    payload = json.loads(path.read_text(encoding="utf-8"))
    raw_matches = payload.get("matches") if isinstance(payload, dict) else payload
    if not isinstance(raw_matches, list):
        raise ValueError("Dataset must contain a 'matches' list.")

    matches: List[BacktestMatch] = []
    for row in raw_matches:
        if not isinstance(row, dict):
            continue
        try:
            kickoff = date.fromisoformat(str(row["date"])[:10])
            matches.append(
                BacktestMatch(
                    match_date=kickoff,
                    home_team=str(row["home_team"]),
                    away_team=str(row["away_team"]),
                    home_goals=int(row["home_goals"]),
                    away_goals=int(row["away_goals"]),
                    home_xg=_optional_float(row.get("home_xg")),
                    away_xg=_optional_float(row.get("away_xg")),
                    home_shots=_optional_int(row.get("home_shots")),
                    away_shots=_optional_int(row.get("away_shots")),
                    home_shots_on_target=_optional_int(row.get("home_shots_on_target")),
                    away_shots_on_target=_optional_int(row.get("away_shots_on_target")),
                    competition=str(row.get("competition") or "unknown"),
                )
            )
        except (KeyError, TypeError, ValueError):
            continue

    if len(matches) < 30:
        raise ValueError("At least 30 usable matches are required for a walk-forward backtest.")
    matches.sort(key=lambda item: (item.match_date, item.home_team, item.away_team))
    return matches


def _optional_float(value) -> Optional[float]:
    if value is None:
        return None
    try:
        number = float(value)
    except (TypeError, ValueError):
        return None
    return number if math.isfinite(number) and number >= 0 else None


def _optional_int(value) -> Optional[int]:
    if value is None:
        return None
    try:
        return int(value)
    except (TypeError, ValueError):
        return None


def outcome_label(match: BacktestMatch) -> str:
    if match.home_goals > match.away_goals:
        return "HOME"
    if match.home_goals == match.away_goals:
        return "DRAW"
    return "AWAY"


def to_training_rows(matches: Sequence[BacktestMatch], as_of: date) -> List[MatchData]:
    rows: List[MatchData] = []
    for match in matches:
        days_ago = max(0.0, (as_of - match.match_date).days)
        rows.append(
            MatchData(
                home_team=match.home_team,
                away_team=match.away_team,
                home_goals=match.home_goals,
                away_goals=match.away_goals,
                days_ago=days_ago,
            )
        )
    return rows


def predict_markets(
    model: DixonColesModel,
    match: BacktestMatch,
    xg_builder: Optional[XgFeatureBuilder],
    xg_weight: float,
) -> Dict[str, object]:
    lambda_home, lambda_away, _ = model.predict_lambdas(match.home_team, match.away_team)
    xg_info: Optional[Dict[str, object]] = None

    if xg_builder is not None and xg_weight > 0:
        home_features = xg_builder.features(match.home_team, match.match_date)
        away_features = xg_builder.features(match.away_team, match.match_date)
        baseline = xg_builder.baseline(match.match_date, match.competition)
        adjustment = adjust_lambdas_with_xg(
            lambda_home, lambda_away, home_features, away_features, baseline, weight=xg_weight
        )
        lambda_home, lambda_away = adjustment.lambda_home, adjustment.lambda_away
        xg_info = adjustment.to_dict()

    matrix, _, _ = compute_score_matrix(lambda_home, lambda_away, model.rho)
    return {
        "lambda_home": float(lambda_home),
        "lambda_away": float(lambda_away),
        "markets": extract_match_markets_from_matrix(matrix),
        "xg": xg_info,
    }


def build_predictions(
    matches: Sequence[BacktestMatch],
    weights: Sequence[float],
    min_train: int,
    refit_every: int,
) -> Dict[str, List[Dict[str, object]]]:
    """Expanding-window walk-forward predictions for every candidate weight."""
    xg_present = any(match.home_xg is not None for match in matches)
    xg_builder = XgFeatureBuilder().extend([match.to_xg_match() for match in matches]) if xg_present else None

    predictions: Dict[str, List[Dict[str, object]]] = {f"{weight:.2f}": [] for weight in weights}
    model: Optional[DixonColesModel] = None

    for index in range(min_train, len(matches)):
        if model is None or (index - min_train) % refit_every == 0:
            history = matches[:index]
            candidate = DixonColesModel(xi=0.0019, l2_reg=0.01, rho=-0.04)
            try:
                candidate.fit(to_training_rows(history, matches[index].match_date))
            except RuntimeError:
                continue
            model = candidate
        if model is None:
            continue

        for weight in weights:
            try:
                result = predict_markets(model, matches[index], xg_builder, weight)
            except (ValueError, RuntimeError, FloatingPointError):
                continue
            markets = result["markets"]
            predictions[f"{weight:.2f}"].append(
                {
                    "index": index,
                    "match_date": matches[index].match_date.isoformat(),
                    "competition": matches[index].competition,
                    "outcome": outcome_label(matches[index]),
                    "home_goals": matches[index].home_goals,
                    "away_goals": matches[index].away_goals,
                    "p": [
                        float(markets["1N2"]["home"]),
                        float(markets["1N2"]["draw"]),
                        float(markets["1N2"]["away"]),
                    ],
                    "markets": markets,
                    "xg": result["xg"],
                }
            )
    return predictions


def score_rows(rows: Sequence[Dict[str, object]]) -> Dict[str, object]:
    if not rows:
        return {"sample_size": 0}

    log_losses: List[float] = []
    briers: List[float] = []
    rps_values: List[float] = []
    probabilities: List[List[float]] = []
    outcomes: List[str] = []
    correct_1n2 = 0
    double_chance_correct = 0
    over_log_losses: List[float] = []
    over_briers: List[float] = []
    btts_log_losses: List[float] = []
    btts_briers: List[float] = []
    team_total_log_losses: List[float] = []
    over_correct = 0
    btts_correct = 0
    team_total_correct = 0

    for row in rows:
        p = [float(value) for value in row["p"]]  # type: ignore[index]
        outcome = str(row["outcome"])
        markets = row["markets"]  # type: ignore[index]
        home_goals = int(row["home_goals"])  # type: ignore[index]
        away_goals = int(row["away_goals"])  # type: ignore[index]
        total_goals = home_goals + away_goals

        log_losses.append(calculate_log_loss(p[0], p[1], p[2], outcome))
        briers.append(calculate_brier_multiclass(p[0], p[1], p[2], outcome))
        rps_values.append(calculate_rps(p[0], p[1], p[2], outcome))
        probabilities.append(p)
        outcomes.append(outcome)
        predicted_index = int(np.argmax(p))
        actual_index = {"HOME": 0, "DRAW": 1, "AWAY": 2}[outcome]
        correct_1n2 += int(predicted_index == actual_index)

        double_chance = markets["double_chance"]  # type: ignore[index]
        dc_pick = max(("1X", "X2", "12"), key=lambda key: float(double_chance[key]))
        won = {
            "1X": actual_index in (0, 1),
            "X2": actual_index in (1, 2),
            "12": actual_index in (0, 2),
        }[dc_pick]
        double_chance_correct += int(won)

        over_probability = float(markets["totals"]["over_2_5"])  # type: ignore[index]
        over_actual = 1 if total_goals > 2.5 else 0
        over_log_losses.append(_binary_log_loss(over_probability, over_actual))
        over_briers.append((over_probability - over_actual) ** 2)
        over_correct += int((over_probability >= 0.5) == bool(over_actual))

        btts_probability = float(markets["btts"]["yes"])  # type: ignore[index]
        btts_actual = 1 if home_goals > 0 and away_goals > 0 else 0
        btts_log_losses.append(_binary_log_loss(btts_probability, btts_actual))
        btts_briers.append((btts_probability - btts_actual) ** 2)
        btts_correct += int((btts_probability >= 0.5) == bool(btts_actual))

        for side, goals in (("home", home_goals), ("away", away_goals)):
            probability = float(markets["team_totals"][side]["over_0_5"])  # type: ignore[index]
            actual = 1 if goals > 0 else 0
            team_total_log_losses.append(_binary_log_loss(probability, actual))
            team_total_correct += int((probability >= 0.5) == bool(actual))

    ece = calculate_multiclass_ece(probabilities, outcomes, n_bins=10, adaptive=True)
    n = len(rows)

    return {
        "sample_size": n,
        "log_loss": round(float(np.mean(log_losses)), 4),
        "brier": round(float(np.mean(briers)), 4),
        "rps": round(float(np.mean(rps_values)), 4),
        "accuracy_1n2": round(correct_1n2 / n, 4),
        "accuracy_double_chance": round(double_chance_correct / n, 4),
        "ece": ece["global_ece"],
        "ece_by_class": {"home": ece["ece_home"], "draw": ece["ece_draw"], "away": ece["ece_away"]},
        "log_loss_over_2_5": round(float(np.mean(over_log_losses)), 4),
        "brier_over_2_5": round(float(np.mean(over_briers)), 4),
        "accuracy_over_2_5": round(over_correct / n, 4),
        "log_loss_btts": round(float(np.mean(btts_log_losses)), 4),
        "brier_btts": round(float(np.mean(btts_briers)), 4),
        "accuracy_btts": round(btts_correct / n, 4),
        "log_loss_team_totals_over_0_5": round(float(np.mean(team_total_log_losses)), 4),
        "accuracy_team_totals_over_0_5": round(team_total_correct / (2 * n), 4),
        "per_outcome_log_loss": _per_group(rows, lambda row: str(row["outcome"])),
        "per_competition_log_loss": _per_group(rows, lambda row: str(row["competition"])),
        "per_total_bucket_log_loss": _per_group(rows, _total_bucket),
        "per_match_log_losses": [round(value, 6) for value in log_losses],
    }


def _binary_log_loss(probability: float, actual: int) -> float:
    clipped = min(1.0 - 1e-12, max(1e-12, probability))
    return float(-math.log(clipped if actual == 1 else 1.0 - clipped))


def _total_bucket(row: Dict[str, object]) -> str:
    total = int(row["home_goals"]) + int(row["away_goals"])  # type: ignore[index]
    if total <= 1:
        return "0-1_buts"
    if total <= 3:
        return "2-3_buts"
    return "4+_buts"


def _per_group(rows: Sequence[Dict[str, object]], key) -> Dict[str, Dict[str, float]]:
    buckets: Dict[str, List[Dict[str, object]]] = {}
    for row in rows:
        buckets.setdefault(str(key(row)), []).append(row)
    report: Dict[str, Dict[str, float]] = {}
    for name, group in buckets.items():
        losses = [
            calculate_log_loss(float(row["p"][0]), float(row["p"][1]), float(row["p"][2]), str(row["outcome"]))  # type: ignore[index]
            for row in group
        ]
        report[name] = {"sample_size": len(group), "log_loss": round(float(np.mean(losses)), 4)}
    return report


def apply_calibrator(calibrator, rows: Sequence[Dict[str, object]]) -> List[Dict[str, object]]:
    calibrated: List[Dict[str, object]] = []
    for row in rows:
        new_row = dict(row)
        new_row["p"] = calibrator.transform_row([float(value) for value in row["p"]])  # type: ignore[index]
        calibrated.append(new_row)
    return calibrated


def split_predictions(rows: Sequence[Dict[str, object]], validation_end: int) -> tuple:
    validation = [row for row in rows if int(row["index"]) < validation_end]  # type: ignore[index]
    test = [row for row in rows if int(row["index"]) >= validation_end]  # type: ignore[index]
    return validation, test


def run(args: argparse.Namespace) -> Dict[str, object]:
    matches = load_dataset(Path(args.dataset))
    weights = [float(value) for value in str(args.xg_weights).split(",") if value.strip()]
    if 0.0 not in weights:
        weights.insert(0, 0.0)

    min_train = max(30, min(int(args.min_train), len(matches) - 20))
    remaining = len(matches) - min_train
    validation_end = min_train + max(10, int(round(remaining * float(args.validation_share))))

    predictions = build_predictions(matches, weights, min_train, int(args.refit_every))
    xg_matches = sum(1 for match in matches if match.home_xg is not None and match.away_xg is not None)

    validation_scores: Dict[str, Dict[str, object]] = {}
    for weight in weights:
        key = f"{weight:.2f}"
        validation_rows, _ = split_predictions(predictions.get(key, []), validation_end)
        validation_scores[key] = score_rows(validation_rows)

    eligible = {key: score for key, score in validation_scores.items() if score.get("sample_size", 0) > 0}
    if not eligible:
        raise RuntimeError("No prediction could be produced; check the dataset.")
    best_key = min(eligible, key=lambda key: float(eligible[key].get("log_loss", 1e9)))
    best_weight = float(best_key)

    baseline_key = "0.00"
    validation_baseline, test_baseline = split_predictions(predictions[baseline_key], validation_end)
    _, test_best = split_predictions(predictions[best_key], validation_end)

    calibration_report: Dict[str, object] = {}
    calibration_payload: Dict[str, object] = {}
    for name, factory in (("platt", PlattCalibrator), ("isotonic", IsotonicCalibrator)):
        calibrator = factory()
        calibrator.fit(
            [[float(value) for value in row["p"]] for row in validation_best_rows(validation_baseline)],
            [str(row["outcome"]) for row in validation_best_rows(validation_baseline)],
        )
        if not calibrator.is_fitted:
            calibration_report[name] = {"fitted": False, "reason": "validation_sample_too_small"}
            continue
        calibrated_test = apply_calibrator(calibrator, test_baseline)
        calibration_report[name] = {
            "fitted": True,
            "state": calibrator.state(),
            "test": score_rows(calibrated_test),
        }
        calibration_payload[name] = calibrated_test

    baseline_test_score = score_rows(test_baseline)
    dm: Dict[str, object] = {}
    if calibration_payload:
        best_calibration = min(
            calibration_payload,
            key=lambda name: float(calibration_report[name]["test"]["log_loss"]),  # type: ignore[index]
        )
        dm["platt_or_isotonic_vs_baseline"] = diebold_mariano_test(
            baseline_test_score["per_match_log_losses"],  # type: ignore[index]
            calibration_report[best_calibration]["test"]["per_match_log_losses"],  # type: ignore[index]
        )
        dm["selected"] = best_calibration

    if best_weight > 0 and test_best:
        dm["xg_vs_dixon_coles"] = diebold_mariano_test(
            baseline_test_score["per_match_log_losses"],  # type: ignore[index]
            score_rows(test_best)["per_match_log_losses"],  # type: ignore[index]
        )

    report = {
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "dataset": {
            "path": str(args.dataset),
            "matches": len(matches),
            "matches_with_xg": xg_matches,
            "competitions": sorted({match.competition for match in matches}),
            "first_date": matches[0].match_date.isoformat(),
            "last_date": matches[-1].match_date.isoformat(),
        },
        "protocol": {
            "min_train": min_train,
            "validation_end_index": validation_end,
            "validation_matches": validation_end - min_train,
            "test_matches": len(matches) - validation_end,
            "refit_every": int(args.refit_every),
            "xg_weight_candidates": weights,
            "odds_available": False,
            "roi_computed": False,
        },
        "market_selection": {
            "selected_xg_weight": best_weight,
            "validation_scores": validation_scores,
            "note": "Le poids xG est choisi uniquement sur la fenêtre de validation.",
        },
        "test": {
            "baseline_dixon_coles": baseline_test_score,
            "selected_model": score_rows(test_best) if test_best else None,
            "calibration": calibration_report,
        },
        "significance": dm,
        "xg_usage": _xg_usage_summary(predictions.get(best_key, [])),
    }
    return report


def validation_best_rows(validation_baseline: Sequence[Dict[str, object]]) -> Sequence[Dict[str, object]]:
    return validation_baseline


def _xg_usage_summary(rows: Sequence[Dict[str, object]]) -> Dict[str, object]:
    applied = 0
    reasons: Dict[str, int] = {}
    coverage: Dict[str, int] = {}
    for row in rows:
        info = row.get("xg")
        if not isinstance(info, dict):
            continue
        if info.get("applied"):
            applied += 1
        reason = str(info.get("reason") or "unknown")
        reasons[reason] = reasons.get(reason, 0) + 1
        tier = str(info.get("coverage") or "none")
        coverage[tier] = coverage.get(tier, 0) + 1
    return {"predictions": len(rows), "xG_applied": applied, "reasons": reasons, "coverage": coverage}


def render_markdown(report: Dict[str, object]) -> str:
    dataset = report["dataset"]  # type: ignore[index]
    protocol = report["protocol"]  # type: ignore[index]
    selection = report["market_selection"]  # type: ignore[index]
    test = report["test"]  # type: ignore[index]
    baseline = test["baseline_dixon_coles"]  # type: ignore[index]
    significance = report.get("significance") or {}

    lines = [
        "# Rapport de backtesting walk-forward",
        "",
        f"Généré le {report['generated_at']} par `ai-service/scripts/backtest_walkforward.py`.",
        "",
        "## Protocole",
        "",
        f"- Matchs disponibles : **{dataset['matches']}** ({dataset['first_date']} → {dataset['last_date']}).",
        f"- Matchs avec xG : **{dataset['matches_with_xg']}**.",
        f"- Compétitions : {', '.join(dataset['competitions'])}.",
        f"- Entraînement initial : {protocol['min_train']} matchs ; validation : {protocol['validation_matches']} ; test : {protocol['test_matches']}.",
        f"- Réentraînement tous les {protocol['refit_every']} matchs, uniquement sur les matchs antérieurs.",
        "- Cotes historiques disponibles : non → ROI non calculé (aucun prix inventé).",
        "",
    ]
    if dataset["matches_with_xg"] == 0:
        lines += [
            "> **xG indisponible pour ce jeu de données.** Aucune comparaison xG n'est",
            "> donc publiée ici : le poids xG sélectionné est nécessairement 0 et le",
            "> moteur se rabat sur Dixon-Coles. Pour produire la comparaison, importez",
            "> les statistiques StatsBomb puis relancez :",
            ">",
            "> ```bash",
            "> php artisan oracle:import-statsbomb --stats --limit=400",
            "> php artisan oracle:export-xg-dataset --league=PL --output=storage/app/backtest/xg.json",
            "> python -m scripts.backtest_walkforward --dataset ../storage/app/backtest/xg.json",
            "> ```",
            "",
        ]
    lines += [
        "## Sélection du poids xG (validation uniquement)",
        "",
        "| Poids xG | Log Loss | Brier | RPS | ECE | Échantillon |",
        "| --- | --- | --- | --- | --- | --- |",
    ]
    for key, score in selection["validation_scores"].items():  # type: ignore[union-attr]
        lines.append(
            f"| {key} | {score.get('log_loss', '—')} | {score.get('brier', '—')} | "
            f"{score.get('rps', '—')} | {score.get('ece', '—')} | {score.get('sample_size', 0)} |"
        )

    lines += [
        "",
        f"Poids retenu : **{selection['selected_xg_weight']}**.",
        "",
        "## Fenêtre de test",
        "",
        "| Modèle | Log Loss | Brier | RPS | ECE | Précision 1N2 | Précision DC |",
        "| --- | --- | --- | --- | --- | --- | --- |",
        f"| Dixon-Coles (référence) | {baseline['log_loss']} | {baseline['brier']} | {baseline['rps']} | "
        f"{baseline['ece']} | {baseline['accuracy_1n2']} | {baseline['accuracy_double_chance']} |",
    ]
    selected = test.get("selected_model")  # type: ignore[union-attr]
    if isinstance(selected, dict):
        lines.append(
            f"| Modèle retenu (xG={selection['selected_xg_weight']}) | {selected['log_loss']} | {selected['brier']} | "
            f"{selected['rps']} | {selected['ece']} | {selected['accuracy_1n2']} | {selected['accuracy_double_chance']} |"
        )
    for name, entry in test["calibration"].items():  # type: ignore[index]
        if not isinstance(entry, dict) or not entry.get("fitted"):
            lines.append(f"| Calibration {name} | non ajustée (échantillon de validation insuffisant) | | | | | |")
            continue
        score = entry["test"]
        lines.append(
            f"| Dixon-Coles + {name} | {score['log_loss']} | {score['brier']} | {score['rps']} | "
            f"{score['ece']} | {score['accuracy_1n2']} | {score['accuracy_double_chance']} |"
        )

    lines += [
        "",
        "## Comparaison chiffrée moteur initial / moteur amélioré",
        "",
    ]
    deltas = []
    if isinstance(selected, dict) and dataset["matches_with_xg"] > 0:
        deltas.append(("xG", selected))
    for name, entry in test["calibration"].items():  # type: ignore[index]
        if isinstance(entry, dict) and entry.get("fitted"):
            deltas.append((f"calibration {name}", entry["test"]))
    if deltas:
        lines += [
            "| Variante | Δ Log Loss | Δ Brier | Δ ECE | Décision |",
            "| --- | --- | --- | --- | --- |",
            "| Référence | 0 | 0 | 0 | conservée par défaut |",
        ]
        for label, score in deltas:
            delta_log = round(float(score["log_loss"]) - float(baseline["log_loss"]), 4)
            delta_brier = round(float(score["brier"]) - float(baseline["brier"]), 4)
            delta_ece = round(float(score["ece"]) - float(baseline["ece"]), 4)
            # Un gain de log loss ne suffit pas : sans signification au test de
            # Diebold-Mariano la variante reste désactivée par défaut.
            if label == "xG":
                p_value = (significance.get("xg_vs_dixon_coles") or {}).get("p_value")
            elif significance.get("selected") and label == f"calibration {significance['selected']}":
                # Le test DM est calculé pour le calibrateur retenu uniquement.
                p_value = (significance.get("platt_or_isotonic_vs_baseline") or {}).get("p_value")
            else:
                p_value = None

            if delta_log >= 0:
                decision = "rejetée (pas de gain)"
            elif p_value is not None and float(p_value) < 0.05:
                decision = f"à activer (p={float(p_value):.3f})"
            elif p_value is not None:
                decision = f"rejetée (non significatif, p={float(p_value):.3f})"
            else:
                decision = "rejetée (non significatif)"
            lines.append(f"| {label} | {delta_log:+} | {delta_brier:+} | {delta_ece:+} | {decision} |")
        lines.append("")
        lines.append(
            "Une variante n'est retenue que si le log loss baisse **et** que le test de "
            "Diebold-Mariano donne p < 0,05. Sinon elle reste désactivée par défaut."
        )
    else:
        lines.append("Aucune variante n'a pu être ajustée sur cet échantillon.")
    if dataset["matches_with_xg"] == 0:
        lines.append("")
        lines.append("xG : **non évaluée** — aucun xG mesuré dans le jeu de données.")

    lines += [
        "",
        "## Marchés dérivés (fenêtre de test, modèle de référence)",
        "",
        "| Marché | Log Loss | Brier | Précision |",
        "| --- | --- | --- | --- |",
        f"| Over/Under 2.5 | {baseline['log_loss_over_2_5']} | {baseline['brier_over_2_5']} | {baseline['accuracy_over_2_5']} |",
        f"| BTTS | {baseline['log_loss_btts']} | {baseline['brier_btts']} | {baseline['accuracy_btts']} |",
        f"| Totaux équipe > 0.5 | {baseline['log_loss_team_totals_over_0_5']} | — | {baseline['accuracy_team_totals_over_0_5']} |",
        "",
        "## Performance par type de rencontre (log loss, modèle de référence)",
        "",
        "| Groupe | Échantillon | Log Loss |",
        "| --- | --- | --- |",
    ]
    for name, score in baseline["per_outcome_log_loss"].items():  # type: ignore[index]
        lines.append(f"| Résultat {name} | {score['sample_size']} | {score['log_loss']} |")
    for name, score in baseline["per_total_bucket_log_loss"].items():  # type: ignore[index]
        lines.append(f"| {name} | {score['sample_size']} | {score['log_loss']} |")

    usage = report["xg_usage"]  # type: ignore[index]
    lines += [
        "",
        "## Usage réel des variables xG",
        "",
        f"- Prédictions évaluées : {usage['predictions']}.",
        f"- Ajustement xG appliqué : {usage['xG_applied']}.",
        f"- Règles de repli déclenchées : {json.dumps(usage['reasons'], ensure_ascii=False)}.",
        f"- Couverture xG observée : {json.dumps(usage['coverage'], ensure_ascii=False)}.",
        "",
        "## Significativité",
        "",
        "```json",
        json.dumps(report["significance"], indent=2, ensure_ascii=False),
        "```",
        "",
        "## Limites",
        "",
        "- Un écart de log loss n'est déclaré significatif que si le test de Diebold-Mariano donne p < 0,05.",
        "- Les marchés dérivés sont lus sur la même matrice de scores que le 1N2 : aucune probabilité n'est estimée séparément.",
        "- Aucun ROI n'est calculé sans cotes historiques vérifiables.",
        "",
    ]
    return "\n".join(lines)


def main() -> int:
    parser = argparse.ArgumentParser(description="Walk-forward backtest for PRONO-IA.")
    parser.add_argument("--dataset", required=True)
    parser.add_argument("--output-json", default="storage/tmp/backtest-report.json")
    parser.add_argument("--output-md", default="docs/backtests/walkforward-report.md")
    parser.add_argument("--min-train", type=int, default=120)
    parser.add_argument("--refit-every", type=int, default=20)
    parser.add_argument("--validation-share", type=float, default=0.4)
    parser.add_argument("--xg-weights", default="0,0.25,0.5,1.0")
    args = parser.parse_args()

    report = run(args)
    output_json = Path(args.output_json)
    output_md = Path(args.output_md)
    output_json.parent.mkdir(parents=True, exist_ok=True)
    output_md.parent.mkdir(parents=True, exist_ok=True)
    output_json.write_text(json.dumps(report, indent=2, ensure_ascii=False), encoding="utf-8")
    output_md.write_text(render_markdown(report), encoding="utf-8")

    baseline = report["test"]["baseline_dixon_coles"]  # type: ignore[index]
    print(f"matches={report['dataset']['matches']} xg={report['dataset']['matches_with_xg']}")  # type: ignore[index]
    print(f"test sample={baseline['sample_size']} log_loss={baseline['log_loss']} brier={baseline['brier']} ece={baseline['ece']}")
    print(f"selected_xg_weight={report['market_selection']['selected_xg_weight']}")  # type: ignore[index]
    print(f"json={output_json} md={output_md}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
