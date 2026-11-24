"""Évalue le cœur Dixon-Coles de production sur le dataset xgabora/Club-Football-Match-Data.

Périmètre : le moteur statistique Dixon-Coles. Le prédicteur `/v1/predict`
ajoute ensuite un blend Elo/forme et, si l'appelant les fournit, les cotes du
marché. Ce blend n'est pas inclus ici : injecter les cotes dans le modèle
rendrait la comparaison au marché circulaire.

Protocole
---------
* Un modèle par division, avec exactement les hyperparamètres de production
  (``xi=0.0019``, ``l2_reg=0.01``, ``rho=-0.04``).
* Les rencontres sont triées chronologiquement. Chaque bloc de test est prédit
  par un modèle réentraîné uniquement sur les rencontres dont la date est
  strictement antérieure au début du bloc (fenêtre expansive).
* Aucune cote n'entre dans les features du modèle. Les cotes Bet365 1X2 sont
  utilisées uniquement comme baseline de marché, après retrait de marge.
* Les métriques sont calculées sur les mêmes rencontres pour le modèle et pour
  le marché : log loss, Brier multiclasse, RPS, ECE et précision 1N2.
* Le gain est testé par Diebold-Mariano et par bootstrap apparié.

Usage
-----
    python -m scripts.evaluate_xgabora_market \
        --dataset data/external/Matches.csv \
        --output-json data/external/xgabora-evaluation.json \
        --output-md ../docs/backtests/xgabora-market-evaluation.md
"""

from __future__ import annotations

import argparse
import json
import math
import sys
from dataclasses import dataclass
from datetime import date
from pathlib import Path
from typing import Dict, Iterable, List, Optional, Sequence, Tuple

ROOT = Path(__file__).resolve().parents[1]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

import numpy as np
import pandas as pd

from app.core.devig import devig_odds
from app.core.dixon_coles import compute_score_matrix, extract_match_markets_from_matrix
from app.core.metrics import (
    calculate_brier_multiclass,
    calculate_log_loss,
    calculate_multiclass_ece,
    calculate_rps,
    diebold_mariano_test,
)
from app.core.poisson_regressor import DixonColesModel, MatchData

DEFAULT_DIVISIONS = "E0,D1,SP1,I1,F1"
OUTCOMES = ("HOME", "DRAW", "AWAY")
OUTCOME_INDEX = {"HOME": 0, "DRAW": 1, "AWAY": 2}


@dataclass(frozen=True)
class Match:
    match_date: date
    division: str
    home_team: str
    away_team: str
    home_goals: int
    away_goals: int
    odd_home: float
    odd_draw: float
    odd_away: float

    @property
    def outcome(self) -> str:
        if self.home_goals > self.away_goals:
            return "HOME"
        if self.home_goals == self.away_goals:
            return "DRAW"
        return "AWAY"

    @property
    def overround(self) -> float:
        return 1.0 / self.odd_home + 1.0 / self.odd_draw + 1.0 / self.odd_away - 1.0


def load_matches(
    path: Path,
    divisions: Sequence[str],
    start_date: date,
) -> Tuple[List[Match], Dict[str, int]]:
    """Charge et nettoie les rencontres, sans imputer de cote ni de score."""
    frame = pd.read_csv(
        path,
        usecols=[
            "Division",
            "MatchDate",
            "HomeTeam",
            "AwayTeam",
            "FTHome",
            "FTAway",
            "OddHome",
            "OddDraw",
            "OddAway",
        ],
        low_memory=False,
    )
    counters = {"rows_read": int(len(frame))}
    frame["MatchDate"] = pd.to_datetime(frame["MatchDate"], errors="coerce")
    frame["Division"] = frame["Division"].astype(str).str.strip()
    frame["HomeTeam"] = frame["HomeTeam"].astype(str).str.strip()
    frame["AwayTeam"] = frame["AwayTeam"].astype(str).str.strip()
    for column in ("FTHome", "FTAway", "OddHome", "OddDraw", "OddAway"):
        frame[column] = pd.to_numeric(frame[column], errors="coerce")

    frame = frame.dropna(
        subset=["MatchDate", "HomeTeam", "AwayTeam", "FTHome", "FTAway", "OddHome", "OddDraw", "OddAway"]
    )
    counters["rows_after_required_fields"] = int(len(frame))

    frame = frame[frame["Division"].isin(set(divisions))]
    counters["rows_in_selected_divisions"] = int(len(frame))

    frame = frame[frame["MatchDate"].dt.date >= start_date]
    counters["rows_after_start_date"] = int(len(frame))

    frame = frame[
        (frame["FTHome"] >= 0)
        & (frame["FTAway"] >= 0)
        & (frame["FTHome"] <= 20)
        & (frame["FTAway"] <= 20)
        & (frame["OddHome"] > 1.01)
        & (frame["OddDraw"] > 1.01)
        & (frame["OddAway"] > 1.01)
        & (frame["OddHome"] < 100.0)
        & (frame["OddDraw"] < 100.0)
        & (frame["OddAway"] < 100.0)
    ]
    counters["rows_after_valid_ranges"] = int(len(frame))

    frame = frame.drop_duplicates(subset=["MatchDate", "Division", "HomeTeam", "AwayTeam"])
    counters["rows_after_deduplication"] = int(len(frame))

    frame = frame.sort_values(["MatchDate", "Division", "HomeTeam", "AwayTeam"]).reset_index(drop=True)
    matches = [
        Match(
            match_date=row.MatchDate.date(),
            division=str(row.Division),
            home_team=str(row.HomeTeam),
            away_team=str(row.AwayTeam),
            home_goals=int(row.FTHome),
            away_goals=int(row.FTAway),
            odd_home=float(row.OddHome),
            odd_draw=float(row.OddDraw),
            odd_away=float(row.OddAway),
        )
        for row in frame.itertuples(index=False)
    ]
    return matches, counters


def training_rows(matches: Iterable[Match], as_of: date) -> List[MatchData]:
    return [
        MatchData(
            home_team=match.home_team,
            away_team=match.away_team,
            home_goals=match.home_goals,
            away_goals=match.away_goals,
            days_ago=float(max(0, (as_of - match.match_date).days)),
        )
        for match in matches
    ]


def predict_model(model: DixonColesModel, match: Match) -> Tuple[np.ndarray, List[str]]:
    lambda_home, lambda_away, warnings = model.predict_lambdas(
        match.home_team, match.away_team, is_neutral=False
    )
    matrix, _, _ = compute_score_matrix(lambda_home, lambda_away, model.rho)
    probabilities = extract_match_markets_from_matrix(matrix)["1N2"]
    row = np.array(
        [float(probabilities["home"]), float(probabilities["draw"]), float(probabilities["away"])],
        dtype=float,
    )
    row = np.clip(row, 1e-12, 1.0)
    return row / float(row.sum()), warnings


def predict_market(match: Match, method: str) -> Tuple[np.ndarray, float]:
    result = devig_odds([match.odd_home, match.odd_draw, match.odd_away], method=method)
    probabilities = np.asarray(result["true_probabilities"], dtype=float)
    probabilities = np.clip(probabilities, 1e-12, 1.0)
    return probabilities / float(probabilities.sum()), float(result["overround"])


def score(probabilities: Sequence[Sequence[float]], outcomes: Sequence[str]) -> Dict[str, object]:
    if not probabilities:
        return {"sample_size": 0}

    matrix = np.asarray(probabilities, dtype=float)
    log_losses = [
        calculate_log_loss(float(row[0]), float(row[1]), float(row[2]), outcome)
        for row, outcome in zip(matrix, outcomes)
    ]
    briers = [
        calculate_brier_multiclass(float(row[0]), float(row[1]), float(row[2]), outcome)
        for row, outcome in zip(matrix, outcomes)
    ]
    rps_values = [
        calculate_rps(float(row[0]), float(row[1]), float(row[2]), outcome)
        for row, outcome in zip(matrix, outcomes)
    ]
    actual_indices = np.asarray([OUTCOME_INDEX[outcome] for outcome in outcomes], dtype=int)
    predicted_indices = np.argmax(matrix, axis=1)
    ece = calculate_multiclass_ece(matrix.tolist(), outcomes, n_bins=10, adaptive=True)

    return {
        "sample_size": len(outcomes),
        "log_loss": round(float(np.mean(log_losses)), 6),
        "brier": round(float(np.mean(briers)), 6),
        "rps": round(float(np.mean(rps_values)), 6),
        "ece": ece["global_ece"],
        "accuracy_1n2": round(float(np.mean(predicted_indices == actual_indices)), 6),
        "per_match_log_losses": [round(float(value), 8) for value in log_losses],
    }


def bootstrap_mean_ci(values: Sequence[float], seed: int, replicates: int = 2000) -> Dict[str, float]:
    array = np.asarray(values, dtype=float)
    if len(array) == 0:
        return {"mean": 0.0, "ci95_low": 0.0, "ci95_high": 0.0}
    rng = np.random.default_rng(seed)
    indices = rng.integers(0, len(array), size=(replicates, len(array)))
    means = array[indices].mean(axis=1)
    return {
        "mean": round(float(array.mean()), 6),
        "ci95_low": round(float(np.quantile(means, 0.025)), 6),
        "ci95_high": round(float(np.quantile(means, 0.975)), 6),
    }


def run(args: argparse.Namespace) -> Dict[str, object]:
    divisions = [value.strip() for value in str(args.divisions).split(",") if value.strip()]
    matches, counters = load_matches(Path(args.dataset), divisions, date.fromisoformat(args.start_date))
    if not matches:
        raise RuntimeError("Aucune rencontre exploitable après nettoyage.")

    test_start = date.fromisoformat(args.test_start)
    by_division: Dict[str, List[Match]] = {}
    for match in matches:
        by_division.setdefault(match.division, []).append(match)

    records: List[Dict[str, object]] = []
    fit_count = 0
    fit_failures = 0
    skipped_divisions: Dict[str, str] = {}
    low_sample_predictions = 0
    unknown_team_predictions = 0

    for division in divisions:
        division_matches = sorted(by_division.get(division, []), key=lambda item: item.match_date)
        if not division_matches:
            skipped_divisions[division] = "no_matches"
            continue
        training_pool = [match for match in division_matches if match.match_date < test_start]
        test_matches = [match for match in division_matches if match.match_date >= test_start]
        if len(training_pool) < int(args.min_train):
            skipped_divisions[division] = f"insufficient_training:{len(training_pool)}"
            continue
        if not test_matches:
            skipped_divisions[division] = "no_test_matches"
            continue

        model: Optional[DixonColesModel] = None
        for block_start in range(0, len(test_matches), int(args.refit_every)):
            block = test_matches[block_start : block_start + int(args.refit_every)]
            block_date = block[0].match_date
            # Strictement antérieur au bloc : aucune rencontre du même jour
            # ne peut entrer dans l'entraînement de ce bloc.
            history = [match for match in division_matches if match.match_date < block_date]
            if len(history) < int(args.min_train):
                continue

            candidate = DixonColesModel(xi=0.0019, l2_reg=0.01, rho=-0.04)
            try:
                candidate.fit(training_rows(history, block_date))
                model = candidate
                fit_count += 1
            except (RuntimeError, ValueError, FloatingPointError):
                fit_failures += 1
                # On conserve le dernier modèle valide plutôt que d'inventer
                # une probabilité ou de supprimer silencieusement le bloc.
                if model is None:
                    continue

            assert model is not None
            for match in block:
                model_probabilities, warnings = predict_model(model, match)
                market_probabilities, overround = predict_market(match, str(args.devig))
                low_sample = any(
                    warning.startswith("low_sample_team:") or warning.startswith("unknown_or_promoted_team:")
                    for warning in warnings
                )
                unknown_team = any(warning.startswith("unknown_or_promoted_team:") for warning in warnings)
                low_sample_predictions += int(low_sample)
                unknown_team_predictions += int(unknown_team)
                records.append(
                    {
                        "date": match.match_date.isoformat(),
                        "division": match.division,
                        "home_team": match.home_team,
                        "away_team": match.away_team,
                        "home_goals": match.home_goals,
                        "away_goals": match.away_goals,
                        "outcome": match.outcome,
                        "model": [float(value) for value in model_probabilities],
                        "market": [float(value) for value in market_probabilities],
                        "overround": overround,
                        "low_sample": low_sample,
                        "unknown_team": unknown_team,
                    }
                )

    if not records:
        raise RuntimeError("Aucune prédiction produite : vérifier les dates et le volume d'entraînement.")

    model_probabilities = [record["model"] for record in records]
    market_probabilities = [record["market"] for record in records]
    outcomes = [str(record["outcome"]) for record in records]
    model_score = score(model_probabilities, outcomes)
    market_score = score(market_probabilities, outcomes)

    model_losses = np.asarray(model_score.pop("per_match_log_losses"), dtype=float)
    market_losses = np.asarray(market_score.pop("per_match_log_losses"), dtype=float)
    # Gain positif = le modèle fait mieux que le marché.
    gain_per_match = market_losses - model_losses
    gain_ci = bootstrap_mean_ci(gain_per_match.tolist(), seed=int(args.seed))
    dm = diebold_mariano_test(model_losses.tolist(), market_losses.tolist())

    per_division: Dict[str, Dict[str, object]] = {}
    for division in sorted({str(record["division"]) for record in records}):
        indices = [index for index, record in enumerate(records) if record["division"] == division]
        division_model = score([records[index]["model"] for index in indices], [records[index]["outcome"] for index in indices])
        division_market = score([records[index]["market"] for index in indices], [records[index]["outcome"] for index in indices])
        division_model.pop("per_match_log_losses", None)
        division_market.pop("per_match_log_losses", None)
        per_division[division] = {
            "sample_size": len(indices),
            "model": division_model,
            "market": division_market,
            "gain_log_loss_market_minus_model": round(
                float(division_market["log_loss"]) - float(division_model["log_loss"]), 6
            ),
        }

    per_season: Dict[str, Dict[str, object]] = {}
    for record in records:
        match_date = date.fromisoformat(str(record["date"]))
        season_start = match_date.year if match_date.month >= 7 else match_date.year - 1
        season = f"{season_start}/{season_start + 1}"
        per_season.setdefault(season, {"model": [], "market": [], "outcomes": []})
        per_season[season]["model"].append(record["model"])
        per_season[season]["market"].append(record["market"])
        per_season[season]["outcomes"].append(record["outcome"])
    season_report: Dict[str, Dict[str, object]] = {}
    for season, values in sorted(per_season.items()):
        season_model = score(values["model"], values["outcomes"])
        season_market = score(values["market"], values["outcomes"])
        season_model.pop("per_match_log_losses", None)
        season_market.pop("per_match_log_losses", None)
        season_report[season] = {
            "sample_size": len(values["outcomes"]),
            "model": season_model,
            "market": season_market,
            "gain_log_loss_market_minus_model": round(
                float(season_market["log_loss"]) - float(season_model["log_loss"]), 6
            ),
        }

    delta_log_loss = round(float(model_score["log_loss"]) - float(market_score["log_loss"]), 6)
    gain_log_loss = round(-delta_log_loss, 6)
    relative_gain = round(gain_log_loss / float(market_score["log_loss"]) * 100.0, 4) if market_score["log_loss"] else None
    mean_overround = round(float(np.mean([record["overround"] for record in records])), 6)

    return {
        "dataset": {
            "path": str(args.dataset),
            "divisions": divisions,
            "rows": counters,
            "matches_used": len(records),
            "first_test_date": records[0]["date"],
            "last_test_date": records[-1]["date"],
            "source": "xgabora/Club-Football-Match-Data (Football-Data.co.uk + ClubElo)",
        },
        "protocol": {
            "model": "DixonColesModel(xi=0.0019, l2_reg=0.01, rho initial=-0.04, rho réestimé à chaque fit)",
            "scope": "cœur Dixon-Coles ; le blend Elo/forme/marché de /v1/predict n'est pas inclus",
            "fit": "un modèle par division, fenêtre expansive",
            "refit_every": int(args.refit_every),
            "min_train": int(args.min_train),
            "start_date": args.start_date,
            "test_start": args.test_start,
            "devig_method": str(args.devig),
            "odds": "Bet365 1X2 du dataset (OddHome/OddDraw/OddAway)",
            "strictly_pre_kickoff": True,
            "fit_count": fit_count,
            "fit_failures": fit_failures,
            "skipped_divisions": skipped_divisions,
            "low_sample_predictions": low_sample_predictions,
            "unknown_team_predictions": unknown_team_predictions,
            "mean_overround": mean_overround,
        },
        "model": model_score,
        "market": market_score,
        "comparison": {
            "delta_log_loss_model_minus_market": delta_log_loss,
            "gain_log_loss_market_minus_model": gain_log_loss,
            "relative_gain_percent": relative_gain,
            "delta_brier_model_minus_market": round(
                float(model_score["brier"]) - float(market_score["brier"]), 6
            ),
            "delta_rps_model_minus_market": round(
                float(model_score["rps"]) - float(market_score["rps"]), 6
            ),
            "delta_ece_model_minus_market": round(
                float(model_score["ece"]) - float(market_score["ece"]), 6
            ),
            "delta_accuracy_model_minus_market": round(
                float(model_score["accuracy_1n2"]) - float(market_score["accuracy_1n2"]), 6
            ),
            "bootstrap_gain_log_loss": gain_ci,
            "diebold_mariano_model_vs_market": dm,
            "interpretation": (
                "gain positif = le modèle a un log loss plus faible que le marché dé-vigé; "
                "un intervalle de confiance contenant 0 ou p >= 0,05 signifie que le gain "
                "n'est pas distinguable du bruit."
            ),
        },
        "per_division": per_division,
        "per_season": season_report,
    }


def _format_metric(value: object) -> str:
    if isinstance(value, float):
        return f"{value:.4f}"
    return str(value)


def render_markdown(report: Dict[str, object]) -> str:
    dataset = report["dataset"]  # type: ignore[index]
    protocol = report["protocol"]  # type: ignore[index]
    model = report["model"]  # type: ignore[index]
    market = report["market"]  # type: ignore[index]
    comparison = report["comparison"]  # type: ignore[index]
    dm = comparison["diebold_mariano_model_vs_market"]  # type: ignore[index]
    ci = comparison["bootstrap_gain_log_loss"]  # type: ignore[index]

    lines = [
        "# Évaluation xgabora — cœur Dixon-Coles vs marché dé-vigé",
        "",
        "Rapport généré par `ai-service/scripts/evaluate_xgabora_market.py`.",
        "",
        "Le modèle évalué est le **cœur statistique Dixon-Coles**. Le prédicteur de production",
        "ajoute ensuite un blend Elo/forme et, si l'appelant les fournit, les cotes du marché.",
        "Ce blend n'est pas inclus ici : injecter les cotes dans le modèle rendrait la",
        "comparaison au marché circulaire.",
        "",
        "## Données et protocole",
        "",
        f"- Source : **{dataset['source']}**.",
        f"- Divisions : {', '.join(dataset['divisions'])}.",
        f"- Rencontres utilisées : **{dataset['matches_used']}** "
        f"({dataset['first_test_date']} → {dataset['last_test_date']}).",
        f"- Cœur statistique : `{protocol['model']}`, un modèle par division ; `rho` est réestimé à chaque fit.",
        f"- Réentraînement tous les **{protocol['refit_every']}** matchs, "
        f"uniquement sur les rencontres strictement antérieures au bloc.",
        f"- Cotes : {protocol['odds']}, retrait de marge **{protocol['devig_method']}**.",
        f"- Marge moyenne du bookmaker : **{protocol['mean_overround']:.4f}**.",
        f"- Réentraînements réussis : {protocol['fit_count']} ; échecs : {protocol['fit_failures']}.",
        f"- Prédictions avec historique d'équipe faible : {protocol['low_sample_predictions']} ; "
        f"avec équipe inconnue : {protocol['unknown_team_predictions']}.",
        "",
        "## Résultat principal",
        "",
        "| Métrique | Dixon-Coles | Marché dé-vigé | Écart (modèle − marché) |",
        "| --- | ---: | ---: | ---: |",
        f"| Log loss | {_format_metric(model['log_loss'])} | {_format_metric(market['log_loss'])} | "
        f"{comparison['delta_log_loss_model_minus_market']:+.4f} |",
        f"| Brier multiclasse | {_format_metric(model['brier'])} | {_format_metric(market['brier'])} | "
        f"{comparison['delta_brier_model_minus_market']:+.4f} |",
        f"| RPS | {_format_metric(model['rps'])} | {_format_metric(market['rps'])} | "
        f"{comparison['delta_rps_model_minus_market']:+.4f} |",
        f"| ECE | {_format_metric(model['ece'])} | {_format_metric(market['ece'])} | "
        f"{comparison['delta_ece_model_minus_market']:+.4f} |",
        f"| Précision 1N2 | {_format_metric(model['accuracy_1n2'])} | {_format_metric(market['accuracy_1n2'])} | "
        f"{comparison['delta_accuracy_model_minus_market']:+.4f} |",
        "",
        f"**Gain de log loss (marché − modèle) : {comparison['gain_log_loss_market_minus_model']:+.4f}** "
        f"({comparison['relative_gain_percent']:+.2f} %). "
        "Un signe positif signifie que le modèle fait mieux que le marché.",
        "",
        f"- IC 95 % du gain moyen (bootstrap apparié) : "
        f"[{ci['ci95_low']:+.4f} ; {ci['ci95_high']:+.4f}].",
        f"- Diebold-Mariano : statistique = {dm['dm_statistic']}, p = {dm['p_value']}.",
        "",
    ]

    if comparison["gain_log_loss_market_minus_model"] > 0 and float(dm["p_value"]) < 0.05:
        verdict = "Le modèle bat le marché de façon statistiquement significative sur cet échantillon."
    elif comparison["gain_log_loss_market_minus_model"] > 0:
        verdict = (
            "Le modèle a un meilleur log loss moyen, mais le gain n'est pas statistiquement "
            "significatif : il peut relever du bruit d'échantillonnage."
        )
    else:
        verdict = (
            "Le modèle ne bat pas le marché dé-vigé sur cet échantillon. Le marché reste "
            "la meilleure baseline probabiliste."
        )
    lines += [f"> {verdict}", "", "## Par division", "", "| Division | N | Log loss modèle | Log loss marché | Gain |", "| --- | ---: | ---: | ---: | ---: |"]
    for division, values in report["per_division"].items():  # type: ignore[union-attr]
        lines.append(
            f"| {division} | {values['sample_size']} | {_format_metric(values['model']['log_loss'])} | "
            f"{_format_metric(values['market']['log_loss'])} | "
            f"{values['gain_log_loss_market_minus_model']:+.4f} |"
        )

    lines += ["", "## Par saison", "", "| Saison | N | Log loss modèle | Log loss marché | Gain |", "| --- | ---: | ---: | ---: | ---: |"]
    for season, values in report["per_season"].items():  # type: ignore[union-attr]
        lines.append(
            f"| {season} | {values['sample_size']} | {_format_metric(values['model']['log_loss'])} | "
            f"{_format_metric(values['market']['log_loss'])} | "
            f"{values['gain_log_loss_market_minus_model']:+.4f} |"
        )

    lines += [
        "",
        "## Lecture",
        "",
        "- Le marché dé-vigé est une baseline forte : il agrège l'information de plusieurs bookmakers et intègre les compositions, blessures et mouvements de cotes.",
        "- Un modèle qui n'utilise que les buts et les dates ne peut pas battre le marché sur la seule base de ces features. Un gain proche de zéro est déjà un résultat honnête.",
        "- L'ECE mesure la calibration globale ; un log loss proche du marché avec une ECE plus élevée signale des probabilités moins bien calibrées, même si le classement des issues est correct.",
        "- Les cotes du dataset sont des cotes Bet365, pas nécessairement des cotes de clôture. Le résultat dépend donc de la qualité et du moment de ces cotes.",
        "- Les équipes promues ou sans historique suffisant sont prédites avec une force ramenée vers la moyenne ; ces cas sont comptés dans le rapport.",
        "",
        "## Limites",
        "",
        "- Évaluation sur les cinq grands championnats, pas sur les 38 divisions du dataset.",
        "- Le modèle est réentraîné par blocs de 100 matchs ; les prédictions en fin de bloc utilisent un modèle légèrement plus ancien, sans fuite temporelle.",
        "- Aucun réglage d'hyperparamètre n'a été effectué sur la période de test.",
        "- Le dataset ne contient pas les compositions, blessures ni xG événementiel : le modèle évalue uniquement la force offensive/défensive historique.",
        "- Le blend Elo/forme/marché de `/v1/predict` n'est pas évalué ici. L'inclure avec les cotes rendrait la comparaison au marché circulaire ; l'évaluer sans cotes serait une autre expérience.",
        "",
    ]
    return "\n".join(lines)


def main() -> int:
    parser = argparse.ArgumentParser(description="Évaluation xgabora vs marché dé-vigé.")
    parser.add_argument("--dataset", required=True)
    parser.add_argument("--output-json", required=True)
    parser.add_argument("--output-md", required=True)
    parser.add_argument("--divisions", default=DEFAULT_DIVISIONS)
    parser.add_argument("--start-date", default="2010-08-01")
    parser.add_argument("--test-start", default="2024-08-01")
    parser.add_argument("--min-train", type=int, default=1000)
    parser.add_argument("--refit-every", type=int, default=100)
    parser.add_argument("--devig", choices=("shin", "power", "proportional"), default="shin")
    parser.add_argument("--seed", type=int, default=42)
    args = parser.parse_args()

    report = run(args)
    output_json = Path(args.output_json)
    output_md = Path(args.output_md)
    output_json.parent.mkdir(parents=True, exist_ok=True)
    output_md.parent.mkdir(parents=True, exist_ok=True)
    output_json.write_text(json.dumps(report, indent=2, ensure_ascii=False), encoding="utf-8")
    output_md.write_text(render_markdown(report), encoding="utf-8")

    comparison = report["comparison"]  # type: ignore[index]
    print(json.dumps({
        "matches": report["dataset"]["matches_used"],  # type: ignore[index]
        "model_log_loss": report["model"]["log_loss"],  # type: ignore[index]
        "market_log_loss": report["market"]["log_loss"],  # type: ignore[index]
        "gain_log_loss": comparison["gain_log_loss_market_minus_model"],
        "relative_gain_percent": comparison["relative_gain_percent"],
        "bootstrap_ci95": comparison["bootstrap_gain_log_loss"],
        "dm_p_value": comparison["diebold_mariano_model_vs_market"]["p_value"],
    }, indent=2, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
