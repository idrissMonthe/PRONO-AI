<x-app-layout>
    @php
        $pickGroups = [
            'prudent' => ['title' => 'Les plus sûrs', 'subtitle' => 'Marchés simples et forte probabilité', 'class' => 'pick-tier-prudent'],
            'equilibre' => ['title' => 'Équilibrés', 'subtitle' => 'Un compromis entre probabilité et intérêt', 'class' => 'pick-tier-equilibre'],
            'audacieux' => ['title' => 'Audacieux', 'subtitle' => 'Plus risqués, pour tenter un scénario intéressant', 'class' => 'pick-tier-audacieux'],
        ];
        $pickCount = collect($dailyPicks)->flatten(1)->count();
        $bigParlayLeagueCount = collect($bigParlayPicks)->pluck('league_key')->unique()->count();
    @endphp

    <section class="hero-card">
        <div>
            <span class="eyebrow">PRONO IA · ANALYSE FOOTBALL</span>
            <p>Des recommandations claires, des probabilités lisibles et une explication simple pour chaque rencontre.</p>
        </div>
        <a href="#today-picks" class="primary-btn">Voir les pronostics <span>↓</span></a>
    </section>

    <section class="stats-strip">
        <div><strong>{{ $pickCount }}</strong><span>PRONOSTICS DISPONIBLES</span></div>
        <div><strong>{{ $upcomingCount }}</strong><span>MATCHS À VENIR</span></div>
        <div><strong>{{ $predictedMatches }}</strong><span>ANALYSES RÉALISÉES</span></div>
        <div><strong>24/7</strong><span>DONNÉES ACTUALISÉES</span></div>
    </section>

    <section id="today-picks" class="section-block">
        <div class="section-heading">
            <div><span class="eyebrow">SÉLECTION DU JOUR</span><h2>Les matchs à surveiller</h2></div>
            <a href="{{ route('matches.index') }}" class="text-link">Tous les matchs →</a>
        </div>

        @if($pickCount > 0)
            @foreach($pickGroups as $risk => $group)
                @if(!empty($dailyPicks[$risk]))
                    <div class="pick-tier {{ $group['class'] }}">
                        <div class="pick-tier-heading">
                            <div><h3>{{ $group['title'] }}</h3><p>{{ $group['subtitle'] }}</p></div>
                            <span>{{ count($dailyPicks[$risk]) }} sélection{{ count($dailyPicks[$risk]) > 1 ? 's' : '' }}</span>
                        </div>
                        <div class="pick-grid">
                            @foreach($dailyPicks[$risk] as $pick)
                                @php $match = $pick['match']; @endphp
                                <a href="{{ route('matches.show', $match->id) }}" class="pick-card">
                                    <div class="pick-card-top"><span>{{ $match->league->name ?? 'Football' }}</span><span>{{ $match->match_date_utc->timezone('Africa/Douala')->format('d M · H:i') }}</span></div>
                                    <div class="teams"><strong>{{ $match->homeTeam->short_name }}</strong><span>vs</span><strong>{{ $match->awayTeam->short_name }}</strong></div>
                                    <div class="pick-verdict"><span class="lock-dot"></span><strong>{{ $pick['label'] }}</strong><span class="pick-prob">{{ round($pick['prob'] * 100) }}%</span></div>
                                    <div class="pick-footer"><span class="reliability">{{ $pick['market'] }}</span><span class="score-pill">{{ $group['title'] }}</span></div>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endif
            @endforeach
        @else
            <div class="empty-card">Aucun pronostic fiable n’est disponible pour le moment. Synchronise les matchs depuis les paramètres.</div>
        @endif
    </section>

    <section class="section-block big-parlay-section">
        <div class="section-heading">
            <div><span class="eyebrow">SÉLECTION ÉLARGIE</span><h2>Gros combinés</h2><p>Au moins 10 pronostics parmi les championnats disponibles, avec une première sélection par championnat et une fiabilité croissante.</p></div>
            <span class="big-parlay-meta">{{ count($bigParlayPicks) }} pronostics · {{ $bigParlayLeagueCount }} championnat{{ $bigParlayLeagueCount > 1 ? 's' : '' }}</span>
        </div>

        @if(count($bigParlayPicks) >= 10)
            <div class="big-parlay-card">
                <div class="big-parlay-head"><span>Fiabilité croissante</span><span>Probabilité conjointe indicative : {{ round($bigParlayProbability * 100, 2) }}%</span></div>
                <div class="big-parlay-list">
                    @foreach($bigParlayPicks as $index => $pick)
                        @php $match = $pick['match']; @endphp
                        <a href="{{ route('matches.show', $match->id) }}" class="big-parlay-row">
                            <span class="big-parlay-rank">{{ $index + 1 }}</span>
                            <span class="big-parlay-match"><small>{{ $match->league->name ?? 'Football' }} · {{ $match->match_date_utc->timezone('Africa/Douala')->format('d/m · H:i') }}</small><strong>{{ $match->homeTeam->short_name }} <i>vs</i> {{ $match->awayTeam->short_name }}</strong></span>
                            <span class="big-parlay-market">{{ $pick['market'] }}<strong>{{ $pick['label'] }}</strong></span>
                            <span class="big-parlay-prob" data-probability="{{ number_format($pick['prob'], 6, '.', '') }}">{{ number_format($pick['prob'] * 100, 1, ',', '') }}%</span>
                        </a>
                    @endforeach
                </div>
                <div class="big-parlay-foot">La probabilité conjointe suppose une indépendance approximative entre les matchs. Ce combiné est volontairement plus risqué que « Les matchs à surveiller ».</div>
            </div>
        @else
            <div class="empty-card">Les gros combinés apparaîtront dès que 10 pronostics officiels, fiables et issus des matchs futurs seront disponibles. Aucun pronostic n’est ajouté artificiellement.</div>
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
