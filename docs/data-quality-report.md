# Rapport de qualité des données

Mesures du 18 novembre 2026 sur MySQL 8.0 (`prono` : 582 rencontres, 4 278 cotes ;
`prono_test` : validation d'import).

## 1. Complétude

| Table | Lignes | Champs vides constatés |
|---|---|---|
| `matches` | 582 | 48 non terminées (statut normal) ; 428 sans score MT |
| `team_match_stats` | 312 (dev) | avant mission : 312/312 vides sur `corners`, `fouls`, `yellow_cards`, `red_cards`, `possession_pct` |
| `player_match_stats` | 84 (`prono_test`) | `position` parfois NULL (événement sans poste), `is_starter` NULL si le joueur apparaît sans composition |
| `odds_snapshots` | 4 278 | lignes par sélection : une cote manquante n'est jamais remplacée par une valeur moyenne |

Le trou majeur était structurel, pas accidentel : les colonnes existaient mais aucun
connecteur ne les remplissait. Il est corrigé pour les matchs dont les événements sont importés.

## 2. Doublons et unicité

| Risque | Mesure en place | Vérification |
|---|---|---|
| Même rencontre importée par deux sources | `ProviderEntityMap` + déduplication par (ligue, équipes, fenêtre horaire) dans `MultiSourceImporter::findExisting` | test `openfootball_import_is_traceable_and_idempotent` |
| Même match StatsBomb réimporté | clé `provider_entity_maps (provider, external_id)` | import `prono_test` : 380 mises à jour, 0 création en double |
| Même joueur deux fois sur un match | index unique `(match_id, provider, external_player_id)` | réimport → 84 lignes stables |
| Même statistique d'équipe deux fois | `unique(match_id, team_id)` | 6 lignes pour 3 matchs × 2 équipes |

## 3. Incohérences détectées et traitées

1. **Score divergent entre fournisseurs** : le score officiel existant est conservé, la
   divergence est journalisée dans `import_runs.metadata.divergences` et la provenance est
   tout de même enregistrée. Aucun écrasement silencieux.
2. **Heure de coup d'envoi inconnue (StatsBomb)** : stockée à 00:00 UTC avec
   `kickoffTimeKnown = false`, déduplication sur la journée entière. Aucune heure inventée.
3. **Score à la mi-temps** : dérivé des buts de période 1 ; un 0-0 est désormais écrit comme
   0-0 (bug corrigé pendant cette mission : seule la présence d'un but de première période
   déclenchait l'écriture).
4. **Second carton jaune** : compté comme jaune **et** rouge, conforme au décompte des marchés
   d'expulsion ; documenté dans `market_registry` (le total jaunes 1xBet ne compte pas le
   second jaune comme un jaune supplémentaire — écart assumé et signalé).
5. **Encodage des noms de joueurs** : les noms accentués sont stockés en utf8mb4
   (contrôle `LENGTH = 14` / `CHAR_LENGTH = 13` sur « Asmir Begović »), l'affichage terminal
   les tronque mais la base est correcte.
6. **Base non migrée** : `countContext()` filtrait une colonne inexistante → erreur SQL corrigée
   par détection `Schema::hasColumn`.

## 4. Valeurs nulles vs zéros

Règle appliquée partout : **NULL = non observé, 0 = observé et nul**.

- Fichier d'événements absent → `home/away = null`, aucune ligne statistique écrite.
- Fichier complet → un 0 corner est une vraie observation et est écrit comme 0.
- `countContext()` ignore les NULL et n'inclut une colonne que si ≥ 3 valeurs réelles existent.
- `playerContext()` n'inclut un joueur que si ≥ 5 matchs ont un temps de jeu connu > 0.
- Aucun `COALESCE(colonne, 0)` ni moyenne de remplacement dans le code de production.

## 5. Qualité des minutes joueurs

- Les minutes sont calculées à partir de la dernière minute d'événement du match, temps
  additionnel inclus (maximum observé : 95).
- Un titulaire remplacé s'arrête à sa minute de sortie ; un entrant démarre à sa minute d'entrée.
- Un joueur apparu sans `Starting XI` reçoit `is_starter = NULL` : il n'est ni déclaré titulaire
  ni remplaçant.

## 6. Volumétrie et représentativité

- 156 matchs seulement disposent de statistiques d'équipe (Premier League 2015-16).
- 3 matchs disposent de statistiques individuelles complètes après import de validation.
- Conséquence : les marchés corners/cartons/joueurs resteront **non publiés** tant que le
  propriétaire n'a pas réimporté les 380 matchs StatsBomb avec `--stats`, et resteront
  limités à un historique 2015-16 (donc peu prédictifs pour des matchs de 2026).

## 7. Recommandations

1. Réimporter les 380 matchs StatsBomb avec `--stats` par lots de 50 (`--offset`), en tâche de fond.
2. Intégrer football-data.co.uk « extra » pour obtenir corners/cartons **récents** et des cotes
   historiques permettant un vrai backtest par marché.
3. Ne pas activer les marchés joueurs avant d'avoir ≥ 8 matchs par joueur sur les équipes concernées.
4. Ajouter un contrôle qualité automatique (commande planifiée) qui alerte quand une source
   cesse de remplir une colonne : le cas `corners = 0/312` serait ainsi détecté en une journée.
5. Documenter dans l'interface existante (sans nouvelle page) la provenance des marchés publiés,
   déjà disponible dans `data_sources` et `missing_data`.
