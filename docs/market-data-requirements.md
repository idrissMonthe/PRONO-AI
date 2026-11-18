# Données requises par marché et couverture des fournisseurs

Ce document relie chaque famille de marchés aux variables nécessaires et aux
sources réellement intégrées dans PRONO-IA. Il ne suppose aucune disponibilité :
chaque ligne a été vérifiée dans le code des connecteurs et dans le schéma.

## 1. Capacités des fournisseurs (état du dépôt)

| Source | Compétitions / saisons | Résultats | Score mi-temps | Stats équipe | Stats joueur | Événements horodatés | Compositions | xG | Corners | Cartons | Tirs | Mise à jour |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| football-data.org v4 | compétitions du compte (`FOOTBALL_DATA_COMPETITIONS`) | oui | oui (selon compétition) | non | non | non | non | non | non | non | non | quasi temps réel (selon plan) |
| API-Football | compétitions configurées (`API_FOOTBALL_COMPETITIONS`) | oui | oui | non ingéré actuellement | non | non | non | non | non | non | non | live/quotidien |
| The Odds API | ligues configurées | non | non | non | non | non | non | non | non | non | non | snapshots de cotes (`h2h`, `totals`, `spreads`, `btts`, `team_totals`, `double_chance`) |
| OpenFootball `football.json` | 10 compétitions, saison configurée | oui | parfois | non | non | non | non | non | non | non | non | manuelle (contributions) |
| OpenFootball World | Sénégal 2024-25 / 2025-26 | oui | parfois | non | non | non | non | non | non | non | non | manuelle |
| StatsBomb Open Data | PL 2015-2016 (configurée) | oui | oui | **oui** | via événements (non ingéré) | **oui** (événements) | **oui** (non ingéré) | **oui** | **oui dans la source** | **oui dans la source** | **oui** | historique figé |

### Ce qui est réellement écrit en base

`app/Services/Import/StatsBombConnector.php::shotStats()` n'extrait aujourd'hui
que `shots`, `shots_on_target` et `xg`. Les colonnes `corners`, `fouls`,
`yellow_cards`, `red_cards` **existent** dans `team_match_stats` mais ne sont
pas alimentées. Conséquence directe :

- les marchés corners, cartons, fautes et hors-jeu **ne sont pas publiés**
  aujourd'hui, même si le moteur sait les calculer ;
- le moteur ne remplit jamais une moyenne manquante par une valeur arbitraire :
  sans échantillon (≥ 3 valeurs), la famille est absente de la réponse.

## 2. Matrice marché → données → statut

| Famille | Variables nécessaires | Source actuelle | Statut réel |
| --- | --- | --- | --- |
| 1X2, double chance, DNB, handicaps | matrice de scores (λ domicile/extérieur, ρ) | Dixon-Coles + Elo + forme + cotes | SUPPORTÉ |
| Totaux, BTTS, score exact, pair/impair, fourchettes | matrice de scores | idem | SUPPORTÉ |
| Totaux par équipe | marges de la matrice | idem | SUPPORTÉ |
| Résultat + total / BTTS | matrice jointe | idem | SUPPORTÉ |
| Mi-temps, 2e mi-temps, HT/FT | scores mi-temps réels | football-data.org / API-Football / StatsBomb (HT) | CONDITIONNEL |
| Corners | corners par équipe et par match | StatsBomb (non extrait) | CONDITIONNEL (aucune donnée en base aujourd'hui) |
| Cartons | jaunes/rouges par équipe | StatsBomb (non extrait) | CONDITIONNEL (aucune donnée aujourd'hui) |
| Tirs / tirs cadrés | `shots`, `shots_on_target` | StatsBomb | CONDITIONNEL |
| Fautes | `fouls` | StatsBomb (non extrait) | CONDITIONNEL |
| Hors-jeu | colonne dédiée | absente du schéma | NON SUPPORTÉ |
| Coups francs, touches | comptages dédiés | absents | NON SUPPORTÉ |
| Arrêts du gardien | comptage dédié | absent | INSUFFISANT |
| Joueurs (buteur, tirs, carton) | compositions, minutes, taux individuels | StatsBomb lineups/events (non ingéré) | CONDITIONNEL |
| Premier/dernier corner, carton par intervalle | horodatage | StatsBomb events (non ingéré) | NON SUPPORTÉ en pratique |
| Premier but par intervalle | λ total | matrice de scores | CONDITIONNEL (hypothèse de taux constant) |
| Penalty | événements | absent | INSUFFISANT |
| Live (prochain but, prochain événement) | flux live fiable | aucun fournisseur intégré | NON SUPPORTÉ |

## 3. Données minimales par famille (seuils)

| Famille | Seuil de publication | Justification |
| --- | --- | --- |
| Périodes | ≥ 5 matchs avec score mi-temps ; `partial` à 20, `full` à 80 | En dessous, la part 1re mi-temps est un prior, pas une mesure |
| Corners / cartons / tirs / fautes | ≥ 3 valeurs par équipe pour un profil, `partial` à 8, `full` à 25 | La variance d'une loi de comptage n'est pas estimable sur 1-2 matchs |
| Joueurs | ≥ 8 matchs, titularisation ≥ 0,60 ou composition officielle | Un taux individuel sur peu de minutes est dominé par le hasard |
| xG | ≥ 3 observations de compétition, couverture par équipe | Déjà appliqué par le moteur xG |
| Calibration | ≥ 30 évaluations par famille (`min_calibration_sample`) | En dessous, aucune confiance élevée |

## 4. Lacunes à combler (actions propriétaire)

1. **Extraire corners et cartons StatsBomb** dans `shotStats()` (ou un nouvel
   extracteur d'événements) pour alimenter `team_match_stats` :
   `Pass`/`Corner` pour les corners, `Foul Committed` + `Bad Behaviour` pour les
   cartons. Sans cela, les moteurs corners/cartons restent inactifs.
2. **Importer les compositions StatsBomb** (`lineups.json`) si les marchés
   joueurs doivent être activés. L'API-Football peut fournir les compositions
   des rencontres à venir si le plan le permet — non branché aujourd'hui.
3. **Étendre l'extraction d'événements** (minute des corners, cartons, buts)
   pour remplacer l'hypothèse de taux constant des marchés temporels.
4. **Vérifier la disponibilité 1xBet Cameroun** des marchés 2UP, OptionBet et
   des lignes asiatiques avant de les exposer dans l'interface.
5. **Ne pas ajouter de cotes simulées** : les combinés corners/cartons ne sont
   proposés que si The Odds API renvoie un prix réel pour la même ligne.

## 5. Provenance et traçabilité

- `team_match_stats` est relié au match, donc à `matches.data_source` et
  `provider_entity_maps` : chaque valeur conserve sa source.
- Les marchés générés exposent `data_sources` et `missing_data`.
- Une famille absente est listée dans `market_coverage.skipped` avec sa raison
  (`no_player_data`, `no_halftime_scores`, `count_insufficient_sample`).
