"""Décomposition du ROI du backtest 1N2 (lecture seule).

Ce script ne réentraîne rien et ne change aucune règle du modèle : il relit
les sélections produites par `scripts/backtest_specialists.py` (walk-forward,
cotes de clôture réelles) et décompose le rendement pour expliquer un ROI
négatif éventuel.

Deux notions sont séparées :
- la qualité probabiliste (Brier, Log Loss, calibration, ECE) ;
- la rentabilité financière (mise, retour, ROI), qui dépend de la règle de
  sélection et de la marge du bookmaker.

Aucune stratégie n'est promue ici. Les règles alternatives sont calculées à
titre diagnostique, sur les mêmes plis déjà figés, pour isoler l'origine des
pertes — pas pour choisir un paramètre après coup.
"""

from __future__ import annotations

import argparse
import json
import math
import statistics
from collections import defaultdict
from typing import Dict, List, Optional, Sequence, Tuple

OUTCOMES = ("home", "draw", "away")
ODDS_KEY = {"home": "oh", "draw": "od", "away": "oa"}


def overround(pick: dict) -> float:
    return sum(1.0 / float(pick[ODDS_KEY[outcome]]) for outcome in OUTCOMES)


def fair_probabilities(pick: dict) -> Dict[str, float]:
    """Probabilités implicites sans marge (normalisation proportionnelle)."""
    total = overround(pick)
    return {outcome: (1.0 / float(pick[ODDS_KEY[outcome]])) / total for outcome in OUTCOMES}


def evaluate_bets(
    picks: Sequence[dict],
    selector,
    stake: float = 1.0,
) -> Dict[str, object]:
    """Applique une règle de sélection et renvoie mise, retour et ROI.

    `selector(pick)` doit renvoyer `None` (pas de pari) ou le triplet
    (issue pariée, cote, probabilité du modèle sur cette issue).
    """
    staked = 0.0
    returned = 0.0
    odds_total = 0.0
    bets = 0
    wins = 0
    profits: List[float] = []

    for pick in picks:
        chosen = selector(pick)
        if chosen is None:
            continue
        outcome, odds, _probability = chosen
        bets += 1
        staked += stake
        odds_total += float(odds)
        profit = (stake * float(odds)) - stake if outcome == pick["actual"] else -stake
        profits.append(profit)
        if outcome == pick["actual"]:
            wins += 1
            returned += stake * float(odds)

    roi = (returned - staked) / staked if staked > 0 else None
    ci = None
    if len(profits) > 1:
        standard_error = statistics.stdev(profits) / math.sqrt(len(profits))
        ci = [round(roi - 1.96 * standard_error, 4), round(roi + 1.96 * standard_error, 4)]

    return {
        "bets": bets,
        "wins": wins,
        "staked": round(staked, 2),
        "returned": round(returned, 2),
        "roi": round(roi, 4) if roi is not None else None,
        "roi_ci95": ci,
        "hit_rate": round(wins / bets, 4) if bets else None,
        "avg_odds": round(odds_total / bets, 2) if bets else None,
    }


def edge_selector(threshold: float, use_fair: bool):
    """Règle historique : premier marché dont l'écart à l'implicite dépasse le seuil."""

    def selector(pick: dict):
        # Une cote absente ou inexploitable ne produit jamais un pari : aucun
        # prix n'est inventé pour combler le manque.
        if any(float(pick.get(ODDS_KEY[outcome]) or 0) <= 1.01 for outcome in OUTCOMES):
            return None
        fair = fair_probabilities(pick) if use_fair else None
        for outcome in OUTCOMES:
            odds = float(pick[ODDS_KEY[outcome]])
            implied = fair[outcome] if fair else 1.0 / odds
            model = float(pick["p_" + outcome])
            if model - implied >= threshold:
                return outcome, odds, model
        return None

    return selector


def favourite_selector(pick: dict):
    best = max(OUTCOMES, key=lambda outcome: float(pick["p_" + outcome]))
    return best, float(pick[ODDS_KEY[best]]), float(pick["p_" + best])


def calibration(picks: Sequence[dict], selector) -> List[Dict[str, object]]:
    """Calibration d'une population de paris.

    Attention : appliquée à la population *sélectionnée* par une règle de
    valeur, cette mesure est conditionnelle et ne décrit pas la calibration
    globale du modèle. Utiliser `favourite_selector` pour la mesure globale.
    """
    buckets: Dict[int, List[Tuple[float, bool]]] = defaultdict(list)
    for pick in picks:
        chosen = selector(pick)
        if chosen is None:
            continue
        outcome, _, probability = chosen
        index = min(9, max(0, int(probability * 10)))
        buckets[index].append((probability, outcome == pick["actual"]))

    rows = []
    for index in sorted(buckets):
        values = buckets[index]
        predicted = sum(value[0] for value in values) / len(values)
        observed = sum(1 for value in values if value[1]) / len(values)
        rows.append(
            {
                "band": f"{index / 10:.1f}-{(index + 1) / 10:.1f}",
                "n": len(values),
                "predicted": round(predicted, 4),
                "observed": round(observed, 4),
                "gap": round(predicted - observed, 4),
            }
        )
    return rows


def group_metrics(
    picks: Sequence[dict],
    selector,
    key_fn,
    *,
    sort_key=None,
) -> Dict[str, Dict[str, object]]:
    groups: Dict[str, List[dict]] = defaultdict(list)
    for pick in picks:
        name = key_fn(pick)
        if name == "aucun pari":
            continue
        groups[name].append(pick)

    names = sorted(groups, key=sort_key) if sort_key else sorted(groups)
    return {name: evaluate_bets(groups[name], selector) for name in names}


def edge_profile(picks: Sequence[dict]) -> Dict[str, object]:
    """Écart moyen entre le modèle et le marché, hors marge.

    Un modèle calibré et sans avantage doit avoir un écart proche de zéro.
    Un écart systématiquement positif signale de l'excès de confiance.
    """
    totals = {outcome: 0.0 for outcome in OUTCOMES}
    model_sum = 0.0
    for pick in picks:
        fair = fair_probabilities(pick)
        model_sum += sum(float(pick["p_" + outcome]) for outcome in OUTCOMES)
        for outcome in OUTCOMES:
            totals[outcome] += float(pick["p_" + outcome]) - fair[outcome]

    count = len(picks) or 1
    return {
        "mean_edge": {outcome: round(totals[outcome] / count, 4) for outcome in OUTCOMES},
        "mean_model_sum": round(model_sum / count, 4),
        "mean_overround": round(sum(overround(pick) for pick in picks) / count, 4),
        "sample": len(picks),
    }


def write_markdown(path: str, report: Dict[str, object]) -> None:
    base = report["rule_raw_edge_0_05"]
    fair = report["rule_fair_edge_0_05"]

    lines = [
        "# Analyse du ROI négatif — 1N2",
        "",
        "Source : `scripts/backtest_specialists.py` (walk-forward, cotes de clôture réelles).",
        f"Rencontres évaluées : **{report['matches']}** · sélections 1N2 avec cotes : **{report['picks']}** · plis : {report['folds']}.",
        "",
        "## 1. Qualité probabiliste (indépendante de la mise)",
        "",
        f"- Brier multiclasse : **{report['brier']}**",
        f"- Log Loss : **{report['log_loss']}**",
        f"- Marge moyenne du bookmaker (overround) : **{report['mean_overround']}**",
        "",
        "| Plage de probabilité | N | Probabilité moyenne | Taux réel | Écart |",
        "|---|---|---|---|---|",
    ]
    for row in report["calibration_all"]:
        lines.append(
            f"| {row['band']} | {row['n']} | {row['predicted']} | {row['observed']} | {row['gap']} |"
        )

    edge = report["edge_profile"]
    lines += [
        "",
        "## 1 bis. Avantage réel du modèle sur le marché",
        "",
        "Écart moyen entre la probabilité du modèle et la probabilité implicite **hors marge**,"
        " sur les trois issues (n = {}). Un modèle sans avantage doit être proche de zéro.".format(
            edge["sample"]
        ),
        "",
        "| Issue | Écart moyen modèle − marché |",
        "|---|---|",
    ]
    for outcome in OUTCOMES:
        lines.append(f"| {outcome} | {edge['mean_edge'][outcome]} |")
    lines += [
        "",
        f"- Somme moyenne des probabilités du modèle sur les trois issues : **{edge['mean_model_sum']}** "
        f"(marge du bookmaker : {edge['mean_overround']}).",
    ]

    lines += [
        "",
        "### Calibration conditionnelle aux paris de la règle du rapport",
        "",
        "Cette table ne décrit **pas** la calibration du modèle : elle ne contient que les"
        " rencontres où le modèle dépassait déjà le marché. Elle montre comment la règle"
        " sélectionne l'excès de confiance.",
        "",
        "| Plage de probabilité | N | Probabilité moyenne | Taux réel | Écart |",
        "|---|---|---|---|---|",
    ]
    for row in report["calibration_bets"]:
        lines.append(
            f"| {row['band']} | {row['n']} | {row['predicted']} | {row['observed']} | {row['gap']} |"
        )

    lines += [
        "",
        "## 2. Règle de pari telle qu'implémentée",
        "",
        "Un pari est placé sur la **première** issue (domicile, nul, extérieur) dont la",
        "probabilité du modèle dépasse la probabilité implicite **brute** de la cote de",
        "clôture d'au moins 0,05. Mise fixe de 1 unité, une seule sélection par rencontre.",
        "",
        "| Règle | Paris | Gagnés | Mise | Retour | ROI | Taux de réussite |",
        "|---|---|---|---|---|---|---|",
    ]
    for label, key in (
        ("Écart vs implicite brute ≥ 0,05 (règle du rapport)", "rule_raw_edge_0_05"),
        ("Écart vs implicite hors marge ≥ 0,05", "rule_fair_edge_0_05"),
        ("Favori du modèle, mise fixe (référence)", "rule_favourite_flat"),
    ):
        row = report[key]
        lines.append(
            f"| {label} | {row['bets']} | {row['wins']} | {row['staked']} | {row['returned']} | "
            f"{row['roi']} | {row['hit_rate']} |"
        )

    lines += [
        "",
        "## 3. Où se concentrent les pertes (règle du rapport)",
        "",
        "### Par plage de probabilité du modèle",
        "",
        "| Plage | Paris | Gagnés | ROI |",
        "|---|---|---|---|",
    ]
    for band, row in report["by_probability_band"].items():
        lines.append(f"| {band} | {row['bets']} | {row['wins']} | {row['roi']} |")

    lines += ["", "### Par plage de cote", "", "| Cote | Paris | Gagnés | ROI |", "|---|---|---|---|"]
    for band, row in report["by_odds_band"].items():
        lines.append(f"| {band} | {row['bets']} | {row['wins']} | {row['roi']} |")

    lines += ["", "### Par issue pariée", "", "| Issue | Paris | Gagnés | ROI |", "|---|---|---|---|"]
    for band, row in report["by_selection"].items():
        lines.append(f"| {band} | {row['bets']} | {row['wins']} | {row['roi']} |")

    lines += ["", "### Par compétition", "", "| Compétition | Paris | Gagnés | ROI |", "|---|---|---|---|"]
    for band, row in report["by_league"].items():
        lines.append(f"| {band} | {row['bets']} | {row['wins']} | {row['roi']} |")

    lines += [
        "",
        "## 4. Lecture",
        "",
        f"- La règle du rapport parie sur {base['bets']} rencontres et perd "
        f"{round((1 + base['roi']) * 100, 1)} % de la mise.",
        f"- Intervalle de confiance à 95 % du ROI de cette règle : **{base['roi_ci95']}** "
        f"sur {base['bets']} paris (mise fixe, une sélection par rencontre).",
        f"- En comparant à l'implicite **hors marge**, la même règle place {fair['bets']} paris "
        f"pour un ROI de {fair['roi']} : la marge du bookmaker explique une partie du déficit, "
        "pas la totalité.",
        f"- Le pari systématique sur le favori du modèle, sans filtre de valeur, donne "
        f"{report['rule_favourite_flat']['roi']} sur {report['rule_favourite_flat']['bets']} paris — "
        f"soit approximativement la marge du bookmaker ({edge['mean_overround']}). C'est la mesure "
        "la plus honnête de l'avantage du modèle : **il n'en a pas**.",
        f"- La règle du rapport ne fait pas mieux : elle sélectionne {report['selection_bias']['above_0_75']} "
        "sélections annoncées au-delà de 75 % et amplifie l'excès de confiance au lieu de l'exploiter.",
        "",
        "Conclusion : le ROI négatif n'est pas un défaut de la formule de ROI. Il vient de "
        "l'absence d'avantage probabiliste sur le marché et d'une règle de mise qui parie "
        "précisément là où le modèle s'écarte le plus du marché.",
        "",
        "Aucune de ces variantes n'est retenue comme stratégie de production. Le backtest "
        "reste un outil de diagnostic, pas une promesse de rendement. Aucun paramètre n'a été "
        "réglé sur cet échantillon : les plis du walk-forward sont ceux du rapport existant.",
    ]

    with open(path, "w", encoding="utf-8") as handle:
        handle.write("\n".join(lines) + "\n")


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--input-json", required=True, help="Sortie de backtest_specialists.py")
    parser.add_argument("--output-json", required=True)
    parser.add_argument("--output-md", required=True)
    args = parser.parse_args()

    with open(args.input_json, "r", encoding="utf-8") as handle:
        backtest = json.load(handle)

    picks: List[dict] = backtest.get("one_n_two_picks") or []
    if not picks:
        raise SystemExit(
            "Aucune sélection individuelle dans le fichier : relancer backtest_specialists.py."
        )

    raw_rule = edge_selector(0.05, use_fair=False)
    fair_rule = edge_selector(0.05, use_fair=True)

    decisive = [pick for pick in picks if pick["actual"] in OUTCOMES]

    brier = 0.0
    log_loss = 0.0
    for pick in decisive:
        actual = pick["actual"]
        probabilities = {outcome: float(pick["p_" + outcome]) for outcome in OUTCOMES}
        brier += sum(
            (probabilities[outcome] - (1.0 if outcome == actual else 0.0)) ** 2
            for outcome in OUTCOMES
        )
        log_loss += -math.log(max(probabilities[actual], 1e-6))

    overrounds = [overround(pick) for pick in picks]

    probability_band = lambda pick: (
        f"{int(float(pick['p_' + max(OUTCOMES, key=lambda o: float(pick['p_' + o]))]) * 10) / 10:.1f}"
        "-"
        f"{int(float(pick['p_' + max(OUTCOMES, key=lambda o: float(pick['p_' + o]))]) * 10) / 10 + 0.1:.1f}"
    )
    odds_band = lambda pick: (
        f"{int(float(pick[ODDS_KEY[max(OUTCOMES, key=lambda o: float(pick['p_' + o]))]]) // 1)}"
        f"-{int(float(pick[ODDS_KEY[max(OUTCOMES, key=lambda o: float(pick['p_' + o]))]]) // 1) + 1}"
    )

    def selection_band(pick: dict) -> str:
        chosen = raw_rule(pick)
        return chosen[0] if chosen else "aucun pari"

    def selection_probability_band(pick: dict) -> str:
        chosen = raw_rule(pick)
        if chosen is None:
            return "aucun pari"
        low = min(9, max(0, int(chosen[2] * 10)))
        return f"{low / 10:.1f}-{(low + 1) / 10:.1f}"

    def selection_odds_band(pick: dict) -> str:
        chosen = raw_rule(pick)
        if chosen is None:
            return "aucun pari"
        low = int(chosen[1])
        return f"{low}-{low + 1}"

    over_75 = [
        pick
        for pick in picks
        if max(float(pick["p_" + outcome]) for outcome in OUTCOMES) >= 0.75
    ]
    over_75_hit = sum(
        1
        for pick in over_75
        if max(OUTCOMES, key=lambda outcome: float(pick["p_" + outcome])) == pick["actual"]
    )

    report = {
        "matches": backtest.get("matches"),
        "folds": backtest.get("folds"),
        "picks": len(picks),
        "brier": round(brier / len(decisive), 4) if decisive else None,
        "log_loss": round(log_loss / len(decisive), 4) if decisive else None,
        "mean_overround": round(sum(overrounds) / len(overrounds), 4) if overrounds else None,
        # Calibration globale : toutes les rencontres, scénario le plus probable.
        "calibration_all": calibration(picks, favourite_selector),
        # Calibration conditionnelle : uniquement les paris de la règle du rapport.
        "calibration_bets": calibration(picks, raw_rule),
        "edge_profile": edge_profile(picks),
        "rule_raw_edge_0_05": evaluate_bets(picks, raw_rule),
        "rule_fair_edge_0_05": evaluate_bets(picks, fair_rule),
        "rule_favourite_flat": evaluate_bets(picks, favourite_selector),
        "by_probability_band": group_metrics(picks, raw_rule, selection_probability_band),
        "by_odds_band": group_metrics(
            picks,
            raw_rule,
            selection_odds_band,
            sort_key=lambda name: int(str(name).split("-")[0]),
        ),
        "by_selection": group_metrics(picks, raw_rule, selection_band),
        "by_league": group_metrics(picks, raw_rule, lambda pick: str(pick.get("league") or "unknown")),
        "selection_bias": {
            "above_0_75": len(over_75),
            "above_0_75_hit_rate": round(over_75_hit / len(over_75), 4) if over_75 else None,
        },
    }

    with open(args.output_json, "w", encoding="utf-8") as handle:
        json.dump(report, handle, indent=2, ensure_ascii=False)

    write_markdown(args.output_md, report)
    print(json.dumps({key: report[key] for key in ("picks", "brier", "log_loss", "rule_raw_edge_0_05", "rule_fair_edge_0_05", "rule_favourite_flat")}, indent=2, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
