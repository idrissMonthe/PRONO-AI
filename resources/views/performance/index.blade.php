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

    <section class="section-block">
        <div class="section-heading"><div><span class="eyebrow">APPRENTISSAGE HYBRIDE</span><h2>Couverture des analyses</h2><p>Les matchs restent comptés même lorsque Python ou l’IA ne peuvent pas publier de sélection.</p></div></div>
        <div class="performance-market-grid">
            <div class="performance-market-card"><span>Matchs suivis</span><strong>{{ $hybridCoverage['matches'] }}</strong><small>calendrier total</small></div>
            <div class="performance-market-card"><span>Python prêt</span><strong>{{ $hybridCoverage['python_ready'] }}</strong><small>analyse statistique</small></div>
            <div class="performance-market-card"><span>IA assistée</span><strong>{{ $hybridCoverage['ai_assisted'] }}</strong><small>Python sous-couvert</small></div>
            <div class="performance-market-card"><span>Hybrides</span><strong>{{ $hybridCoverage['hybrid_ready'] }}</strong><small>Python + IA</small></div>
            <div class="performance-market-card"><span>Données limitées</span><strong>{{ $hybridCoverage['limited_data'] }}</strong><small>sans publication fiable</small></div>
            <div class="performance-market-card"><span>Origine IA</span><strong>{{ $hybridCoverage['ai_predictions'] }}</strong><small>estimations non calibrées</small></div>
        </div>
    </section>

    @if($calibrationStats->isNotEmpty())
        <div class="performance-market-grid">
            @foreach($calibrationStats->take(8) as $stat)
                <div class="performance-market-card"><span>{{ $stat['market'] }}</span><strong>ECE {{ $stat['ece'] !== null ? number_format($stat['ece'] * 100, 1) : '—' }}%</strong><small>{{ $stat['sample_size'] }} obs. · Brier {{ $stat['brier'] ?? '—' }} · {{ $stat['model'] }}</small></div>
            @endforeach
        </div>
    @endif

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
