"""Moteur de génération des marchés à partir des distributions statistiques.

Le moteur ne crée pas un modèle par ligne : chaque famille dispose d'une
distribution (matrice de scores, loi de comptage, distribution par période) et
les variantes de lignes en sont dérivées. Une famille dont les données sont
absentes ne produit aucune sélection.
"""
from __future__ import annotations

from typing import Any, Dict, Iterable, List, Optional, Sequence

import numpy as np

from app.core.count_models import (
    CountProfile,
    count_distribution,
    difference_distribution,
    expected_win_probability,
    fit_profile,
    generate_lines,
    margin_settlement,
    settle_over_under,
)
from app.core.dixon_coles import compute_score_matrix, extract_match_markets_from_matrix
from app.core.market_registry import REGISTRY, market
from app.core.period_model import PeriodModel, period_markets
from app.core.selection_engine import reliability_score
from app.core.time_events import goal_time_markets, goal_first_team_markets

DEFAULT_CALIBRATION = 0.5
PUBLISH_FLOOR = 0.10
PUBLISH_CEILING = 0.94


def _fmt_line(line: float) -> str:
    return str(int(line)) if float(line).is_integer() else str(line).replace(".", ",")


class MarketEngine:
    def __init__(self, calibration: Optional[Dict[str, float]] = None) -> None:
        self.calibration = calibration or {}
        self.predictions: List[Dict[str, Any]] = []
        self.report: Dict[str, Dict[str, Any]] = {}

    # ------------------------------------------------------------------ API
    def generate(
        self,
        match_id: str,
        matrix: np.ndarray,
        lambda_home: float,
        lambda_away: float,
        rho: float,
        context: Optional[Dict[str, Any]] = None,
        period_model: Optional[PeriodModel] = None,
    ) -> Dict[str, Any]:
        context = context or {}
        self.predictions = []
        self.report = {}
        markets = extract_match_markets_from_matrix(matrix)

        self._result_markets(match_id, markets, matrix, context)
        self._goal_markets(match_id, markets, matrix, context)
        if period_model is not None:
            self._period_markets(match_id, lambda_home, lambda_away, rho, period_model, context)
        self._count_markets(match_id, context)
        self._player_markets(match_id, context, lambda_home, lambda_away)
        self._time_markets(match_id, lambda_home, lambda_away, context)

        selected = self._dedupe(self.predictions)
        return {
            "match_id": match_id,
            "predictions": selected,
            "coverage": self._coverage_summary(selected),
            "families": sorted({prediction["family"] for prediction in selected}),
        }

    # -------------------------------------------------------------- helpers
    def _coverage_summary(self, predictions: Sequence[Dict[str, Any]]) -> Dict[str, Any]:
        families = {}
        for prediction in predictions:
            entry = families.setdefault(
                prediction["family"],
                {"generated": 0, "coverage": prediction["coverage"], "market_status": prediction["market_status"]},
            )
            entry["generated"] += 1
        return {
            "families": families,
            "skipped": {key: value for key, value in self.report.items() if value.get("generated", 0) == 0},
        }

    def _dedupe(self, predictions: Sequence[Dict[str, Any]]) -> List[Dict[str, Any]]:
        seen = set()
        result = []
        for prediction in predictions:
            key = (prediction["market_id"], prediction["selection"], prediction.get("line"), prediction["period"])
            if key in seen:
                continue
            seen.add(key)
            result.append(prediction)
        return result

    def _add(
        self,
        match_id: str,
        market_id: str,
        selection: str,
        label: str,
        probability: Any,
        coverage: str,
        *,
        line: Optional[float] = None,
        sample_size: int = 0,
        uncertainty: float = 0.35,
        data_sources: Optional[List[str]] = None,
        justification: str = "",
        missing: Optional[List[str]] = None,
        status: Optional[str] = None,
        settlement: Optional[Dict[str, float]] = None,
    ) -> None:
        if not isinstance(probability, (int, float, np.floating)):
            return
        probability = float(probability)
        if not np.isfinite(probability) or probability < PUBLISH_FLOOR or probability > PUBLISH_CEILING:
            return
        definition = market(market_id)
        if definition is None:
            return
        if coverage == "none":
            return
        effective_status = status or definition.status
        if effective_status == "unsupported":
            return
        family = definition.family
        reliability = reliability_score(
            probability,
            coverage,
            float(self.calibration.get(family, DEFAULT_CALIBRATION)),
            uncertainty,
            sample_size if sample_size > 0 else 12,
        )
        if settlement is not None:
            settlement = {key: round(float(value), 4) for key, value in settlement.items()}
        self.predictions.append({
            "match_id": match_id,
            "market_id": market_id,
            "market_name": definition.name,
            "family": family,
            "family_label": family,
            "period": definition.period,
            "selection": selection,
            "selection_label": label,
            "line": line,
            "probability": round(probability, 4),
            "fair_odds": round(1.0 / probability, 3) if probability > 0 else None,
            "coverage": coverage,
            "sample_size": int(sample_size),
            "reliability": reliability,
            "uncertainty": round(float(uncertainty), 3),
            "model": definition.model,
            "market_status": effective_status,
            "data_sources": data_sources or [],
            "justification": justification,
            "missing_data": missing or [],
            "settlement": settlement,
        })

    def _mark(self, family: str, generated: int, reason: str = "") -> None:
        self.report[family] = {"generated": generated, "reason": reason}

    # --------------------------------------------------------- 1X2 & co
    def _result_markets(self, match_id: str, markets: Dict[str, Any], matrix: np.ndarray, context: Dict[str, Any]) -> None:
        names = context.get("team_names", {})
        home_name = names.get("home", "Domicile")
        away_name = names.get("away", "Extérieur")
        quality_note = "Distribution jointe des scores Dixon-Coles."
        for key, label in (("home", f"Victoire {home_name}"), ("draw", "Match nul"), ("away", f"Victoire {away_name}")):
            self._add(match_id, "1x2", key, label, markets["1N2"][key], "full",
                      sample_size=int(context.get("sample_size", 0) or 0), justification=quality_note)
        for key, label in (
            ("1X", f"{home_name} ou nul (1X)"),
            ("X2", f"Nul ou {away_name} (X2)"),
            ("12", "Pas de match nul (12)"),
        ):
            self._add(match_id, "double_chance", key, label, markets["double_chance"][key], "full",
                      justification="Somme des issues exclues de la matrice.")
        for side, team in (("home", home_name), ("away", away_name)):
            self._add(match_id, "draw_no_bet", side, f"Draw No Bet {team}", markets["dnb"][side], "full",
                      justification="Issue conditionnelle hors match nul.")
            self._add(match_id, "clean_sheet", side, f"{team} garde sa cage inviolée",
                      markets["clean_sheet"][side], "full")
            win_to_nil = float(np.sum(matrix[1:, 0])) if side == "home" else float(np.sum(matrix[0, 1:]))
            self._add(match_id, "win_to_nil", side, f"{team} gagne sans encaisser",
                      win_to_nil, "full",
                      justification="Cellules gagnantes avec score adverse nul.")
        self._mark("result", 8)

        # Handicaps européens (trois issues, remboursement possible non compté).
        for key, entry in markets["european_handicap"].items():
            line = float(key.replace("m", "-").replace("_", "."))
            for side, label in (("home", f"{home_name} {line:+g}"), ("draw", f"Handicap nul {line:+g}"), ("away", f"{away_name} {-line:+g}")):
                self._add(match_id, "european_handicap", f"{key}:{side}", label, entry[side], "full",
                          line=line, justification="Handicap européen sur la différence de buts.")
        # Handicaps asiatiques, y compris quarts (demi-gains conservés).
        for key, entry in markets["asian_handicap"].items():
            line = float(entry.get("line_home", 0.0))
            for side, team in (("home", home_name), ("away", away_name)):
                settlement = entry[side]
                self._add(match_id, "asian_handicap", f"{side}:{key}", f"{team} handicap {line:+g}",
                          expected_win_probability(settlement), "full", line=line,
                          settlement=settlement,
                          justification="Règlement asiatique (push, demi-gain, demi-perte).")
        self._mark("handicap", len(markets["asian_handicap"]) * 2)

    # -------------------------------------------------------------- buts
    def _goal_markets(self, match_id: str, markets: Dict[str, Any], matrix: np.ndarray, context: Dict[str, Any]) -> None:
        names = context.get("team_names", {})
        home_name = names.get("home", "Domicile")
        away_name = names.get("away", "Extérieur")
        n = matrix.shape[0]
        grid = np.indices((n, n))
        total = grid[0] + grid[1]
        diff = grid[0] - grid[1]

        generated = 0
        for key, probability in markets["totals"].items():
            direction, raw = key.split("_", 1)
            line = float(raw.replace("_", "."))
            # Exclusion des lignes extrêmes et non rentables (0,5 et 5,5 buts)
            if line in (0.5, 5.5):
                continue
            if not (0.15 <= float(probability) <= 0.82):
                continue
            self._add(match_id, "total_goals", key, f"{'Plus' if direction == 'over' else 'Moins'} de {_fmt_line(line)} buts",
                      probability, "full", line=line, justification="Somme des cellules au-dessus/en dessous de la ligne.")
            generated += 1
        for side, team in (("home", home_name), ("away", away_name)):
            for key, probability in markets["team_totals"][side].items():
                direction, raw = key.split("_", 1)
                line = float(raw.replace("_", "."))
                if line == 0.5 and float(probability) > 0.80:
                    continue
                if not (0.15 <= float(probability) <= 0.82):
                    continue
                self._add(match_id, "team_total_goals", f"{side}:{key}",
                          f"{team} : {'plus' if direction == 'over' else 'moins'} de {_fmt_line(line)} but",
                          probability, "full", line=line)
                generated += 1
        self._mark("goals", generated)

        self._add(match_id, "btts", "yes", "Les deux équipes marquent", markets["btts"]["yes"], "full")
        self._add(match_id, "btts", "no", "Au moins une équipe ne marque pas", markets["btts"]["no"], "full")
        self._add(match_id, "no_goals", "yes", "Aucun but (0-0)", float(matrix[0, 0]), "full")

        exact_total = markets["exact_totals"]
        for goals, probability in exact_total.items():
            if float(probability) < 0.06:
                continue
            self._add(match_id, "exact_total_goals", str(goals),
                      f"Exactement {goals} but{'s' if int(goals) > 1 else ''}", probability, "full")
        odd = float(np.sum(matrix[total % 2 == 1]))
        self._add(match_id, "odd_even_goals", "odd", "Nombre de buts impair", odd, "full")
        self._add(match_id, "odd_even_goals", "even", "Nombre de buts pair", 1.0 - odd, "full")

        for key, mask, label in (
            ("0-1", total <= 1, "0 à 1 but"),
            ("2-3", (total >= 2) & (total <= 3), "2 à 3 buts"),
            ("4+", total >= 4, "4 buts ou plus"),
        ):
            self._add(match_id, "goal_ranges", key, label, float(np.sum(matrix[mask])), "full")

        home_goals = np.sum(matrix, axis=1)
        away_goals = np.sum(matrix, axis=0)
        self._add(match_id, "team_most_goals", "home", f"{home_name} marque plus que {away_name}", markets["1N2"]["home"], "full")
        self._add(match_id, "team_most_goals", "draw", "Les deux équipes marquent autant", markets["1N2"]["draw"], "full")
        self._add(match_id, "team_most_goals", "away", f"{away_name} marque plus que {home_name}", markets["1N2"]["away"], "full")

        # Scores exacts : catégorie Audacieux uniquement, masse explicite.
        for score in markets["top_scores"][:4]:
            if float(score["p"]) < 0.03:
                continue
            self._add(match_id, "exact_score", score["score"], f"Score exact {score['score']}",
                      score["p"], "full", uncertainty=0.55,
                      justification="Cellule individuelle de la matrice ; masse de scores restante élevée.")

        # Combinaisons issues de la même matrice (jamais un produit).
        for key, probability in markets["combos"].items():
            if float(probability) < 0.05:
                continue
            market_id = "result_total_combo" if "_over_" in key or "_under_" in key else "result_btts_combo"
            self._add(match_id, market_id, key, key.replace("_", " "), probability, "full",
                      justification="Cellules jointes (résultat ET total/BTTS).")

    # ---------------------------------------------------------- périodes
    def _period_markets(
        self,
        match_id: str,
        lambda_home: float,
        lambda_away: float,
        rho: float,
        period_model: PeriodModel,
        context: Dict[str, Any],
    ) -> None:
        share = period_model.state
        coverage = share.coverage()
        if coverage == "none":
            self._mark("periods", 0, "no_halftime_scores")
            return
        payload = period_markets(lambda_home, lambda_away, rho, share.share)
        first = payload["first_half"]["markets"]
        second = payload["second_half"]["markets"]
        uncertainty = 0.55 if coverage == "thin" else (0.40 if coverage == "partial" else 0.30)
        missing = [] if coverage == "full" else ["halftime_score_sample_limited"]
        generated = 0

        for key, label in (("home", "Domicile"), ("draw", "Match nul"), ("away", "Extérieur")):
            self._add(match_id, "ht_1x2", key, f"Mi-temps : {label}", first["1N2"][key], coverage,
                      sample_size=share.sample_size, uncertainty=uncertainty, missing=missing)
            generated += 1
        for key, probability in first["double_chance"].items():
            self._add(match_id, "ht_double_chance", key, f"Mi-temps double chance {key}", probability, coverage,
                      sample_size=share.sample_size, uncertainty=uncertainty, missing=missing)
            generated += 1
        for key, probability in first["totals"].items():
            direction, raw = key.split("_", 1)
            line = float(raw.replace("_", "."))
            if line > 3.5 or not (0.10 <= float(probability) <= 0.92):
                continue
            self._add(match_id, "ht_total_goals", key, f"1re mi-temps : {'plus' if direction == 'over' else 'moins'} de {_fmt_line(line)} buts",
                      probability, coverage, line=line, sample_size=share.sample_size,
                      uncertainty=uncertainty, missing=missing)
            generated += 1
        for key, probability in second["totals"].items():
            direction, raw = key.split("_", 1)
            line = float(raw.replace("_", "."))
            if line > 3.5 or not (0.10 <= float(probability) <= 0.92):
                continue
            self._add(match_id, "sh_total_goals", key, f"2e mi-temps : {'plus' if direction == 'over' else 'moins'} de {_fmt_line(line)} buts",
                      probability, coverage, line=line, sample_size=share.sample_size,
                      uncertainty=uncertainty, missing=missing)
            generated += 1
        for key, probability in first["btts"].items():
            self._add(match_id, "btts_first_half", key, f"Les deux équipes marquent en 1re mi-temps : {'oui' if key == 'yes' else 'non'}",
                      probability, coverage, sample_size=share.sample_size, uncertainty=uncertainty)
            generated += 1
        for score in first["top_scores"][:3]:
            if float(score["p"]) >= 0.05:
                self._add(match_id, "ht_exact_score", score["score"], f"Score exact mi-temps {score['score']}",
                          score["p"], coverage, sample_size=share.sample_size,
                          uncertainty=uncertainty + 0.10)
                generated += 1
        for key, probability in payload["htft"].items():
            if float(probability) < 0.03:
                continue
            self._add(match_id, "htft", key, f"Mi-temps / fin : {key}", probability, coverage,
                      sample_size=share.sample_size, uncertainty=uncertainty,
                      justification="Convolution jointe des deux mi-temps.")
            self._add(match_id, "htft_combo", key, f"Mi-temps et fin de match {key}", probability, coverage,
                      sample_size=share.sample_size, uncertainty=uncertainty)
            generated += 1
        for key, label in (("first_half", "Première mi-temps"), ("tie", "Égalité"), ("second_half", "Seconde mi-temps")):
            self._add(match_id, "most_prolific_half", key, f"Mi-temps la plus prolifique : {label}",
                      payload["most_prolific_half"][key], coverage,
                      sample_size=share.sample_size, uncertainty=uncertainty)
            generated += 1
        self._add(match_id, "goal_in_both_halves", "yes", "But dans les deux mi-temps",
                  payload["goal_in_both_halves"], coverage, sample_size=share.sample_size, uncertainty=uncertainty)
        self._add(match_id, "goal_in_both_halves", "no", "Pas de but dans les deux mi-temps",
                  1.0 - payload["goal_in_both_halves"], coverage, sample_size=share.sample_size, uncertainty=uncertainty)
        self._add(match_id, "draw_in_at_least_one_half", "yes", "Match nul dans au moins une mi-temps",
                  payload["draw_in_at_least_one_half"], coverage, sample_size=share.sample_size,
                  uncertainty=uncertainty)
        self._add(match_id, "draw_in_at_least_one_half", "no", "Aucune mi-temps nulle",
                  payload["no_draw_in_any_half"], coverage, sample_size=share.sample_size,
                  uncertainty=uncertainty)
        self._mark("periods", generated + 4, f"halftime_sample={share.sample_size}")

    # ----------------------------------------------------------- comptages
    def _count_markets(self, match_id: str, context: Dict[str, Any]) -> None:
        counts = context.get("counts") or {}
        layout = {
            "corners": ("total_corners", "team_total_corners", "corners_handicap", "most_corners", "corners"),
            "yellow_cards": ("total_yellow_cards", "team_total_cards", "cards_handicap", None, "cards"),
            "red_cards": ("total_red_cards", None, None, None, "cards"),
            "cards": ("total_cards", "team_total_cards", "cards_handicap", None, "cards"),
            "shots": ("total_shots", "team_total_shots", None, None, "statistics"),
            "shots_on_target": ("shots_on_target", None, None, None, "statistics"),
            "fouls": ("fouls", None, None, None, "statistics"),
            "offsides": ("offsides", None, None, None, "statistics"),
        }
        for count_family, (total_market, team_market, handicap_market, most_market, family) in layout.items():
            entry = counts.get(count_family)
            if not isinstance(entry, dict):
                continue
            home = self._profile(entry.get("home"))
            away = self._profile(entry.get("away"))
            if home is None or away is None:
                self._mark(family, 0, f"{count_family}_insufficient_sample")
                continue
            generated = 0
            sample = min(home.sample_size, away.sample_size)
            coverage = home.coverage if home.coverage == away.coverage else "thin"
            if coverage == "none":
                continue
            home_distribution = home.distribution()
            away_distribution = away.distribution()
            total_distribution = np.convolve(home_distribution, away_distribution)
            total_distribution /= max(float(total_distribution.sum()), 1e-12)

            if total_market:
                for line in generate_lines(home.mean + away.mean, home.variance + away.variance):
                    settlement = settle_over_under(total_distribution, line)
                    mirrored = {
                        "win": settlement["loss"],
                        "push": settlement["push"],
                        "half_win": settlement["half_loss"],
                        "half_loss": settlement["half_win"],
                        "loss": settlement["win"],
                    }
                    # Over et Under d'une même ligne sont publiés ensemble ou
                    # pas du tout : une ligne quasi certaine d'un côté ne doit
                    # pas créer une sélection isolée sans complément.
                    p_over = expected_win_probability(settlement)
                    p_under = expected_win_probability(mirrored)
                    if not (PUBLISH_FLOOR <= p_over <= PUBLISH_CEILING and PUBLISH_FLOOR <= p_under <= PUBLISH_CEILING):
                        continue
                    unit = {"corners": "corners", "cards": "cartons"}.get(count_family) or (
                        "cartons" if family == "cards" else self._unit(count_family)
                    )
                    for direction, settlement_entry in (("over", settlement), ("under", mirrored)):
                        self._add(match_id, total_market, f"{direction}:{line}",
                                  f"{'Plus' if direction == 'over' else 'Moins'} de {_fmt_line(line)} {unit}".strip(),
                                  expected_win_probability(settlement_entry), coverage, line=line,
                                  sample_size=sample, settlement=settlement_entry,
                                  data_sources=["team_match_stats"],
                                  missing=[] if coverage == "full" else ["count_sample_limited"])
                        generated += 1

            if team_market:
                for side, profile, distribution in (("home", home, home_distribution), ("away", away, away_distribution)):
                    for line in generate_lines(profile.mean, profile.variance, max_lines=4):
                        settlement = settle_over_under(distribution, line)
                        mirrored = {
                            "win": settlement["loss"], "push": settlement["push"],
                            "half_win": settlement["half_loss"], "half_loss": settlement["half_win"],
                            "loss": settlement["win"],
                        }
                        p_over = expected_win_probability(settlement)
                        p_under = expected_win_probability(mirrored)
                        if not (PUBLISH_FLOOR <= p_over <= PUBLISH_CEILING and PUBLISH_FLOOR <= p_under <= PUBLISH_CEILING):
                            continue
                        for direction, settlement_entry in (("over", settlement), ("under", mirrored)):
                            self._add(match_id, team_market, f"{side}:{direction}:{line}",
                                      f"{side.capitalize()} : {'plus' if direction == 'over' else 'moins'} de "
                                      f"{_fmt_line(line)} {self._unit(count_family)}",
                                      expected_win_probability(settlement_entry), coverage, line=line,
                                      sample_size=profile.sample_size, settlement=settlement_entry)
                            generated += 1

            difference = difference_distribution(home_distribution, away_distribution)
            if handicap_market:
                for line in (-3.5, -2.5, -1.5, -1.0, -0.5, 0.0, 0.5, 1.0, 1.5, 2.5, 3.5):
                    settlement = margin_settlement(difference, line)
                    self._add(match_id, handicap_market, f"home:{line}",
                              f"Domicile {line:+g} {self._unit(count_family)}",
                              expected_win_probability(settlement), coverage, line=line,
                              sample_size=sample, settlement=settlement)
                    mirrored = {
                        "win": settlement["loss"], "push": settlement["push"],
                        "half_win": settlement["half_loss"], "half_loss": settlement["half_win"],
                        "loss": settlement["win"],
                    }
                    self._add(match_id, handicap_market, f"away:{-line}",
                              f"Extérieur {-line:+g} {self._unit(count_family)}",
                              expected_win_probability(mirrored), coverage, line=-line,
                              sample_size=sample, settlement=mirrored)
                    generated += 2

            if most_market:
                values = np.arange(len(difference)) - len(difference) // 2
                home_more = float(difference[values > 0].sum())
                tie = float(difference[values == 0].sum())
                away_more = float(difference[values < 0].sum())
                for key, probability, label in (
                    ("home", home_more, "Domicile obtient plus de corners"),
                    ("tie", tie, "Autant de corners"),
                    ("away", away_more, "Extérieur obtient plus de corners"),
                ):
                    self._add(match_id, most_market, key, label, probability, coverage, sample_size=sample)
                    generated += 1

            if count_family == "red_cards":
                probability = 1.0 - float(count_distribution(home.mean + away.mean, home.variance + away.variance)[0])
                self._add(match_id, "sending_off", "yes", "Au moins une expulsion", probability, coverage,
                          sample_size=sample, uncertainty=0.5)
                self._add(match_id, "sending_off", "no", "Aucune expulsion", 1.0 - probability, coverage,
                          sample_size=sample, uncertainty=0.5)
                generated += 2

            self._mark(family, generated, f"{count_family}_sample={sample}")

    @staticmethod
    def _unit(count_family: str) -> str:
        return {"corners": "corners", "yellow_cards": "cartons jaunes", "red_cards": "cartons rouges",
                "cards": "cartons", "shots": "tirs", "shots_on_target": "tirs cadrés",
                "fouls": "fautes", "offsides": "hors-jeu"}.get(count_family, "")

    @staticmethod
    def _profile(payload: Any) -> Optional[CountProfile]:
        if not isinstance(payload, dict) or payload.get("sample_size", 0) < 3:
            return None
        return CountProfile(
            mean=max(0.2, float(payload.get("mean", 0.0))),
            variance=max(float(payload.get("variance", 0.0)), 0.2),
            sample_size=int(payload.get("sample_size", 0)),
        )

    # ------------------------------------------------------------- joueurs
    def _player_markets(self, match_id: str, context: Dict[str, Any], lambda_home: float, lambda_away: float) -> None:
        players = context.get("players") or []
        if not players:
            self._mark("players", 0, "no_player_data")
            return
        from app.core.player_engine import player_markets
        generated = 0
        for prediction in player_markets(match_id, players, lambda_home, lambda_away):
            self.predictions.append(prediction)
            generated += 1
        self._mark("players", generated)

    # -------------------------------------------------------------- temps
    def _time_markets(self, match_id: str, lambda_home: float, lambda_away: float, context: Dict[str, Any]) -> None:
        generated = 0
        for prediction in goal_time_markets(match_id, lambda_home, lambda_away):
            self.predictions.append(prediction)
            generated += 1
        for prediction in goal_first_team_markets(match_id, lambda_home, lambda_away):
            self.predictions.append(prediction)
            generated += 1
        self._mark("time", generated)
