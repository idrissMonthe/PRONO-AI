"""
Elo Rating Model with Home Advantage and Goal Margin (CDC Section 7.9, 7.11 & 29.1)
Implements Model M1 (Simple Force Baseline).
"""
from typing import Dict, Tuple
import numpy as np


class EloEngine:
    def __init__(self, k_factor: float = 20.0, default_home_adv: float = 65.0, default_elo: float = 1500.0):
        self.k_factor = k_factor
        self.home_adv = default_home_adv
        self.default_elo = default_elo

    def expected_win_prob(self, r_home: float, r_away: float, is_neutral: bool = False) -> float:
        """
        E_dom = 1 / (1 + 10^(-(R_dom - R_ext + H) / 400))
        """
        h = 0.0 if is_neutral else self.home_adv
        diff = (r_home - r_away + h)
        return float(1.0 / (1.0 + 10.0 ** (-diff / 400.0)))

    def goal_difference_multiplier(self, goal_diff: int) -> float:
        """
        G = 1 if d <= 1; 1.5 if d == 2; (11 + d) / 8 if d >= 3
        """
        d = abs(goal_diff)
        if d <= 1:
            return 1.0
        elif d == 2:
            return 1.5
        else:
            return (11.0 + d) / 8.0

    def update_ratings(
        self,
        r_home: float,
        r_away: float,
        home_score: int,
        away_score: int,
        is_neutral: bool = False
    ) -> Tuple[float, float]:
        """
        Updates Elo ratings for both teams after a match.
        """
        e_home = self.expected_win_prob(r_home, r_away, is_neutral)
        e_away = 1.0 - e_home

        if home_score > away_score:
            s_home, s_away = 1.0, 0.0
        elif home_score == away_score:
            s_home, s_away = 0.5, 0.5
        else:
            s_home, s_away = 0.0, 1.0

        g = self.goal_difference_multiplier(home_score - away_score)

        new_r_home = r_home + self.k_factor * g * (s_home - e_home)
        new_r_away = r_away + self.k_factor * g * (s_away - e_away)

        return float(round(new_r_home, 2)), float(round(new_r_away, 2))

    def predict_1n2(self, r_home: float, r_away: float, is_neutral: bool = False) -> Dict[str, float]:
        """
        Converts Elo difference into 1N2 probabilities using standard logistic mapping.
        Draw probability is highest when teams are evenly matched (~28% in football).
        """
        h = 0.0 if is_neutral else self.home_adv
        diff = (r_home - r_away + h)

        # Baseline draw prob around 27%, decreasing as diff increases
        p_draw = 0.27 * np.exp(-abs(diff) / 600.0)

        # Remaining mass is split according to logistic win prob
        p_win_decided = 1.0 / (1.0 + 10.0 ** (-diff / 400.0))
        rem = 1.0 - p_draw

        p_home = rem * p_win_decided
        p_away = rem * (1.0 - p_win_decided)

        # Normalize
        total = p_home + p_draw + p_away
        return {
            "home": round(float(p_home / total), 4),
            "draw": round(float(p_draw / total), 4),
            "away": round(float(p_away / total), 4),
        }

