"""Catalogue centralisé des marchés football.

Chaque définition porte un identifiant stable, sa famille, la période couverte,
ses paramètres, les données nécessaires, le modèle statistique associé et son
statut de disponibilité. Le statut décrit la capacité réelle de PRONO-IA à
calculer le marché avec les données présentes dans le dépôt, pas le catalogue
commercial de 1xBet.

Statuts :
- ``supported``     : données et modèle disponibles pour toutes les rencontres
                      couvertes par le fournisseur principal ;
- ``conditional``   : calculable seulement si un échantillon suffisant existe
                      (xG, corners, cartons, joueurs, mi-temps…) ;
- ``insufficient``  : données présentes mais couverture ou historique trop
                      faible pour publier une probabilité ;
- ``unsupported``   : la variable nécessaire n'existe dans aucune source
                      gratuite intégrée (horodatage des corners, compositions
                      officielles, live…).

Sources 1xBet : règles générales publiques (définitions, abréviations CK/YC/YRC/
SO/SOT, marchés « Draw in at least one half », « Total yellow cards ») et pages
``/line/football`` / ``/live/football``. La disponibilité varie selon la
compétition et la rencontre : elle n'est jamais déduite du nombre de sélections.
"""
from __future__ import annotations

from dataclasses import dataclass, field
from typing import Dict, List, Optional


@dataclass(frozen=True)
class MarketDefinition:
    market_id: str
    family: str
    name: str
    period: str
    selections: List[str]
    required_data: List[str]
    model: str
    status: str
    source: str = "documented"
    parameters: Dict[str, str] = field(default_factory=dict)
    live: bool = False
    notes: str = ""

    def to_dict(self) -> Dict[str, object]:
        return {
            "market_id": self.market_id,
            "family": self.family,
            "name": self.name,
            "period": self.period,
            "selections": list(self.selections),
            "parameters": dict(self.parameters),
            "required_data": list(self.required_data),
            "model": self.model,
            "status": self.status,
            "source": self.source,
            "live": self.live,
            "notes": self.notes,
        }


def _market(
    market_id: str,
    family: str,
    name: str,
    period: str,
    selections: List[str],
    required_data: List[str],
    model: str,
    status: str,
    source: str = "documented",
    parameters: Optional[Dict[str, str]] = None,
    live: bool = False,
    notes: str = "",
) -> MarketDefinition:
    return MarketDefinition(
        market_id=market_id,
        family=family,
        name=name,
        period=period,
        selections=selections,
        required_data=required_data,
        model=model,
        status=status,
        source=source,
        parameters=parameters or {},
        live=live,
        notes=notes,
    )


SCORE_MATRIX = "score_matrix"
HT_SCORE = "matches.ht_home_score/ht_away_score"
TEAM_STATS = "team_match_stats"
COUNT = "team_match_stats (corners/cartons/tirs/fautes/hors-jeu)"
PLAYER = "compositions + statistiques joueur"
EVENTS = "événements horodatés"

MARKETS: List[MarketDefinition] = [
    # --- Résultat et handicaps -------------------------------------------
    _market("1x2", "result", "Résultat 1X2", "full",
            ["home", "draw", "away"], [SCORE_MATRIX], "score_matrix", "supported",
            source="observed"),
    _market("double_chance", "result", "Double chance 1X / X2 / 12", "full",
            ["1X", "X2", "12"], [SCORE_MATRIX], "score_matrix", "supported"),
    _market("draw_no_bet", "result", "Draw No Bet", "full",
            ["home", "away"], [SCORE_MATRIX], "score_matrix", "supported"),
    _market("european_handicap", "handicap", "Handicap européen", "full",
            ["home", "draw", "away"], [SCORE_MATRIX], "score_matrix", "supported",
            parameters={"line": "-2..+2"}),
    _market("asian_handicap", "handicap", "Handicap asiatique (dont quarts)", "full",
            ["home", "away"], [SCORE_MATRIX], "score_matrix", "supported",
            parameters={"line": "-2.0..+2.0, pas de 0.25"}),
    _market("handicap_2up", "result", "1X2 avec avantage 2UP", "full",
            ["home", "draw", "away"], [SCORE_MATRIX], "score_matrix_2up", "conditional",
            source="observed",
            notes="Modélisé comme un 1X2 classique avec remboursement si l'équipe "
                  "mène de deux buts puis est rejointe : dépend d'une règle "
                  "bookmaker précise à confirmer au Cameroun."),
    _market("to_qualify", "result", "Équipe qualifiée", "full",
            ["home", "away"], [SCORE_MATRIX, "format de la compétition"],
            "qualification_matrix", "conditional",
            notes="Uniquement en élimination directe, avec règle de prolongation "
                  "non modélisée : probabilité publiée seulement si le format "
                  "est fourni par la compétition."),
    _market("win_to_nil", "result", "Victoire sans encaisser", "full",
            ["home", "away"], [SCORE_MATRIX], "score_matrix", "supported"),

    # --- Buts --------------------------------------------------------------
    _market("total_goals", "goals", "Total de buts Over/Under", "full",
            ["over", "under"], [SCORE_MATRIX], "score_matrix", "supported",
            parameters={"line": "0.5..5.5"}),
    _market("team_total_goals", "goals", "Total de buts par équipe", "full",
            ["over", "under"], [SCORE_MATRIX], "score_matrix", "supported",
            parameters={"team": "home|away", "line": "0.5..3.5"}),
    _market("btts", "goals", "Les deux équipes marquent", "full",
            ["yes", "no"], [SCORE_MATRIX], "score_matrix", "supported"),
    _market("exact_score", "goals", "Score exact", "full",
            ["0-0..6-6"], [SCORE_MATRIX], "score_matrix", "conditional",
            notes="Publié uniquement dans la catégorie Audacieux et avec une "
                  "masse de scores suffisante ; jamais comme certitude."),
    _market("exact_total_goals", "goals", "Nombre exact de buts", "full",
            ["0..10+"], [SCORE_MATRIX], "score_matrix", "supported"),
    _market("odd_even_goals", "goals", "Nombre de buts pair/impair", "full",
            ["odd", "even"], [SCORE_MATRIX], "score_matrix", "supported"),
    _market("goal_ranges", "goals", "Fourchettes de buts", "full",
            ["0-1", "2-3", "4+"], [SCORE_MATRIX], "score_matrix", "supported"),
    _market("clean_sheet", "goals", "Clean sheet", "full",
            ["home", "away"], [SCORE_MATRIX], "score_matrix", "supported"),
    _market("btts_first_half", "goals", "BTTS première mi-temps", "first_half",
            ["yes", "no"], [SCORE_MATRIX, HT_SCORE], "period_matrix", "conditional"),
    _market("team_most_goals", "goals", "Équipe avec le plus de buts", "full",
            ["home", "draw", "away"], [SCORE_MATRIX], "score_matrix", "supported"),
    _market("no_goals", "goals", "Aucun but (0-0)", "full",
            ["yes", "no"], [SCORE_MATRIX], "score_matrix", "supported"),
    _market("first_team_to_score", "goals", "Première équipe à marquer", "full",
            ["home", "away", "no_goal"], [SCORE_MATRIX], "goal_arrival_order",
            "conditional",
            notes="Dérivé de l'ordre d'arrivée des buts sous hypothèse de taux "
                  "constants par équipe ; aucun horodatage n'est inventé."),
    _market("last_team_to_score", "goals", "Dernière équipe à marquer", "full",
            ["home", "away", "no_goal"], [SCORE_MATRIX], "goal_arrival_order",
            "conditional",
            notes="Même hypothèse de taux constants ; fiabilité limitée signalée."),

    # --- Périodes ---------------------------------------------------------
    _market("ht_1x2", "periods", "Résultat première mi-temps", "first_half",
            ["home", "draw", "away"], [SCORE_MATRIX, HT_SCORE], "period_matrix", "conditional"),
    _market("ht_double_chance", "periods", "Double chance première mi-temps", "first_half",
            ["1X", "X2", "12"], [SCORE_MATRIX, HT_SCORE], "period_matrix", "conditional"),
    _market("ht_total_goals", "periods", "Total de buts première mi-temps", "first_half",
            ["over", "under"], [SCORE_MATRIX, HT_SCORE], "period_matrix", "conditional",
            parameters={"line": "0.5..3.5"}),
    _market("sh_total_goals", "periods", "Total de buts seconde mi-temps", "second_half",
            ["over", "under"], [SCORE_MATRIX, HT_SCORE], "period_matrix", "conditional",
            parameters={"line": "0.5..3.5"}),
    _market("ht_team_total_goals", "periods", "Total de buts par équipe en 1re mi-temps", "first_half",
            ["over", "under"], [SCORE_MATRIX, HT_SCORE], "period_matrix", "conditional",
            parameters={"team": "home|away"}),
    _market("ht_exact_score", "periods", "Score exact première mi-temps", "first_half",
            ["0-0..3-3"], [SCORE_MATRIX, HT_SCORE], "period_matrix", "conditional"),
    _market("ht_handicap", "periods", "Handicap première mi-temps", "first_half",
            ["home", "away"], [SCORE_MATRIX, HT_SCORE], "period_matrix", "conditional",
            parameters={"line": "-1.5..+1.5"}),
    _market("sh_handicap", "periods", "Handicap seconde mi-temps", "second_half",
            ["home", "away"], [SCORE_MATRIX, HT_SCORE], "period_matrix", "conditional",
            parameters={"line": "-1.5..+1.5"}),
    _market("htft", "periods", "Mi-temps / Fin de match", "full",
            ["1/1", "1/X", "1/2", "X/1", "X/X", "X/2", "2/1", "2/X", "2/2"],
            [SCORE_MATRIX, HT_SCORE], "period_joint", "conditional"),
    _market("most_prolific_half", "periods", "Période la plus prolifique", "full",
            ["first_half", "tie", "second_half"], [SCORE_MATRIX, HT_SCORE],
            "period_joint", "conditional"),
    _market("goal_in_both_halves", "periods", "But dans les deux mi-temps", "full",
            ["yes", "no"], [SCORE_MATRIX, HT_SCORE], "period_joint", "conditional"),
    _market("draw_in_at_least_one_half", "periods", "Match nul dans au moins une mi-temps", "full",
            ["yes", "no"], [SCORE_MATRIX, HT_SCORE], "period_joint", "conditional",
            source="documented"),

    # --- Corners ----------------------------------------------------------
    _market("total_corners", "corners", "Total de corners Over/Under", "full",
            ["over", "under"], [COUNT], "negative_binomial", "conditional",
            parameters={"line": "5.5..13.5"}),
    _market("team_total_corners", "corners", "Total de corners par équipe", "full",
            ["over", "under"], [COUNT], "negative_binomial", "conditional"),
    _market("corners_handicap", "corners", "Handicap de corners", "full",
            ["home", "away"], [COUNT], "negative_binomial_difference", "conditional"),
    _market("most_corners", "corners", "Équipe avec le plus de corners", "full",
            ["home", "tie", "away"], [COUNT], "negative_binomial_difference", "conditional"),
    _market("first_corner", "corners", "Première équipe à obtenir un corner", "full",
            ["home", "away"], [COUNT, EVENTS], "event_arrival", "unsupported",
            notes="Nécessite l'horodatage des corners par équipe."),
    _market("last_corner", "corners", "Dernière équipe à obtenir un corner", "full",
            ["home", "away"], [COUNT, EVENTS], "event_arrival", "unsupported"),
    _market("corner_intervals", "corners", "Premier / dernier corner par intervalle", "full",
            ["0-15", "16-30", "31-45", "46-60", "61-75", "76-90"], [EVENTS],
            "event_arrival", "unsupported"),
    _market("ht_corners", "corners", "Corners première / seconde mi-temps", "first_half",
            ["over", "under"], [COUNT, EVENTS], "period_count", "unsupported"),
    _market("corners_result_combo", "corners", "Résultat et total de corners", "full",
            ["home+over", "home+under", "away+over", "away+under"], [SCORE_MATRIX, COUNT],
            "joint_combo", "conditional",
            notes="Dépendance entre résultat et corners non identifiée dans les "
                  "données : produit seulement si le couplage est explicite."),

    # --- Cartons et discipline -------------------------------------------
    _market("total_yellow_cards", "cards", "Total de cartons jaunes", "full",
            ["over", "under"], [COUNT], "negative_binomial", "conditional",
            source="documented",
            notes="Un second jaune entraînant une expulsion n'est pas compté "
                  "comme un carton jaune supplémentaire par 1xBet."),
    _market("total_red_cards", "cards", "Total de cartons rouges", "full",
            ["over", "under"], [COUNT], "negative_binomial", "conditional"),
    _market("total_cards", "cards", "Total cartons jaunes et rouges (YRC)", "full",
            ["over", "under"], [COUNT], "negative_binomial", "conditional"),
    _market("team_total_cards", "cards", "Total de cartons par équipe", "full",
            ["over", "under"], [COUNT], "negative_binomial", "conditional"),
    _market("cards_handicap", "cards", "Handicap de cartons", "full",
            ["home", "away"], [COUNT], "negative_binomial_difference", "conditional"),
    _market("first_team_carded", "cards", "Première équipe avertie", "full",
            ["home", "away"], [COUNT, EVENTS], "event_arrival", "unsupported"),
    _market("last_team_carded", "cards", "Dernière équipe avertie", "full",
            ["home", "away"], [COUNT, EVENTS], "event_arrival", "unsupported"),
    _market("player_carded", "cards", "Joueur averti", "full",
            ["player"], [PLAYER], "player_event_rate", "conditional",
            notes="Exige l'identité du joueur, sa titularisation probable et un "
                  "historique de cartons ; jamais déduit du seul poste."),
    _market("player_sent_off", "cards", "Joueur expulsé", "full",
            ["player"], [PLAYER], "player_event_rate", "insufficient",
            notes="Événement rare : un taux individuel n'est pas estimable de "
                  "façon fiable avec les historiques disponibles."),
    _market("sending_off", "cards", "Expulsion pendant le match", "full",
            ["yes", "no"], [COUNT], "negative_binomial", "conditional"),
    _market("card_in_interval", "cards", "Carton dans un intervalle", "full",
            ["0-15", "16-30", "31-45", "46-60", "61-75", "76-90"], [EVENTS],
            "event_arrival", "unsupported"),
    _market("cards_by_half", "cards", "Cartons par mi-temps", "first_half",
            ["over", "under"], [COUNT, EVENTS], "period_count", "unsupported"),
    _market("disciplinary_points", "cards", "Points disciplinaires", "full",
            ["over", "under"], [COUNT], "negative_binomial", "conditional",
            notes="Barème 1xBet (jaune=1, rouge=3) à confirmer avant publication."),

    # --- Statistiques de match -------------------------------------------
    _market("total_shots", "statistics", "Tirs totaux", "full",
            ["over", "under"], [COUNT], "negative_binomial", "conditional"),
    _market("shots_on_target", "statistics", "Tirs cadrés", "full",
            ["over", "under"], [COUNT], "negative_binomial", "conditional"),
    _market("team_total_shots", "statistics", "Tirs par équipe", "full",
            ["over", "under"], [COUNT], "negative_binomial", "conditional"),
    _market("fouls", "statistics", "Fautes", "full",
            ["over", "under"], [COUNT], "negative_binomial", "conditional"),
    _market("offsides", "statistics", "Hors-jeu", "full",
            ["over", "under"], [COUNT], "negative_binomial", "conditional"),
    _market("free_kicks", "statistics", "Coups francs", "full",
            ["over", "under"], [COUNT], "negative_binomial", "insufficient",
            notes="Aucune source intégrée ne fournit les coups francs."),
    _market("throw_ins", "statistics", "Touches", "full",
            ["over", "under"], [COUNT], "negative_binomial", "unsupported"),
    _market("goalkeeper_saves", "statistics", "Arrêts du gardien", "full",
            ["over", "under"], [COUNT], "negative_binomial", "insufficient"),

    # --- Joueurs ----------------------------------------------------------
    _market("player_anytime_scorer", "players", "Joueur marque à tout moment", "full",
            ["player"], [PLAYER], "player_goal_rate", "conditional"),
    _market("player_first_scorer", "players", "Premier buteur", "full",
            ["player"], [PLAYER], "player_goal_order", "conditional"),
    _market("player_last_scorer", "players", "Dernier buteur", "full",
            ["player"], [PLAYER], "player_goal_order", "conditional"),
    _market("player_two_goals", "players", "Joueur inscrit deux buts", "full",
            ["player"], [PLAYER], "player_goal_rate", "conditional"),
    _market("player_hat_trick", "players", "Hat-trick", "full",
            ["player"], [PLAYER], "player_goal_rate", "insufficient"),
    _market("player_total_shots", "players", "Total de tirs individuel", "full",
            ["over", "under"], [PLAYER], "player_event_rate", "conditional"),
    _market("player_shots_on_target", "players", "Tirs cadrés individuels", "full",
            ["over", "under"], [PLAYER], "player_event_rate", "conditional"),
    _market("player_substituted", "players", "Joueur remplacé", "full",
            ["yes", "no"], [PLAYER], "player_minutes", "insufficient"),

    # --- Temps et événements spéciaux ------------------------------------
    _market("first_goal_interval", "time", "Premier but dans un intervalle", "full",
            ["0-15", "16-30", "31-45", "46-60", "61-75", "76-90", "no_goal"],
            [SCORE_MATRIX], "goal_arrival_interval", "conditional",
            notes="Hypothèse de taux de but constant par intervalle, documentée "
                  "et signalée ; les événements horodatés amélioreraient le modèle."),
    _market("last_goal_interval", "time", "Dernier but dans un intervalle", "full",
            ["0-15", "16-30", "31-45", "46-60", "61-75", "76-90"],
            [SCORE_MATRIX], "goal_arrival_interval", "conditional"),
    _market("goal_before_minute", "time", "But avant une minute donnée", "full",
            ["yes", "no"], [SCORE_MATRIX], "goal_arrival_interval", "conditional"),
    _market("goals_by_interval", "time", "Nombre de buts par intervalle", "full",
            ["over", "under"], [SCORE_MATRIX], "goal_arrival_interval", "conditional"),
    _market("first_corner_time", "time", "Premier corner par intervalle", "full",
            ["0-15", "16-30", "31-45"], [EVENTS], "event_arrival", "unsupported"),
    _market("first_card_time", "time", "Premier carton par intervalle", "full",
            ["0-15", "16-30", "31-45"], [EVENTS], "event_arrival", "unsupported"),
    _market("penalty_awarded", "time", "Penalty accordé", "full",
            ["yes", "no"], [EVENTS], "event_arrival", "insufficient",
            notes="Aucun historique de penalties dans les sources intégrées."),
    _market("penalty_and_red", "time", "Penalty et expulsion", "full",
            ["yes"], [EVENTS], "event_arrival", "unsupported"),
    _market("first_substitution", "time", "Première substitution", "full",
            ["home", "away", "half_time"], [PLAYER, EVENTS], "event_arrival", "unsupported"),
    _market("next_goal", "time", "Prochain but", "live",
            ["home", "away", "no_more_goal"], ["état live"], "live_state", "unsupported",
            live=True,
            notes="Exige un flux live fiable ; volontairement non activé."),
    _market("next_event", "time", "Prochain événement", "live",
            ["corner", "card", "goal", "substitution"], ["état live"],
            "live_state", "unsupported", live=True),

    # --- Combinaisons -----------------------------------------------------
    _market("result_total_combo", "combos", "Résultat et total de buts", "full",
            ["home+over", "home+under", "away+over", "away+under"], [SCORE_MATRIX],
            "joint_combo", "supported"),
    _market("result_btts_combo", "combos", "Résultat et BTTS", "full",
            ["home+yes", "home+no", "away+yes", "away+no"], [SCORE_MATRIX],
            "joint_combo", "supported"),
    _market("result_goals_combo", "combos", "Résultat et nombre de buts", "full",
            ["home+0-1", "home+2-3", "draw+2-3", "away+2-3"], [SCORE_MATRIX],
            "joint_combo", "supported"),
    _market("htft_combo", "combos", "Mi-temps et fin de match", "full",
            ["1/1", "X/X", "2/2", "1/X", "X/1"], [SCORE_MATRIX, HT_SCORE],
            "period_joint", "conditional"),
]

REGISTRY: Dict[str, MarketDefinition] = {market.market_id: market for market in MARKETS}

FAMILIES: Dict[str, str] = {
    "result": "Résultat",
    "goals": "Buts",
    "handicap": "Handicap",
    "periods": "Mi-temps",
    "corners": "Corners",
    "cards": "Cartons",
    "statistics": "Statistiques",
    "players": "Joueurs",
    "time": "Intervalles",
    "combos": "Combinaisons",
    "live": "Live",
}


def market(market_id: str) -> Optional[MarketDefinition]:
    return REGISTRY.get(market_id)


def by_status() -> Dict[str, List[str]]:
    grouped: Dict[str, List[str]] = {}
    for definition in MARKETS:
        grouped.setdefault(definition.status, []).append(definition.market_id)
    return grouped


def registry_summary() -> Dict[str, object]:
    return {
        "market_count": len(MARKETS),
        "family_count": len({definition.family for definition in MARKETS}),
        "by_family": {
            family: len([d for d in MARKETS if d.family == family])
            for family in sorted({definition.family for definition in MARKETS})
        },
        "by_status": {status: len(ids) for status, ids in by_status().items()},
        "live_enabled": False,
    }
