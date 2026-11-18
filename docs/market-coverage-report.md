# Rapport de couverture des marchés

État au 18 novembre 2026, branche `feature/football-markets-engine`.
Ce rapport décrit ce que PRONO-IA peut réellement calculer, pas ce que 1xBet
affiche.

## 1. Synthèse

| Indicateur | Valeur |
| --- | --- |
| Familles auditées | 10 (résultat, buts, handicap, périodes, corners, cartons, statistiques, joueurs, intervalles, combinaisons) + live |
| Définitions de marchés au registre | 86 |
| Marchés `supported` (toutes rencontres couvertes) | 18 |
| Marchés `conditional` | 47 |
| Marchés `insufficient` | 6 |
| Marchés `unsupported` | 15 |
| Marchés live activés | 0 |
| Modèles indépendants créés | 0 (5 moteurs de distribution réutilisables) |

Le registre est la source de vérité : `GET /v1/markets/registry` et
`app/core/market_registry.py`.

## 2. Par compétition

La couverture dépend de la source qui a alimenté la rencontre, pas de la
compétition en soi.

| Compétition / source | Résultats | Mi-temps | xG | Tirs | Corners | Cartons | Joueurs |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Premier League (football-data.org + OpenFootball + StatsBomb 2015-16) | oui | oui (selon match) | 156 matchs | oui sur les matchs StatsBomb | non extrait | non extrait | non ingéré |
| La Liga / Bundesliga / Serie A / Ligue 1 (football-data.org + OpenFootball) | oui | selon match | non | non | non | non | non |
| Eredivisie, Primeira, Pro League, Süper Lig, Grèce (OpenFootball) | oui | parfois | non | non | non | non | non |
| Ligue 1 Sénégal (OpenFootball World) | oui | parfois | non | non | non | non | non |
| CAN / compétitions africaines (API-Football) | oui | oui | non | non | non | non | non |
| Compétitions sans historique local | oui | non | non | non | non | non | non |

Conséquence : seuls le résultat, les buts, les handicaps et les combinaisons de
buts sont publiés pour toutes ces compétitions. Les périodes ne le sont que si
la compétition expose des scores mi-temps. Corners et cartons ne le sont nulle
part aujourd'hui, faute d'extraction.

### Requêtes de contrôle

```sql
-- Couverture mi-temps par compétition
SELECT l.code, COUNT(*) AS matchs,
       SUM(m.ht_home_score IS NOT NULL) AS avec_mi_temps
FROM matches m JOIN leagues l ON l.id = m.league_id
WHERE m.status = 'FINISHED'
GROUP BY l.code ORDER BY matchs DESC;

-- Couverture des comptages
SELECT COUNT(*) AS lignes,
       SUM(corners IS NOT NULL) AS corners,
       SUM(yellow_cards IS NOT NULL) AS jaunes,
       SUM(shots IS NOT NULL) AS tirs,
       SUM(xg IS NOT NULL) AS xg
FROM team_match_stats;
```

## 3. Marchés calculables dès maintenant

Pour une rencontre dont les deux équipes ont un historique Dixon-Coles :

- 1X2, double chance 1X/X2/12, Draw No Bet ;
- handicap européen (−2…+2), handicap asiatique (0, ±0,25, ±0,5, ±1, ±1,5, ±2) ;
- total de buts (lignes générées), total par équipe, total asiatique ;
- BTTS, clean sheet, win to nil, aucun but ;
- score exact (Audacieux), nombre exact de buts, pair/impair, fourchettes ;
- équipe avec le plus de buts ;
- résultat + total, résultat + BTTS, résultat + nombre de buts.

Ces marchés découlent tous de la même matrice ; leurs probabilités sont
cohérentes entre elles (somme des issues exclusives vérifiée par tests).

## 4. Marchés conditionnels

Publiés uniquement si l'échantillon existe :

- **Périodes** : ≥ 5 matchs avec score mi-temps. Fiabilité abaissée en dessous
  de 80 matchs.
- **Corners, cartons, tirs, fautes** : profils par équipe (≥ 3 valeurs,
  `partial` à 8, `full` à 25). Aujourd'hui : **aucune valeur corners/cartons**
  en base, donc aucune sélection de ces familles.
- **Joueurs** : ≥ 8 matchs, participation ≥ 0,60 ou composition officielle.
  Aujourd'hui : aucune composition ingérée.
- **Premier/dernier buteur, premier but par intervalle** : hypothèse de taux
  constant explicitement signalée (`coverage: thin`).
- **Arbitre** : jamais modélisé sans identité et statistiques vérifiées.

## 5. Marchés impossibles aujourd'hui

| Marché | Raison |
| --- | --- |
| Premier / dernier corner, corner par intervalle | Horodatage des corners absent de la base |
| Corners par mi-temps | Répartition horodatée absente |
| Carton par intervalle, cartons par mi-temps | Horodatage absent |
| Premier / dernière équipe avertie | Horodatage absent |
| Hors-jeu | Colonne absente de `team_match_stats` |
| Coups francs, touches | Aucune source intégrée |
| Arrêts du gardien | Non collecté |
| Penalty accordé | Aucun historique de penalties |
| Penalty + expulsion | Aucune source |
| Première substitution | Compositions/événements absents |
| Prochain but / prochain événement (live) | Aucun flux live fiable |
| OptionBet | Fonctionnalité commerciale, pas un marché statistique |
| 2UP | Règle exacte non confirmée pour le Cameroun |
| Équipe qualifiée | Format/prolongation non fournis par la compétition |

Un marché de cette liste ne reçoit **jamais** de probabilité inventée : il est
absent de la réponse, et la raison figure dans `market_coverage.skipped`.

## 6. Preuve d'intégration bout en bout

- `GeneratePredictionsCommand` transmet `period_share` et `counts` réels.
- La réponse Python est persistée dans `derived_markets_json`
  (`market_predictions`, `market_categories`, `market_coverage`,
  `combinations`, `period_profile`).
- `DashboardController` affiche les sélections classées dans les trois
  catégories existantes (Les plus sûrs, Équilibrés, Audacieux).
- `ParlayService` accepte les familles corners/cartons/tirs uniquement avec une
  cote bookmaker réelle sur la même ligne.
- Tests : `tests/Feature/MarketEngineIntegrationTest.php`,
  `ai-service/tests/test_market_engine.py`,
  `ai-service/tests/test_market_api.py`.

## 7. Limites du rapport

- La disponibilité commerciale exacte sur `1xbet.cm` n'a pas pu être confirmée
  (pages publiques rendues en JavaScript, document réglementaire régional
  distinct). Le catalogue décrit les marchés documentés/observés, pas une
  garantie de ligne au Cameroun.
- Les couvertures par compétition ci-dessus reflètent les sources configurées ;
  les volumes exacts doivent être lus avec les requêtes SQL fournies.
- Aucun ROI n'est mesuré : les cotes historiques par marché sont absentes.
- Les nouveaux marchés n'ont pas encore de backtest par famille : ils restent
  conditionnels et leur fiabilité est plafonnée.

## 8. Actions recommandées au propriétaire

1. Étendre l'extracteur StatsBomb aux corners, cartons et fautes, puis
   relancer `oracle:import-statsbomb --stats`.
2. Ingérer les compositions (StatsBomb lineups ou API-Football) avant
   d'activer les marchés joueurs.
3. Conserver des snapshots de cotes historiques pour mesurer le ROI.
4. Vérifier sur `1xbet.cm` la présence réelle des marchés 2UP, asiatiques quarts
   et OptionBet avant toute exposition commerciale.
5. Ne pas activer les marchés live avant d'avoir un fournisseur temps réel
   fiable.
