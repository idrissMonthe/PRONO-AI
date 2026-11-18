"""Modèles de comptage (corners, cartons, tirs, fautes, hors-jeu).

Les événements de comptage sont surdispersés : leur variance dépasse souvent la
moyenne. Le moteur compare donc Poisson et binomiale négative (loi Gamma-Poisson)
estimée par la méthode des moments, et retient la binomiale négative lorsque la
variance observée dépasse la moyenne.

Aucune moyenne n'est inventée : un profil sans échantillon suffisant retourne
``None`` et le marché correspondant n'est pas proposé.
"""
from __future__ import annotations

from dataclasses import dataclass
from typing import Any, Dict, Iterable, List, Mapping, Optional

import numpy as np
from scipy.stats import nbinom, poisson

MIN_COUNT_SAMPLE = 8
FULL_COUNT_SAMPLE = 25
PRIOR_WEIGHT = 6.0
MAX_COUNT = 40


def _get(row: Any, key: str) -> Any:
    if isinstance(row, Mapping):
        return row.get(key)
    return getattr(row, key, None)


@dataclass
class CountProfile:
    mean: float
    variance: float
    sample_size: int
    family: str = "count"

    @property
    def coverage(self) -> str:
        if self.sample_size < 3:
            return "none"
        if self.sample_size < MIN_COUNT_SAMPLE:
            return "thin"
        if self.sample_size < FULL_COUNT_SAMPLE:
            return "partial"
        return "full"

    def reliability_factor(self) -> float:
        base = {"none": 0.0, "thin": 0.35, "partial": 0.7, "full": 1.0}[self.coverage()]
        return base

    def distribution(self, max_count: int = MAX_COUNT) -> np.ndarray:
        return count_distribution(self.mean, self.variance, max_count)

    def to_dict(self) -> Dict[str, Any]:
        return {
            "mean": round(float(self.mean), 3),
            "variance": round(float(self.variance), 3),
            "sample_size": int(self.sample_size),
            "coverage": self.coverage,
            "family": self.family,
        }


def fit_profile(values: Iterable[float], prior_mean: Optional[float] = None) -> Optional[CountProfile]:
    sample = [float(value) for value in values if value is not None]
    if len(sample) < 3:
        return None
    observed_mean = float(np.mean(sample))
    if prior_mean is not None and prior_mean > 0:
        mean = (observed_mean * len(sample) + prior_mean * PRIOR_WEIGHT) / (len(sample) + PRIOR_WEIGHT)
    else:
        mean = observed_mean
    if len(sample) > 1:
        variance = float(np.var(sample, ddof=1))
    else:
        variance = mean
    # Une variance inférieure à la moyenne ne justifie pas la binomiale négative.
    variance = max(variance, mean * 1.0001)
    return CountProfile(mean=max(0.2, mean), variance=variance, sample_size=len(sample))


def count_distribution(mean: float, variance: Optional[float] = None, max_count: int = MAX_COUNT) -> np.ndarray:
    mean = max(float(mean), 1e-6)
    counts = np.arange(max_count + 1)
    if variance is None or variance <= mean + 1e-9:
        probabilities = poisson.pmf(counts, mean)
    else:
        dispersion = mean * mean / max(variance - mean, 1e-6)
        probability = dispersion / (dispersion + mean)
        probabilities = nbinom.pmf(counts, dispersion, probability)
    total = float(probabilities.sum())
    if total <= 0:
        probabilities = poisson.pmf(counts, mean)
        total = float(probabilities.sum())
    return probabilities / total


def settle_over_under(distribution: np.ndarray, line: float) -> Dict[str, float]:
    """Règlement Over/Under avec remboursements, demi-gains et demi-pertes.

    Une ligne entière (3.0) rembourse exactement 3. Une ligne quart (2.75) est la
    moyenne d'une ligne 2.5 et d'une ligne 3.0 : exactement 3 buts produit un
    demi-gain, pas un gain complet.
    """
    if abs(line * 4.0 - round(line * 4.0)) > 1e-9:
        raise ValueError("La ligne doit être un multiple de 0.25.")
    if abs(line * 2.0 - round(line * 2.0)) > 1e-9:
        # Ligne quart : mise partagée entre les deux lignes voisines. Le total
        # exact de la ligne entière encaisse un demi-gain (ou une demi-perte
        # pour l'Under), jamais un gain complet.
        counts = np.arange(len(distribution))
        integer_line = int(round(line))
        win = float(distribution[counts > line + 0.25].sum())
        half = float(distribution[counts == integer_line].sum())
        loss = float(distribution[counts < line - 0.25].sum())
        return {
            "win": round(win, 4),
            "push": 0.0,
            "half_win": round(half, 4),
            "half_loss": 0.0,
            "loss": round(loss, 4),
        }
    return _settle_half(distribution, line)


def _settle_half(distribution: np.ndarray, line: float) -> Dict[str, float]:
    counts = np.arange(len(distribution))
    if abs(line - round(line)) < 1e-9:
        push = float(distribution[counts == int(round(line))].sum())
        win = float(distribution[counts > line].sum())
        loss = float(distribution[counts < line].sum())
        return {
            "win": round(win, 4),
            "push": round(push, 4),
            "half_win": 0.0,
            "half_loss": 0.0,
            "loss": round(loss, 4),
        }
    win = float(distribution[counts > line].sum())
    loss = float(distribution[counts < line].sum())
    return {
        "win": round(win, 4),
        "push": 0.0,
        "half_win": 0.0,
        "half_loss": 0.0,
        "loss": round(loss, 4),
    }


def expected_win_probability(settlement: Mapping[str, float]) -> float:
    """Probabilité effective d'un règlement asiatique (demi-gain = 0,5)."""
    return float(settlement.get("win", 0.0)) + 0.5 * float(settlement.get("half_win", 0.0))


def difference_distribution(home: np.ndarray, away: np.ndarray) -> np.ndarray:
    """Distribution de (score domicile − score extérieur) pour un comptage."""
    size = len(home)
    difference = np.zeros(2 * size - 1)
    for i, p_home in enumerate(home):
        if p_home <= 0:
            continue
        for j, p_away in enumerate(away):
            if p_away <= 0:
                continue
            difference[i - j + size - 1] += p_home * p_away
    total = float(difference.sum())
    return difference / total if total > 0 else difference


def margin_settlement(difference: np.ndarray, line: float) -> Dict[str, float]:
    """Règlement d'un handicap sur une distribution de différence.

    ``line`` est appliquée au camp domicile (ex. −1.5). Les index sont décalés
    de ``len(diff)//2`` : la cellule centrale correspond à une différence nulle.
    """
    offset = len(difference) // 2
    values = np.arange(len(difference)) - offset
    adjusted = values + line
    win = float(difference[adjusted > 0].sum())
    push = float(difference[adjusted == 0].sum())
    loss = float(difference[adjusted < 0].sum())
    return {
        "win": round(win, 4),
        "push": round(push, 4),
        "half_win": 0.0,
        "half_loss": 0.0,
        "loss": round(loss, 4),
    }


def generate_lines(
    mean: float,
    variance: Optional[float] = None,
    minimum: float = 0.25,
    min_probability: float = 0.25,
    max_probability: float = 0.80,
    max_lines: int = 6,
) -> List[float]:
    """Génère les lignes utiles autour de la moyenne observée.

    Les lignes quasi certaines ne sont pas publiées : elles gonflent le volume
    sans apporter d'information. Le jeu est borné pour éviter des milliers de
    variantes inutiles.
    """
    distribution = count_distribution(mean, variance)
    deviation = float(np.sqrt(max(variance if variance is not None else mean, 0.25)))
    low = max(minimum, np.floor((mean - 1.6 * deviation) * 4) / 4)
    high = np.ceil((mean + 1.6 * deviation) * 4) / 4
    lines: List[float] = []
    step = int(round(low * 4))
    last_step = int(round(high * 4))
    while step <= last_step:
        line = step / 4.0
        settlement = settle_over_under(distribution, line)
        probability = expected_win_probability(settlement)
        if min_probability <= probability <= max_probability:
            lines.append(line)
        step += 1
    if not lines:
        nearest = round(max(minimum, mean * 2.0 - 0.5) * 2) / 2.0
        if nearest >= minimum:
            lines = [nearest]
    return lines[:max_lines]
