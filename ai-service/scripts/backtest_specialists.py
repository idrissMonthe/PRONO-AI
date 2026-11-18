"""Backtest chronologique des marchés corners, cartons et 1N2.

Protocole strictement walk-forward : chaque lot de rencontres est évalué avec
un modèle réentraîné uniquement sur les rencontres antérieures. Aucune cote
n'est utilisée pour produire une probabilité, seulement pour chiffrer un ROI
sur les marchés où une cote de clôture réelle existe.
"""
from __future__ import annotations

import argparse
import json
import math
from collections import defaultdict
from datetime import date
from typing import Dict, List, Optional, Tuple

import numpy as np
from scipy.stats import nbinom, poisson

from app.core.poisson_regressor import DixonColesModel, MatchData


def binary_metrics(probabilities: List[float], outcomes: List[bool]) -> Dict[str, Optional[float]]:
    if not probabilities:
        return {"n": 0, "brier": None, "log_loss": None, "hit_rate": None, "ece": None}
    p = np.clip(np.asarray(probabilities, dtype=float), 1e-6, 1 - 1e-6)
    y = np.asarray(outcomes, dtype=float)
    brier = float(np.mean((p - y) ** 2))
    log_loss = float(-np.mean(y * np.log(p) + (1 - y) * np.log(1 - p)))

    # ECE sur 10 tranches de probabilité.
    buckets = np.clip((p * 10).astype(int), 0, 9)
    ece = 0.0
    for bucket in range(10):
        mask = buckets == bucket
        if not mask.any():
            continue
        ece += float(mask.sum()) / len(p) * abs(float(p[mask].mean()) - float(y[mask].mean()))

    return {
        "n": int(len(p)),
        "brier": round(brier, 4),
        "log_loss": round(log_loss, 4),
        "hit_rate": round(float(y.mean()), 4),
        "ece": round(ece, 4),
    }


class CountModel:
    """Modèle de comptage (corners, cartons) à effets d'équipe amortis."""

    def __init__(self, prior: float = 5.0) -> None:
        self.prior = prior
        self.mean = 10.0
        self.for_rates: Dict[str, float] = {}
        self.against_rates: Dict[str, float] = {}
        self.away_share = 0.5

    def fit(self, rows: List[dict], home_key: str, away_key: str) -> "CountModel":
        totals = [row[home_key] + row[away_key] for row in rows]
        self.mean = float(np.mean(totals)) if totals else 0.0
        summed_for: Dict[str, float] = defaultdict(float)
        summed_against: Dict[str, float] = defaultdict(float)
        counts: Dict[str, int] = defaultdict(int)
        home_mass = 0.0
        away_mass = 0.0

        for row in rows:
            home_team, away_team = row["home"], row["away"]
            home_value, away_value = float(row[home_key]), float(row[away_key])
            summed_for[home_team] += home_value
            summed_against[away_team] += home_value
            summed_for[away_team] += away_value
            summed_against[home_team] += away_value
            counts[home_team] += 1
            counts[away_team] += 1
            home_mass += home_value
            away_mass += away_value

        base_side = max(0.2, self.mean / 2.0)
        for team, appearances in counts.items():
            self.for_rates[team] = (summed_for[team] + self.prior * base_side) / (
                appearances * base_side + self.prior * base_side
            )
            self.against_rates[team] = (summed_against[team] + self.prior * base_side) / (
                appearances * base_side + self.prior * base_side
            )

        self.away_share = away_mass / (home_mass + away_mass) if (home_mass + away_mass) > 0 else 0.5
        return self

    def lambda_home(self, home_team: str, away_team: str) -> float:
        attack = self.for_rates.get(home_team, 1.0)
        defence = self.against_rates.get(away_team, 1.0)
        return max(0.05, self.mean * (1.0 - self.away_share) * attack * defence)

    def lambda_away(self, home_team: str, away_team: str) -> float:
        attack = self.for_rates.get(away_team, 1.0)
        defence = self.against_rates.get(home_team, 1.0)
        return max(0.05, self.mean * self.away_share * attack * defence)


def poisson_total_distribution(home_rate: float, away_rate: float, size: int = 40) -> np.ndarray:
    goals = np.arange(size)
    home = poisson.pmf(goals, home_rate)
    away = poisson.pmf(goals, away_rate)
    totals = np.convolve(home, away)
    return totals / totals.sum()


def negbin_total_distribution(home_rate: float, away_rate: float, dispersion: float, size: int = 40) -> np.ndarray:
    """Binomiale négative : variance = mu + mu^2 / k, k = dispersion."""
    k = max(1e-3, dispersion)
    goals = np.arange(size)
    home = nbinom.pmf(goals, k, k / (k + home_rate))
    away = nbinom.pmf(goals, k, k / (k + away_rate))
    totals = np.convolve(home, away)
    return totals / totals.sum()


def over_probability(distribution: np.ndarray, line: float) -> float:
    goals = np.arange(len(distribution))
    return float(distribution[goals > line].sum())


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--dataset", required=True)
    parser.add_argument("--min-train", type=int, default=200)
    parser.add_argument("--step", type=int, default=25)
    parser.add_argument("--output-json", required=True)
    parser.add_argument("--output-md", required=True)
    args = parser.parse_args()

    with open(args.dataset, "r", encoding="utf-8") as handle:
        rows = json.load(handle)

    rows = [row for row in rows if row.get("hc") is not None and row.get("hg") is not None]
    rows.sort(key=lambda row: (row["date"], row["home"]))
    if len(rows) < args.min_train + args.step:
        raise SystemExit(f"Historique insuffisant : {len(rows)} rencontres.")

    reference = max(date.fromisoformat(row["date"]) for row in rows)

    corners: Dict[str, List] = defaultdict(list)
    cards: Dict[str, List] = defaultdict(list)
    one_n_two: Dict[str, List] = defaultdict(list)
    dispersion_samples: List[float] = []

    index = args.min_train
    folds = 0
    while index < len(rows):
        train = rows[:index]
        test = rows[index:index + args.step]
        index += args.step
        folds += 1

        corner_model = CountModel(prior=4.0).fit(train, "hc", "ac")
        card_model = CountModel(prior=4.0).fit(train, "hy", "ay")

        goals_train = [
            MatchData(
                home_team=row["home"],
                away_team=row["away"],
                home_goals=int(row["hg"]),
                away_goals=int(row["ag"]),
                days_ago=float((reference - date.fromisoformat(row["date"])).days),
            )
            for row in train
        ]
        goals_model = DixonColesModel()
        try:
            goals_model.fit(goals_train)
        except (RuntimeError, ValueError):
            goals_model = None

        # Dispersion des corners observée sur l'historique, pour la binomiale négative.
        residuals = []
        for row in train:
            predicted = corner_model.lambda_home(row["home"], row["away"]) + corner_model.lambda_away(row["home"], row["away"])
            observed = row["hc"] + row["ac"]
            if predicted > 0:
                residuals.append((observed - predicted) ** 2 / predicted)
        dispersion = float(np.mean(residuals)) if residuals else 1.0
        dispersion_samples.append(dispersion)

        for row in test:
            total_corners = row["hc"] + row["ac"]
            total_cards = row["hy"] + row["ay"]
            home_rate = corner_model.lambda_home(row["home"], row["away"])
            away_rate = corner_model.lambda_away(row["home"], row["away"])
            distribution = poisson_total_distribution(home_rate, away_rate)
            nb_distribution = negbin_total_distribution(home_rate, away_rate, dispersion)

            for line in (8.5, 9.5, 10.5):
                corners["poisson"].append((over_probability(distribution, line), total_corners > line))
                corners["negbin"].append((over_probability(nb_distribution, line), total_corners > line))
                corners["baseline"].append(
                    (over_probability(poisson_total_distribution(corner_model.mean / 2, corner_model.mean / 2), line),
                     total_corners > line)
                )

            card_home = card_model.lambda_home(row["home"], row["away"])
            card_away = card_model.lambda_away(row["home"], row["away"])
            card_distribution = poisson_total_distribution(card_home, card_away)
            for line in (2.5, 3.5, 4.5):
                cards["poisson"].append((over_probability(card_distribution, line), total_cards > line))
                cards["baseline"].append(
                    (over_probability(poisson_total_distribution(card_model.mean / 2, card_model.mean / 2), line),
                     total_cards > line)
                )

            # 1N2 : probabilités du modèle de buts, cotes de clôture réelles.
            if goals_model is None or not all(row.get(key) for key in ("oh", "od", "oa")):
                continue
            home_rate_goals, away_rate_goals, _ = goals_model.predict_lambdas(row["home"], row["away"])
            from app.core.dixon_coles import compute_score_matrix, extract_match_markets_from_matrix

            matrix, _, _ = compute_score_matrix(home_rate_goals, away_rate_goals, goals_model.rho)
            probabilities = extract_match_markets_from_matrix(matrix)["1N2"]
            actual = "home" if row["hg"] > row["ag"] else ("draw" if row["hg"] == row["ag"] else "away")
            one_n_two["model"].append({
                "p_home": float(probabilities["home"]),
                "p_draw": float(probabilities["draw"]),
                "p_away": float(probabilities["away"]),
                "oh": float(row["oh"]),
                "od": float(row["od"]),
                "oa": float(row["oa"]),
                "actual": actual,
                "league": row.get("league") or "unknown",
            })

    corner_results = {
        key: binary_metrics([value[0] for value in values], [value[1] for value in values])
        for key, values in corners.items()
    }
    card_results = {
        key: binary_metrics([value[0] for value in values], [value[1] for value in values])
        for key, values in cards.items()
    }

    # 1N2 : Brier multiclasse et ROI sur cotes de clôture Bet365.
    picks = one_n_two["model"]
    one_n_two_result: Dict[str, Optional[float]] = {"n": len(picks)}
    if picks:
        brier = 0.0
        log_loss = 0.0
        staked = 0.0
        returned = 0.0
        bets = 0
        for pick in picks:
            probabilities = {"home": pick["p_home"], "draw": pick["p_draw"], "away": pick["p_away"]}
            actual = pick["actual"]
            brier += sum(
                (probabilities[outcome] - (1.0 if outcome == actual else 0.0)) ** 2
                for outcome in ("home", "draw", "away")
            )
            log_loss += -math.log(max(probabilities[actual], 1e-6))

            # Mise uniquement si le modèle dépasse la probabilité implicite.
            for outcome, odds_key in (("home", "oh"), ("draw", "od"), ("away", "oa")):
                implied = 1.0 / pick[odds_key]
                if probabilities[outcome] - implied >= 0.05:
                    bets += 1
                    staked += 1.0
                    if outcome == actual:
                        returned += pick[odds_key]
                    break

        one_n_two_result.update({
            "brier": round(brier / len(picks), 4),
            "log_loss": round(log_loss / len(picks), 4),
            "bets": bets,
            "roi": round((returned - staked) / staked, 4) if staked > 0 else None,
        })

        # Performances par compétition : base d'une éventuelle approche hybride.
        by_league = {}
        for league in sorted({pick["league"] for pick in picks}):
            subset = [pick for pick in picks if pick["league"] == league]
            league_brier = 0.0
            league_log_loss = 0.0
            for pick in subset:
                probabilities = {"home": pick["p_home"], "draw": pick["p_draw"], "away": pick["p_away"]}
                actual = pick["actual"]
                league_brier += sum(
                    (probabilities[outcome] - (1.0 if outcome == actual else 0.0)) ** 2
                    for outcome in ("home", "draw", "away")
                )
                league_log_loss += -math.log(max(probabilities[actual], 1e-6))
            by_league[league] = {
                "n": len(subset),
                "brier": round(league_brier / len(subset), 4),
                "log_loss": round(league_log_loss / len(subset), 4),
            }
        one_n_two_result["by_league"] = by_league

    report = {
        "dataset": args.dataset,
        "matches": len(rows),
        "folds": folds,
        "min_train": args.min_train,
        "step": args.step,
        "corner_dispersion": round(float(np.mean(dispersion_samples)), 4) if dispersion_samples else None,
        "corners": corner_results,
        "cards": card_results,
        "one_n_two": one_n_two_result,
    }

    with open(args.output_json, "w", encoding="utf-8") as handle:
        json.dump(report, handle, indent=2, ensure_ascii=False)

    with open(args.output_md, "w", encoding="utf-8") as handle:
        handle.write("# Backtest chronologique — marchés spécialisés\n\n")
        handle.write(f"- Rencontres évaluées : **{len(rows)}** (dont {folds} plis walk-forward)\n")
        handle.write(f"- Entraînement minimal : {args.min_train} rencontres · pas : {args.step}\n")
        handle.write(f"- Dispersion (corners, binomiale négative) : {report['corner_dispersion']}\n\n")
        handle.write("## Corners — Plus/Moins\n\n")
        handle.write("| Modèle | N | Brier | Log Loss | ECE | Taux réel |\n|---|---|---|---|---|---|\n")
        for key, value in corner_results.items():
            handle.write(f"| {key} | {value['n']} | {value['brier']} | {value['log_loss']} | {value['ece']} | {value['hit_rate']} |\n")
        handle.write("\n## Cartons — Plus/Moins\n\n")
        handle.write("| Modèle | N | Brier | Log Loss | ECE | Taux réel |\n|---|---|---|---|---|---|\n")
        for key, value in card_results.items():
            handle.write(f"| {key} | {value['n']} | {value['brier']} | {value['log_loss']} | {value['ece']} | {value['hit_rate']} |\n")
        handle.write("\n## 1N2 avec cotes de clôture réelles\n\n")
        handle.write(f"- Rencontres avec cotes : {one_n_two_result['n']}\n")
        if one_n_two_result.get("brier") is not None:
            handle.write(f"- Brier multiclasse : {one_n_two_result['brier']}\n")
            handle.write(f"- Log Loss : {one_n_two_result['log_loss']}\n")
            handle.write(f"- Paris simulés (écart ≥ 0,05) : {one_n_two_result['bets']}\n")
            handle.write(f"- ROI : {one_n_two_result['roi']}\n")
        else:
            handle.write("- Aucune cote de clôture exploitable : ROI non calculable.\n")

        if one_n_two_result.get("by_league"):
            handle.write("\n## 1N2 par compétition\n\n| Compétition | N | Brier | Log Loss |\n|---|---|---|---|\n")
            for league, value in one_n_two_result["by_league"].items():
                handle.write(f"| {league} | {value['n']} | {value['brier']} | {value['log_loss']} |\n")

    print(json.dumps(report, indent=2, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
