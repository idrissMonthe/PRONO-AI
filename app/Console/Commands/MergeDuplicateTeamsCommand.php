<?php

namespace App\Console\Commands;

use App\Services\Deduplication\TeamAndMatchMergeService;
use Illuminate\Console\Command;

class MergeDuplicateTeamsCommand extends Command
{
    protected $signature = 'oracle:merge-duplicate-teams
        {--dry-run : Affiche le plan sans modifier la base}';

    protected $description = 'Fusionne les équipes en doublon et les rencontres dupliquées qui en découlent.';

    /**
     * Identités en double observées dans les imports. La clé est l'équipe à
     * absorber, la valeur l'équipe canonique conservée (nom complet, source
     * football-data.org/manual, porteuse des prédictions).
     *
     * Les 11 groupes de noms (Monaco, Real Madrid, Manchester City, etc.) sont
     * complétés par les noms abrégés du fournisseur football-data-uk
     * (Inter, Lens, Man City, etc.), qui dupliquaient les mêmes affiches.
     *
     * @var array<int,int>
     */
    private const TEAM_MAP = [
        // Serie A
        156 => 3,   // Inter -> FC Internazionale Milano
        162 => 4,   // Fiorentina -> ACF Fiorentina
        158 => 7,   // Genoa -> Genoa CFC
        161 => 58,  // Bologna -> Bologna FC 1909
        160 => 51,  // Sassuolo -> US Sassuolo Calcio
        157 => 43,  // Udinese -> Udinese Calcio
        159 => 16,  // Frosinone -> Frosinone Calcio

        // Premier League
        144 => 77,  // Man City -> Manchester City FC
        99 => 77,   // Manchester City (statsbomb) -> Manchester City FC
        138 => 13,  // Man United -> Manchester United FC
        145 => 10,  // Newcastle -> Newcastle United FC
        140 => 76,  // Nott'm Forest -> Nottingham Forest FC
        142 => 83,  // Tottenham -> Tottenham Hotspur FC
        101 => 83,  // Tottenham Hotspur (statsbomb) -> Tottenham Hotspur FC
        143 => 20,  // Brighton -> Brighton & Hove Albion FC
        136 => 79,  // Coventry -> Coventry City FC
        137 => 11,  // Hull -> Hull City AFC
        139 => 78,  // Ipswich -> Ipswich Town FC
        141 => 38,  // Leeds -> Leeds United FC
        177 => 135, // West Ham -> West Ham United
        100 => 84,  // Aston Villa -> Aston Villa FC
        102 => 46,  // Real Madrid -> Real Madrid CF

        // La Liga
        154 => 5,   // Ath Bilbao -> Athletic Club
        151 => 68,  // Ath Madrid -> Club Atlético de Madrid
        104 => 68,  // Atlético de Madrid (manual) -> Club Atlético de Madrid
        152 => 24,  // Betis -> Real Betis Balompié
        155 => 23,  // Celta -> RC Celta de Vigo
        148 => 65,  // Espanol -> RCD Espanyol de Barcelona
        150 => 97,  // La Coruna -> RC Deportivo La Coruña
        149 => 42,  // Levante -> Levante UD
        147 => 88,  // Santander -> Real Racing Club de Santander
        153 => 41,  // Sociedad -> Real Sociedad de Fútbol
        146 => 80,  // Vallecano -> Rayo Vallecano de Madrid
        103 => 45,  // FC Barcelone (manual) -> FC Barcelona

        // Ligue 1
        173 => 31,  // Angers -> Angers SCO
        172 => 66,  // Lens -> Racing Club de Lens
        171 => 27,  // Marseille -> Olympique de Marseille
        174 => 17,  // Monaco -> AS Monaco FC
        106 => 17,  // AS Monaco (officiel) -> AS Monaco FC
        176 => 47,  // Paris SG -> Paris Saint-Germain FC
        105 => 47,  // Paris Saint-Germain (officiel) -> Paris Saint-Germain FC
        175 => 29,  // Rennes -> Stade Rennais FC 1901

        // Bundesliga
        169 => 82,  // Dortmund -> Borussia Dortmund
        168 => 91,  // Ein Frankfurt -> Eintracht Frankfurt
        170 => 92,  // Hamburg -> Hamburger SV
        165 => 21,  // Hoffenheim -> TSG 1899 Hoffenheim
        163 => 22,  // Leverkusen -> Bayer 04 Leverkusen
        166 => 63,  // Mainz -> 1. FSV Mainz 05
        167 => 71,  // M'gladbach -> Borussia Mönchengladbach
        164 => 87,  // FC Koln -> 1. FC Köln
    ];

    public function handle(TeamAndMatchMergeService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $stats = $service->merge(self::TEAM_MAP, $dryRun);

        $this->info($dryRun
            ? 'Simulation de fusion des doublons (aucune écriture).'
            : 'Fusion des doublons terminée.');

        $rows = [
            ['Équipes fusionnées', $stats['teams_merged']],
            ['Rencontres fusionnées', $stats['matches_merged']],
            ['Cotes déplacées', $stats['odds_moved']],
            ['Prédictions déplacées', $stats['predictions_moved']],
            ['Statistiques d’équipe déplacées', $stats['team_stats_moved']],
            ['Statistiques d’équipe en conflit supprimées', $stats['team_stats_dropped']],
            ['Alias déplacés', $stats['aliases_moved']],
            ['Classements déplacés', $stats['ratings_moved']],
            ['Statistiques joueurs déplacées', $stats['player_stats_moved']],
            ['Autres lignes déplacées', $stats['other_rows_moved']],
            ['Paris mis à jour', $stats['parlays_updated']],
            ['Prédictions officielles démarquées', $stats['officials_demoted']],
        ];

        if ($stats['ledger_rebuilt']) {
            $rows[] = ['Registre reconstruit (blocs)', $stats['ledger_blocks']];
        }

        $this->table(['Élément', 'Quantité'], $rows);

        if ($dryRun) {
            $this->warn('Mode dry-run : la base n’a pas été modifiée.');
        }

        return self::SUCCESS;
    }
}
