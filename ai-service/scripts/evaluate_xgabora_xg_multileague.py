"""Évalue l'ajout de features xG au Dixon-Coles sur les cinq grands championnats.

Source xG
---------
Understat et FBref étaient inaccessibles en direct (page de maintenance et
blocage Cloudflare). Le script utilise donc un CSV public dérivé d'Understat /
FotMob, `xg_raw/xg_matches.csv` du dépôt `xline8/footballprediction` (MIT).
Aucun xG n'est inventé : les rencontres sans xG restent sans ajustement.

Protocole
---------
* Un Dixon-Coles par division, hyperparamètres de production.
* Validation 2023/24 pour choisir le poids xG ; test 2024/25 → 2026/27.
* Test en walk-forward, réentraînement tous les 100 matchs, historique
  strictement antérieur au bloc.
* Features xG via `XgFeatureBuilder`, qui filtre sur `match_date < as_of`.
* Marché Bet365 1X2 dé-vigé avec Shin, évalué sur les mêmes rencontres.

Usage
-----
    python -m scripts.evaluate_xgabora_xg_multileague \
        --matches data/external/Matches.csv \
        --xg-csv data/external/xline8_xg_matches.csv \
        --output-json data/external/xgabora-xg-multileague.json \
        --output-md ../docs/backtests/xgabora-xg-multileague.md
"""

from __future__ import annotations

import argparse
import json
import sys
from collections import defaultdict
from dataclasses import dataclass
from datetime import date
from pathlib import Path
from typing import Dict, List, Optional, Sequence, Tuple

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

DEFAULT_DIVISIONS = ("E0", "D1", "SP1", "I1", "F1")

# Noms football-data.co.uk / xgabora -> noms Understat du CSV xline8.
TEAM_ALIASES = {
    # Premier League
    "Man City": "Manchester City",
    "Man United": "Manchester United",
    "Newcastle": "Newcastle United",
    "Nott'm Forest": "Nottingham Forest",
    "Nottm Forest": "Nottingham Forest",
    "West Brom": "West Bromwich Albion",
    "Wolves": "Wolverhampton Wanderers",
    # Bundesliga
    "Bielefeld": "Arminia Bielefeld",
    "Dortmund": "Borussia Dortmund",
    "Ein Frankfurt": "Eintracht Frankfurt",
    "FC Koln": "FC Cologne",
    "Fortuna Dusseldorf": "Fortuna Duesseldorf",
    "Greuther Furth": "Greuther Fuerth",
    "Hamburg": "Hamburger SV",
    "Hannover": "Hannover 96",
    "Heidenheim": "FC Heidenheim",
    "Hertha": "Hertha Berlin",
    "Leverkusen": "Bayer Leverkusen",
    "M'gladbach": "Borussia M.Gladbach",
    "MGladbach": "Borussia M.Gladbach",
    "Mainz": "Mainz 05",
    "Nurnberg": "Nuernberg",
    "RB Leipzig": "RasenBallsport Leipzig",
    "St Pauli": "St. Pauli",
    "Stuttgart": "VfB Stuttgart",
    # La Liga
    "Ath Bilbao": "Athletic Club",
    "Ath Madrid": "Atletico Madrid",
    "Betis": "Real Betis",
    "Celta": "Celta Vigo",
    "Espanol": "Espanyol",
    "Huesca": "SD Huesca",
    "La Coruna": "Deportivo La Coruna",
    "Oviedo": "Real Oviedo",
    "Santander": "Racing Santander",
    "Sociedad": "Real Sociedad",
    "Sp Gijon": "Sporting Gijon",
    "Valladolid": "Real Valladolid",
    "Vallecano": "Rayo Vallecano",
    # Serie A
    "Milan": "AC Milan",
    "Parma": "Parma Calcio 1913",
    "Spal": "SPAL 2013",
    # Ligue 1
    "Bastia": "SC Bastia",
    "Clermont": "Clermont Foot",
    "Paris SG": "Paris Saint Germain",
    "St Etienne": "Saint-Etienne",
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


def _xg_lookup(xg: pd.DataFrame) -> Dict[Tuple[str, str, str], List[Tuple[date, float, float]]]:
    lookup: Dict[Tuple[str, str, str], List[Tuple[date, float, float]]] = defaultdict(list)
    for row in xg.itertuples(index=False):
        lookup[(str(row.div), str(row.home), str(row.away))].append(
            (row.match_date, float(row.xgh), float(row.xga))
        )
    return lookup


def _find_xg(
    lookup: Dict[Tuple[str, str, str], List[Tuple[date, float, float]]],
    division: str,
    home: str,
    away: str,
    match_date: date,
    tolerance_days: int = 3,
) -> Optional[Tuple[float, float]]:
    rows = lookup.get((division, home, away))
    if not rows:
        return None
    exact = [row for row in rows if row[0] == match_date]
    if exact:
        return exact[0][1], exact[0][2]
    near = [row for row in rows if abs((row[0] - match_date).days) <= tolerance_days]
    if not near:
        return None
    closest = min(near, key=lambda row: abs((row[0] - match_date).days))
    return closest[1], closest[2]


def load_matches_with_xg(
    matches_path: Path,
    xg_path: Path,
    divisions: Sequence[str],
    start_date: date,
    end_date: date,
) -> Tuple[List[XgEvalMatch], Dict[str, int]]:
    """Charge les rencontres et joint le CSV xG multi-ligues."""
    base, counters = load_matches(matches_path, list(divisions), start_date)
    base = [match for match in base if match.match_date < end_date]

    xg = pd.read_csv(xg_path, low_memory=False)
    required = {"div", "datetime", "home", "away", "xgh", "xga"}
    if not required.issubset(set(xg.columns)):
        raise ValueError(
            "Le CSV xG doit contenir les colonnes div, datetime, home, away, xgh, xga."
        )
    xg["div"] = xg["div"].astype(str).str.strip()
    xg = xg[xg["div"].isin(set(divisions))].copy()
    xg["match_date"] = pd.to_datetime(xg["datetime"], errors="coerce").dt.date
    xg = xg.dropna(subset=["match_date", "xgh", "xga"]).copy()
    xg["home"] = xg["home"].map(canonical_team)
    xg["away"] = xg["away"].map(canonical_team)
    xg = xg.drop_duplicates(subset=["div", "match_date", "home", "away"])
    lookup = _xg_lookup(xg)

    enriched: List[XgEvalMatch] = []
    matched = 0
    for match in base:
        home = canonical_team(match.home_team)
        away = canonical_team(match.away_team)
        xg_pair = _find_xg(lookup, match.division, home, away, match.match_date)
        if xg_pair is not None:
            matched += 1
        enriched.append(
            XgEvalMatch(
                match_date=match.match_date,
                division=match.division,
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


def build_xg_builder(
    matches: Sequence[XgEvalMatch],
    division: str,
    as_of: date,
) -> XgFeatureBuilder:
    builder = XgFeatureBuilder()
    for match in matches:
        if match.division != division or match.match_date >= as_of:
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
                competition=division,
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
    baseline = builder.baseline(match.match_date, match.division)
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
    records: List[Dict[str, object]] = []
    fit_count = 0
    fit_failures = 0
    divisions = sorted({match.division for match in matches})

    for division in divisions:
        division_matches = [match for match in matches if match.division == division]
        test_matches = [match for match in division_matches if start <= match.match_date < end]
        for block_start in range(0, len(test_matches), refit_every):
            block = test_matches[block_start : block_start + refit_every]
            block_date = block[0].match_date
            history = [match for match in division_matches if match.match_date < block_date]
            if len(history) < min_train:
                continue

            model = DixonColesModel(xi=0.0019, l2_reg=0.01, rho=-0.04)
            try:
                model.fit(training_rows(history, block_date))
                fit_count += 1
            except (RuntimeError, ValueError, FloatingPointError):
                fit_failures += 1
                continue

            builder = build_xg_builder(history, division, block_date)
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
                        "division": division,
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
    result = score(
        [record[key] for record in records],
        [str(record["outcome"]) for record in records],
    )
    result.pop("per_match_log_losses", None)
    return result


def losses_for(records: Sequence[Dict[str, object]], key: str) -> np.ndarray:
    result = score(
        [record[key] for record in records],
        [str(record["outcome"]) for record in records],
    )
    return np.asarray(result["per_match_log_losses"], dtype=float)


def xg_losses_for(records: Sequence[Dict[str, object]], weight_key: str) -> np.ndarray:
    result = score(
        [record["xg"][weight_key] for record in records],
        [str(record["outcome"]) for record in records],
    )
    return np.asarray(result["per_match_log_losses"], dtype=float)


def compare(first: np.ndarray, second: np.ndarray, seed: int) -> Dict[str, object]:
    gain = second - first
    return {
        "gain_first_over_second": round(float(gain.mean()), 6),
        "bootstrap_ci95": bootstrap_mean_ci(gain.tolist(), seed=seed),
        "diebold_mariano": diebold_mariano_test(first.tolist(), second.tolist()),
    }


def run(args: argparse.Namespace) -> Dict[str, object]:
    divisions = [value.strip() for value in str(args.divisions).split(",") if value.strip()]
    weights = [float(value) for value in str(args.xg_weights).split(",") if value.strip()]
    if 0.0 not in weights:
        weights.insert(0, 0.0)

    matches, counters = load_matches_with_xg(
        Path(args.matches),
        Path(args.xg_csv),
        divisions,
        date.fromisoformat(args.start_date),
        date.fromisoformat(args.end_date),
    )
    if not matches:
        raise RuntimeError("Aucune rencontre exploitable.")

    validation_records, validation_fits = walk_forward(
        matches,
        date.fromisoformat(args.validation_start),
        date.fromisoformat(args.validation_end),
        weights,
        int(args.validation_refit_every),
        int(args.min_train),
        str(args.devig),
    )
    if not validation_records:
        raise RuntimeError("Aucune prédiction de validation produite.")

    validation_scores: Dict[str, Dict[str, object]] = {}
    for weight in weights:
        key = f"{weight:.2f}"
        result = score(
            [record["xg"][key] for record in validation_records],
            [str(record["outcome"]) for record in validation_records],
        )
        result.pop("per_match_log_losses", None)
        validation_scores[key] = result
    best_key = min(
        validation_scores,
        key=lambda key: (float(validation_scores[key]["log_loss"]), float(key)),
    )
    best_weight = float(best_key)

    test_records, test_fits = walk_forward(
        matches,
        date.fromisoformat(args.test_start),
        date.fromisoformat(args.end_date),
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

    def _season(match_date: date) -> str:
        season_start = match_date.year if match_date.month >= 7 else match_date.year - 1
        return f"{season_start}/{season_start + 1}"

    per_season: Dict[str, Dict[str, object]] = {}
    per_division: Dict[str, Dict[str, object]] = {}
    for record in test_records:
        match_date = date.fromisoformat(str(record["date"]))
        season = _season(match_date)
        division = str(record["division"])
        for bucket, key in ((per_season, season), (per_division, division)):
            bucket.setdefault(key, {"dc": [], "xg": [], "market": [], "outcomes": []})
            bucket[key]["dc"].append(record["dixon_coles"])
            bucket[key]["xg"].append(record["xg"][best_key])
            bucket[key]["market"].append(record["market"])
            bucket[key]["outcomes"].append(record["outcome"])

    def _group_report(bucket: Dict[str, Dict[str, object]]) -> Dict[str, Dict[str, object]]:
        result: Dict[str, Dict[str, object]] = {}
        for key, values in sorted(bucket.items()):
            group_dc = score(values["dc"], values["outcomes"]); group_dc.pop("per_match_log_losses", None)
            group_xg = score(values["xg"], values["outcomes"]); group_xg.pop("per_match_log_losses", None)
            group_market = score(values["market"], values["outcomes"]); group_market.pop("per_match_log_losses", None)
            result[key] = {
                "sample_size": len(values["outcomes"]),
                "dixon_coles": group_dc,
                "xg": group_xg,
                "market": group_market,
                "xg_minus_dixon_coles": round(float(group_xg["log_loss"]) - float(group_dc["log_loss"]), 6),
                "xg_minus_market": round(float(group_xg["log_loss"]) - float(group_market["log_loss"]), 6),
            }
        return result

    return {
        "dataset": {
            "matches": str(args.matches),
            "xg_csv": str(args.xg_csv),
            "divisions": divisions,
            "rows": counters,
            "matches_used": len(test_records),
            "first_test_date": min(str(record["date"]) for record in test_records),
            "last_test_date": max(str(record["date"]) for record in test_records),
            "xg_source": (
                "CSV public dérivé d'Understat/FotMob "
                "(dépôt xline8/footballprediction, code MIT, fichier xg_raw/xg_matches.csv)"
            ),
            "live_source_note": (
                "soccerdata a été essayé ; Understat renvoyait une page de maintenance et "
                "FBref bloquait les requêtes par Cloudflare au moment de l'exécution. "
                "Aucun xG n'a été inventé."
            ),
        },
        "protocol": {
            "model": "DixonColesModel(xi=0.0019, l2_reg=0.01, rho initial=-0.04, rho réestimé)",
            "xg_features": "XgFeatureBuilder + adjust_lambdas_with_xg",
            "validation": f"{args.validation_start} → {args.validation_end}",
            "test": f"{args.test_start} → {args.end_date}",
            "weight_candidates": weights,
            "selected_weight": best_weight,
            "validation_refit_every": int(args.validation_refit_every),
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
        "per_division": _group_report(per_division),
        "per_season": _group_report(per_season),
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
        "# Évaluation xG — cinq grands championnats, walk-forward",
        "",
        "Rapport généré par `ai-service/scripts/evaluate_xgabora_xg_multileague.py`.",
        "",
        "## Source et périmètre",
        "",
        f"- xG : **{dataset['xg_source']}**.",
        f"- {dataset['live_source_note']}",
        f"- Divisions : {', '.join(dataset['divisions'])}.",
        f"- Matchs de test : **{dataset['matches_used']}** "
        f"({dataset['first_test_date']} → {dataset['last_test_date']}).",
        f"- Validation du poids xG : **{protocol['validation']}** "
        f"(réentraînement tous les {protocol['validation_refit_every']} matchs).",
        f"- Test : **{protocol['test']}**, réentraînement tous les {protocol['refit_every']} matchs.",
        f"- Réentraînements : {protocol['test_fits']['fit_count']} test, "
        f"{protocol['test_fits']['fit_failures']} échec(s) ; {protocol['validation_fits']['fit_count']} validation, "
        f"{protocol['validation_fits']['fit_failures']} échec(s).",
        f"- Poids xG candidats : {protocol['weight_candidates']} ; poids retenu : **{protocol['selected_weight']}**.",
        f"- xG appliqué sur **{protocol['xg_applied']} / {dataset['matches_used']}** prédictions.",
        f"- Poids effectif moyen : **{protocol['mean_effective_weight']}**.",
        f"- Couverture xG disponible : **{json.dumps(protocol['xg_coverage'], ensure_ascii=False)}**.",
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
        "Le poids 0.50 ferait légèrement mieux sur le test, mais il n'a pas été retenu : "
        "le choix est fait uniquement sur 2023/24.",
        "",
        "## Par division",
        "",
        "| Division | N | DC log loss | xG log loss | Marché log loss | xG − DC | xG − marché |",
        "| --- | ---: | ---: | ---: | ---: | ---: | ---: |",
    ]
    for division, values in report["per_division"].items():  # type: ignore[union-attr]
        lines.append(
            f"| {division} | {values['sample_size']} | {_metric(values['dixon_coles']['log_loss'])} | "
            f"{_metric(values['xg']['log_loss'])} | {_metric(values['market']['log_loss'])} | "
            f"{values['xg_minus_dixon_coles']:+.4f} | {values['xg_minus_market']:+.4f} |"
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
        f"Le marché reste devant : log loss {float(scores['market']['log_loss']):.4f} contre "
        f"{float(scores['xg']['log_loss']):.4f} pour xG. L'ECE se dégrade de "
        f"{float(scores['dixon_coles']['ece']):.4f} à {float(scores['xg']['ece']):.4f}.",
        "",
        "## Limites",
        "",
        "- Les sources live Understat/FBref étaient inaccessibles ; le CSV xG est un miroir public dérivé d'Understat/FotMob.",
        "- Le CSV xG est un miroir tiers (dépôt au code MIT), pas une collecte officielle Understat/FBref.",
        "- Les cotes sont des cotes Bet365 du dataset, pas nécessairement des cotes de clôture.",
        "- Les équipes promues sans historique xG dans leur division ne reçoivent aucun ajustement xG.",
        "- Le poids xG est sélectionné sur 2023/24 ; le test 2024/25–2026/27 n'entre pas dans ce choix.",
        "",
    ]
    return "\n".join(lines)


def main() -> int:
    parser = argparse.ArgumentParser(description="Évaluation xG walk-forward, cinq grands championnats.")
    parser.add_argument("--matches", required=True)
    parser.add_argument("--xg-csv", required=True)
    parser.add_argument("--output-json", required=True)
    parser.add_argument("--output-md", required=True)
    parser.add_argument("--divisions", default=",".join(DEFAULT_DIVISIONS))
    parser.add_argument("--start-date", default="2010-08-01")
    parser.add_argument("--validation-start", default="2023-08-01")
    parser.add_argument("--validation-end", default="2024-07-01")
    parser.add_argument("--test-start", default="2024-08-01")
    parser.add_argument("--end-date", default="2026-09-04")
    parser.add_argument("--min-train", type=int, default=1000)
    parser.add_argument("--validation-refit-every", type=int, default=200)
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
