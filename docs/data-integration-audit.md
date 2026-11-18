# Audit d'intégration des sources de données

Date de l'audit : 18 novembre 2026
Branche : `feature/data-integration-validation` (depuis `feature/football-markets-engine` @ `d053a79`)
Base inspectée : MySQL 8.0 (`pronoia-db`, 127.0.0.1:3307), 582 rencontres, 4 278 cotes.

## 1. Méthode

Chaque ressource a été classée par **nature réelle** (dataset, fournisseur, bibliothèque,
données de démonstration) et par **statut d'intégration** au sens strict du terme :

- **VALIDÉ** : les données sont récupérées, normalisées, stockées, **et** consommées par un
  modèle ou un marché, avec des volumes mesurés.
- **PARTIELLEMENT VALIDÉ** : récupération et stockage prouvés, consommation partielle ou
  couverture limitée à un sous-ensemble de compétitions/saisons.
- **NON VALIDÉ** : code ou dataset identifié mais aucune preuve d'import et d'exploitation.
- **BLOQUÉ** : dépendance externe (clé, licence, accès, volumétrie) empêchant la validation.
- **NON PERTINENT** : la ressource n'apporte rien d'exploitable aux marchés actuels.

Un connecteur qui existe n'est jamais considéré comme une intégration : la consommation est
vérifiée dans le code (voir `docs/model-data-lineage.md`).

## 2. Ressources historiques et datasets

| # | Ressource | Nature | Ce qu'elle apporte réellement | Statut |
|---|-----------|--------|-------------------------------|--------|
| 1 | [Club-Football-Match-Data](https://github.com/xgabora/Club-Football-Match-Data) | Dataset historique (CSV, 5 grands championnats, ~2000-2025) | Résultats, cotes 1X2/O-U, tirs, corners, cartons, fautes par équipe et par match. Recoupe partiellement football-data.co.uk ; licence non explicite dans le dépôt. | NON VALIDÉ |
| 2 | [OpenFootball](https://github.com/openfootball) (`football.json`) | Dataset historique (JSON, CC0) | Résultats et scores de mi-temps des grands championnats 2025-26. Connecteur `OpenFootballConnector` opérationnel. | VALIDÉ (résultats) / voir §4 |
| 3 | OpenFootball World | Dataset historique (Football.TXT, CC0) | Résultats des compétitions africaines peu couvertes par les API (Ligue 1 Sénégal). Connecteur `OpenFootballWorldConnector`. | VALIDÉ (résultats) |
| 4 | [StatsBomb Open Data](https://github.com/statsbomb/open-data) | Dataset événementiel (JSON, licence StatsBomb non commerciale) | 3 961 matchs / 80 compétitions-saisons. Events horodatés, compositions, xG par tir, corners, cartons, fautes, hors-jeu, remplacements. **Source la plus riche du projet.** | VALIDÉ (après enrichissement de cette mission) |
| 5 | [OpenPlay](https://github.com/nlbair/openplay) | Données de tracking (échantillon, format propriétaire) | Coordonnées de joueurs et ballon, quelques matchs seulement. Aucun marché du registre n'exploite le tracking. | NON PERTINENT |
| 6 | [Football-Data-Analysis](https://github.com/akareen/Football-Data-Analysis) | Notebooks d'analyse | Pas un dataset : démonstrations de visualisation. | NON PERTINENT |
| 7 | [Metrica Sports Sample Data](https://github.com/metrica-sports/sample-data) | Données de démonstration (3 matchs anonymisés) | Tracking + événements de 3 rencontres non nommées. Inutilisable pour des marchés réels. | NON PERTINENT |
| 8 | [Football-Data.co.uk](https://www.football-data.co.uk/) | Dataset historique + cotes | Résultats, cotes historiques 1X2/O-U/AH, et dans les fichiers « extra » tirs, corners, cartons, fautes. **Complémentarité forte pour backtester corners/cartons et le ROI.** | NON VALIDÉ (recommandé en priorité 1) |

## 3. Bibliothèques

| # | Bibliothèque | Nature | Utilité réelle pour PRONO-IA | Statut |
|---|--------------|--------|------------------------------|--------|
| 9 | [SoccerData](https://github.com/probberechts/soccerdata) | Bibliothèque Python de collecte (scraping FBref, WhoScored, Sofascore, ESPN, ClubElo, Football-Data.co.uk) | Apporterait corners/cartons/FBref, mais par scraping soumis aux conditions d'utilisation des sites, avec des formats instables. Aucune installation : rien ne la justifie tant que football-data.co.uk (CSV direct, licence consultable) n'est pas intégré. | NON PERTINENT (à réévaluer) |
| 10 | [socceraction](https://github.com/ML-KULeuven/socceraction) | Bibliothèque d'analyse (xT, VAEP) | Ne collecte aucune donnée. Ses métriques de valuation ne correspondent à aucun marché du registre. | NON PERTINENT |

Vérification d'environnement : `soccerdata`, `socceraction`, `statsbombpy`, `kloppy` ne sont
**pas** installés dans `ai-service/venv` (seuls `pandas`, `scipy`, `numpy` le sont). Aucune
dépendance n'a été ajoutée : l'extraction StatsBomb reste faite par le connecteur PHP existant.

## 4. Fournisseurs déjà intégrés

| Fournisseur | Ce qu'il fournit | Preuve d'intégration | Statut |
|-------------|------------------|----------------------|--------|
| football-data.org | Calendrier, résultats, scores de mi-temps, classements | 169 rencontres en base (`data_source = football-data.org`), synchronisation planifiée `SyncMatchesCommand` | VALIDÉ |
| API-Football | Calendrier, résultats, statistiques d'équipe | 3 rencontres (`api-football`), `SyncApiFootballCommand` avec quotas (`provider_quotas`) | PARTIELLEMENT VALIDÉ (volume très faible, clé à quota) |
| The Odds API | Cotes 1X2, totals, handicaps, BTTS, double chance | 4 278 `odds_snapshots`, bookmakers Pinnacle/Bet365/Bet-at-home/Betfair/Unibet + consensus | VALIDÉ |

Distinction maintenue dans tout le code et ce rapport :

- **football-data.org** = API commerciale (clé) ;
- **Football-Data.co.uk** = site de fichiers CSV historiques (pas d'API, pas de clé) ;
- **Football-Data-Analysis** = dépôt de notebooks ;
- **SoccerData** = bibliothèque Python.

Aucun de ces quatre éléments n'est interchangeable.

## 5. Ce que cette mission a changé dans l'intégration StatsBomb

Avant : le connecteur n'extrayait que `shots`, `shots_on_target`, `xg`.
Constat mesuré en base : sur 312 lignes `team_match_stats`, `corners`, `fouls`,
`yellow_cards` et `red_cards` étaient renseignés **0 fois**.

Après (`StatsBombConnector::eventStats()`, un seul téléchargement du fichier d'événements
par match, réutilisé pour les tirs et les nouveaux champs) :

- corners (`Pass` + `pass.type.name = Corner`, par équipe) ;
- hors-jeu (`Pass` + `pass.outcome.name = Pass Offside`, par équipe) ;
- fautes (`Foul Committed`) ;
- cartons jaunes/rouges (`foul_committed.card`, `bad_behaviour.card`, second jaune = jaune + rouge) ;
- score à la mi-temps dérivé des buts de période 1 (0-0 inclus, car les événements sont complets) ;
- compositions (`Starting XI`), remplacements (`Substitution`), minutes jouées, buts, tirs, tirs
  cadrés, xG et cartons par joueur.

Persistance : nouvelles colonnes `team_match_stats.offsides`, nouvelle table
`player_match_stats` (unique `match_id + provider + external_player_id`, donc import idempotent).

## 6. Sources non retenues et raisons

- **OpenPlay / Metrica** : tracking sans marché correspondant, échantillons non représentatifs.
- **Football-Data-Analysis** : notebooks, pas de données.
- **socceraction** : métriques sans marché.
- **SoccerData** : scraping, conditions d'utilisation hétérogènes, doublon fonctionnel de
  football-data.co.uk pour les statistiques. Réévaluation possible si football-data.co.uk s'avère
  insuffisant.

## 7. Conclusion

Sources réellement intégrées et exploitées : **StatsBomb Open Data, OpenFootball (dont World),
football-data.org, API-Football (faible volume), The Odds API**.

Aucun fournisseur supplémentaire n'a été ajouté : le gain de cette mission vient de
l'**exploitation complète** d'une source déjà présente (StatsBomb) et de sa transmission
effective jusqu'aux moteurs, pas de l'empilement de connecteurs.
