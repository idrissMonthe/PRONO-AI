# Données football multi-sources

Ce document décrit les sources additionnelles intégrées à PRONO IA, leur
périmètre réel, et les mécanismes de normalisation, déduplication et
traçabilité. football-data.org reste le fournisseur principal pour les matchs à
venir ; API-Football complète les compétitions internationales. Les sources
ci-dessous n'ont **pas** de données temps réel.

## 1. Sources et capacités vérifiées

| Source | Format | Calendriers à venir | Résultats historiques | Stats avancées | Live | Licence |
|---|---|---|---|---|---|---|
| `openfootball/football.json` | JSON par saison | Oui, pour les saisons publiées | Oui (scores FT/HT) | Non | Non | CC0 1.0 |
| `openfootball/world` | Football.TXT | Oui, pour les saisons publiées | Oui (scores FT, parfois HT) | Non | Non | CC0 1.0 |
| `statsbomb/open-data` | JSON (`matches`, `events`, `lineups`) | Non (historique uniquement) | Oui | Oui : tirs, xG, événements, compositions | Non | Données ouvertes StatsBomb, attribution obligatoire, usage recherche/analyse |

Aucune de ces sources ne fournit de cotes. Aucune ne remplace les appels
football-data.org/API-Football pour le live.

## 2. Compétitions importables

### openfootball/football.json (saison `2025-26` par défaut)

| Fichier | Code interne | Compétition |
|---|---|---|
| `en.1.json` | `PL` | Premier League |
| `es.1.json` | `PD` | La Liga |
| `de.1.json` | `BL1` | Bundesliga |
| `it.1.json` | `SA` | Serie A |
| `fr.1.json` | `FL1` | Ligue 1 |
| `nl.1.json` | `OF-NL1` | Eredivisie |
| `pt.1.json` | `OF-PT1` | Primeira Liga |
| `be.1.json` | `OF-BE1` | Pro League |
| `tr.1.json` | `OF-TR1` | Süper Lig |
| `gr.1.json` | `OF-GR1` | Super League Grèce |

Les codes `PL`, `PD`, `BL1`, `SA` et `FL1` sont **volontairement identiques** à
ceux de football-data.org : c'est ce qui permet de rattacher un match
OpenFootball au match déjà connu sans créer de doublon.

### openfootball/world (Football.TXT)

| Clé | Fichier | Code | Compétition |
|---|---|---|---|
| `senegal-2025-26` | `africa/senegal/2025-26_sn1.txt` | `SN1` | Ligue 1 Sénégal |
| `senegal-2024-25` | `africa/senegal/2024-25_sn1.txt` | `SN1` | Ligue 1 Sénégal |

Le dépôt contient d'autres pays (Égypte, Rwanda, Tanzanie, Ouganda, Afrique du
Sud, etc.). Pour en ajouter un, il faut **vérifier le chemin et le format**
puis l'ajouter dans `config/imports.php`. Aucun chemin n'est deviné.

### StatsBomb Open Data

Une seule saison est configurée par défaut, car elle a été vérifiée dans
`competitions.json` : Premier League 2015/2016 (`competition_id=2`,
`season_id=27`). Pour en ajouter une, consulter
`data/competitions.json` et ajouter le couple exact dans
`config/imports.php`. Un couple non configuré est refusé explicitement.

## 3. Architecture

```
openfootball ─┐
openfootball/world ─┤→ connecteurs → NormalizedMatch → MultiSourceImporter
statsbomb ────┘                               │
                                              ├→ League / Season / Team (résolution existante)
                                              ├→ ProviderEntityMap (provenance + id externe)
                                              ├→ FootballMatch (déduplication bornée)
                                              └→ TeamMatchStat (tirs, cadrés, xG StatsBomb)
```

- `app/Services/Import/OpenFootballConnector.php`
- `app/Services/Import/OpenFootballWorldConnector.php`
- `app/Services/Import/StatsBombConnector.php`
- `app/Services/Import/NormalizedMatch.php`
- `app/Services/Import/MultiSourceImporter.php`
- `app/Models/ImportRun.php` + table `import_runs`

## 4. Normalisation et déduplication

- Les équipes passent par `FootballDataService::resolveTeam()` : nom exact,
  alias validé, puis clé normalisée (suffixes `FC`, `CF`…). Toute
  correspondance approximative crée un alias tracé, jamais une fusion muette.
- Un match est retrouvé par (1) identifiant fournisseur via
  `provider_entity_maps`, (2) même compétition + mêmes équipes + coup d'envoi à
  ±6 h, ou (3) même compétition + mêmes équipes + même **journée** quand la
  source ne fournit pas d'heure (StatsBomb, Football.TXT).
- Quand une source ne donne pas l'heure, la rencontre est stockée à 00:00 UTC
  et l'heure est marquée inconnue : aucune heure n'est inventée.
- Si un match existant possède déjà un score d'un autre fournisseur et que le
  score entrant diffère, **le score existant est conservé** et la divergence est
  enregistrée dans `import_runs.metadata.divergences`. Aucun écrasement
  silencieux.
- La provenance (`matches.data_source`, `matches.external_id`) d'origine est
  préservée ; `provider_entity_maps` conserve l'identifiant de chaque source.

## 5. Commandes

```bash
# OpenFootball JSON (saison configurée, toutes compétitions configurées)
php artisan oracle:import-openfootball

# Une saison / des fichiers précis, sans écrire en base
php artisan oracle:import-openfootball --season=2025-26 --competition=en.1.json --dry-run

# openfootball/world
php artisan oracle:import-openfootball-world --league=senegal-2025-26

# StatsBomb : matchs historiques
php artisan oracle:import-statsbomb

# StatsBomb : matchs + tirs/cadrés/xG (limite le nombre de fichiers d'événements)
php artisan oracle:import-statsbomb --stats --limit=10
```

Chaque exécution crée une ligne `import_runs` (statut, compteurs, erreurs,
divergences). Le code de sortie est non nul en cas d'échec ou d'import partiel.

## 6. Variables d'environnement

Toutes optionnelles ; aucune clé n'est nécessaire (dépôts publics).

```dotenv
OPENFOOTBALL_ENABLED=true
OPENFOOTBALL_TIMEOUT=20
OPENFOOTBALL_WORLD_ENABLED=true
OPENFOOTBALL_WORLD_TIMEOUT=20
STATSBOMB_ENABLED=true
STATSBOMB_IMPORT_STATS=true
STATSBOMB_STATS_LIMIT=25
STATSBOMB_TIMEOUT=30
```

Pour désactiver une source : passer la variable à `false`. Les commandes
correspondantes s'arrêtent alors sans écrire, et les données déjà importées
restent utilisables.

## 7. Planification

Ces imports sont **manuels** par défaut. Si vous les planifiez, gardez des
fréquences réalistes (quotidienne ou hebdomadaire) : les dépôts sont mis à jour
par contributions humaines, pas en continu. Ne les ajoutez pas au cron toutes
les minutes.

## 8. Limites connues

- **Pas de live** : les trois sources sont historiques ; le live reste assuré
  par football-data.org et API-Football.
- **StatsBomb ne couvre pas les saisons récentes** de la plupart des
  compétitions et ne fournit ni cotes ni calendrier à venir.
- **Heure de coup d'envoi absente** dans StatsBomb et Football.TXT : la
  déduplication se fait à la journée, avec un risque résiduel de deux matchs
  le même jour entre les mêmes équipes (très rare, et signalé comme divergence
  plutôt que fusionné).
- **Pas de fusion des statistiques avec les modèles** : `team_match_stats` est
  alimenté, mais Dixon-Coles/Elo n'exploitent pas encore xG. Toute
  modification des pondérations demanderait un backtest dédié.
- **Attribution StatsBomb** obligatoire dans toute publication d'analyse
  dérivée.
- Les fichiers `*.json` volumineux (événements) sont lus en mémoire ; la limite
  `--limit` borne le nombre de fichiers par exécution.
