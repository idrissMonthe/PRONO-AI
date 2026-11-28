<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Vérifie que le serveur peut réellement joindre chaque fournisseur externe.
 *
 * Sur un hébergement mutualisé (DOM Cloud Free), un pare-feu sortant n'autorise
 * qu'une liste de domaines : ce test distingue « bloqué par le réseau » de
 * « clé absente ou invalide », sans consommer de quota significatif.
 */
class CheckProvidersCommand extends Command
{
    protected $signature = 'oracle:check-providers
                            {--json : Sortie JSON pour les scripts de déploiement}';

    protected $description = 'Teste la connectivité sortante vers football-data.org, API-Football, The Odds API et Gemini';

    public function handle(): int
    {
        $probes = [
            [
                'provider' => 'football-data.org',
                'domain' => 'api.football-data.org',
                'url' => rtrim((string) config('services.football_data.base_url', 'https://api.football-data.org/v4'), '/').'/competitions',
                'headers' => array_filter(['X-Auth-Token' => (string) config('services.football_data.api_key')]),
                'configured' => (string) config('services.football_data.api_key') !== '',
            ],
            [
                'provider' => 'API-Football',
                'domain' => 'v3.football.api-sports.io',
                'url' => rtrim((string) config('services.api_football.base_url', 'https://v3.football.api-sports.io'), '/').'/status',
                'headers' => array_filter(['x-apisports-key' => (string) config('services.api_football.api_key')]),
                'configured' => (string) config('services.api_football.api_key') !== '',
            ],
            [
                'provider' => 'The Odds API',
                'domain' => 'api.the-odds-api.com',
                // /sports ne coûte aucun crédit de quota.
                'url' => rtrim((string) config('services.the_odds_api.base_url', 'https://api.the-odds-api.com/v4'), '/').'/sports',
                'headers' => [],
                'query' => array_filter(['apiKey' => (string) config('services.the_odds_api.api_key')]),
                'configured' => (string) config('services.the_odds_api.api_key') !== '',
            ],
            [
                'provider' => 'Google Gemini',
                'domain' => 'generativelanguage.googleapis.com',
                'url' => 'https://generativelanguage.googleapis.com/v1beta/models',
                'headers' => [],
                'query' => array_filter(['key' => (string) config('services.gemini.api_key')]),
                'configured' => (string) config('services.gemini.api_key') !== '',
            ],
        ];

        $results = [];

        foreach ($probes as $probe) {
            $results[] = $this->probe($probe);
        }

        if ($this->option('json')) {
            $this->line(json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->table(
            ['Fournisseur', 'Domaine', 'Réseau', 'HTTP', 'Identifiant'],
            array_map(fn (array $row): array => [
                $row['provider'],
                $row['domain'],
                $row['network'],
                $row['status'] ?? '—',
                $row['configured'] ? 'configuré' : 'absent',
            ], $results)
        );

        if (in_array('bloqué', array_column($results, 'network'), true)) {
            $this->warn('Au moins un fournisseur est injoignable depuis ce serveur : pare-feu sortant à étendre (voir deployment/domcloud/README.md).');
        }

        return self::SUCCESS;
    }

    /**
     * @return array{provider:string,domain:string,network:string,status:int|null,configured:bool}
     */
    private function probe(array $probe): array
    {
        $result = [
            'provider' => $probe['provider'],
            'domain' => $probe['domain'],
            'network' => 'joignable',
            'status' => null,
            'configured' => $probe['configured'],
        ];

        try {
            // Un 401/403 prouve que le domaine est joignable : le pare-feu ne
            // bloque pas la connexion, seul l'identifiant manque ou est refusé.
            $result['status'] = Http::timeout(8)
                ->connectTimeout(5)
                ->withHeaders($probe['headers'])
                ->get($probe['url'], $probe['query'] ?? [])
                ->status();
        } catch (ConnectionException) {
            // Le message cURL recopie l'URL demandée, `apiKey` ou `key` compris :
            // il n'est ni affiché ni journalisé, seul le domaine est conservé.
            $result['network'] = 'bloqué';
        }

        return $result;
    }
}
