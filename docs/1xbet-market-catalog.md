# Catalogue des marchés football 1xBet — audit PRONO-IA

Ce document décrit les marchés football de 1xBet, leurs variantes, leurs règles
de règlement et les informations nécessaires pour les calculer. Il distingue
systématiquement :

- **documenté** : défini dans les règles publiques 1xBet (règles générales,
  abréviations CK/YC/YRC/SO/SOT/OFF/F, marchés « Draw in at least one half »,
  « Total yellow cards », « 1X2 Over/Under », « Penalti Sí/No ») ;
- **observé** : présent sur les pages `/line/football` et `/live/football`
  consultées (1x2, double chance, BTTS, handicap, total de buts, score exact,
  buteur, équipe qualifiée, 2UP) ;
- **variante** : la règle autorise le marché mais la ligne ou la période dépend
  de la rencontre ;
- **à vérifier au Cameroun** : la disponibilité commerciale sur `1xbet.cm` n'a
  pas pu être confirmée depuis les pages publiques (contenu chargé en
  JavaScript) ; le document réglementaire `afg.1xbet.com/web-api/rulespdf` est
  une référence régionale qui ne garantit pas l'identité des règles camerounaises.

Le nombre de sélections affiché par le bookmaker n'est **pas** un nombre de
modèles statistiques : une même distribution de scores produit des dizaines de
lignes. PRONO-IA modélise des **familles**, pas des centaines de modèles.

## 1. Résultat et handicaps

| Marché | Variantes | Règle de calcul PRONO-IA | Statut |
| --- | --- | --- | --- |
| 1X2 | 1, X, 2 | Somme des cellules i>j, i=j, i<j | SUPPORTÉ |
| Double chance | 1X, X2, 12 | Somme de deux issues de la même matrice | SUPPORTÉ |
| Draw No Bet | domicile, extérieur | P(issue) / (1 − P(nul)) | SUPPORTÉ |
| Handicap européen | −2…+2 | Handicap ajouté à la différence de buts, trois issues | SUPPORTÉ |
| Handicap asiatique | 0, ±0,25, ±0,5, ±1, ±1,5, ±2 | Règlement push / demi-gain / demi-perte sur la matrice | SUPPORTÉ |
| 2UP | 1X2 avec avantage de deux buts | Non modélisé : la règle exacte de 1xBet (remboursement si l'avance de 2 buts est perdue) n'est pas fournie dans les règles consultées | CONDITIONNEL / à vérifier |
| Équipe qualifiée | domicile, extérieur | Nécessite le format de la compétition et la prolongation | CONDITIONNEL |
| Victoire sans encaisser | domicile, extérieur | Cellules gagnantes avec score adverse nul | SUPPORTÉ |

## 2. Marchés de buts

| Marché | Variantes | Règle de calcul | Statut |
| --- | --- | --- | --- |
| Total de buts | 0,5 à 5,5 (générées dynamiquement) | Somme des cellules au-dessus/en dessous | SUPPORTÉ |
| Total par équipe | 0,5 à 3,5 | Marges de la matrice | SUPPORTÉ |
| BTTS | Oui / Non | i ≥ 1 et j ≥ 1 | SUPPORTÉ |
| Score exact | 0-0 à 6-6 | Cellule individuelle ; publié seulement en Audacieux | CONDITIONNEL |
| Nombre exact de buts | 0 à 10+ | Diagonales de la matrice | SUPPORTÉ |
| Pair / impair | pair, impair | Somme des cellules de parité | SUPPORTÉ |
| Fourchettes | 0-1, 2-3, 4+ | Somme de cellules | SUPPORTÉ |
| Clean sheet | domicile, extérieur | Colonne/ligne zéro | SUPPORTÉ |
| Win to Nil | domicile, extérieur | Victoire + cage inviolée | SUPPORTÉ |
| Première équipe à marquer | domicile, extérieur, aucun but | Taux d'arrivée des buts (hypothèse de taux constant) | CONDITIONNEL |
| Dernière équipe à marquer | idem | idem | CONDITIONNEL |
| Aucun but | 0-0 | Cellule 0-0 | SUPPORTÉ |
| Équipe avec le plus de buts | domicile, nul, extérieur | Identique au 1X2 | SUPPORTÉ |
| Total asiatique | lignes entières et quarts | Push et demi-gains conservés | SUPPORTÉ |
| Résultat + total / BTTS | combinaisons | Cellules jointes de la matrice (jamais un produit) | SUPPORTÉ |

**Génération dynamique des lignes.** Les lignes Over/Under sont produites autour
de la moyenne observée (`generate_lines`), filtrées pour rester informatives
(probabilité comprise entre 0,25 et 0,80). Une ligne quasi certaine n'est pas
publiée. Over et Under d'une même ligne sont publiés ensemble ou pas du tout.

## 3. Marchés par période

| Marché | Variantes | Règle | Statut |
| --- | --- | --- | --- |
| 1X2 mi-temps | 1, X, 2 | Matrice de la première mi-temps | CONDITIONNEL |
| Double chance mi-temps | 1X, X2, 12 | Somme de deux issues | CONDITIONNEL |
| Total 1re mi-temps | 0,5 à 3,5 | Matrice 1re période | CONDITIONNEL |
| Total 2e mi-temps | 0,5 à 3,5 | Matrice 2e période | CONDITIONNEL |
| Total par équipe 1re mi-temps | domicile, extérieur | Marges | CONDITIONNEL |
| BTTS mi-temps | oui, non | Cellules i≥1, j≥1 de la 1re période | CONDITIONNEL |
| Score exact mi-temps | 0-0 à 3-3 | Cellules | CONDITIONNEL |
| Handicap 1re / 2e mi-temps | −1,5 à +1,5 | Différence de buts de la période | CONDITIONNEL |
| Mi-temps / fin de match | 9 combinaisons | Convolution jointe des deux mi-temps | CONDITIONNEL |
| Période la plus prolifique | 1re, égalité, 2e | Comparaison des distributions de totaux | CONDITIONNEL |
| But dans les deux mi-temps | oui, non | 1 − P(0-0 en 1re) × P(0-0 en 2e) | CONDITIONNEL |
| Nul dans au moins une mi-temps | oui, non | 1 − P(aucune mi-temps nulle) | CONDITIONNEL |

**La part de buts de la première période est estimée** sur les rencontres
disposant réellement d'un score à la mi-temps (`matches.ht_home_score`,
`ht_away_score`), bornée à [0,30 ; 0,62]. Sous 5 rencontres, aucune famille
« période » n'est publiée. Les buts attendus ne sont jamais divisés par deux.

## 4. Corners

| Marché | Variantes | Règle | Statut |
| --- | --- | --- | --- |
| Total de corners | lignes autour de la moyenne | Binomiale négative (surdispersion) | CONDITIONNEL |
| Total par équipe | domicile, extérieur | Idem par équipe | CONDITIONNEL |
| Handicap de corners | −3,5 à +3,5 | Distribution de la différence | CONDITIONNEL |
| Équipe avec le plus de corners | domicile, égalité, extérieur | Différence de comptages | CONDITIONNEL |
| Premier / dernier corner | domicile, extérieur | Exige l'horodatage des corners | NON SUPPORTÉ |
| Corner par intervalle | 0-15 … 76-90 | Exige les événements horodatés | NON SUPPORTÉ |
| Corners par mi-temps | over/under | Exige la répartition horodatée | NON SUPPORTÉ |
| Résultat + corners | combinaisons | La dépendance résultat/corners n'est pas identifiée dans les données | CONDITIONNEL (non publié) |

## 5. Cartons et discipline

| Marché | Variantes | Règle | Statut |
| --- | --- | --- | --- |
| Total cartons jaunes | over/under | Loi de comptage | CONDITIONNEL |
| Total cartons rouges | over/under | Loi de comptage | CONDITIONNEL |
| Total cartons (YRC) | over/under | Jaunes + rouges | CONDITIONNEL |
| Total par équipe | over/under | Loi par équipe | CONDITIONNEL |
| Handicap de cartons | domicile, extérieur | Différence de comptages | CONDITIONNEL |
| Première / dernière équipe avertie | domicile, extérieur | Exige l'horodatage | NON SUPPORTÉ |
| Joueur averti | joueur | Taux individuel observé | CONDITIONNEL |
| Joueur expulsé | joueur | Événement rare | INSUFFISANT |
| Expulsion dans le match | oui, non | P(au moins un rouge) | CONDITIONNEL |
| Carton par intervalle | 0-15 … 76-90 | Exige l'horodatage | NON SUPPORTÉ |
| Cartons par mi-temps | over/under | Exige la répartition horodatée | NON SUPPORTÉ |
| Points disciplinaires | over/under | Barème 1xBet à confirmer | CONDITIONNEL |

**Définitions respectées.** Un carton jaune, un deuxième jaune entraînant une
expulsion et un carton rouge direct ne sont pas confondus : le total « cartons
jaunes » ne compte pas un second jaune comme un jaune supplémentaire, et le
total YRC additionne jaunes et rouges. Le barème de points disciplinaires
(jaune = 1, rouge = 3 selon la règle régionale consultée) reste à confirmer pour
le Cameroun avant publication.

## 6. Statistiques de match

| Marché | Statut | Remarque |
| --- | --- | --- |
| Tirs totaux | CONDITIONNEL | `team_match_stats.shots` |
| Tirs cadrés | CONDITIONNEL | `team_match_stats.shots_on_target` |
| Tirs par équipe | CONDITIONNEL | idem |
| Fautes | CONDITIONNEL | `team_match_stats.fouls` |
| Hors-jeu | NON SUPPORTÉ | Colonne absente de `team_match_stats` |
| Coups francs | INSUFFISANT | Aucune source intégrée |
| Touches | NON SUPPORTÉ | Aucune source intégrée |
| Arrêts du gardien | INSUFFISANT | Non collecté |

Ces statistiques ne sont pas interchangeables : chacune a sa propre loi de
comptage, sa moyenne et sa surdispersion. Le moteur ne réutilise pas la
distribution des buts pour les tirs ou les fautes.

## 7. Marchés joueurs

| Marché | Statut | Condition |
| --- | --- | --- |
| Marque à tout moment | CONDITIONNEL | Identité + titularisation probable ≥ 0,60 + 8 matchs |
| Premier / dernier buteur | CONDITIONNEL | Même condition |
| Deux buts | CONDITIONNEL | Même condition |
| Hat-trick | INSUFFISANT | Événement trop rare |
| Total de tirs / tirs cadrés | CONDITIONNEL | Taux individuel observé |
| Joueur averti | CONDITIONNEL | Taux de carton observé |
| Joueur expulsé | INSUFFISANT | Événement rare |
| Joueur remplacé | INSUFFISANT | Minutes et rotations insuffisamment documentées |

Aucun marché joueur n'est produit sans nom, participation probable et
historique. La composition officielle, quand elle existe, remplace la
probabilité de titularisation ; sinon l'incertitude est signalée.

## 8. Marchés temporels et spéciaux

| Marché | Statut | Remarque |
| --- | --- | --- |
| Premier but par intervalle | CONDITIONNEL | Taux de but supposé constant, couverture `thin` |
| Dernier but par intervalle | CONDITIONNEL | idem |
| But avant une minute | CONDITIONNEL | idem |
| Buts par intervalle | CONDITIONNEL | idem |
| Premier corner / carton par intervalle | NON SUPPORTÉ | Horodatage absent |
| Penalty accordé | INSUFFISANT | Aucun historique de penalties |
| Penalty + expulsion | NON SUPPORTÉ | Aucune source |
| Première substitution | NON SUPPORTÉ | Compositions/événements |
| Prochain but / prochain événement | NON SUPPORTÉ (live) | Exige un flux live fiable |

Les marchés « prochain but » et « prochain événement » restent **désactivés**.
Ils ne peuvent pas être prédits avec des fichiers historiques statiques.

## 9. Combinaisons

| Combinaison | Règle | Statut |
| --- | --- | --- |
| Résultat + total | Cellules jointes | SUPPORTÉ |
| Résultat + BTTS | Cellules jointes | SUPPORTÉ |
| Victoire + sans encaisser | Cellules jointes | SUPPORTÉ |
| Résultat + nombre de buts | Cellules jointes | SUPPORTÉ |
| Mi-temps + résultat final | Convolution des périodes | CONDITIONNEL |
| Gagnant + corners | Dépendance non identifiée | NON PUBLIÉ |

## 10. Ce que ce catalogue ne prétend pas

- Toutes les lignes ne sont pas disponibles sur toutes les rencontres.
- Les catégories commerciales 1xBet (« Populaires », « Total », « Handicap »,
  « Goals », « Joueurs », « Intervalles », « Résultat + Total », « Marchés
  asiatiques », « OptionBet ») ne sont pas reproduites telles quelles : PRONO-IA
  classe par **famille statistique** et n'affiche que les marchés calculables.
- « OptionBet » est une fonctionnalité commerciale de 1xBet, pas un marché
  statistique : elle n'est pas modélisée.
- Aucune cote n'est inventée. Sans prix bookmaker réel, le marché peut être
  calculé mais aucune valeur (EV/ROI) n'est affichée.
