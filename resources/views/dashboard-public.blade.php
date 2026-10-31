<x-app-layout>
    @php
        $picks = $upcomingMatches
            ->filter(fn ($m) => $m->officialPrediction && !$m->officialPrediction->is_abstained)
            ->unique(fn ($m) => $m->home_team_id.'-'.$m->away_team_id.'-'.$m->match_date_utc->timestamp)
            ->values();
    @endphp

    <section class="hero-card">
        <div>
            <span class="eyebrow">PRONO IA · ANALYSE FOOTBALL</span>
            <p>Des recommandations claires, des probabilités lisibles et une explication simple pour chaque rencontre.</p>
        </div>
        <a href="#today-picks" class="primary-btn">Voir les pronostics <span>↓</span></a>
    </section>

    <section class="stats-strip">
        <div><strong>{{ $picks->count() }}</strong><span>PRONOSTICS DISPONIBLES</span></div>
        <div><strong>{{ $upcomingCount }}</strong><span>MATCHS À VENIR</span></div>
        <div><strong>{{ $predictedMatches }}</strong><span>ANALYSES RÉALISÉES</span></div>
        <div><strong>24/7</strong><span>DONNÉES ACTUALISÉES</span></div>
    </section>

    <section id="today-picks" class="section-block">
        <div class="section-heading">
            <div><span class="eyebrow">SÉLECTION DU JOUR</span><h2>Les matchs à surveiller</h2></div>
            <a href="{{ route('matches.index') }}" class="text-link">Tous les matchs →</a>
        </div>

        @if($picks->isNotEmpty())
            <div class="pick-grid">
                @foreach($picks->take(9) as $match)
                    @php
                        $pred = $match->officialPrediction;
                        $probabilities = ['home' => (float) $pred->prob_home, 'draw' => (float) $pred->prob_draw, 'away' => (float) $pred->prob_away];
                        $winner = array_keys($probabilities, max($probabilities), true)[0];
                        $label = $winner === 'home' ? 'Victoire '.$match->homeTeam->short_name : ($winner === 'away' ? 'Victoire '.$match->awayTeam->short_name : 'Match nul');
                        $probability = max($probabilities);
                        $reliability = (int) max(1, min(10, round(((float) ($pred->quality_score ?? 0) / 100) * 5 + $probability * 4 + ($pred->confidence_tier === 'high' ? 1 : 0))));
                        $reliabilityLabel = $reliability >= 8 ? 'Élevée' : ($reliability >= 6 ? 'Moyenne' : 'Faible');
                    @endphp
                    <a href="{{ route('matches.show', $match->id) }}" class="pick-card">
                        <div class="pick-card-top"><span>{{ $match->league->name ?? 'Football' }}</span><span>{{ $match->match_date_utc->timezone('Africa/Douala')->format('d M · H:i') }}</span></div>
                        <div class="teams"><strong>{{ $match->homeTeam->short_name }}</strong><span>vs</span><strong>{{ $match->awayTeam->short_name }}</strong></div>
                        <div class="pick-verdict"><span class="lock-dot"></span><strong>{{ $label }}</strong><span class="pick-prob">{{ round($probability * 100) }}%</span></div>
                        <div class="pick-footer"><span class="reliability reliability-{{ strtolower($reliabilityLabel) }}">Fiabilité {{ $reliabilityLabel }}</span><span class="score-pill">{{ $reliability }}/10</span></div>
                    </a>
                @endforeach
            </div>
        @else
            <div class="empty-card">Aucun pronostic fiable n’est disponible pour le moment. Synchronise les matchs depuis les paramètres.</div>
        @endif
    </section>

    <section class="how-section">
        <div><span class="eyebrow">COMMENT ÇA MARCHE</span><h2>Une analyse complète.<br>Une décision claire.</h2></div>
        <div class="how-grid">
            <div><b>01</b><h3>Données fraîches</h3><p>Calendrier, résultats, forme et cotes sont rassemblés avant chaque analyse.</p></div>
            <div><b>02</b><h3>Modèle probabiliste</h3><p>Les probabilités sont calculées avec une estimation Dixon-Coles et un contrôle de qualité.</p></div>
            <div><b>03</b><h3>Détection de valeur</h3><p>Les probabilités du modèle sont comparées au marché lorsqu’une cote est disponible.</p></div>
            <div><b>04</b><h3>Verdict final</h3><p>Le moteur peut s’abstenir quand les données sont trop faibles ou incertaines.</p></div>
        </div>
    </section>

    <section class="section-block">
        <div class="section-heading"><div><span class="eyebrow">RÉSULTATS</span><h2>Les dernières rencontres</h2></div><a href="{{ route('results') }}" class="text-link">Voir tout →</a></div>
        <div class="result-list">
            @forelse($recentFinished->take(6) as $match)
                <a href="{{ route('matches.show', $match->id) }}" class="result-row"><span>{{ $match->league->name ?? 'Football' }}</span><strong>{{ $match->homeTeam->short_name }} <b>{{ $match->home_score }}–{{ $match->away_score }}</b> {{ $match->awayTeam->short_name }}</strong><span>{{ $match->match_date_utc->timezone('Africa/Douala')->format('d/m/Y') }}</span></a>
            @empty
                <div class="empty-card">Les résultats apparaîtront ici après la synchronisation des matchs.</div>
            @endforelse
        </div>
    </section>

    <section class="responsible-note"><strong>À retenir</strong><span>Une probabilité n’est jamais une certitude. PronoIA est un outil d’analyse et ne garantit aucun gain.</span></section>
</x-app-layout>
