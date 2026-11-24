"""Évalue le blend Elo/forme de production, sans cotes, face au Dixon-Coles pur et au marché.

Le blend reprend les poids fixes de `/v1/predict` :

    0.65 * Dixon-Coles + 0.25 * Elo + 0.10 * forme

Contrairement au prédicteur complet, aucune cote n'entre dans le blend. Les
cotes Bet365 servent uniquement de baseline de marché, dé-vigée, sur les mêmes
rencontres. Le protocole est identique à `evaluate_xgabora_market.py` : un
modèle par division, fenêtre expansive, réentraînement tous les 100 matchs,
historique strictement antérieur au bloc.

Usage
-----
    python -m scripts.evaluate_xgabora_blend \
        --dataset data/external/Matches.csv \
        --output-json data/external/xgabora-blend-evaluation.json \
        --output-md ../docs/backtests/xgabora-elo-form-blend.md
"""

from __future__ import annotations

import argparse
import json
import sys
from datetime import date
from pathlib import Path
from typing import Dict, List, Optional, Sequence, Tuple

ROOT = Path(__file__).resolve().parents[1]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

import numpy as np

from app.core.elo import EloEngine
from app.core.metrics import diebold_mariano_test
from app.core.poisson_regressor import DixonColesModel
from scripts.evaluate_xgabora_market import (
    Match,
    bootstrap_mean_ci,
    load_matches,
    predict_market,
    predict_model,
    score,
    training_rows,
)

BLEND_WEIGHTS = {"dixon_coles": 0.65, "elo": 0.25, "form": 0.10}


def build_elo_and_form(history: Sequence[Match]) -> Tuple[Dict[str, float], Dict[str, List[float]]]:
    """Reconstruit Elo et forme uniquement à partir des résultats antérieurs."""
    engine = EloEngine(k_factor=20.0, default_home_adv=65.0, default_elo=1500.0)
    ratings: Dict[str, float] = {}
    form: Dict[str, List[float]] = {}

    for match in sorted(history, key=lambda item: (item.match_date, item.home_team, item.away_team)):
        home_rating = ratings.get(match.home_team, engine.default_elo)
        away_rating = ratings.get(match.away_team, engine.default_elo)
        new_home, new_away = engine.update_ratings(
            home_rating,
            away_rating,
            match.home_goals,
            match.away_goals,
            is_neutral=False,
        )
        ratings[match.home_team] = new_home
        ratings[match.away_team] = new_away

        home_points = 3.0 if match.home_goals > match.away_goals else (1.0 if match.home_goals == match.away_goals else 0.0)
        away_points = 3.0 if match.away_goals > match.home_goals else (1.0 if match.home_goals == match.away_goals else 0.0)
        form.setdefault(match.home_team, []).append(home_points)
        form.setdefault(match.away_team, []).append(away_points)

    for team in form:
        form[team] = form[team][-5:]
    return ratings, form


def elo_probabilities(
    ratings: Dict[str, float],
    match: Match,
) -> np.ndarray:
    engine = EloEngine(k_factor=20.0, default_home_adv=65.0, default_elo=1500.0)
    probabilities = engine.predict_1n2(
        ratings.get(match.home_team, engine.default_elo),
        ratings.get(match.away_team, engine.default_elo),
        is_neutral=False,
    )
    row = np.array([probabilities["home"], probabilities["draw"], probabilities["away"]], dtype=float)
    return row / float(row.sum())


def form_probabilities(form: Dict[str, List[float]], match: Match) -> np.ndarray:
    """Même mapping que le prédicteur de production."""
    home_form = float(np.mean(form.get(match.home_team, [1.0]))) / 3.0
    away_form = float(np.mean(form.get(match.away_team, [1.0]))) / 3.0
    form_delta = float(np.clip(home_form - away_form, -1.0, 1.0))
    home = float(np.clip(0.33 + 0.18 * form_delta, 0.10, 0.70))
    away = float(np.clip(0.33 - 0.18 * form_delta, 0.10, 0.70))
    draw = max(0.0, 1.0 - home - away)
    row = np.array([home, draw, away], dtype=float)
    return row / float(row.sum())


def blend_probabilities(
    dixon_coles: np.ndarray,
    elo: np.ndarray,
    form: np.ndarray,
) -> np.ndarray:
    blended = (
        BLEND_WEIGHTS["dixon_coles"] * dixon_coles
        + BLEND_WEIGHTS["elo"] * elo
        + BLEND_WEIGHTS["form"] * form
    )
    blended = np.clip(blended, 1e-12, 1.0)
    return blended / float(blended.sum())


def comparison(
    first_losses: Sequence[float],
    second_losses: Sequence[float],
    seed: int,
) -> Dict[str, object]:
    """Compare deux flux de pertes ; un gain positif signifie que le premier est meilleur."""
    first = np.asarray(first_losses, dtype=float)
    second = np.asarray(second_losses, dtype=float)
    gain = second - first
    return {
        "gain_first_over_second": round(float(gain.mean()), 6),
        "bootstrap_ci95": bootstrap_mean_ci(gain.tolist(), seed=seed),
        "diebold_mariano": diebold_mariano_test(first.tolist(), second.tolist()),
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
                if model is None:
                    continue

            assert model is not None
            ratings, form = build_elo_and_form(history)

            for match in block:
                dc_probabilities, warnings = predict_model(model, match)
                elo_row = elo_probabilities(ratings, match)
                form_row = form_probabilities(form, match)
                blend_row = blend_probabilities(dc_probabilities, elo_row, form_row)
                market_row, overround = predict_market(match, str(args.devig))
                records.append(
                    {
                        "date": match.match_date.isoformat(),
                        "division": match.division,
                        "outcome": match.outcome,
                        "dixon_coles": [float(value) for value in dc_probabilities],
                        "elo": [float(value) for value in elo_row],
                        "form": [float(value) for value in form_row],
                        "blend": [float(value) for value in blend_row],
                        "market": [float(value) for value in market_row],
                        "overround": overround,
                        "low_sample": any(
                            warning.startswith("low_sample_team:") or warning.startswith("unknown_or_promoted_team:")
                            for warning in warnings
                        ),
                    }
                )

    if not records:
        raise RuntimeError("Aucune prédiction produite : vérifier les dates et le volume d'entraînement.")

    outcomes = [str(record["outcome"]) for record in records]
    raw_scores = {
        name: score([record[name] for record in records], outcomes)
        for name in ("dixon_coles", "elo", "form", "blend", "market")
    }
    losses = {
        name: np.asarray(raw_scores[name].pop("per_match_log_losses"), dtype=float)
        for name in raw_scores
    }

    comparisons = {
        "blend_vs_dixon_coles": comparison(losses["blend"], losses["dixon_coles"], int(args.seed)),
        "blend_vs_market": comparison(losses["blend"], losses["market"], int(args.seed)),
        "dixon_coles_vs_market": comparison(losses["dixon_coles"], losses["market"], int(args.seed)),
        "elo_vs_dixon_coles": comparison(losses["elo"], losses["dixon_coles"], int(args.seed)),
        "form_vs_dixon_coles": comparison(losses["form"], losses["dixon_coles"], int(args.seed)),
    }

    dc_gap = float(raw_scores["dixon_coles"]["log_loss"]) - float(raw_scores["market"]["log_loss"])
    blend_gap = float(raw_scores["blend"]["log_loss"]) - float(raw_scores["market"]["log_loss"])

    per_division: Dict[str, Dict[str, object]] = {}
    for division in sorted({str(record["division"]) for record in records}):
        indices = [index for index, record in enumerate(records) if record["division"] == division]
        division_outcomes = [records[index]["outcome"] for index in indices]
        division_scores = {
            name: score([records[index][name] for index in indices], division_outcomes)
            for name in ("dixon_coles", "blend", "market")
        }
        for value in division_scores.values():
            value.pop("per_match_log_losses", None)
        per_division[division] = {
            "sample_size": len(indices),
            "scores": division_scores,
            "blend_minus_dixon_coles": round(
                float(division_scores["blend"]["log_loss"]) - float(division_scores["dixon_coles"]["log_loss"]), 6
            ),
            "blend_minus_market": round(
                float(division_scores["blend"]["log_loss"]) - float(division_scores["market"]["log_loss"]), 6
            ),
        }

    per_season: Dict[str, Dict[str, object]] = {}
    for record in records:
        match_date = date.fromisoformat(str(record["date"]))
        season_start = match_date.year if match_date.month >= 7 else match_date.year - 1
        season = f"{season_start}/{season_start + 1}"
        per_season.setdefault(season, {"dixon_coles": [], "blend": [], "market": [], "outcomes": []})
        for name in ("dixon_coles", "blend", "market"):
            per_season[season][name].append(record[name])
        per_season[season]["outcomes"].append(record["outcome"])
    season_report: Dict[str, Dict[str, object]] = {}
    for season, values in sorted(per_season.items()):
        season_scores = {
            name: score(values[name], values["outcomes"])
            for name in ("dixon_coles", "blend", "market")
        }
        for value in season_scores.values():
            value.pop("per_match_log_losses", None)
        season_report[season] = {
            "sample_size": len(values["outcomes"]),
            "scores": season_scores,
            "blend_minus_dixon_coles": round(
                float(season_scores["blend"]["log_loss"]) - float(season_scores["dixon_coles"]["log_loss"]), 6
            ),
            "blend_minus_market": round(
                float(season_scores["blend"]["log_loss"]) - float(season_scores["market"]["log_loss"]), 6
            ),
        }

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
            "blend": "0.65 * Dixon-Coles + 0.25 * Elo + 0.10 * forme",
            "market_odds_in_blend": False,
            "market_used_only_for_scoring": True,
            "elo": "EloEngine(k_factor=20.0, default_home_adv=65.0, default_elo=1500.0), reconstruit chronologiquement",
            "form": "points des 5 derniers matchs, même mapping que /v1/predict",
            "dixon_coles": "DixonColesModel(xi=0.0019, l2_reg=0.01, rho initial=-0.04, rho réestimé)",
            "fit": "un modèle par division, fenêtre expansive, réentraînement tous les 100 matchs",
            "strictly_pre_kickoff": True,
            "start_date": args.start_date,
            "test_start": args.test_start,
            "min_train": int(args.min_train),
            "refit_every": int(args.refit_every),
            "devig_method": str(args.devig),
            "fit_count": fit_count,
            "fit_failures": fit_failures,
            "skipped_divisions": skipped_divisions,
            "low_sample_predictions": sum(1 for record in records if record["low_sample"]),
        },
        "scores": raw_scores,
        "gap_reduction": {
            "dixon_coles_minus_market": round(dc_gap, 6),
            "blend_minus_market": round(blend_gap, 6),
            "reduction": round(dc_gap - blend_gap, 6),
            "relative_reduction_percent": round((dc_gap - blend_gap) / dc_gap * 100.0, 4) if dc_gap else None,
        },
        "comparisons": comparisons,
        "per_division": per_division,
        "per_season": season_report,
    }


def _metric(value: object) -> str:
    return f"{value:.4f}" if isinstance(value, float) else str(value)


def render_markdown(report: Dict[str, object]) -> str:
    dataset = report["dataset"]  # type: ignore[index]
    protocol = report["protocol"]  # type: ignore[index]
    scores = report["scores"]  # type: ignore[index]
    comparisons = report["comparisons"]  # type: ignore[index]
    blend_vs_dc = comparisons["blend_vs_dixon_coles"]  # type: ignore[index]
    blend_vs_market = comparisons["blend_vs_market"]  # type: ignore[index]
    dc_vs_market = comparisons["dixon_coles_vs_market"]  # type: ignore[index]

    lines = [
        "# Évaluation xgabora — blend Elo/forme sans cotes",
        "",
        "Rapport généré par `ai-service/scripts/evaluate_xgabora_blend.py`.",
        "",
        "Le blend reprend les poids fixes du prédicteur de production :",
        "",
        "```text",
        "0,65 × Dixon-Coles + 0,25 × Elo + 0,10 × forme",
        "```",
        "",
        "Aucune cote n'entre dans le calcul. Les cotes Bet365 sont uniquement la baseline",
        "de marché, dé-vigée, évaluée sur les mêmes rencontres.",
        "",
        "## Données et protocole",
        "",
        f"- Source : **{dataset['source']}**.",
        f"- Divisions : {', '.join(dataset['divisions'])}.",
        f"- Rencontres de test : **{dataset['matches_used']}** "
        f"({dataset['first_test_date']} → {dataset['last_test_date']}).",
        f"- Dixon-Coles : `{protocol['dixon_coles']}`, un modèle par division.",
        f"- Elo : {protocol['elo']}.",
        f"- Forme : {protocol['form']}.",
        f"- Réentraînement tous les **{protocol['refit_every']}** matchs, "
        "sur les rencontres strictement antérieures au bloc.",
        f"- Cotes : Bet365 1X2, retrait de marge **{protocol['devig_method']}**.",
        f"- Réentraînements réussis : {protocol['fit_count']} ; échecs : {protocol['fit_failures']}.",
        f"- Prédictions avec historique d'équipe faible : {protocol['low_sample_predictions']}.",
        "",
        "## Résultat principal",
        "",
        "| Modèle | Log loss | Brier | RPS | ECE | Précision 1N2 |",
        "| --- | ---: | ---: | ---: | ---: | ---: |",
        f"| Dixon-Coles pur | {_metric(scores['dixon_coles']['log_loss'])} | "
        f"{_metric(scores['dixon_coles']['brier'])} | {_metric(scores['dixon_coles']['rps'])} | "
        f"{_metric(scores['dixon_coles']['ece'])} | {_metric(scores['dixon_coles']['accuracy_1n2'])} |",
        f"| Blend Elo/forme | {_metric(scores['blend']['log_loss'])} | "
        f"{_metric(scores['blend']['brier'])} | {_metric(scores['blend']['rps'])} | "
        f"{_metric(scores['blend']['ece'])} | {_metric(scores['blend']['accuracy_1n2'])} |",
        f"| Marché dé-vigé | {_metric(scores['market']['log_loss'])} | "
        f"{_metric(scores['market']['brier'])} | {_metric(scores['market']['rps'])} | "
        f"{_metric(scores['market']['ece'])} | {_metric(scores['market']['accuracy_1n2'])} |",
        "",
        "### Écarts et significativité",
        "",
        "Un **gain positif** signifie que le premier modèle est meilleur que le second.",
        "",
        "| Comparaison | Δ log loss (1er − 2e) | Gain (2e − 1er) | IC 95 % du gain | DM p |",
        "| --- | ---: | ---: | ---: | ---: |",
        f"| Blend vs Dixon-Coles | "
        f"{float(scores['blend']['log_loss']) - float(scores['dixon_coles']['log_loss']):+.4f} | "
        f"{blend_vs_dc['gain_first_over_second']:+.4f} | "
        f"[{blend_vs_dc['bootstrap_ci95']['ci95_low']:+.4f} ; {blend_vs_dc['bootstrap_ci95']['ci95_high']:+.4f}] | "
        f"{blend_vs_dc['diebold_mariano']['p_value']} |",
        f"| Blend vs marché | "
        f"{float(scores['blend']['log_loss']) - float(scores['market']['log_loss']):+.4f} | "
        f"{blend_vs_market['gain_first_over_second']:+.4f} | "
        f"[{blend_vs_market['bootstrap_ci95']['ci95_low']:+.4f} ; {blend_vs_market['bootstrap_ci95']['ci95_high']:+.4f}] | "
        f"{blend_vs_market['diebold_mariano']['p_value']} |",
        f"| Dixon-Coles vs marché | "
        f"{float(scores['dixon_coles']['log_loss']) - float(scores['market']['log_loss']):+.4f} | "
        f"{dc_vs_market['gain_first_over_second']:+.4f} | "
        f"[{dc_vs_market['bootstrap_ci95']['ci95_low']:+.4f} ; {dc_vs_market['bootstrap_ci95']['ci95_high']:+.4f}] | "
        f"{dc_vs_market['diebold_mariano']['p_value']} |",
        "",
        "## Par division",
        "",
        "| Division | N | DC log loss | Blend log loss | Marché log loss | Blend − DC | Blend − marché |",
        "| --- | ---: | ---: | ---: | ---: | ---: | ---: |",
    ]
    for division, values in report["per_division"].items():  # type: ignore[union-attr]
        division_scores = values["scores"]
        lines.append(
            f"| {division} | {values['sample_size']} | {_metric(division_scores['dixon_coles']['log_loss'])} | "
            f"{_metric(division_scores['blend']['log_loss'])} | {_metric(division_scores['market']['log_loss'])} | "
            f"{values['blend_minus_dixon_coles']:+.4f} | {values['blend_minus_market']:+.4f} |"
        )

    lines += [
        "",
        "## Par saison",
        "",
        "| Saison | N | DC log loss | Blend log loss | Marché log loss | Blend − DC | Blend − marché |",
        "| --- | ---: | ---: | ---: | ---: | ---: | ---: |",
    ]
    for season, values in report["per_season"].items():  # type: ignore[union-attr]
        season_scores = values["scores"]
        lines.append(
            f"| {season} | {values['sample_size']} | {_metric(season_scores['dixon_coles']['log_loss'])} | "
            f"{_metric(season_scores['blend']['log_loss'])} | {_metric(season_scores['market']['log_loss'])} | "
            f"{values['blend_minus_dixon_coles']:+.4f} | {values['blend_minus_market']:+.4f} |"
        )

    gap = report["gap_reduction"]  # type: ignore[index]
    dc_gap = float(gap["dixon_coles_minus_market"])
    blend_gap = float(gap["blend_minus_market"])
    reduction = float(gap["reduction"])
    reduction_p = float(blend_vs_dc["diebold_mariano"]["p_value"])

    lines += [
        "",
        "## Conclusion",
        "",
        f"- Écart Dixon-Coles / marché : **{dc_gap:+.4f}** de log loss.",
        f"- Écart blend / marché : **{blend_gap:+.4f}** de log loss.",
        f"- Réduction de l'écart apportée par Elo/forme : **{reduction:+.4f}** "
        f"({gap['relative_reduction_percent']:+.2f} % de l'écart initial).",
        "",
    ]
    if reduction > 0 and reduction_p < 0.05:
        lines.append(
            "Le blend **réduit significativement** l'écart au marché, mais ne l'annule pas : "
            "le marché reste devant."
        )
    elif reduction > 0:
        lines.append(
            "Le blend **réduit légèrement** l'écart au marché, mais cette réduction n'est pas "
            "statistiquement significative (test Blend vs Dixon-Coles). Le marché reste devant."
        )
    else:
        lines.append(
            "Le blend **n'améliore pas** le Dixon-Coles pur : l'écart au marché ne se réduit pas. "
            "Les signaux Elo/forme, avec ces poids, ajoutent surtout du bruit."
        )
    lines += [
        "",
        "## Limites",
        "",
        "- Le blend est appliqué au Dixon-Coles pur ; les spécialistes de marché et le blend marché optionnel de `/v1/predict` ne sont pas inclus.",
        "- Les cotes du dataset sont des cotes Bet365, pas nécessairement des cotes de clôture.",
        "- Elo et forme sont reconstruits uniquement sur les résultats antérieurs au bloc, sans utiliser les colonnes Elo précalculées du dataset.",
        "- Aucun hyperparamètre n'a été réglé sur la période de test.",
        "",
    ]
    return "\n".join(lines)


def main() -> int:
    parser = argparse.ArgumentParser(description="Évaluation du blend Elo/forme sans cotes.")
    parser.add_argument("--dataset", required=True)
    parser.add_argument("--output-json", required=True)
    parser.add_argument("--output-md", required=True)
    parser.add_argument("--divisions", default="E0,D1,SP1,I1,F1")
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

    scores = report["scores"]  # type: ignore[index]
    comparisons = report["comparisons"]  # type: ignore[index]
    print(json.dumps({
        "matches": report["dataset"]["matches_used"],  # type: ignore[index]
        "dixon_coles_log_loss": scores["dixon_coles"]["log_loss"],
        "blend_log_loss": scores["blend"]["log_loss"],
        "market_log_loss": scores["market"]["log_loss"],
        "blend_vs_dixon_coles": comparisons["blend_vs_dixon_coles"],
        "blend_vs_market": comparisons["blend_vs_market"],
    }, indent=2, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
