"""Blend 0,5 buts + 0,5 xG dans l'ajustement Dixon-Coles, face à l'ajustement xG a posteriori.

Deux façons d'injecter le xG dans le Dixon-Coles :

* ``xg_adjust`` : le modèle est ajusté sur les buts réels, puis les lambda sont
  déplacés a posteriori par ``adjust_lambdas_with_xg`` (poids 0,25, retenu sur
  2023/24 lors de l'évaluation précédente).
* ``blend_fit`` : la vraisemblance de Poisson porte directement sur
  ``0,5 * buts + 0,5 * xG``, comme dans le dépôt source. La correction tau du
  Dixon-Coles reste indexée sur le score réel, seul endroit où elle est définie.

Le protocole est celui de ``evaluate_xgabora_xg_multileague`` : un modèle par
division, fenêtre expansive, réentraînement tous les 100 matchs, historique
strictement antérieur au bloc, cotes Bet365 dé-vigées (Shin) uniquement pour
scorer le marché. Les deux variantes sont évaluées sur exactement les mêmes
rencontres et les mêmes blocs.

Les rencontres d'entraînement sans xG mesuré gardent leurs buts réels : aucun
xG n'est inventé.

Usage
-----
    python -m scripts.evaluate_xgabora_xg_blend_fit \
        --matches data/external/Matches.csv \
        --xg-csv data/external/xline8_xg_matches.csv \
        --output-json data/external/xgabora-xg-blend-fit.json \
        --output-md ../docs/backtests/xgabora-xg-blend-fit.md
"""

from __future__ import annotations

import argparse
import json
import sys
from datetime import date
from pathlib import Path
from typing import Dict, List, Sequence, Tuple

ROOT = Path(__file__).resolve().parents[1]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

import numpy as np

from app.core.poisson_regressor import DixonColesModel
from scripts.evaluate_xgabora_market import (
    bootstrap_mean_ci,
    predict_market,
    predict_model,
    score,
    training_rows,
)
from scripts.evaluate_xgabora_xg_multileague import (
    DEFAULT_DIVISIONS,
    XgEvalMatch,
    build_xg_builder,
    load_matches_with_xg,
    predict_with_xg,
)

# Poids xG retenu sur 2023/24 par l'évaluation a posteriori.
POSTHOC_WEIGHT = 0.25
# Poids du blend dans la vraisemblance, imposé par le dépôt source.
BLEND_XG_WEIGHT = 0.5


def blended_targets(
    history: Sequence[XgEvalMatch],
    blend_weight: float,
) -> Tuple[np.ndarray, int]:
    """Pseudo-buts (buts, xG) par rencontre ; buts réels si le xG manque."""
    home = np.empty(len(history), dtype=float)
    away = np.empty(len(history), dtype=float)
    covered = 0
    for index, match in enumerate(history):
        if match.home_xg is not None and match.away_xg is not None:
            home[index] = (1.0 - blend_weight) * match.home_goals + blend_weight * match.home_xg
            away[index] = (1.0 - blend_weight) * match.away_goals + blend_weight * match.away_xg
            covered += 1
        else:
            home[index] = float(match.home_goals)
            away[index] = float(match.away_goals)
    return np.column_stack([home, away]), covered


def walk_forward(
    matches: Sequence[XgEvalMatch],
    start: date,
    end: date,
    refit_every: int,
    min_train: int,
    devig: str,
    blend_weight: float,
    posthoc_weight: float,
) -> Tuple[List[Dict[str, object]], Dict[str, int]]:
    records: List[Dict[str, object]] = []
    fit_count = 0
    fit_failures = 0
    blend_fit_count = 0
    blend_fit_failures = 0
    blend_fit_fallbacks = 0
    history_matches = 0
    history_with_xg = 0
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

            rows = training_rows(history, block_date)
            model = DixonColesModel(xi=0.0019, l2_reg=0.01, rho=-0.04)
            try:
                model.fit(rows)
                fit_count += 1
            except (RuntimeError, ValueError, FloatingPointError):
                fit_failures += 1
                continue

            targets, covered = blended_targets(history, blend_weight)
            blend_model = DixonColesModel(xi=0.0019, l2_reg=0.01, rho=-0.04)
            blend_fallback = False
            try:
                blend_model.fit(rows, blended_targets=targets)
                blend_fit_count += 1
            except (RuntimeError, ValueError, FloatingPointError):
                # Repli explicite : le bloc est scoré par le modèle buts. Le
                # drapeau par rencontre évite de présenter un blend qui n'a
                # pas eu lieu comme un vrai résultat du blend.
                blend_fit_failures += 1
                blend_fit_fallbacks += 1
                blend_model = model
                blend_fallback = True

            history_matches += len(history)
            history_with_xg += covered
            builder = build_xg_builder(history, division, block_date)

            for match in block:
                dc_probabilities, _ = predict_model(model, match)
                adjusted_probabilities, info = predict_with_xg(
                    model, builder, match, posthoc_weight
                )
                blend_probabilities, _ = predict_model(blend_model, match)
                market_probabilities, overround = predict_market(match, devig)
                records.append(
                    {
                        "date": match.match_date.isoformat(),
                        "division": division,
                        "home": match.home_team,
                        "away": match.away_team,
                        "outcome": match.outcome,
                        "dixon_coles": [float(value) for value in dc_probabilities],
                        "xg_adjust": [float(value) for value in adjusted_probabilities],
                        "blend_fit": [float(value) for value in blend_probabilities],
                        "market": [float(value) for value in market_probabilities],
                        "xg_adjust_applied": bool(info.get("applied")),
                        "blend_fit_fallback": blend_fallback,
                        "has_xg": match.home_xg is not None and match.away_xg is not None,
                        "overround": overround,
                    }
                )

    return records, {
        "fit_count": fit_count,
        "fit_failures": fit_failures,
        "blend_fit_count": blend_fit_count,
        "blend_fit_failures": blend_fit_failures,
        "blend_fit_fallbacks": blend_fit_fallbacks,
        "history_matches": history_matches,
        "history_matches_with_xg": history_with_xg,
    }


MODEL_KEYS = ("dixon_coles", "xg_adjust", "blend_fit", "market")


def losses_for(records: Sequence[Dict[str, object]], key: str) -> np.ndarray:
    result = score(
        [record[key] for record in records],
        [str(record["outcome"]) for record in records],
    )
    return np.asarray(result["per_match_log_losses"], dtype=float)


def metrics_for(records: Sequence[Dict[str, object]], key: str) -> Dict[str, object]:
    result = score(
        [record[key] for record in records],
        [str(record["outcome"]) for record in records],
    )
    result.pop("per_match_log_losses", None)
    return result


def compare(first: np.ndarray, second: np.ndarray, seed: int) -> Dict[str, object]:
    gain = second - first
    return {
        "gain_first_over_second": round(float(gain.mean()), 6),
        "bootstrap_ci95": bootstrap_mean_ci(gain.tolist(), seed=seed),
        "diebold_mariano": diebold_mariano(first, second),
    }


def diebold_mariano(first: np.ndarray, second: np.ndarray) -> Dict[str, object]:
    from app.core.metrics import diebold_mariano_test

    return diebold_mariano_test(first.tolist(), second.tolist())


def _season(match_date: date) -> str:
    start = match_date.year if match_date.month >= 7 else match_date.year - 1
    return f"{start}/{start + 1}"


def _group_report(
    records: Sequence[Dict[str, object]],
    key: str,
) -> Dict[str, Dict[str, object]]:
    buckets: Dict[str, List[Dict[str, object]]] = {}
    for record in records:
        if key == "division":
            name = str(record["division"])
        else:
            name = _season(date.fromisoformat(str(record["date"])))
        buckets.setdefault(name, []).append(record)

    report: Dict[str, Dict[str, object]] = {}
    for name, group in sorted(buckets.items()):
        group_scores = {model: metrics_for(group, model) for model in MODEL_KEYS}
        report[name] = {
            "sample_size": len(group),
            **group_scores,
            "blend_minus_xg_adjust": round(
                float(group_scores["blend_fit"]["log_loss"])
                - float(group_scores["xg_adjust"]["log_loss"]),
                6,
            ),
            "blend_minus_dixon_coles": round(
                float(group_scores["blend_fit"]["log_loss"])
                - float(group_scores["dixon_coles"]["log_loss"]),
                6,
            ),
            "blend_minus_market": round(
                float(group_scores["blend_fit"]["log_loss"])
                - float(group_scores["market"]["log_loss"]),
                6,
            ),
        }
    return report


def run(args: argparse.Namespace) -> Dict[str, object]:
    divisions = [value.strip() for value in str(args.divisions).split(",") if value.strip()]
    matches, counters = load_matches_with_xg(
        Path(args.matches),
        Path(args.xg_csv),
        divisions,
        date.fromisoformat(args.start_date),
        date.fromisoformat(args.end_date),
    )
    if not matches:
        raise RuntimeError("Aucune rencontre exploitable.")

    records, fits = walk_forward(
        matches,
        date.fromisoformat(args.test_start),
        date.fromisoformat(args.end_date),
        int(args.refit_every),
        int(args.min_train),
        str(args.devig),
        float(args.blend_xg_weight),
        float(args.posthoc_weight),
    )
    if not records:
        raise RuntimeError("Aucune prédiction de test produite.")

    scores = {model: metrics_for(records, model) for model in MODEL_KEYS}
    losses = {model: losses_for(records, model) for model in MODEL_KEYS}
    blend_only = np.array(
        [not bool(record["blend_fit_fallback"]) for record in records], dtype=bool
    )

    comparisons = {
        "blend_vs_xg_adjust": compare(losses["blend_fit"], losses["xg_adjust"], int(args.seed)),
        "blend_vs_xg_adjust_on_blend_blocks": compare(
            losses["blend_fit"][blend_only], losses["xg_adjust"][blend_only], int(args.seed)
        )
        if blend_only.any()
        else None,
        "blend_vs_dixon_coles": compare(losses["blend_fit"], losses["dixon_coles"], int(args.seed)),
        "blend_vs_market": compare(losses["blend_fit"], losses["market"], int(args.seed)),
        "xg_adjust_vs_dixon_coles": compare(losses["xg_adjust"], losses["dixon_coles"], int(args.seed)),
        "xg_adjust_vs_market": compare(losses["xg_adjust"], losses["market"], int(args.seed)),
        "dixon_coles_vs_market": compare(losses["dixon_coles"], losses["market"], int(args.seed)),
    }

    market_loss = float(scores["market"]["log_loss"])
    dc_gap = float(scores["dixon_coles"]["log_loss"]) - market_loss
    xg_gap = float(scores["xg_adjust"]["log_loss"]) - market_loss
    blend_gap = float(scores["blend_fit"]["log_loss"]) - market_loss

    return {
        "dataset": {
            "matches": str(args.matches),
            "xg_csv": str(args.xg_csv),
            "divisions": divisions,
            "rows": counters,
            "matches_used": len(records),
            "first_test_date": min(str(record["date"]) for record in records),
            "last_test_date": max(str(record["date"]) for record in records),
            "xg_source": (
                "CSV public dérivé d'Understat/FotMob "
                "(dépôt xline8/footballprediction, code MIT, fichier xg_raw/xg_matches.csv)"
            ),
        },
        "protocol": {
            "model": "DixonColesModel(xi=0.0019, l2_reg=0.01, rho initial=-0.04, rho réestimé)",
            "xg_adjust": (
                "ajustement a posteriori des lambda par adjust_lambdas_with_xg, "
                f"poids {float(args.posthoc_weight)} (retenu sur 2023/24)"
            ),
            "blend_fit": (
                "vraisemblance de Poisson sur "
                f"{1.0 - float(args.blend_xg_weight):.2f} * buts + "
                f"{float(args.blend_xg_weight):.2f} * xG ; tau indexée sur le score réel"
            ),
            "test": f"{args.test_start} → {args.end_date}",
            "refit_every": int(args.refit_every),
            "min_train": int(args.min_train),
            "devig_method": str(args.devig),
            "strictly_pre_kickoff": True,
            "fits": fits,
            "blend_fit_fallback_matches": int((~blend_only).sum()),
            "history_xg_coverage": round(
                fits["history_matches_with_xg"] / fits["history_matches"], 6
            )
            if fits["history_matches"]
            else 0.0,
            "test_xg_coverage": round(
                sum(1 for record in records if record["has_xg"]) / len(records), 6
            ),
            "posthoc_applied": sum(1 for record in records if record["xg_adjust_applied"]),
        },
        "scores": scores,
        "comparisons": comparisons,
        "gap_to_market": {
            "dixon_coles": round(dc_gap, 6),
            "xg_adjust": round(xg_gap, 6),
            "blend_fit": round(blend_gap, 6),
            "xg_adjust_reduction": round(dc_gap - xg_gap, 6),
            "blend_fit_reduction": round(dc_gap - blend_gap, 6),
            "xg_adjust_relative_reduction_percent": round((dc_gap - xg_gap) / dc_gap * 100.0, 4)
            if dc_gap
            else None,
            "blend_fit_relative_reduction_percent": round((dc_gap - blend_gap) / dc_gap * 100.0, 4)
            if dc_gap
            else None,
        },
        "per_division": _group_report(records, "division"),
        "per_season": _group_report(records, "season"),
    }


def _metric(value: object) -> str:
    return f"{value:.4f}" if isinstance(value, float) else str(value)


def render_markdown(report: Dict[str, object]) -> str:
    dataset = report["dataset"]  # type: ignore[index]
    protocol = report["protocol"]  # type: ignore[index]
    scores = report["scores"]  # type: ignore[index]
    comparisons = report["comparisons"]  # type: ignore[index]
    blend_vs_xg = comparisons["blend_vs_xg_adjust"]  # type: ignore[index]
    blend_vs_market = comparisons["blend_vs_market"]  # type: ignore[index]
    xg_vs_dc = comparisons["xg_adjust_vs_dixon_coles"]  # type: ignore[index]
    gap = report["gap_to_market"]  # type: ignore[index]

    lines = [
        "# Blend 0,5 buts + 0,5 xG dans l'ajustement Dixon-Coles",
        "",
        "Rapport généré par `ai-service/scripts/evaluate_xgabora_xg_blend_fit.py`.",
        "",
        "Deux variantes xG comparées sur les **mêmes blocs** du même walk-forward :",
        "",
        "```text",
        "xg_adjust : Dixon-Coles sur les buts, puis lambda déplacés a posteriori (poids 0,25)",
        "blend_fit : vraisemblance de Poisson sur 0,50 × buts + 0,50 × xG",
        "```",
        "",
        "## Source et protocole",
        "",
        f"- xG : **{dataset['xg_source']}**.",
        f"- Divisions : {', '.join(dataset['divisions'])}.",
        f"- Matchs de test : **{dataset['matches_used']}** "
        f"({dataset['first_test_date']} → {dataset['last_test_date']}).",
        f"- Test : **{protocol['test']}**, réentraînement tous les {protocol['refit_every']} matchs.",
        f"- `xg_adjust` : {protocol['xg_adjust']}.",
        f"- `blend_fit` : {protocol['blend_fit']}.",
        f"- Réentraînements : {protocol['fits']['fit_count']} sur buts, "
        f"{protocol['fits']['blend_fit_count']} sur pseudo-buts ; "
        f"échecs : {protocol['fits']['fit_failures']} et {protocol['fits']['blend_fit_failures']}."
        + (
            f" Blocs où le fit blend a échoué et où le modèle buts a été réutilisé : "
            f"**{protocol['fits']['blend_fit_fallbacks']}**, soit "
            f"{protocol['blend_fit_fallback_matches']} rencontre(s) scorée(s) hors blend."
            if int(protocol['fits'].get("blend_fit_fallbacks", 0))
            else ""
        ),
        f"- xG disponible dans l'historique d'entraînement : "
        f"**{protocol['history_xg_coverage']:.2%}** des rencontres ; "
        f"sur le test : **{protocol['test_xg_coverage']:.2%}**.",
        f"- Ajustement a posteriori appliqué sur **{protocol['posthoc_applied']} / "
        f"{dataset['matches_used']}** prédictions.",
        "",
        "## Résultat principal",
        "",
        "| Modèle | Log loss | Brier | RPS | ECE | Précision 1N2 |",
        "| --- | ---: | ---: | ---: | ---: | ---: |",
        f"| Dixon-Coles pur | {_metric(scores['dixon_coles']['log_loss'])} | "
        f"{_metric(scores['dixon_coles']['brier'])} | {_metric(scores['dixon_coles']['rps'])} | "
        f"{_metric(scores['dixon_coles']['ece'])} | {_metric(scores['dixon_coles']['accuracy_1n2'])} |",
        f"| xG a posteriori (0,25) | {_metric(scores['xg_adjust']['log_loss'])} | "
        f"{_metric(scores['xg_adjust']['brier'])} | {_metric(scores['xg_adjust']['rps'])} | "
        f"{_metric(scores['xg_adjust']['ece'])} | {_metric(scores['xg_adjust']['accuracy_1n2'])} |",
        f"| Blend dans le fit (0,5 xG) | {_metric(scores['blend_fit']['log_loss'])} | "
        f"{_metric(scores['blend_fit']['brier'])} | {_metric(scores['blend_fit']['rps'])} | "
        f"{_metric(scores['blend_fit']['ece'])} | {_metric(scores['blend_fit']['accuracy_1n2'])} |",
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
        f"| Blend vs xG a posteriori | "
        f"{float(scores['blend_fit']['log_loss']) - float(scores['xg_adjust']['log_loss']):+.4f} | "
        f"{blend_vs_xg['gain_first_over_second']:+.4f} | "
        f"[{blend_vs_xg['bootstrap_ci95']['ci95_low']:+.4f} ; "
        f"{blend_vs_xg['bootstrap_ci95']['ci95_high']:+.4f}] | "
        f"{blend_vs_xg['diebold_mariano']['p_value']} |",
        f"| Blend vs Dixon-Coles | "
        f"{float(scores['blend_fit']['log_loss']) - float(scores['dixon_coles']['log_loss']):+.4f} | "
        f"{comparisons['blend_vs_dixon_coles']['gain_first_over_second']:+.4f} | "
        f"[{comparisons['blend_vs_dixon_coles']['bootstrap_ci95']['ci95_low']:+.4f} ; "
        f"{comparisons['blend_vs_dixon_coles']['bootstrap_ci95']['ci95_high']:+.4f}] | "
        f"{comparisons['blend_vs_dixon_coles']['diebold_mariano']['p_value']} |",
        f"| xG a posteriori vs Dixon-Coles | "
        f"{float(scores['xg_adjust']['log_loss']) - float(scores['dixon_coles']['log_loss']):+.4f} | "
        f"{xg_vs_dc['gain_first_over_second']:+.4f} | "
        f"[{xg_vs_dc['bootstrap_ci95']['ci95_low']:+.4f} ; "
        f"{xg_vs_dc['bootstrap_ci95']['ci95_high']:+.4f}] | "
        f"{xg_vs_dc['diebold_mariano']['p_value']} |",
        f"| Blend vs marché | "
        f"{float(scores['blend_fit']['log_loss']) - float(scores['market']['log_loss']):+.4f} | "
        f"{blend_vs_market['gain_first_over_second']:+.4f} | "
        f"[{blend_vs_market['bootstrap_ci95']['ci95_low']:+.4f} ; "
        f"{blend_vs_market['bootstrap_ci95']['ci95_high']:+.4f}] | "
        f"{blend_vs_market['diebold_mariano']['p_value']} |",
        "",
        "## Par division",
        "",
        "| Division | N | DC | xG a posteriori | Blend fit | Marché | Blend − xG a posteriori |",
        "| --- | ---: | ---: | ---: | ---: | ---: | ---: |",
    ]
    for division, values in report["per_division"].items():  # type: ignore[union-attr]
        lines.append(
            f"| {division} | {values['sample_size']} | "
            f"{_metric(values['dixon_coles']['log_loss'])} | "
            f"{_metric(values['xg_adjust']['log_loss'])} | "
            f"{_metric(values['blend_fit']['log_loss'])} | "
            f"{_metric(values['market']['log_loss'])} | "
            f"{values['blend_minus_xg_adjust']:+.4f} |"
        )

    lines += [
        "",
        "## Par saison",
        "",
        "| Saison | N | DC | xG a posteriori | Blend fit | Marché | Blend − xG a posteriori |",
        "| --- | ---: | ---: | ---: | ---: | ---: | ---: |",
    ]
    for season, values in report["per_season"].items():  # type: ignore[union-attr]
        lines.append(
            f"| {season} | {values['sample_size']} | "
            f"{_metric(values['dixon_coles']['log_loss'])} | "
            f"{_metric(values['xg_adjust']['log_loss'])} | "
            f"{_metric(values['blend_fit']['log_loss'])} | "
            f"{_metric(values['market']['log_loss'])} | "
            f"{values['blend_minus_xg_adjust']:+.4f} |"
        )

    lines += [
        "",
        "## Écart au marché",
        "",
        f"- Dixon-Coles pur : **{gap['dixon_coles']:+.4f}** de log loss.",
        f"- xG a posteriori : **{gap['xg_adjust']:+.4f}** "
        f"(réduction de {gap['xg_adjust_reduction']:+.4f}, "
        f"{gap['xg_adjust_relative_reduction_percent']:+.2f} % de l'écart initial).",
        f"- Blend dans le fit : **{gap['blend_fit']:+.4f}** "
        f"(réduction de {gap['blend_fit_reduction']:+.4f}, "
        f"{gap['blend_fit_relative_reduction_percent']:+.2f} % de l'écart initial).",
        "",
        "## Conclusion",
        "",
    ]
    delta = float(scores["blend_fit"]["log_loss"]) - float(scores["xg_adjust"]["log_loss"])
    p_value = float(blend_vs_xg["diebold_mariano"]["p_value"])
    if delta < 0 and p_value < 0.05:
        verdict = (
            f"Le blend dans le fit est **meilleur** que l'ajustement a posteriori : "
            f"{delta:+.4f} de log loss, p = {p_value}."
        )
    elif delta < 0:
        verdict = (
            f"Le blend dans le fit est légèrement meilleur ({delta:+.4f} de log loss), "
            f"mais l'écart n'est pas significatif (p = {p_value})."
        )
    elif p_value < 0.05:
        verdict = (
            f"L'ajustement a posteriori reste **meilleur** : le blend coûte "
            f"{-delta:+.4f} de log loss, p = {p_value}."
        )
    else:
        verdict = (
            f"Les deux variantes sont statistiquement indiscernables : écart "
            f"{delta:+.4f} de log loss, p = {p_value}."
        )
    lines += [verdict, ""]
    if float(gap["blend_fit"]) < float(gap["xg_adjust"]):
        lines.append(
            f"Le blend rapproche un peu plus du marché : écart "
            f"{float(gap['blend_fit']):+.4f} contre {float(gap['xg_adjust']):+.4f}. "
            "Le marché reste devant."
        )
    else:
        lines.append(
            f"Le blend ne rapproche pas du marché : écart "
            f"{float(gap['blend_fit']):+.4f} contre {float(gap['xg_adjust']):+.4f}. "
            "Le marché reste devant."
        )

    lines += [
        "",
        "## Limites",
        "",
        "- Le blend porte sur des pseudo-buts : la vraisemblance de Poisson est une quasi-vraisemblance, "
        "les intervalles de confiance des paramètres ne sont plus exacts.",
        "- La correction tau reste indexée sur le score réel ; elle n'est pas redéfinie pour des scores fractionnaires.",
        "- Les rencontres d'entraînement sans xG gardent leurs buts réels ; le blend ne porte donc que sur la part couverte.",
        "- Le poids 0,5 est celui du dépôt source, pas un poids optimisé sur ce dataset ; "
        "le poids 0,25 de l'ajustement a posteriori a été choisi sur 2023/24.",
        "- Si la période évaluée est celle qui a servi à choisir ce poids 0,25 (2023/24), "
        "l'ajustement a posteriori est en partie in-sample : la comparaison y est favorable "
        "à l'ajustement xG, donc défavorable au blend.",
        "- Le CSV xG est un miroir public dérivé d'Understat/FotMob, pas une collecte officielle live.",
        "- Les cotes sont des cotes Bet365 du dataset, pas nécessairement des cotes de clôture.",
        "",
    ]
    return "\n".join(lines)


def main() -> int:
    parser = argparse.ArgumentParser(
        description="Blend 0,5 buts + 0,5 xG dans le fit Dixon-Coles vs ajustement a posteriori."
    )
    parser.add_argument("--matches", required=True)
    parser.add_argument("--xg-csv", required=True)
    parser.add_argument("--output-json", required=True)
    parser.add_argument("--output-md", required=True)
    parser.add_argument("--divisions", default=",".join(DEFAULT_DIVISIONS))
    parser.add_argument("--start-date", default="2010-08-01")
    parser.add_argument("--test-start", default="2024-08-01")
    parser.add_argument("--end-date", default="2026-09-04")
    parser.add_argument("--min-train", type=int, default=1000)
    parser.add_argument("--refit-every", type=int, default=100)
    parser.add_argument("--blend-xg-weight", type=float, default=BLEND_XG_WEIGHT)
    parser.add_argument("--posthoc-weight", type=float, default=POSTHOC_WEIGHT)
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
        "dixon_coles_log_loss": report["scores"]["dixon_coles"]["log_loss"],  # type: ignore[index]
        "xg_adjust_log_loss": report["scores"]["xg_adjust"]["log_loss"],  # type: ignore[index]
        "blend_fit_log_loss": report["scores"]["blend_fit"]["log_loss"],  # type: ignore[index]
        "market_log_loss": report["scores"]["market"]["log_loss"],  # type: ignore[index]
        "blend_vs_xg_adjust": report["comparisons"]["blend_vs_xg_adjust"],  # type: ignore[index]
    }, indent=2, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
