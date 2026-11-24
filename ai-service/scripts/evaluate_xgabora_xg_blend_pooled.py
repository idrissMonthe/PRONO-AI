"""Diebold-Mariano cumulé : validation 2023/24 + test 2024/25–2026/27.

Les deux périodes ont été évaluées séparément avec le même protocole
(``evaluate_xgabora_xg_blend_fit``) et le blend y devance l'ajustement a
posteriori de 0,0012 et 0,0011 de log loss, sans significativité dans aucune
des deux (p = 0,199 et p = 0,149). Ce script rejoue exactement les mêmes
blocs — mêmes dates, mêmes réentraînements (200 en validation, 100 en test),
même historique strictement antérieur — et cumule les pertes par rencontre
pour un seul test de Diebold-Mariano sur les 5 354 rencontres disjointes.

Aucune rencontre n'est comptée deux fois : la validation s'arrête au
2024-07-01 et le test commence au 2024-08-01.

Le DM du projet corrige l'autocorrélation au lag 1 (Newey-West). Les
prédictions d'un même bloc partagent les paramètres ajustés, donc la
dépendance dépasse le lag 1 ; un Newey-West à lag 5 est rapporté en plus,
comme contrôle de robustesse.

Usage
-----
    python -m scripts.evaluate_xgabora_xg_blend_pooled \
        --matches data/external/Matches.csv \
        --xg-csv data/external/xline8_xg_matches.csv \
        --output-json data/external/xgabora-xg-blend-pooled.json \
        --output-md ../docs/backtests/xgabora-xg-blend-pooled.md
"""

from __future__ import annotations

import argparse
import json
import sys
from datetime import date
from pathlib import Path
from typing import Dict, List, Sequence

ROOT = Path(__file__).resolve().parents[1]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

import numpy as np
from scipy import stats

from app.core.metrics import diebold_mariano_test
from scripts.evaluate_xgabora_market import bootstrap_mean_ci
from scripts.evaluate_xgabora_xg_blend_fit import (
    BLEND_XG_WEIGHT,
    POSTHOC_WEIGHT,
    losses_for,
    metrics_for,
    walk_forward,
)
from scripts.evaluate_xgabora_xg_multileague import (
    DEFAULT_DIVISIONS,
    load_matches_with_xg,
)

MODEL_KEYS = ("dixon_coles", "xg_adjust", "blend_fit", "market")
HAC_LAG = 5


def newey_west_dm(first: np.ndarray, second: np.ndarray, lag: int) -> Dict[str, object]:
    """DM avec variance de long terme Newey-West tronquée à ``lag``.

    ``d = first - second`` ; une statistique négative signifie que ``first``
    est meilleur (perte plus faible). La variance de long terme vaut
    ``gamma0 + 2 * somme((1 - l/(L+1)) * gamma_l)``. Si cet estimateur est
    négatif, il n'est pas défini : on retombe sur ``gamma0``, c'est-à-dire
    qu'on ignore l'autocorrélation, plutôt que de diviser par une variance
    quasi nulle et de fabriquer une significativité.
    """
    d = np.asarray(first, dtype=float) - np.asarray(second, dtype=float)
    n = int(d.size)
    mean = float(d.mean()) if n else 0.0
    if n < 3:
        return {
            "mean_diff": round(mean, 6),
            "dm_statistic": 0.0,
            "p_value": 1.0,
            "lag": lag,
            "n": n,
        }
    centered = d - mean
    gamma0 = float(np.dot(centered, centered) / n)
    long_run = gamma0
    for offset in range(1, min(lag, n - 1) + 1):
        gamma = float(np.dot(centered[offset:], centered[:-offset]) / n)
        long_run += 2.0 * (1.0 - offset / (lag + 1.0)) * gamma
    if long_run <= 0.0:
        long_run = gamma0
    se = float(np.sqrt(long_run / n))
    stat = mean / se if se > 0.0 else 0.0
    return {
        "mean_diff": round(mean, 6),
        "dm_statistic": round(stat, 4),
        "p_value": round(float(2.0 * (1.0 - stats.norm.cdf(abs(stat)))), 6),
        "lag": lag,
        "n": n,
    }


def _compare(first: np.ndarray, second: np.ndarray, seed: int) -> Dict[str, object]:
    gain = second - first
    return {
        "gain_first_over_second": round(float(gain.mean()), 6),
        "bootstrap_ci95": bootstrap_mean_ci(gain.tolist(), seed=seed),
        "diebold_mariano_lag1": diebold_mariano_test(first.tolist(), second.tolist()),
        "diebold_mariano_newey_west": newey_west_dm(first, second, HAC_LAG),
    }


def _group_deltas(records: Sequence[Dict[str, object]], key: str) -> Dict[str, Dict[str, object]]:
    buckets: Dict[str, List[Dict[str, object]]] = {}
    for record in records:
        if key == "division":
            name = str(record["division"])
        else:
            match_date = date.fromisoformat(str(record["date"]))
            start = match_date.year if match_date.month >= 7 else match_date.year - 1
            name = f"{start}/{start + 1}"
        buckets.setdefault(name, []).append(record)

    report: Dict[str, Dict[str, object]] = {}
    for name, group in sorted(buckets.items()):
        losses = {model: float(metrics_for(group, model)["log_loss"]) for model in MODEL_KEYS}
        report[name] = {
            "sample_size": len(group),
            **{model: round(value, 6) for model, value in losses.items()},
            "blend_minus_xg_adjust": round(losses["blend_fit"] - losses["xg_adjust"], 6),
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

    # Chevauchement de périodes : contrôle sur les arguments, avant les ajustements.
    validation_end = date.fromisoformat(args.validation_end)
    test_start = date.fromisoformat(args.test_start)
    if validation_end > test_start:
        raise RuntimeError(
            f"Périodes chevauchantes ({args.validation_end} > {args.test_start}) : cumul refusé."
        )

    periods = (
        (
            "validation",
            date.fromisoformat(args.validation_start),
            validation_end,
            args.validation_refit_every,
        ),
        ("test", test_start, date.fromisoformat(args.end_date), args.refit_every),
    )

    period_records: Dict[str, List[Dict[str, object]]] = {}
    period_fits: Dict[str, Dict[str, int]] = {}
    for name, start, end, refit_every in periods:
        records, fits = walk_forward(
            matches,
            start,
            end,
            int(refit_every),
            int(args.min_train),
            str(args.devig),
            float(args.blend_xg_weight),
            float(args.posthoc_weight),
        )
        if not records:
            raise RuntimeError(f"Aucune prédiction produite sur la période {name}.")
        period_records[name] = records
        period_fits[name] = fits

    validation_records = period_records["validation"]
    test_records = period_records["test"]
    # Clé d'identité d'une rencontre : la date, la division et les deux équipes.
    # (date, division, issue) n'est pas unique : deux matchs du même jour peuvent
    # partager le même résultat.
    keys = [
        (record["date"], record["division"], record["home"], record["away"])
        for record in validation_records + test_records
    ]
    duplicate_matches = len(keys) - len(set(keys))
    if duplicate_matches:
        raise RuntimeError(
            f"{duplicate_matches} rencontre(s) dupliquée(s) dans le cumul : refusé."
        )

    pooled = validation_records + test_records
    losses = {model: losses_for(pooled, model) for model in MODEL_KEYS}
    scores = {model: metrics_for(pooled, model) for model in MODEL_KEYS}

    period_comparisons: Dict[str, Dict[str, object]] = {}
    for name, records in period_records.items():
        period_losses = {model: losses_for(records, model) for model in MODEL_KEYS}
        period_comparisons[name] = _compare(
            period_losses["blend_fit"], period_losses["xg_adjust"], int(args.seed)
        )

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
            "matches_used": len(pooled),
            "validation_matches": len(validation_records),
            "test_matches": len(test_records),
            "duplicate_matches": duplicate_matches,
            "first_date": min(str(record["date"]) for record in validation_records),
            "last_date": max(str(record["date"]) for record in test_records),
        },
        "protocol": {
            "validation": f"{args.validation_start} → {args.validation_end}",
            "test": f"{args.test_start} → {args.end_date}",
            "validation_refit_every": int(args.validation_refit_every),
            "refit_every": int(args.refit_every),
            "min_train": int(args.min_train),
            "blend_xg_weight": float(args.blend_xg_weight),
            "posthoc_weight": float(args.posthoc_weight),
            "devig_method": str(args.devig),
            "hac_lag": HAC_LAG,
            "fits": period_fits,
        },
        "scores": scores,
        "pooled_comparison": _compare(losses["blend_fit"], losses["xg_adjust"], int(args.seed)),
        "pooled_blend_vs_dixon_coles": _compare(
            losses["blend_fit"], losses["dixon_coles"], int(args.seed)
        ),
        "pooled_xg_adjust_vs_dixon_coles": _compare(
            losses["xg_adjust"], losses["dixon_coles"], int(args.seed)
        ),
        "pooled_blend_vs_market": _compare(losses["blend_fit"], losses["market"], int(args.seed)),
        "per_period": period_comparisons,
        "gap_to_market": {
            "dixon_coles": round(dc_gap, 6),
            "xg_adjust": round(xg_gap, 6),
            "blend_fit": round(blend_gap, 6),
            "xg_adjust_relative_reduction_percent": round((dc_gap - xg_gap) / dc_gap * 100.0, 4)
            if dc_gap
            else None,
            "blend_fit_relative_reduction_percent": round((dc_gap - blend_gap) / dc_gap * 100.0, 4)
            if dc_gap
            else None,
        },
        "per_division": _group_deltas(pooled, "division"),
        "per_season": _group_deltas(pooled, "season"),
    }


def render_markdown(report: Dict[str, object]) -> str:
    dataset = report["dataset"]  # type: ignore[index]
    protocol = report["protocol"]  # type: ignore[index]
    scores = report["scores"]  # type: ignore[index]
    pooled = report["pooled_comparison"]  # type: ignore[index]
    gap = report["gap_to_market"]  # type: ignore[index]
    nw = pooled["diebold_mariano_newey_west"]  # type: ignore[index]
    lag1 = pooled["diebold_mariano_lag1"]  # type: ignore[index]
    fits = protocol["fits"]  # type: ignore[index]
    fit_counts = " + ".join(f"{name} {values['fit_count']}" for name, values in fits.items())
    fit_failures = sum(
        int(values["fit_failures"]) + int(values["blend_fit_failures"])
        for values in fits.values()
    )
    fit_fallbacks = sum(int(values["blend_fit_fallbacks"]) for values in fits.values())

    lines = [
        "# Diebold-Mariano cumulé — blend vs ajustement xG",
        "",
        "Rapport généré par `ai-service/scripts/evaluate_xgabora_xg_blend_pooled.py`.",
        "",
        "Les deux périodes du walk-forward sont rejouées avec leurs réentraînements "
        "d'origine, puis leurs pertes par rencontre sont concaténées pour un seul test.",
        "",
        "## Périmètre",
        "",
        f"- Validation : **{protocol['validation']}**, réentraînement tous les "
        f"{protocol['validation_refit_every']} matchs — {dataset['validation_matches']} rencontres.",
        f"- Test : **{protocol['test']}**, réentraînement tous les {protocol['refit_every']} matchs — "
        f"{dataset['test_matches']} rencontres.",
        f"- Cumul : **{dataset['matches_used']}** rencontres disjointes "
        f"({dataset['first_date']} → {dataset['last_date']}), "
        f"doublons : {dataset['duplicate_matches']}.",
        f"- Divisions : {', '.join(dataset['divisions'])}.",
        f"- `blend_fit` : {protocol['blend_xg_weight']:.2f} × xG dans la vraisemblance ; "
        f"`xg_adjust` : poids {protocol['posthoc_weight']:.2f}.",
        f"- Ajustements : {fit_counts} ; {fit_failures} échec(s) ; {fit_fallbacks} repli(s) "
        "du fit blend.",
        "",
        "## Résultat cumulé",
        "",
        "| Modèle | Log loss | Brier | RPS | ECE | Précision 1N2 |",
        "| --- | ---: | ---: | ---: | ---: | ---: |",
    ]
    for model, label in (
        ("dixon_coles", "Dixon-Coles pur"),
        ("xg_adjust", "xG a posteriori (0,25)"),
        ("blend_fit", "Blend dans le fit (0,5 xG)"),
        ("market", "Marché dé-vigé"),
    ):
        values = scores[model]  # type: ignore[index]
        lines.append(
            f"| {label} | {values['log_loss']:.4f} | {values['brier']:.4f} | "
            f"{values['rps']:.4f} | {values['ece']:.4f} | {values['accuracy_1n2']:.4f} |"
        )

    lines += [
        "",
        "### Blend vs ajustement xG",
        "",
        f"- Gain cumulé (xG − blend) : **{pooled['gain_first_over_second']:+.6f}** de log loss.",
        f"- IC 95 % bootstrap : "
        f"[{pooled['bootstrap_ci95']['ci95_low']:+.6f} ; {pooled['bootstrap_ci95']['ci95_high']:+.6f}].",
        f"- DM Newey-West lag 1 (convention du projet) : statistique "
        f"{lag1['dm_statistic']}, p = {lag1['p_value']}.",
        f"- DM Newey-West lag {nw['lag']} : statistique {nw['dm_statistic']}, p = {nw['p_value']}.",
        "",
        "| Période | N | Gain (xG − blend) | IC 95 % | DM lag 1 p | DM lag "
        f"{nw['lag']} p |",
        "| --- | ---: | ---: | ---: | ---: | ---: |",
    ]
    for name, values in report["per_period"].items():  # type: ignore[union-attr]
        lines.append(
            f"| {name} | {values['diebold_mariano_lag1']['sample_size']} | "
            f"{values['gain_first_over_second']:+.6f} | "
            f"[{values['bootstrap_ci95']['ci95_low']:+.6f} ; "
            f"{values['bootstrap_ci95']['ci95_high']:+.6f}] | "
            f"{values['diebold_mariano_lag1']['p_value']} | "
            f"{values['diebold_mariano_newey_west']['p_value']} |"
        )

    lines += [
        "",
        "### Autres comparaisons cumulées",
        "",
        "Gain = perte du second modèle − perte du premier ; positif = le second est meilleur.",
        "",
        "| Comparaison | Gain | IC 95 % | DM lag 1 p |",
        "| --- | ---: | ---: | ---: |",
    ]
    for key, label in (
        ("pooled_blend_vs_dixon_coles", "Blend vs Dixon-Coles"),
        ("pooled_xg_adjust_vs_dixon_coles", "xG a posteriori vs Dixon-Coles"),
        ("pooled_blend_vs_market", "Blend vs marché"),
    ):
        values = report[key]  # type: ignore[index]
        lines.append(
            f"| {label} | {values['gain_first_over_second']:+.6f} | "
            f"[{values['bootstrap_ci95']['ci95_low']:+.6f} ; "
            f"{values['bootstrap_ci95']['ci95_high']:+.6f}] | "
            f"{values['diebold_mariano_lag1']['p_value']} |"
        )

    for title, column, key in (
        ("## Par division (cumul)", "Division", "per_division"),
        ("## Par saison (cumul)", "Saison", "per_season"),
    ):
        lines += [
            "",
            title,
            "",
            f"| {column} | N | DC | xG a posteriori | Blend | Marché | Blend − xG a posteriori |",
            "| --- | ---: | ---: | ---: | ---: | ---: | ---: |",
        ]
        for name, values in report[key].items():  # type: ignore[union-attr]
            lines.append(
                f"| {name} | {values['sample_size']} | {values['dixon_coles']:.4f} | "
                f"{values['xg_adjust']:.4f} | {values['blend_fit']:.4f} | "
                f"{values['market']:.4f} | {values['blend_minus_xg_adjust']:+.4f} |"
            )

    lines += [
        "",
        "## Écart au marché (cumul)",
        "",
        f"- Dixon-Coles pur : **{gap['dixon_coles']:+.4f}**.",
        f"- xG a posteriori : **{gap['xg_adjust']:+.4f}** "
        f"({gap['xg_adjust_relative_reduction_percent']:+.2f} % de réduction).",
        f"- Blend dans le fit : **{gap['blend_fit']:+.4f}** "
        f"({gap['blend_fit_relative_reduction_percent']:+.2f} % de réduction).",
        "",
        "## Conclusion",
        "",
    ]

    gain = float(pooled["gain_first_over_second"])
    p_lag1 = float(lag1["p_value"])
    p_nw = float(nw["p_value"])
    if gain > 0 and p_lag1 < 0.05 and p_nw < 0.05:
        verdict = (
            f"Avec la puissance cumulée, l'avance du blend devient **significative** : "
            f"{gain:+.6f} de log loss, p = {p_lag1} (lag 1) et p = {p_nw} (lag {nw['lag']})."
        )
    elif gain > 0 and min(p_lag1, p_nw) < 0.06:
        verdict = (
            f"L'avance cumulée du blend reste **à la frontière** : {gain:+.6f} de log loss, "
            f"IC 95 % bootstrap "
            f"[{pooled['bootstrap_ci95']['ci95_low']:+.6f} ; "
            f"{pooled['bootstrap_ci95']['ci95_high']:+.6f}], "
            f"DM p = {p_lag1} (lag 1) et p = {p_nw} (lag {nw['lag']}). "
            "Selon la correction d'autocorrélation retenue, le seuil de 5 % est manqué de "
            "peu ou tout juste atteint : l'effet est cohérent mais trop petit pour être "
            "conclu sur ce seul cumul."
        )
    elif gain > 0:
        verdict = (
            f"Même cumulée sur {dataset['matches_used']} rencontres, l'avance du blend "
            f"reste **non significative** : {gain:+.6f} de log loss, p = {p_lag1} (lag 1), "
            f"p = {p_nw} (lag {nw['lag']}). L'effet est cohérent mais petit."
        )
    else:
        verdict = (
            f"Le blend ne devance pas l'ajustement xG sur le cumul : {gain:+.6f} de log loss, "
            f"p = {p_lag1} (lag 1), p = {p_nw} (lag {nw['lag']})."
        )
    lines += [verdict, ""]

    lines += [
        "## Limites",
        "",
        "- Le cumul additionne deux régimes de marché différents (2023/24 et 2024/25–2026/27) : "
        "un seul effet moyen est estimé, l'hétérogénéité entre périodes n'est pas modélisée.",
        "- Les paramètres sont partagés par blocs de 100 à 200 rencontres, donc les pertes sont "
        "corrélées au-delà du lag 1 ; d'où le contrôle à lag 5. Ici l'autocovariance estimée est "
        "légèrement négative : le lag 5 donne une variance un peu plus petite que le lag 1 "
        "(p = 0,050 contre 0,055), pas l'inverse.",
        "- Les rencontres d'un même jour restent corrélées entre elles (même journée, mêmes "
        "informations) ; ni l'un ni l'autre lag ne corrige cette dépendance transversale.",
        "- Le poids 0,25 de l'ajustement a posteriori a été choisi sur 2023/24 : la moitié "
        "validation du cumul est donc in-sample pour lui, ce qui défavorise le blend.",
        "- Le CSV xG est un miroir public dérivé d'Understat/FotMob, pas une collecte officielle live.",
        "",
    ]
    return "\n".join(lines)


def main() -> int:
    parser = argparse.ArgumentParser(
        description="DM cumulé validation + test : blend dans le fit vs ajustement xG."
    )
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
        "validation_matches": report["dataset"]["validation_matches"],  # type: ignore[index]
        "test_matches": report["dataset"]["test_matches"],  # type: ignore[index]
        "duplicate_matches": report["dataset"]["duplicate_matches"],  # type: ignore[index]
        "log_loss": {
            model: report["scores"][model]["log_loss"]  # type: ignore[index]
            for model in MODEL_KEYS
        },
        "pooled_comparison": report["pooled_comparison"],
    }, indent=2, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
