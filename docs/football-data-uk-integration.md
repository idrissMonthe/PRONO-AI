# Intégration Football-Data.co.uk

## 1. Audit de la source

Football-Data.co.uk publie un CSV par compétition et par saison à l'adresse
`https://www.football-data.co.uk/mmz4281/{saison}/{division}.csv` (`2627` = 2026-27).

- **Compétitions vérifiées** : `E0` (Premier League), `SP1` (La Liga), `I1` (Serie A), `D1` (Bundesliga), `F1` (Ligue 1), plus `N1`, `P1`, `B1`, `T1`, `G1` déclarées dans la configuration.
- **Saisons réellement récupérées** : 2026-27 (en cours) et 2025-26.
- **Accès** : le serveur renvoie un corps vide sans en-tête `User-Agent` ; le connecteur en envoie un. Aucune clé n'est requise.
- **Licence** : données librement consultables, réutilisation non commerciale. Les fichiers bruts sont mis en cache dans `storage/app/football-data-uk` (hors Git) et ne sont pas redistribués.

### Colonnes constatées (fichier E0 2026-27)

```
Div, Date, Time, HomeTeam, AwayTeam, FTHG, FTAG, FTR, HTHG, HTAG, HTR,
Referee, HxG, AxG, HS, AS, HST, AST, HF, AF, HC, AC, HY, AY, HR, AR,
B365H/D/A, PSH/PSD/PSA, BFH/BFD/BFA, MaxH/D/A, AvgH/D/A,
B365>2.5, B365<2.5, Max>2.5, Max<2.5, Avg>2.5, Avg<2.5, BFE>2.5, …
```

Les colonnes varient selon les saisons : `HxG`/`AxG` n'existent que récemment, `PSH` (Pinnacle) apparaît en cours de période, les colonnes de cotes asiatiques changent de nom. **Chaque champ est lu par son nom, jamais par sa position**, et une colonne absente reste nulle.

Le premier en-tête est précédé d'un BOM UTF-8, retiré avant lecture.

## 2. Connecteur

`app/Services/Import/FootballDataUkConnector.php`

- Produit des `NormalizedMatch` comme les autres sources : aucune écriture directe en base.
- Champs statistiques : `HS/AS` (tirs), `HST/AST` (tirs cadrés), `HF/AF` (fautes), `HC/AC` (corners), `HY/AY` (jaunes), `HR/AR` (rouges), `HxG/AxG` (xG).
- Scores : `FTHG/FTAG` (temps plein), `HTHG/HTAG` (mi-temps).
- Heure : `Time` est en heure locale britannique, convertie en UTC. Sans heure, la date est conservée à minuit et la déduplication se fait sur la journée.
- Identifiant externe déterministe : `fduk:{saison}:{division}:{date}:{équipe domicile}:{équipe extérieur}`.

## 3. Cotes

Uniquement des **cotes de clôture pré-match**, un bookmaker par colonne :

| Colonne | Bookmaker | Marché |
|---|---|---|
| `B365H/D/A` | Bet365 | 1N2 |
| `PSH/PSD/PSA` | Pinnacle | 1N2 |
| `BFH/BFD/BFA` | Betfair Exchange | 1N2 |
| `MaxH/D/A` | Cote maximale du marché | 1N2 |
| `AvgH/D/A` | Consensus moyen | 1N2 |
| `B365>2.5` / `B365<2.5` | Bet365 | Plus/Moins 2,5 |
| `Avg>2.5` / `Avg<2.5` | Consensus | Plus/Moins 2,5 |

Une cote n'est écrite que si les trois issues (ou les deux côtés) sont présentes et strictement supérieures à 1,00. `is_closing = true`, `is_opening = false`, `source = football-data-uk`.

**Aucune cote de corners, de cartons ou de tirs n'existe dans cette source.** Le ROI de ces familles reste donc incalculable.

## 4. Déduplication et provenance

Le rapprochement réutilise `MultiSourceImporter::findExisting` : correspondance d'abord par `provider_entity_maps`, puis par championnat + équipes + fenêtre horaire (±6 h si l'heure est connue, journée entière sinon).

- Une rencontre déjà présente est **mise à jour**, pas dupliquée.
- Un score divergent n'écrase jamais le score officiel : il est consigné comme divergent.
- `team_match_stats.provider` porte la provenance. Un champ déjà renseigné par un autre fournisseur n'est **jamais** écrasé ; seuls les champs vides sont complétés.

## 5. Résultats d'import

### Base de recette `prono_test`

```
Reçus 480 · Importés 370 · Mis à jour 110 · Rejetés 0 · Divergents 0
Lignes stats 960 · Cotes 6 870
```

Second passage : `100 reçus · 0 importé · 100 mis à jour · cotes inchangées` → idempotent.
Contrôle : `0` doublon dans `odds_snapshots` sur (match, bookmaker, marché, sélection, ligne).

### Base de développement `prono`

```
2 217 rencontres · 3 132 lignes de statistiques · 21 729 cotes
966 / 980 lignes de recette portent corners, fautes et cartons
```

## 6. Commandes

```bash
php artisan oracle:import-football-data-uk \
  --season=2627 --season=2526 \
  --division=E0 --division=SP1 --division=I1 --division=D1 --division=F1
```

Options : `--season=*`, `--division=*`, `--dry-run`. Sans option, la configuration (`config/imports.php`) s'applique.

## 7. Limites

- Pas de cotes de corners/cartons → pas de ROI sur ces marchés.
- Les fichiers ne contiennent que les rencontres des championnats nationaux : ni Champions League, ni compétitions africaines.
- `HxG` n'existe que pour les saisons récentes ; les saisons anciennes restent sans xG.
- Les noms d'équipes diffèrent de ceux de football-data.org (ex. « Man United » vs « Manchester United ») : la résolution passe par `FootballDataService::resolveTeam` et ses alias.
