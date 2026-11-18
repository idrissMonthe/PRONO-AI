"""
Poisson & Dixon-Coles Regression Engine (CDC Section 7.10, 7.11 & 7.12)
Estimates team attack/defense parameters with:
- Temporal exponential decay: w = exp(-xi * days)
- L2 regularization
- Strict identifiability constraint (mean attack == 0, mean defense == 0)
- Optional 80% and 95% parameter-bootstrap intervals; unavailable when the sample is insufficient
"""
import os
from typing import Any, Dict, List, Optional, Tuple
import numpy as np
from scipy.optimize import minimize
from app.core.dixon_coles import compute_score_matrix, extract_match_markets_from_matrix, tau_correction

TEAM_STRENGTH_PRIOR_MATCHES = 5.0
MIN_TEAM_MATCHES_FOR_OFFICIAL_PREDICTIONS = 5
# Teams with fewer observations are still fitted, but their attack/defence
# parameters are strongly shrunk toward the league mean. Excluding them from
# the optimization made every sparse fixture share the exact same lambdas.
MIN_TEAM_MATCHES_FOR_MODELING = 1


class MatchData:
    def __init__(
        self,
        home_team: str,
        away_team: str,
        home_goals: int,
        away_goals: int,
        days_ago: float,
        is_neutral: bool = False
    ):
        self.home_team = home_team
        self.away_team = away_team
        self.home_goals = home_goals
        self.away_goals = away_goals
        self.days_ago = days_ago
        self.is_neutral = is_neutral


class DixonColesModel:
    def __init__(
        self,
        xi: float = 0.0019,  # Half-life = ln(2) / 0.0019 ~= 365 days
        l2_reg: float = 0.01,
        rho: float = -0.05,
    ):
        self.xi = xi
        self.l2_reg = l2_reg
        self.rho = rho
        self.teams: List[str] = []
        self.team_indices: Dict[str, int] = {}
        self.mu: float = 0.0
        self.home_adv: float = 0.25
        self.attack_strengths: np.ndarray = np.array([])
        self.defense_strengths: np.ndarray = np.array([])
        self.team_match_counts: Dict[str, int] = {}
        self.is_fitted: bool = False
        self.training_hash: str = ""
        self.fit_success: bool = False
        self.fit_message: str = "Not fitted"
        self.training_matches: List[MatchData] = []

    def fit(self, matches: List[MatchData]) -> "DixonColesModel":
        """
        Fits Poisson / Dixon-Coles parameters by minimizing regularized negative log likelihood.
        """
        if len(matches) < 5:
            raise ValueError("At least 5 matches required to fit Dixon-Coles model.")

        # Extract unique teams
        all_teams = sorted(list(set(
            [m.home_team for m in matches] + [m.away_team for m in matches]
        )))
        candidate_team_match_counts = {team: 0 for team in all_teams}
        for match in matches:
            candidate_team_match_counts[match.home_team] += 1
            candidate_team_match_counts[match.away_team] += 1
        modeled_teams = [
            team for team in all_teams
            if candidate_team_match_counts[team] >= MIN_TEAM_MATCHES_FOR_MODELING
        ]
        if len(modeled_teams) < 2:
            modeled_teams = []
        candidate_team_indices = {team: index for index, team in enumerate(modeled_teams)}
        num_teams = len(modeled_teams)

        # Precompute weights w_k = exp(-xi * days_ago)
        weights = np.array([np.exp(-self.xi * m.days_ago) for m in matches])

        # Vecteurs de travail préparés une seule fois. L-BFGS-B différencie la
        # vraisemblance numériquement (2 * paramètres + 1 évaluations par
        # itération) : sur 534 matchs et ~200 équipes, une boucle Python sur
        # les matchs représentait l'essentiel du temps d'entraînement.
        safe_home = np.array(
            [candidate_team_indices.get(m.home_team, 0) for m in matches], dtype=np.int64
        )
        safe_away = np.array(
            [candidate_team_indices.get(m.away_team, 0) for m in matches], dtype=np.int64
        )
        has_home = np.array([m.home_team in candidate_team_indices for m in matches], dtype=bool)
        has_away = np.array([m.away_team in candidate_team_indices for m in matches], dtype=bool)
        goals_home = np.array([int(m.home_goals) for m in matches], dtype=float)
        goals_away = np.array([int(m.away_goals) for m in matches], dtype=float)
        not_neutral = np.array([0.0 if m.is_neutral else 1.0 for m in matches], dtype=float)

        # Les quatre seules cases corrigées par tau (0-0, 0-1, 1-0, 1-1).
        cell_00 = (goals_home == 0) & (goals_away == 0)
        cell_01 = (goals_home == 0) & (goals_away == 1)
        cell_10 = (goals_home == 1) & (goals_away == 0)
        cell_11 = (goals_home == 1) & (goals_away == 1)

        def tau_penalty(tau: np.ndarray) -> np.ndarray:
            # Même convention que tau_correction : une masse négative est
            # ramenée à zéro, puis fortement pénalisée si elle devient nulle.
            tau = np.maximum(tau, 0.0)
            return np.where(tau > 1e-8, -np.log(np.maximum(tau, 1e-8)), 20.0)

        # Parameter vector layout:
        # [0]: mu (intercept)
        # [1]: home_adv (h)
        # [2 : 2 + num_teams - 1]: att (last team constrained to sum to 0)
        # [2 + num_teams - 1 : 2 + 2 * (num_teams - 1)]: def (last team constrained to sum to 0)
        num_free_att = max(0, num_teams - 1)
        num_free_def = max(0, num_teams - 1)
        total_params = 2 + num_free_att + num_free_def

        # Last coordinate is the Dixon-Coles rho, estimated rather than held fixed.
        total_params += 1
        x0 = np.zeros(total_params)
        # Initial guess for mu: log of avg goals per team
        total_goals = sum(m.home_goals + m.away_goals for m in matches)
        x0[0] = np.log(max(0.5, total_goals / (2.0 * len(matches))))
        x0[1] = 0.25  # Home advantage starting prior ~ +0.25 in log scale
        x0[-1] = float(np.clip(self.rho, -0.2, 0.2))
        bounds = [(None, None)] * (total_params - 1) + [(-0.2, 0.2)]

        def nll(params: np.ndarray) -> float:
            mu = params[0]
            h = params[1]
            rho = float(params[-1])

            # Reconstruct attack vectors with sum = 0
            free_att = params[2 : 2 + num_free_att]
            att = np.append(free_att, -np.sum(free_att))

            free_def = params[2 + num_free_att : 2 + num_free_att + num_free_def]
            defense = np.append(free_def, -np.sum(free_def))

            att_h = np.where(has_home, att[safe_home], 0.0)
            def_h = np.where(has_home, defense[safe_home], 0.0)
            att_a = np.where(has_away, att[safe_away], 0.0)
            def_a = np.where(has_away, defense[safe_away], 0.0)

            eff_h = h * not_neutral
            log_lambda_h = mu + eff_h + att_h - def_a
            log_lambda_a = mu + att_a - def_h

            lambda_h = np.exp(np.clip(log_lambda_h, -5.0, 5.0))
            lambda_a = np.exp(np.clip(log_lambda_a, -5.0, 5.0))

            # Negative log likelihood Poisson: lambda - y * log(lambda)
            loss = np.sum(
                weights
                * (
                    lambda_h
                    - goals_home * np.log(np.maximum(1e-8, lambda_h))
                    + lambda_a
                    - goals_away * np.log(np.maximum(1e-8, lambda_a))
                )
            )

            # tau ne dépend des buts attendus que sur quatre cases : les
            # sélectionner évite d'évaluer la correction sur tout le match.
            if cell_00.any():
                tau = 1.0 - lambda_h[cell_00] * lambda_a[cell_00] * rho
                loss += np.sum(weights[cell_00] * tau_penalty(tau))
            if cell_01.any():
                tau = 1.0 + lambda_h[cell_01] * rho
                loss += np.sum(weights[cell_01] * tau_penalty(tau))
            if cell_10.any():
                tau = 1.0 + lambda_a[cell_10] * rho
                loss += np.sum(weights[cell_10] * tau_penalty(tau))
            if cell_11.any():
                tau = 1.0 - rho
                loss += np.sum(weights[cell_11] * tau_penalty(tau))

            # L2 penalty to shrink forces towards mean
            reg = self.l2_reg * (np.sum(att**2) + np.sum(defense**2))
            value = float(loss + reg)
            return value if np.isfinite(value) else float("inf")

        def grad(params: np.ndarray) -> np.ndarray:
            """Gradient analytique de nll.

            Sans lui, L-BFGS-B approxime le gradient par différences finies :
            2 * total_params + 1 évaluations de la vraisemblance par itération,
            soit ~800 sur ~400 paramètres. Le modèle dépensait alors tout son
            budget d'évaluations avant d'avoir convergé. Le gradient est
            exact, donc le budget ne sert plus qu'à la convergence.
            """
            mu = params[0]
            h = params[1]
            rho = float(params[-1])

            free_att = params[2 : 2 + num_free_att]
            att = np.append(free_att, -np.sum(free_att))
            free_def = params[2 + num_free_att : 2 + num_free_att + num_free_def]
            defense = np.append(free_def, -np.sum(free_def))

            att_h = np.where(has_home, att[safe_home], 0.0)
            def_h = np.where(has_home, defense[safe_home], 0.0)
            att_a = np.where(has_away, att[safe_away], 0.0)
            def_a = np.where(has_away, defense[safe_away], 0.0)

            log_lambda_h = mu + h * not_neutral + att_h - def_a
            log_lambda_a = mu + att_a - def_h
            lambda_h = np.exp(np.clip(log_lambda_h, -5.0, 5.0))
            lambda_a = np.exp(np.clip(log_lambda_a, -5.0, 5.0))

            # d/d(log lambda) de lambda - y * log(lambda) = lambda - y.
            # Hors bornes de clipping, la dérivée du logarithme écrasé est nulle.
            active_h = ((log_lambda_h > -5.0) & (log_lambda_h < 5.0)).astype(float)
            active_a = ((log_lambda_a > -5.0) & (log_lambda_a < 5.0)).astype(float)
            d_h = weights * (lambda_h - goals_home) * active_h
            d_a = weights * (lambda_a - goals_away) * active_a

            d_rho = 0.0
            if cell_00.any():
                lh = lambda_h[cell_00]
                la = lambda_a[cell_00]
                tau = np.maximum(1.0 - lh * la * rho, 0.0)
                live = tau > 1e-8
                tau_safe = np.where(live, tau, 1.0)
                # d tau / d log(lambda) = d tau / d lambda * lambda.
                d_h[cell_00] += np.where(live, weights[cell_00] * la * rho * lh / tau_safe, 0.0)
                d_a[cell_00] += np.where(live, weights[cell_00] * lh * rho * la / tau_safe, 0.0)
                d_rho += float(np.sum(np.where(live, weights[cell_00] * lh * la / tau_safe, 0.0)))
            if cell_01.any():
                lh = lambda_h[cell_01]
                tau = np.maximum(1.0 + lh * rho, 0.0)
                live = tau > 1e-8
                tau_safe = np.where(live, tau, 1.0)
                d_h[cell_01] += np.where(live, -weights[cell_01] * rho * lh / tau_safe, 0.0)
                d_rho += float(np.sum(np.where(live, -weights[cell_01] * lh / tau_safe, 0.0)))
            if cell_10.any():
                la = lambda_a[cell_10]
                tau = np.maximum(1.0 + la * rho, 0.0)
                live = tau > 1e-8
                tau_safe = np.where(live, tau, 1.0)
                d_a[cell_10] += np.where(live, -weights[cell_10] * rho * la / tau_safe, 0.0)
                d_rho += float(np.sum(np.where(live, -weights[cell_10] * la / tau_safe, 0.0)))
            if cell_11.any():
                tau = np.maximum(1.0 - rho, 0.0)
                live = tau > 1e-8
                if live:
                    d_rho += float(np.sum(weights[cell_11])) / float(tau)

            gradient = np.zeros(total_params)
            gradient[0] = float(np.sum(d_h + d_a))
            gradient[1] = float(np.sum(d_h * not_neutral))

            g_att = np.zeros(att.shape[0])
            g_def = np.zeros(defense.shape[0])
            np.add.at(g_att, safe_home[has_home], d_h[has_home])
            np.add.at(g_att, safe_away[has_away], d_a[has_away])
            np.add.at(g_def, safe_home[has_home], -d_a[has_home])
            np.add.at(g_def, safe_away[has_away], -d_h[has_away])

            # L2 : d/d att (l2 * sum(att^2)) = 2 * l2 * att.
            g_att += 2.0 * self.l2_reg * att
            g_def += 2.0 * self.l2_reg * defense

            # att[dernier] = -sum(att_libres) : la contrainte d'identifiabilité
            # reporte la dérivée du dernier coefficient sur les libres.
            if num_free_att:
                gradient[2 : 2 + num_free_att] = g_att[:num_free_att] - g_att[-1]
            if num_free_def:
                gradient[2 + num_free_att : 2 + num_free_att + num_free_def] = (
                    g_def[:num_free_def] - g_def[-1]
                )
            gradient[-1] = d_rho

            return np.where(np.isfinite(gradient), gradient, 0.0)

        # Le gradient est analytique : le budget d'évaluations n'a plus à
        # croître avec le nombre d'équipes, il ne sert qu'à la convergence.
        res = minimize(
            nll,
            x0,
            jac=grad,
            method="L-BFGS-B",
            bounds=bounds,
            # L'objectif est peu courbé : sur ~600 paramètres corrélés, la
            # convergence demande beaucoup d'itérations. Chacune ne coûte
            # qu'une évaluation et un gradient exacts, donc le budget utile
            # est en itérations, pas en temps.
            options={"maxiter": 3000, "maxfun": max(20000, 60 * total_params)},
        )

        fit_message = str(getattr(res, "message", "Unknown optimizer result"))
        optimized_params = np.asarray(getattr(res, "x", []), dtype=float)
        optimized_loss = float(getattr(res, "fun", np.inf))
        valid_result = (
            bool(getattr(res, "success", False))
            and optimized_params.shape == x0.shape
            and np.all(np.isfinite(optimized_params))
            and np.isfinite(optimized_loss)
            and np.isfinite(nll(optimized_params))
        )
        self.fit_success = valid_result
        self.fit_message = fit_message
        if not valid_result:
            # Do not touch the active parameters. A failed retraining must not
            # replace a previously valid model with NaN or partial estimates.
            if not self.is_fitted:
                self.is_fitted = False
            raise RuntimeError(f"Dixon-Coles optimization failed: {fit_message}")

        candidate_mu = float(optimized_params[0])
        candidate_home_adv = float(optimized_params[1])
        candidate_rho = float(np.clip(optimized_params[-1], -0.2, 0.2))

        free_att = optimized_params[2 : 2 + num_free_att]
        candidate_attack = np.append(free_att, -np.sum(free_att)) if num_teams else np.array([])

        free_def = optimized_params[2 + num_free_att : 2 + num_free_att + num_free_def]
        candidate_defense = np.append(free_def, -np.sum(free_def)) if num_teams else np.array([])

        if not (np.all(np.isfinite(candidate_attack)) and np.all(np.isfinite(candidate_defense))):
            self.fit_success = False
            raise RuntimeError(f"Dixon-Coles optimization produced invalid parameters: {fit_message}")

        # Commit the complete candidate atomically after all validation passed.
        self.teams = modeled_teams
        self.team_indices = candidate_team_indices
        self.team_match_counts = candidate_team_match_counts
        self.mu = candidate_mu
        self.home_adv = candidate_home_adv
        self.rho = candidate_rho
        self.attack_strengths = candidate_attack
        self.defense_strengths = candidate_defense
        self.is_fitted = True
        self.training_matches = list(matches)
        return self

    def bootstrap_1n2_intervals(
        self,
        home_team: str,
        away_team: str,
        is_neutral: bool = False,
        replicates: int = 0,
        min_valid_replicates: int = 12,
        min_observations: int = 20,
        seed: Optional[int] = None,
        adjust=None,
    ) -> Dict[str, Any]:
        """Estimate 1N2 uncertainty from fitted-model bootstrap replicates.

        This deliberately returns ``available=False`` instead of fabricated
        bounds when the training sample or convergence diagnostics are weak.
        The central production probabilities are never changed by this method.
        """
        if replicates <= 0:
            return {"available": False, "method": "disabled", "valid_replicates": 0}
        if len(self.training_matches) < min_observations:
            return {
                "available": False,
                "method": "bootstrap_unavailable_insufficient_sample",
                "valid_replicates": 0,
                "required_observations": min_observations,
            }

        rng = np.random.default_rng(seed)
        probabilities: List[List[float]] = []
        observations = self.training_matches
        for _ in range(replicates):
            indices = rng.integers(0, len(observations), size=len(observations))
            sample = [observations[int(index)] for index in indices]
            candidate = DixonColesModel(xi=self.xi, l2_reg=self.l2_reg, rho=self.rho)
            try:
                candidate.fit(sample)
                lh, la, _ = candidate.predict_lambdas(home_team, away_team, is_neutral)
                matrix, _, _ = compute_score_matrix(lh, la, candidate.rho)
                p = extract_match_markets_from_matrix(matrix)["1N2"]
                row = [float(p["home"]), float(p["draw"]), float(p["away"])]
                if adjust is not None:
                    adjusted = list(adjust(row))
                    adjusted_sum = float(sum(adjusted))
                    if adjusted_sum <= 0:
                        continue
                    row = [float(value) / adjusted_sum for value in adjusted]
                if np.all(np.isfinite(row)) and np.isclose(sum(row), 1.0, atol=1e-3):
                    probabilities.append(row)
            except (RuntimeError, ValueError, FloatingPointError):
                continue

        if len(probabilities) < min_valid_replicates:
            return {
                "available": False,
                "method": "bootstrap_unavailable_convergence",
                "valid_replicates": len(probabilities),
                "requested_replicates": replicates,
            }

        values = np.asarray(probabilities, dtype=float)
        intervals = {}
        for index, name in enumerate(("home", "draw", "away")):
            intervals[name] = {
                "ci80": [round(float(x), 4) for x in np.quantile(values[:, index], [0.10, 0.90])],
                "ci95": [round(float(x), 4) for x in np.quantile(values[:, index], [0.025, 0.975])],
            }
        return {
            "available": True,
            "method": "dixon_coles_parametric_bootstrap",
            "valid_replicates": len(probabilities),
            "requested_replicates": replicates,
            "intervals": intervals,
        }

    def predict_lambdas(
        self,
        home_team: str,
        away_team: str,
        is_neutral: bool = False,
        competition_total: Optional[float] = None,
        international: bool = False,
    ) -> Tuple[float, float, List[str]]:
        """
        Returns (lambda_home, lambda_away, warnings).
        Handles unknown/promoted teams with baseline shrinkage (CDC 7.10).
        """
        warnings = []
        prior_matches = 8.0 if international else TEAM_STRENGTH_PRIOR_MATCHES
        home_sparse = False
        away_sparse = False
        if not self.is_fitted:
            # Fallback default lambdas
            return 1.35, 1.05, ["model_not_fitted_using_defaults"]

        # Check for unknown teams
        if home_team in self.team_indices:
            appearances = self.team_match_counts.get(home_team, 0)
            reliability = appearances / (appearances + prior_matches)
            att_h = self.attack_strengths[self.team_indices[home_team]] * reliability
            def_h = self.defense_strengths[self.team_indices[home_team]] * reliability
            if appearances < MIN_TEAM_MATCHES_FOR_OFFICIAL_PREDICTIONS:
                warnings.append(f"low_sample_team:{home_team}:{appearances}")
                home_sparse = True
        elif home_team in self.team_match_counts:
            att_h, def_h = 0.0, 0.0
            warnings.append(f"low_sample_team:{home_team}:{self.team_match_counts[home_team]}")
            home_sparse = True
        else:
            att_h, def_h = 0.0, 0.0  # League average prior
            warnings.append(f"unknown_or_promoted_team:{home_team}")
            home_sparse = True

        if away_team in self.team_indices:
            appearances = self.team_match_counts.get(away_team, 0)
            reliability = appearances / (appearances + prior_matches)
            att_a = self.attack_strengths[self.team_indices[away_team]] * reliability
            def_a = self.defense_strengths[self.team_indices[away_team]] * reliability
            if appearances < MIN_TEAM_MATCHES_FOR_OFFICIAL_PREDICTIONS:
                warnings.append(f"low_sample_team:{away_team}:{appearances}")
                away_sparse = True
        elif away_team in self.team_match_counts:
            att_a, def_a = 0.0, 0.0
            warnings.append(f"low_sample_team:{away_team}:{self.team_match_counts[away_team]}")
            away_sparse = True
        else:
            att_a, def_a = 0.0, 0.0  # League average prior
            warnings.append(f"unknown_or_promoted_team:{away_team}")
            away_sparse = True

        h = 0.0 if is_neutral else self.home_adv

        log_lh = self.mu + h + att_h - def_a
        log_la = self.mu + att_a - def_h

        lh = float(np.exp(np.clip(log_lh, np.log(0.1), np.log(8.0))))
        la = float(np.exp(np.clip(log_la, np.log(0.1), np.log(8.0))))
        if log_lh < np.log(0.1) or log_lh > np.log(8.0) or log_la < np.log(0.1) or log_la > np.log(8.0):
            warnings.append("expected_goals_clamped")

        if competition_total is not None and competition_total > 0 and (home_sparse or away_sparse):
            share = 0.45 if (home_sparse and away_sparse) else 0.25
            baseline_side = float(np.clip(competition_total / 2.0, 0.4, 3.2))
            if home_sparse:
                lh = (1.0 - share) * lh + share * baseline_side * (1.0 if is_neutral else 1.08)
            if away_sparse:
                la = (1.0 - share) * la + share * baseline_side
            warnings.append("competition_baseline_applied")

        return lh, la, warnings

    def mean_1n2_log_loss(self, matches: List[MatchData]) -> float:
        """Chronological score of the current parameters. Does not refit."""
        if not matches or not self.is_fitted:
            return float("inf")
        total = 0.0
        for match in matches:
            home_lambda, away_lambda, _ = self.predict_lambdas(match.home_team, match.away_team, match.is_neutral)
            matrix, _, _ = compute_score_matrix(home_lambda, away_lambda, self.rho)
            probabilities = extract_match_markets_from_matrix(matrix)["1N2"]
            if match.home_goals > match.away_goals:
                probability = probabilities["home"]
            elif match.home_goals == match.away_goals:
                probability = probabilities["draw"]
            else:
                probability = probabilities["away"]
            total += -np.log(max(float(probability), 1e-6))
        return float(total / len(matches))

    def predict_match(
        self,
        home_team: str,
        away_team: str,
        is_neutral: bool = False,
        quality_score: float = 100.0,
    ) -> Dict[str, any]:
        """
        Full match prediction pipeline with score matrix, 1N2, derived markets,
        and optional bootstrap intervals (CDC Section 6.9, 7.10, 7.12 & 29.4).
        """
        lh, la, warnings = self.predict_lambdas(home_team, away_team, is_neutral)

        # Generate score matrix with Dixon-Coles tau correction
        matrix, residual_mass, n = compute_score_matrix(lh, la, self.rho)
        markets = extract_match_markets_from_matrix(matrix)

        p_home = markets["1N2"]["home"]
        p_draw = markets["1N2"]["draw"]
        p_away = markets["1N2"]["away"]

        # Determine confidence tiers (CDC 7.12). Une donnée insuffisante
        # dégrade l'étiquette mais n'empêche plus la prédiction en phase de
        # test, sauf si l'abstention est explicitement réactivée.
        allow_limited_data = os.getenv(
            "PREDICTION_ALLOW_LIMITED_DATA_PREDICTIONS", "true"
        ).strip().lower() not in {"0", "false", "no", "off"}
        if quality_score < 40.0:
            quality_tier = "insufficient"
            confidence_tier = "low"
            warnings.append("limited_data:unverified_quality" if allow_limited_data else "abstain_due_to_insufficient_quality")
        elif quality_score < 70.0:
            quality_tier = "degraded"
            confidence_tier = "medium"
        else:
            quality_tier = "usable"
            confidence_tier = "high"
        is_abstained = False

        return {
            "expected_goals": {
                "home": round(lh, 3),
                "away": round(la, 3),
                "total": round(lh + la, 3),
            },
            "probabilities": {
                "home": {"p": p_home, "ci80": None, "ci95": None},
                "draw": {"p": p_draw, "ci80": None, "ci95": None},
                "away": {"p": p_away, "ci80": None, "ci95": None},
            },
            "markets": markets,
            "top_scores": markets["top_scores"],
            "matrix": {
                "max_goals": n,
                "truncated_mass": float(residual_mass),
            },
            "quality": {
                "score": quality_score,
                "tier": quality_tier,
                "confidence_tier": confidence_tier,
            },
            "abstain": is_abstained,
            "limited_data_mode": allow_limited_data,
            "warnings": warnings,
        }
