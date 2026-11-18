# Priorités des sources de données

Classement par **valeur ajoutée réelle** pour les marchés du registre (86 définitions),
en tenant compte de ce qui est déjà en base et de ce qui manque effectivement.

## 1. Classement

| Rang | Source | Valeur ajoutée | Statut actuel | Effort | Décision |
|---|---|---|---|---|---|
| 1 | **Football-Data.co.uk** (`football-data.co.uk/mmz4281/...` + fichiers « extra ») | Corners, cartons, fautes **sur saisons récentes** (2015→aujourd'hui) pour 22 divisions, et cotes historiques 1X2/O-U/AH → débloque corners/cartons **et** le backtest par marché avec ROI. | NON VALIDÉ | Moyen : 1 connecteur CSV, licence consultable, fichiers légers (< 1 Mo/saison) | **À intégrer en priorité** |
| 2 | **StatsBomb** (réimport complet `--stats`) | Déjà la source la plus riche : événements, compositions, minutes, xG. Rien à coder, seulement à importer. | VALIDÉ (3 matchs validés en MySQL) | Faible : commande existante par lots | **À exécuter par le propriétaire** |
| 3 | **football-data.org** | Résultats, scores MT, calendrier des grands championnats. Base du Dixon-Coles. | VALIDÉ | – | Conserver |
| 4 | **The Odds API** | Cotes réelles, unique source autorisant un ROI vérifiable et des combinés chiffrés. | VALIDÉ (4 278 snapshots) | – | Conserver, surveiller le quota |
| 5 | **API-Football** | Résultats + statistiques d'équipe, mais quota serré et seulement 3 rencontres importées. | PARTIELLEMENT VALIDÉ | – | Conserver en secours |
| 6 | **OpenFootball / OpenFootball World** | Résultats récents (2025-26) et compétitions africaines peu couvertes. Scores MT. | VALIDÉ (résultats) | – | Conserver |
| 7 | **Club-Football-Match-Data** | Recoupement résultats/statistiques 5 grands championnats, mais redondant avec football-data.co.uk et licence floue. | NON VALIDÉ | Moyen | Ne pas intégrer ; réévaluer si #1 échoue |
| 8 | **SoccerData** | Scraping FBref/WhoScored/Sofascore. Doublon fonctionnel de #1, ToS hétérogènes. | NON PERTINENT | Élevé (maintenance du scraping) | Ne pas installer |
| 9 | **socceraction** | xT/VAEP, sans marché correspondant. | NON PERTINENT | – | Ne pas installer |
| 10 | **OpenPlay / Metrica** | Tracking d'échantillons non représentatifs. | NON PERTINENT | – | Ignorer |

## 2. Pourquoi Football-Data.co.uk avant tout autre

C'est la seule source identifiée qui réunit les trois conditions manquantes :

1. **statistiques de comptage récentes** (corners, cartons, fautes, tirs) sur les
   compétitions où le projet publie déjà des pronostics ;
2. **cotes historiques** (1X2, O/U 2.5, AH) permettant un backtest avec ROI, aujourd'hui
   impossible (le rapport de backtest indique `odds_available: false`) ;
3. **coût d'intégration faible** : un fichier CSV par division et par saison, sans clé API.

Elle ne remplace pas StatsBomb (pas d'événements horodatés ni de xG par tir) : les deux sont
complémentaires et ne se doublonnent pas.

## 3. Séquence recommandée

1. **Réimporter StatsBomb avec événements** (aucun développement) :
   ```bash
   for offset in $(seq 0 50 350); do
     php artisan oracle:import-statsbomb --stats --limit=50 --offset=$offset
   done
   ```
   Effet attendu : `team_match_stats.corners/fouls/yellow_cards/red_cards/offsides` renseignés
   sur ~156 matchs, `player_match_stats` peuplée, marchés corners/cartons activables.
2. **Intégrer Football-Data.co.uk** : nouveau connecteur CSV + normalisation vers
   `team_match_stats` (corners, cartons, fautes, tirs) et `odds_snapshots` (cotes historiques).
   La colonne de provenance `provider_entity_maps` existe déjà.
3. **Backtester par marché** avec les cotes historiques : log loss, Brier, calibration, ROI par
   famille, walk-forward sans fuite.
4. **Marchés joueurs** : n'activer que lorsque `player_match_stats` couvre ≥ 8 matchs par joueur ;
   sinon ils restent `insufficient`.
5. **Live** : aucun fournisseur fiable identifié ; maintenir `live_enabled = false`.

## 4. Ce qui ne doit pas être fait

- Ajouter SoccerData « pour avoir plus de sources » : elle récupère les mêmes données par
  scraping fragile.
- Compter StatsBomb et SoccerData comme deux sources indépendantes : ce sont les mêmes matchs.
- Publier des marchés corners/cartons dès maintenant : l'échantillon réel est de 3 matchs.
- Modifier les pondérations du modèle sans backtest significatif (voir §5).

## 5. État expérimental actuel

Backtest walk-forward (407 matchs, 156 avec xG, fenêtre de test de 172 matchs) :

| Modèle | Log loss | Brier | RPS | Précision 1N2 | ECE |
|---|---|---|---|---|---|
| Dixon-Coles (référence) | 1,0322 | 0,6072 | 0,2042 | 48,8 % | 0,0865 |
| Dixon-Coles + xG | écart moyen +0,0042 (défavorable) | – | – | – | – |

Test de Diebold-Mariano xG vs Dixon-Coles : p = 0,565 → **non significatif**.
Calibration Platt/isotonic vs référence : p = 0,840 → non significative.
Conclusion : `XG_ADJUSTMENT_WEIGHT` reste à 0. Aucune amélioration statistique n'est
revendiquée ; seul un jeu de données plus récent et plus large (priorité 1) permettra de
trancher.
