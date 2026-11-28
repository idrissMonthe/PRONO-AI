<x-app-layout>
    <div class="section-heading page-heading"><div><span class="eyebrow">HISTORIQUE</span><h1>Résultats des pronostics</h1><p>Un suivi transparent des matchs analysés par PronoIA.</p></div></div>

    <form method="GET" class="filter-bar">
        <label>Date <input type="date" name="date" value="{{ request('date') }}"></label>
        <label>Ligue <input type="text" name="league" value="{{ request('league') }}" placeholder="Ex. Premier League"></label>
        <button class="secondary-btn" type="submit">Filtrer</button>
        @if(request()->hasAny(['date', 'league']))<a href="{{ route('results') }}" class="text-link">Réinitialiser</a>@endif
    </form>

    <div class="result-list result-list-large">
        @forelse($results as $match)
            @php
                $pred = $match->officialPrediction;
                $probabilities = $pred ? ['home' => (float) $pred->prob_home, 'draw' => (float) $pred->prob_draw, 'away' => (float) $pred->prob_away] : [];
                $actual = $match->home_score === $match->away_score ? 'draw' : ($match->home_score > $match->away_score ? 'home' : 'away');
                // Le verdict vient du règlement du marché réellement publié.
                // Juger toutes les rencontres avec l'argmax 1N2 affichait
                // « correct » sur des sélections cartons/corners perdues.
                $settled = $pred?->evaluations->firstWhere('market_key', 'published');
                $correct = null;
                if ($settled && in_array($settled->status, ['WON', 'LOST'], true)) {
                    $correct = $settled->status === 'WON';
                } elseif ($pred && in_array((string) $pred->published_market, ['1N2', '1x2'], true) && $probabilities) {
                    $correct = collect($probabilities)->sortDesc()->keys()->first() === $actual;
                }
            @endphp
            <a href="{{ route('matches.show', $match->id) }}" class="result-row result-row-card">
                <span>{{ $match->match_date_utc->timezone('Africa/Douala')->format('d M Y · H:i') }}<small>{{ $match->league->name ?? 'Football' }}</small></span>
                <strong>{{ $match->homeTeam->name }} <b>{{ $match->home_score }}–{{ $match->away_score }}</b> {{ $match->awayTeam->name }}</strong>
                <span class="result-badge {{ $correct === true ? 'result-win' : ($correct === false ? 'result-loss' : '') }}">{{ $correct === true ? 'Pronostic correct' : ($correct === false ? 'À revoir' : 'Analysé') }}</span>
            </a>
        @empty
            <div class="empty-card">Aucun résultat ne correspond à ces filtres.</div>
        @endforelse
    </div>

    <div class="pagination-wrap">{{ $results->links() }}</div>
</x-app-layout>
