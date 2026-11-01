<x-app-layout>
    <div class="section-heading page-heading">
        <div><span class="eyebrow">SUIVI DU MODÈLE</span><h1>Performance des pronostics</h1><p>Chaque snapshot est comparé au résultat réel après la synchronisation football-data.org.</p></div>
    </div>

    <div class="stats-strip">
        <div><strong>{{ $total }}</strong><span>ÉVALUATIONS</span></div>
        <div><strong class="text-[#88B69A]">{{ $won }}</strong><span>RÉUSSIES</span></div>
        <div><strong class="text-[#D68D86]">{{ $lost }}</strong><span>MANQUÉES</span></div>
        <div><strong>{{ $rate !== null ? $rate.'%' : '—' }}</strong><span>TAUX GLOBAL</span></div>
    </div>

    <div class="performance-market-grid">
        @forelse($marketStats as $stat)
            <div class="performance-market-card"><span>{{ $stat['market'] }}</span><strong>{{ $stat['rate'] }}%</strong><small>{{ $stat['won'] }}/{{ $stat['total'] }} réussies</small></div>
        @empty
            <div class="empty-card">Les statistiques apparaîtront après le premier match terminé avec une prédiction officielle.</div>
        @endforelse
    </div>

    <form method="GET" class="filter-bar">
        <label>Statut
            <select name="status"><option value="">Tous</option><option value="WON" @selected(request('status') === 'WON')>Réussis</option><option value="LOST" @selected(request('status') === 'LOST')>Manqués</option></select>
        </label>
        <label>Marché
            <select name="market"><option value="">Tous les marchés</option>@foreach($marketStats as $stat)<option value="{{ $stat['market'] }}" @selected(request('market') === $stat['market'])>{{ $stat['market'] }}</option>@endforeach</select>
        </label>
        <button class="secondary-btn" type="submit">Filtrer</button>
        @if(request()->hasAny(['status', 'market']))<a href="{{ route('performance.index') }}" class="text-link">Réinitialiser</a>@endif
    </form>

    <div class="result-list result-list-large">
        @forelse($evaluations as $evaluation)
            <a href="{{ route('matches.show', $evaluation->match_id) }}" class="result-row result-row-card">
                <span>{{ $evaluation->match->match_date_utc->timezone('Africa/Douala')->format('d M Y · H:i') }}<small>{{ $evaluation->market_group }} · {{ $evaluation->match->league->name ?? 'Football' }}</small></span>
                <strong>{{ $evaluation->match->homeTeam->short_name }} <b>{{ $evaluation->match->home_score }}–{{ $evaluation->match->away_score }}</b> {{ $evaluation->match->awayTeam->short_name }}<small>{{ $evaluation->predicted_label }} · {{ round($evaluation->predicted_probability * 100) }}%</small></strong>
                <span class="result-badge {{ $evaluation->status === 'WON' ? 'result-win' : 'result-loss' }}">{{ $evaluation->status === 'WON' ? 'Réussi' : 'Manqué' }}</span>
            </a>
        @empty
            <div class="empty-card">Aucune évaluation ne correspond à ces filtres.</div>
        @endforelse
    </div>
    <div class="pagination-wrap">{{ $evaluations->links() }}</div>

    <section class="responsible-note"><strong>Pour l’entraînement</strong><span>Ces résultats servent à mesurer la calibration du modèle. Ils ne garantissent pas les prochains pronostics.</span></section>
</x-app-layout>
