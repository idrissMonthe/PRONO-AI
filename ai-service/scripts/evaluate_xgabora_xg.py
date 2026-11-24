"""Évalue l'ajout de features xG au Dixon-Coles, en walk-forward.

Contexte de source
------------------
Understat et FBref étaient inaccessibles au moment de l'exécution :
Understat renvoyait sa page de maintenance et FBref bloquait les requêtes par
Cloudflare. Le script accepte donc un CSV public dérivé de FBref (EPL
seulement, licence CC BY-NC 4.0) et conserve le même protocole de validation
chronologique. Aucun xG n'est inventé : les matchs sans xG restent sans
ajustement.

Protocole
---------
* Un modèle Dixon-Coles par division (ici E0 uniquement), hyperparamètres de
  production.
* Poids xG choisi sur la saison de validation 2023/24, jamais sur le test.
* Test 2024/25 + 2025/26, réentraînement tous les 100 matchs, historique
  strictement antérieur au bloc.
* Les features xG sont construites par `XgFeatureBuilder`, qui filtre les
  matchs sur `match_date < as_of`.
* Le marché Bet365 1X2 est dé-vigé avec Shin et évalué sur les mêmes matchs.

Usage
-----
    python -m scripts.evaluate_xgabora_xg \
        --matches data/external/Matches.csv \
        --xg-csv data/external/epl_xg_2000_2026.csv \
        --output-json data/external/xgabora-xg-evaluation.json \
        --output-md ../docs/backtests/xgabora-xg-evaluation.md
"""

from __future__ import annotations

import argparse
import json
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

from app.core.dixon_coles import compute_score_matrix, extract_match_markets_from_matrix
from app.core.metrics import diebold_mariano_test
from app.core.poisson_regressor import DixonColesModel
from app.core.xg_features import XgFeatureBuilder, XgMatch, adjust_lambdas_with_xg
from scripts.evaluate_xgabora_market import (
    bootstrap_mean_ci,
    load_matches,
    predict_market,
    predict_model,
    score,
    training_rows,
)

TEAM_ALIASES = {
    "Nott'm Forest": "Nottingham Forest",
    "Nottm Forest": "Nottingham Forest",
    "Nottingham": "Nottingham Forest",
    "Sheffield": "Sheffield United",
}


@dataclass(frozen=True)
class XgEvalMatch:
    match_date: date
    division: str
    home_team: str
    away_team: str
    home_goals: int
    away_goals: int
    odd_home: float
    odd_draw: float
    odd_away: float
    home_xg: Optional[float] = None
    away_xg: Optional[float] = None

    @property
    def outcome(self) -> str:
        if self.home_goals > self.away_goals:
            return "HOME"
        if self.home_goals == self.away_goals:
            return "DRAW"
        return "AWAY"


def canonical_team(name: object) -> str:
    value = str(name).strip()
    return TEAM_ALIASES.get(value, value)


def load_epl_with_xg(
    matches_path: Path,
    xg_path: Path,
    start_date: date,
    end_date: date,
) -> Tuple[List[XgEvalMatch], Dict[str, int]]:
    """Charge E0 et joint le CSV xG sur (date, équipe domicile, équipe extérieur)."""
    base, counters = load_matches(matches_path, ["E0"], start_date)
    base = [match for match in base if match.match_date < end_date]

    xg = pd.read_csv(xg_path, low_memory=False)
    xg["Season"] = xg["Season"].astype(str)
    xg = xg[xg["Season"].isin(["1819", "1920", "2021", "2122", "2223", "2324", "2425", "2526"])].copy()
    xg["date"] = pd.to_datetime(
        dict(year=xg["Year"], month=xg["Month"], day=xg["Day"]), errors="coerce"
    )
    xg = xg.dropna(subset=["date", "XGHome", "XGAway"]).copy()
    xg["home"] = xg["HomeTeam"].map(canonical_team)
    xg["away"] = xg["AwayTeam"].map(canonical_team)
    xg = xg.drop_duplicates(subset=["date", "home", "away"])
    lookup = {
        (row.date.date(), row.home, row.away): (float(row.XGHome), float(row.XGAway))
        for row in xg.itertuples(index=False)
    }

    enriched: List[XgEvalMatch] = []
    matched = 0
    for match in base:
        home = canonical_team(match.home_team)
        away = canonical_team(match.away_team)
        xg_pair = lookup.get((match.match_date, home, away))
        if xg_pair is not None:
            matched += 1
        enriched.append(
            XgEvalMatch(
                match_date=match.match_date,
                division="E0",
                home_team=home,
                away_team=away,
                home_goals=match.home_goals,
                away_goals=match.away_goals,
                odd_home=match.odd_home,
                odd_draw=match.odd_draw,
                odd_away=match.odd_away,
                home_xg=xg_pair[0] if xg_pair else None,
                away_xg=xg_pair[1] if xg_pair else None,
            )
        )

    counters["xg_rows"] = int(len(xg))
    counters["matches_with_xg"] = matched
    counters["matches_without_xg"] = len(enriched) - matched
    return enriched, counters


def build_xg_builder(matches: Sequence[XgEvalMatch], as_of: date) -> XgFeatureBuilder:
    builder = XgFeatureBuilder()
    for match in matches:
        if match.match_date >= as_of:
            continue
        if match.home_xg is None or match.away_xg is None:
            continue
        builder.add(
            XgMatch(
                match_date=match.match_date,
                home_team=match.home_team,
                away_team=match.away_team,
                home_goals=match.home_goals,
                away_goals=match.away_goals,
                home_xg=match.home_xg,
                away_xg=match.away_xg,
                competition="E0",
            )
        )
    return builder


def predict_with_xg(
    model: DixonColesModel,
    builder: XgFeatureBuilder,
    match: XgEvalMatch,
    weight: float,
) -> Tuple[np.ndarray, Dict[str, object]]:
    lambda_home, lambda_away, _ = model.predict_lambdas(match.home_team, match.away_team)
    home_features = builder.features(match.home_team, match.match_date)
    away_features = builder.features(match.away_team, match.match_date)
    baseline = builder.baseline(match.match_date, "E0")
    adjustment = adjust_lambdas_with_xg(
        lambda_home,
        lambda_away,
        home_features,
        away_features,
        baseline,
        weight=weight,
    )
    matrix, _, _ = compute_score_matrix(
        adjustment.lambda_home, adjustment.lambda_away, model.rho
    )
    probabilities = extract_match_markets_from_matrix(matrix)["1N2"]
    row = np.array(
        [float(probabilities["home"]), float(probabilities["draw"]), float(probabilities["away"])],
        dtype=float,
    )
    row = np.clip(row, 1e-12, 1.0)
    info = adjustment.to_dict()
    info["baseline_coverage"] = baseline.get("coverage")
    info["baseline_available"] = baseline.get("available")
    return row / float(row.sum()), info


def walk_forward(
    matches: Sequence[XgEvalMatch],
    start: date,
    end: date,
    weights: Sequence[float],
    refit_every: int,
    min_train: int,
    devig: str,
) -> Tuple[List[Dict[str, object]], Dict[str, int]]:
    test_matches = [match for match in matches if start <= match.match_date < end]
    records: List[Dict[str, object]] = []
    fit_count = 0
    fit_failures = 0

    for block_start in range(0, len(test_matches), refit_every):
        block = test_matches[block_start : block_start + refit_every]
        block_date = block[0].match_date
        history = [match for match in matches if match.match_date < block_date]
        if len(history) < min_train:
            continue

        model = DixonColesModel(xi=0.0019, l2_reg=0.01, rho=-0.04)
        try:
            model.fit(training_rows(history, block_date))
            fit_count += 1
        except (RuntimeError, ValueError, FloatingPointError):
            fit_failures += 1
            continue

        builder = build_xg_builder(history, block_date)
        for match in block:
            dc_probabilities, _ = predict_model(model, match)
            market_probabilities, overround = predict_market(match, devig)
            xg_probabilities: Dict[str, List[float]] = {}
            xg_info: Dict[str, Dict[str, object]] = {}
            for weight in weights:
                probabilities, info = predict_with_xg(model, builder, match, weight)
                key = f"{weight:.2f}"
                xg_probabilities[key] = [float(value) for value in probabilities]
                xg_info[key] = info
            records.append(
                {
                    "date": match.match_date.isoformat(),
                    "outcome": match.outcome,
                    "dixon_coles": [float(value) for value in dc_probabilities],
                    "market": [float(value) for value in market_probabilities],
                    "xg": xg_probabilities,
                    "xg_info": xg_info,
                    "overround": overround,
                    "has_xg": match.home_xg is not None and match.away_xg is not None,
                }
            )

    return records, {"fit_count": fit_count, "fit_failures": fit_failures}


def metrics_for(records: Sequence[Dict[str, object]], key: str) -> Dict[str, object]:
    probabilities = [record[key] for record in records]
    outcomes = [str(record["outcome"]) for record in records]
    result = score(probabilities, outcomes)
    result.pop("per_match_log_losses", None)
    return result


def losses_for(records: Sequence[Dict[str, object]], key: str) -> np.ndarray:
    probabilities = [record[key] for record in records]
    outcomes = [str(record["outcome"]) for record in records]
    result = score(probabilities, outcomes)
    return np.asarray(result["per_match_log_losses"], dtype=float)


def xg_losses_for(records: Sequence[Dict[str, object]], weight_key: str) -> np.ndarray:
    probabilities = [record["xg"][weight_key] for record in records]
    outcomes = [str(record["outcome"]) for record in records]
    result = score(probabilities, outcomes)
    return np.asarray(result["per_match_log_losses"], dtype=float)


def compare(first: np.ndarray, second: np.ndarray, seed: int) -> Dict[str, object]:
    gain = second - first
    return {
        "gain_first_over_second": round(float(gain.mean()), 6),
        "bootstrap_ci95": bootstrap_mean_ci(gain.tolist(), seed=seed),
        "diebold_mariano": diebold_mariano_test(first.tolist(), second.tolist()),
    }


def run(args: argparse.Namespace) -> Dict[str, object]:
    weights = [float(value) for value in str(args.xg_weights).split(",") if value.strip()]
    if 0.0 not in weights:
        weights.insert(0, 0.0)

    matches, counters = load_epl_with_xg(
        Path(args.matches),
        Path(args.xg_csv),
        date.fromisoformat(args.start_date),
        date.fromisoformat(args.end_date),
    )
    if not matches:
        raise RuntimeError("Aucune rencontre EPL exploitable.")

    validation_start = date.fromisoformat(args.validation_start)
    validation_end = date.fromisoformat(args.validation_end)
    test_start = date.fromisoformat(args.test_start)
    test_end = date.fromisoformat(args.end_date)

    validation_records, validation_fits = walk_forward(
        matches,
        validation_start,
        validation_end,
        weights,
        int(args.refit_every),
        int(args.min_train),
        str(args.devig),
    )
    if not validation_records:
        raise RuntimeError("Aucune prédiction de validation produite.")

    # Metrics for each candidate weight.
    validation_scores = {}
    for weight in weights:
        key = f"{weight:.2f}"
        probabilities = [record["xg"][key] for record in validation_records]
        outcomes = [str(record["outcome"]) for record in validation_records]
        result = score(probabilities, outcomes)
        result.pop("per_match_log_losses", None)
        validation_scores[key] = result
    best_key = min(
        validation_scores,
        key=lambda key: (float(validation_scores[key]["log_loss"]), float(key)),
    )
    best_weight = float(best_key)

    test_records, test_fits = walk_forward(
        matches,
        test_start,
        test_end,
        weights,
        int(args.refit_every),
        int(args.min_train),
        str(args.devig),
    )
    if not test_records:
        raise RuntimeError("Aucune prédiction de test produite.")

    dc_losses = losses_for(test_records, "dixon_coles")
    market_losses = losses_for(test_records, "market")
    xg_losses = xg_losses_for(test_records, best_key)

    dc_score = metrics_for(test_records, "dixon_coles")
    market_score = metrics_for(test_records, "market")
    # Full metrics for every weight on test, including the selected one. The
    # test is never used to choose the weight; this table is a sensitivity
    # check, not a second tuning pass.
    test_sensitivity: Dict[str, Dict[str, object]] = {}
    for weight in weights:
        key = f"{weight:.2f}"
        result = score(
            [record["xg"][key] for record in test_records],
            [str(record["outcome"]) for record in test_records],
        )
        result.pop("per_match_log_losses", None)
        test_sensitivity[key] = result
    xg_score = test_sensitivity[best_key]

    comparisons = {
        "xg_vs_dixon_coles": compare(xg_losses, dc_losses, int(args.seed)),
        "xg_vs_market": compare(xg_losses, market_losses, int(args.seed)),
        "dixon_coles_vs_market": compare(dc_losses, market_losses, int(args.seed)),
    }

    applied = 0
    coverage: Dict[str, int] = {}
    reasons: Dict[str, int] = {}
    effective_weights: List[float] = []
    for record in test_records:
        info = record["xg_info"][best_key]
        if info.get("applied"):
            applied += 1
        coverage[str(info.get("coverage"))] = coverage.get(str(info.get("coverage")), 0) + 1
        reasons[str(info.get("reason"))] = reasons.get(str(info.get("reason")), 0) + 1
        effective_weights.append(float(info.get("effective_weight") or 0.0))

    per_season: Dict[str, Dict[str, object]] = {}
    for record in test_records:
        match_date = date.fromisoformat(str(record["date"]))
        season_start = match_date.year if match_date.month >= 7 else match_date.year - 1
        season = f"{season_start}/{season_start + 1}"
        per_season.setdefault(season, {"dc": [], "xg": [], "market": [], "outcomes": []})
        per_season[season]["dc"].append(record["dixon_coles"])
        per_season[season]["xg"].append(record["xg"][best_key])
        per_season[season]["market"].append(record["market"])
        per_season[season]["outcomes"].append(record["outcome"])
    season_report: Dict[str, Dict[str, object]] = {}
    for season, values in sorted(per_season.items()):
        season_dc = score(values["dc"], values["outcomes"]); season_dc.pop("per_match_log_losses", None)
        season_xg = score(values["xg"], values["outcomes"]); season_xg.pop("per_match_log_losses", None)
        season_market = score(values["market"], values["outcomes"]); season_market.pop("per_match_log_losses", None)
        season_report[season] = {
            "sample_size": len(values["outcomes"]),
            "dixon_coles": season_dc,
            "xg": season_xg,
            "market": season_market,
            "xg_minus_dixon_coles": round(float(season_xg["log_loss"]) - float(season_dc["log_loss"]), 6),
            "xg_minus_market": round(float(season_xg["log_loss"]) - float(season_market["log_loss"]), 6),
        }

    return {
        "dataset": {
            "matches": str(args.matches),
            "xg_csv": str(args.xg_csv),
            "division": "E0",
            "rows": counters,
            "matches_used": len(test_records),
            "first_test_date": test_records[0]["date"],
            "last_test_date": test_records[-1]["date"],
            "xg_source": "CSV public dérivé de FBref, EPL, CC BY-NC 4.0",
            "live_source_note": (
                "Understat renvoyait une page de maintenance et FBref bloquait les requêtes "
                "par Cloudflare au moment de l'exécution. Aucun xG n'a été inventé."
            ),
        },
        "protocol": {
            "model": "DixonColesModel(xi=0.0019, l2_reg=0.01, rho initial=-0.04, rho réestimé)",
            "xg_features": "XgFeatureBuilder + adjust_lambdas_with_xg",
            "validation": f"{args.validation_start} → {args.validation_end}",
            "test": f"{args.test_start} → {args.end_date}",
            "weight_candidates": weights,
            "selected_weight": best_weight,
            "refit_every": int(args.refit_every),
            "min_train": int(args.min_train),
            "devig_method": str(args.devig),
            "strictly_pre_kickoff": True,
            "validation_fits": validation_fits,
            "test_fits": test_fits,
            "xg_applied": applied,
            "xg_coverage": coverage,
            "xg_reasons": reasons,
            "mean_effective_weight": round(float(np.mean(effective_weights)), 6) if effective_weights else 0.0,
        },
        "validation_scores": validation_scores,
        "test_sensitivity": test_sensitivity,
        "scores": {
            "dixon_coles": dc_score,
            "xg": xg_score,
            "market": market_score,
        },
        "comparisons": comparisons,
        "per_season": season_report,
    }


def _metric(value: object) -> str:
    return f"{value:.4f}" if isinstance(value, float) else str(value)


def render_markdown(report: Dict[str, object]) -> str:
    dataset = report["dataset"]  # type: ignore[index]
    protocol = report["protocol"]  # type: ignore[index]
    scores = report["scores"]  # type: ignore[index]
    comparisons = report["comparisons"]  # type: ignore[index]
    xg_vs_dc = comparisons["xg_vs_dixon_coles"]  # type: ignore[index]
    xg_vs_market = comparisons["xg_vs_market"]  # type: ignore[index]
    dc_vs_market = comparisons["dixon_coles_vs_market"]  # type: ignore[index]

    lines = [
        "# Évaluation xG — Premier League, walk-forward",
        "",
        "Rapport généré par `ai-service/scripts/evaluate_xgabora_xg.py`.",
        "",
        "## Source et périmètre",
        "",
        f"- xG : **{dataset['xg_source']}**.",
        f"- {dataset['live_source_note']}",
        "- Périmètre : Premier League uniquement (E0), faute d'accès live aux cinq ligues.",
        f"- Matchs de test : **{dataset['matches_used']}** "
        f"({dataset['first_test_date']} → {dataset['last_test_date']}).",
        f"- Validation du poids xG : **{protocol['validation']}**.",
        f"- Test : **{protocol['test']}**.",
        f"- Poids xG candidats : {protocol['weight_candidates']} ; poids retenu : **{protocol['selected_weight']}**.",
        f"- Réentraînement tous les **{protocol['refit_every']}** matchs, sur l'historique strictement antérieur.",
        f"- xG appliqué sur **{protocol['xg_applied']} / {dataset['matches_used']}** prédictions.",
        f"- Poids effectif moyen : **{protocol['mean_effective_weight']}** (plafonné par la couverture et la fiabilité).",
        f"- Couverture xG disponible : **{json.dumps(protocol['xg_coverage'], ensure_ascii=False)}**.",
        "- Un poids retenu de 0 signifie que le modèle final est exactement le Dixon-Coles pur.",
        "",
        "## Résultat principal",
        "",
        "| Modèle | Log loss | Brier | RPS | ECE | Précision 1N2 |",
        "| --- | ---: | ---: | ---: | ---: | ---: |",
        f"| Dixon-Coles pur | {_metric(scores['dixon_coles']['log_loss'])} | "
        f"{_metric(scores['dixon_coles']['brier'])} | {_metric(scores['dixon_coles']['rps'])} | "
        f"{_metric(scores['dixon_coles']['ece'])} | {_metric(scores['dixon_coles']['accuracy_1n2'])} |",
        f"| Dixon-Coles + xG | {_metric(scores['xg']['log_loss'])} | "
        f"{_metric(scores['xg']['brier'])} | {_metric(scores['xg']['rps'])} | "
        f"{_metric(scores['xg']['ece'])} | {_metric(scores['xg']['accuracy_1n2'])} |",
        f"| Marché dé-vigé | {_metric(scores['market']['log_loss'])} | "
        f"{_metric(scores['market']['brier'])} | {_metric(scores['market']['rps'])} | "
        f"{_metric(scores['market']['ece'])} | {_metric(scores['market']['accuracy_1n2'])} |",
        "",
        "### Écarts et significativité",
        "",
        "Un **gain positif** signifie que le premier modèle est meilleur que le second.",
        "",
        "| Comparaison | Δ log loss (1er − 2e) | Gain (2e − 1er) | IC 95 % | DM p |",
        "| --- | ---: | ---: | ---: | ---: |",
        f"| xG vs Dixon-Coles | "
        f"{float(scores['xg']['log_loss']) - float(scores['dixon_coles']['log_loss']):+.4f} | "
        f"{xg_vs_dc['gain_first_over_second']:+.4f} | "
        f"[{xg_vs_dc['bootstrap_ci95']['ci95_low']:+.4f} ; {xg_vs_dc['bootstrap_ci95']['ci95_high']:+.4f}] | "
        f"{xg_vs_dc['diebold_mariano']['p_value']} |",
        f"| xG vs marché | "
        f"{float(scores['xg']['log_loss']) - float(scores['market']['log_loss']):+.4f} | "
        f"{xg_vs_market['gain_first_over_second']:+.4f} | "
        f"[{xg_vs_market['bootstrap_ci95']['ci95_low']:+.4f} ; {xg_vs_market['bootstrap_ci95']['ci95_high']:+.4f}] | "
        f"{xg_vs_market['diebold_mariano']['p_value']} |",
        f"| Dixon-Coles vs marché | "
        f"{float(scores['dixon_coles']['log_loss']) - float(scores['market']['log_loss']):+.4f} | "
        f"{dc_vs_market['gain_first_over_second']:+.4f} | "
        f"[{dc_vs_market['bootstrap_ci95']['ci95_low']:+.4f} ; {dc_vs_market['bootstrap_ci95']['ci95_high']:+.4f}] | "
        f"{dc_vs_market['diebold_mariano']['p_value']} |",
        "",
        "## Sélection du poids xG",
        "",
        "| Poids | Log loss validation | Brier | RPS |",
        "| ---: | ---: | ---: | ---: |",
    ]
    for key, value in report["validation_scores"].items():  # type: ignore[union-attr]
        lines.append(
            f"| {key} | {_metric(value['log_loss'])} | {_metric(value['brier'])} | {_metric(value['rps'])} |"
        )

    lines += [
        "",
        "## Sensibilité au poids xG (test)",
        "",
        "Le poids est choisi uniquement sur la validation ; ce tableau montre ce que\n"
        "chaque poids aurait donné sur le test, sans servir à le sélectionner.",
        "",
        "| Poids | Log loss test | Brier | RPS |",
        "| ---: | ---: | ---: | ---: |",
    ]
    for key, value in report["test_sensitivity"].items():  # type: ignore[union-attr]
        lines.append(
            f"| {key} | {_metric(value['log_loss'])} | {_metric(value['brier'])} | {_metric(value['rps'])} |"
        )

    lines += [
        "",
        "## Par saison",
        "",
        "| Saison | N | DC log loss | xG log loss | Marché log loss | xG − DC | xG − marché |",
        "| --- | ---: | ---: | ---: | ---: | ---: | ---: |",
    ]
    for season, values in report["per_season"].items():  # type: ignore[union-attr]
        lines.append(
            f"| {season} | {values['sample_size']} | {_metric(values['dixon_coles']['log_loss'])} | "
            f"{_metric(values['xg']['log_loss'])} | {_metric(values['market']['log_loss'])} | "
            f"{values['xg_minus_dixon_coles']:+.4f} | {values['xg_minus_market']:+.4f} |"
        )

    lines += [
        "",
        "## Couverture xG",
        "",
        f"- Couverture : `{json.dumps(protocol['xg_coverage'], ensure_ascii=False)}`.",
        f"- Raisons de non-application : `{json.dumps(protocol['xg_reasons'], ensure_ascii=False)}`.",
        "",
        "## Conclusion",
        "",
    ]
    dc_gap = float(scores["dixon_coles"]["log_loss"]) - float(scores["market"]["log_loss"])
    xg_gap = float(scores["xg"]["log_loss"]) - float(scores["market"]["log_loss"])
    reduction = dc_gap - xg_gap
    lines += [
        f"- Écart Dixon-Coles / marché : **{dc_gap:+.4f}**.",
        f"- Écart xG / marché : **{xg_gap:+.4f}**.",
        f"- Réduction de l'écart apportée par xG : **{reduction:+.4f}**.",
        "",
    ]
    if reduction > 0 and float(xg_vs_dc["diebold_mariano"]["p_value"]) < 0.05:
        lines.append("Le xG réduit significativement l'écart au marché sur cette période.")
    elif reduction > 0:
        lines.append(
            "Le xG réduit l'écart au marché, mais le gain n'est pas statistiquement significatif."
        )
    else:
        lines.append(
            "Le xG n'améliore pas le Dixon-Coles sur cette période : l'écart au marché ne se réduit pas."
        )
    lines += [
        "",
        "## Limites",
        "",
        "- Une seule ligue (EPL) à cause de l'indisponibilité des sources live ; les autres championnats ne sont pas couverts.",
        "- Le CSV xG est dérivé de FBref et sous licence CC BY-NC 4.0 ; il ne remplace pas une collecte live.",
        "- Les cotes sont des cotes Bet365 du dataset, pas nécessairement des cotes de clôture.",
        "- Les équipes promues sans historique xG en Premier League ne reçoivent aucun ajustement xG.",
        "",
    ]
    return "\n".join(lines)


def main() -> int:
    parser = argparse.ArgumentParser(description="Évaluation xG walk-forward sur la Premier League.")
    parser.add_argument("--matches", required=True)
    parser.add_argument("--xg-csv", required=True)
    parser.add_argument("--output-json", required=True)
    parser.add_argument("--output-md", required=True)
    parser.add_argument("--start-date", default="2010-08-01")
    parser.add_argument("--validation-start", default="2023-08-01")
    parser.add_argument("--validation-end", default="2024-07-01")
    parser.add_argument("--test-start", default="2024-08-01")
    parser.add_argument("--end-date", default="2026-07-01")
    parser.add_argument("--min-train", type=int, default=1000)
    parser.add_argument("--refit-every", type=int, default=100)
    parser.add_argument("--xg-weights", default="0,0.25,0.5,1.0")
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

    print(json.dumps({
        "matches": report["dataset"]["matches_used"],  # type: ignore[index]
        "selected_weight": report["protocol"]["selected_weight"],  # type: ignore[index]
        "dixon_coles_log_loss": report["scores"]["dixon_coles"]["log_loss"],  # type: ignore[index]
        "xg_log_loss": report["scores"]["xg"]["log_loss"],  # type: ignore[index]
        "market_log_loss": report["scores"]["market"]["log_loss"],  # type: ignore[index]
        "xg_vs_dixon_coles": report["comparisons"]["xg_vs_dixon_coles"],  # type: ignore[index]
        "xg_vs_market": report["comparisons"]["xg_vs_market"],  # type: ignore[index]
    }, indent=2, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
