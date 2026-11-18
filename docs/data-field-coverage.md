# Couverture des statistiques par source et par compétition

Mesures réalisées le 18 novembre 2026 sur la base MySQL de développement
(`prono`, 582 rencontres) et sur la base isolée `prono_test` (validation d'import).

## 1. Couverture par source (base de développement)

| Statistique | football-data.org (169) | API-Football (3) | StatsBomb (380) | OpenFootball (résultats) | Total renseigné |
|---|---|---|---|---|---|
| Résultat final | 169 | 3 | 380 | oui | 552 |
| Score à la mi-temps | 154 (dont sources API) | 0 | 0 avant mission | oui (champ `ht`) | 154 |
| Tirs | 0 | 0 | 312 lignes (156 matchs) | non | 312 lignes |
| Tirs cadrés | 0 | 0 | 312 | non | 312 |
| xG | 0 | 0 | 312 | non | 312 |
| Corners | 0 | 0 | **0 avant mission** | non | 0 |
| Fautes | 0 | 0 | **0 avant mission** | non | 0 |
| Cartons jaunes | 0 | 0 | **0 avant mission** | non | 0 |
| Cartons rouges | 0 | 0 | **0 avant mission** | non | 0 |
| Hors-jeu | colonne absente du schéma avant mission | – | extractible, non extrait | non | 0 |
| Compositions / minutes | non | non | extractibles, non extraits | non | table absente |
| Cotes | via The Odds API (4 278 snapshots) | – | – | – | 4 278 |

Le décalage était donc structurel : la donnée existait dans les fichiers StatsBomb mais
n'était ni extraite ni stockée, donc aucun modèle corners/cartons ne pouvait s'exécuter.

## 2. Couverture par compétition

| Compétition | Rencontres | Terminées | Score MT | Matchs avec stat d'équipe |
|---|---|---|---|---|
| Premier League (PL) | 417 | 407 | 27 | 156 |
| Serie A (SA) | 45 | 35 | 35 | 0 |
| La Liga (PD) | 36 | 26 | 26 | 0 |
| Ligue 1 (FL1) | 34 | 25 | 25 | 0 |
| Bundesliga (BL1) | 29 | 20 | 20 | 0 |
| Champions League (CL) | 18 | 18 | 18 | 0 |
| Autre (AF6) | 3 | 3 | 3 | 0 |

Conséquence directe : les familles **périodes** (score MT) sont calculables sur 5 compétitions,
mais la famille **corners/cartons** ne dispose d'un historique que sur la Premier League,
et uniquement pour les matchs StatsBomb réimportés avec événements.

## 3. Couverture après enrichissement (validation `prono_test`)

Import réel de 3 rencontres StatsBomb 2015-16 avec `--stats` sur MySQL 8 :

| Match | Équipe | Tirs | Cadrés | xG | Corners | Fautes | Hors-jeu | Jaunes | Rouges |
|---|---|---|---|---|---|---|---|---|---|
| 623 | Manchester City FC | 19 | 6 | 1.25 | 8 | 20 | 1 | 2 | 0 |
| 623 | Arsenal FC | 8 | 5 | 1.35 | 4 | 7 | 3 | 0 | 0 |
| 622 | Aston Villa FC | 19 | 2 | 1.03 | 5 | 11 | 2 | 0 | 0 |
| 621 | Chelsea FC | 23 | 6 | 1.64 | 5 | 17 | 0 | 3 | 0 |

- 6 lignes `team_match_stats` enrichies sur 6 (100 % des matchs importés avec événements) ;
- 84 lignes `player_match_stats` (11 titulaires + remplaçants par équipe) ;
- score à la mi-temps dérivé pour les 3 rencontres (dont un 0-0 conservé comme 0-0, pas comme NULL).

Les valeurs sont plausibles et non constantes : corners 4 à 8, fautes 7 à 20, cartons 0 à 4.
Aucune valeur n'a été complétée artificiellement.

## 4. Profondeur historique exploitable par famille

| Famille de marché | Données requises | Matchs disponibles | Verdict |
|---|---|---|---|
| 1X2, double chance, BTTS, totals | résultats | 534 terminés | exploitable |
| Périodes (MT/FT) | score à la mi-temps | 154 (dont 27 PL seulement) | exploitable, `coverage: thin` hors SA/PD/FL1/BL1/CL |
| Corners | corners par équipe (≥ 3 par équipe) | 0 en base de dev, 3 matchs en `prono_test` | **non exploitable à ce jour** ; nécessite la réimportation StatsBomb `--stats` |
| Cartons, fautes | idem | idem | idem |
| Tirs, tirs cadrés | tirs par équipe | 156 matchs (PL 2015-16) | exploitable |
| Joueurs | compositions + minutes | 3 matchs (`prono_test`), 0 en dev | **non exploitable à ce jour** (échantillon < 8) |
| Hors-jeu | offsides par équipe | schéma prêt, 3 matchs en `prono_test` | non exploitable (échantillon) |
| Live | flux temps réel | aucun fournisseur fiable | non activé |

## 5. Limites documentées

- StatsBomb ne couvre **pas** les saisons en cours : les marchés des matchs à venir ne peuvent
  pas être alimentés par StatsBomb seul, uniquement par un historique des mêmes équipes.
- Les minutes jouées incluent le temps additionnel (maximum observé 95) ; elles ne sont pas
  tronquées à 90.
- Les corners « Pass + Corner » ne comptent pas les corners joués à deux ; StatsBomb les
  enregistre comme corners distincts dans les événements, ce qui correspond au décompte bookmaker.
- Football-data.co.uk n'est pas intégré : c'est la seule source identifiée qui apporterait
  corners/cartons **et** cotes historiques sur des saisons récentes.
