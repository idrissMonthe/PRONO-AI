# PRONO-IA — Préparation des données pour la production

Branche : `feature/production-data-readiness` (depuis `feature/data-integration-validation`, commit `73109b1`).
Base : MySQL 8 local `prono` (conteneur `pronoia-db`, `127.0.0.1:3307`, non exposée au réseau).
Base de recette : `prono_test`, isolée, utilisée avant toute écriture en développement.
Sauvegarde préalable : `storage/tmp/prono_backup_20261118_062945.sql` (23 Mo).

Statuts utilisés : **VALIDÉ**, **PARTIELLEMENT VALIDÉ**, **NON VALIDÉ**, **BLOQUÉ**, **NON PERTINENT**.

## 1. Compteurs réels

Distinguer les couches évite de confondre « téléchargé » et « utilisable ».

| Couche | Volume | Statut |
|---|---|---|
| Rencontres en base | 2 808 | VALIDÉ |
| Rencontres terminées | 2 751 | VALIDÉ |
| Rencontres avec corners | 2 632 | VALIDÉ |
| Rencontres avec cartons | 2 632 | VALIDÉ |
| Rencontres avec fautes | 2 632 | VALIDÉ |
| Rencontres avec hors-jeu | 380 | PARTIELLEMENT VALIDÉ |
| Rencontres avec xG | 880 | PARTIELLEMENT VALIDÉ |
| Rencontres avec score à la pause | 2 751 | VALIDÉ |
| Lignes de statistiques joueurs | 10 469 (380 rencontres) | PARTIELLEMENT VALIDÉ |
| Cotes en base | 36 260 | VALIDÉ |
| Snapshots de pronostics | 171 | VALIDÉ (règlement en attente, matchs à venir) |

Répartition par fournisseur : `football-data-uk` 2 217 · `statsbomb` 380 · `openfootball`/`openfootball-world` 620 · `football-data.org` 178 · `manual` 30 · `api-football` 3.

## 2. Ce qui a réellement été importé dans cette mission

### StatsBomb — **VALIDÉ**

- 380 rencontres Premier League 2015-2016, un seul fichier d'événements lu par match.
- Import complet exécuté : `380 reçus / 380 mis à jour / 0 rejeté / 380 jeux de statistiques`, code de sortie 0.
- Corners, fautes, cartons jaunes et rouges, hors-jeu, tirs, tirs cadrés, xG, compositions, titularisations, remplacements, minutes, buts et cartons individuels.
- 10 469 lignes `player_match_stats`, provenance `statsbomb`.
- Les 760 lignes de statistiques d'équipe portent `provider = statsbomb`.

Un cache local des fichiers d'événements (`storage/app/statsbomb-cache`, hors Git, 991 Mo) rend l'import reprenable : la licence StatsBomb interdit la redistribution, les fichiers ne sont donc jamais versionnés.

### Football-Data.co.uk — **VALIDÉ**

- 2 217 rencontres, 3 132 lignes de statistiques d'équipe, 21 729 cotes de clôture.
- Cinq championnats (Premier League, La Liga, Serie A, Bundesliga, Ligue 1), saisons 2025-26 et 2026-27.
- Idempotence vérifiée : second passage = `0 importé / 100 mis à jour`, aucune cote dupliquée.
- Détail dans `docs/football-data-uk-integration.md`.

## 3. Ce qui reste incomplet — et pourquoi

| Sujet | Statut | Explication |
|---|---|---|
| Cotes historiques corners / cartons | **BLOQUÉ** | Football-Data.co.uk ne publie que 1N2, Plus/Moins 2,5 et handicaps. Aucune source gratuite vérifiée ne fournit l'historique corners/cartons. Aucun ROI ne peut donc être calculé pour ces familles. |
| Statistiques joueurs au-delà de StatsBomb | **BLOQUÉ** | Les autres sources ne publient ni compositions ni minutes. Aucune valeur n'est extrapolée. |
| Temps réel | **NON VALIDÉ** | Aucun fournisseur temps réel fiable configuré ; le live reste désactivé. |
| MariaDB | **NON PERTINENT** | Le socle déployé est MySQL 8.0 ; aucune divergence de schéma n'a été rencontrée. |
| Règlement des 171 snapshots | **PARTIELLEMENT VALIDÉ** | Ils portent sur les 57 rencontres à venir : ils restent `PENDING` tant qu'aucun résultat définitif n'existe. |

## 4. Intégrité des données

- Aucune valeur manquante n'est convertie en zéro : un fichier sans colonne `HxG` laisse `xg` nul, un match sans statistiques ne crée aucune ligne.
- La provenance est portée par `team_match_stats.provider` ; un import ne remplace jamais un fait mesuré par un autre fournisseur, il ne complète que les champs vides.
- Aucun import n'a été exécuté sur une base de production. `APP_ENV=production` dans les conteneurs ne désigne ici qu'un réglage local : l'hôte est `127.0.0.1` et la base s'appelle `prono`.

## 5. Verdict

Le socle de données est **prêt pour l'entraînement à grande échelle** : 2 751 rencontres terminées, 2 632 avec corners et cartons, 880 avec xG, cotes de clôture réelles sur 2 252 rencontres.

Il n'est **pas encore prêt pour un ROI multi-marchés** : seuls 1N2 et Plus/Moins 2,5 disposent de cotes historiques.
