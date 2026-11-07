<x-app-layout>
    <div class="consumer-page space-y-5">
        <!-- En-tête -->
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 pb-2 border-b border-[#303A32]">
            <div>
                <h1 class="text-xl font-bold tracking-tight text-[#F0F0E8]">Matchs & Calendrier</h1>
                <p class="text-xs text-[#A2ACA3] mt-1">
                    Rencontres réelles synchronisées depuis football-data.org · Fuseau : <strong>Africa/Douala (WAT / UTC+1)</strong>
                </p>
            </div>
            <div class="flex items-center gap-3">
                @if($lastSyncFormatted)
                    <div class="mono text-[11px] text-[#A2ACA3]">
                        Dernière sync API : <strong class="text-[#F0F0E8]">{{ $lastSyncFormatted }}</strong>
                    </div>
                @endif
                <div class="mono text-xs text-[#A2ACA3]">
                    Affichés : <strong class="text-[#C3A16A]">{{ $matches->total() }}</strong> match(s)
                </div>
            </div>
        </div>

        <!-- Onglets Temporels par Date (Africa/Douala) -->
        <div class="flex items-center gap-2 overflow-x-auto pb-1 text-xs mono">
            <a href="{{ route('matches.index', array_merge(request()->except('page'), ['date_filter' => 'today'])) }}"
               class="px-3 py-2 rounded-md border flex items-center gap-2 transition-colors {{ $dateFilter === 'today' ? 'bg-[#C3A16A] text-[#101513] font-bold border-[#C3A16A]' : 'bg-[#141A17] text-[#A2ACA3] border-[#303A32] hover:border-[#C3A16A] hover:text-[#F0F0E8]' }}">
                <span>Aujourd'hui</span>
                <span class="px-1.5 py-0.2 rounded-full text-[10px] {{ $dateFilter === 'today' ? 'bg-[#101513]/20 text-[#101513]' : 'bg-[#222B25] text-[#C3A16A]' }}">
                    {{ $counts['today'] }}
                </span>
            </a>

            <a href="{{ route('matches.index', array_merge(request()->except('page'), ['date_filter' => 'tomorrow'])) }}"
               class="px-3 py-2 rounded-md border flex items-center gap-2 transition-colors {{ $dateFilter === 'tomorrow' ? 'bg-[#C3A16A] text-[#101513] font-bold border-[#C3A16A]' : 'bg-[#141A17] text-[#A2ACA3] border-[#303A32] hover:border-[#C3A16A] hover:text-[#F0F0E8]' }}">
                <span>Demain</span>
                <span class="px-1.5 py-0.2 rounded-full text-[10px] {{ $dateFilter === 'tomorrow' ? 'bg-[#101513]/20 text-[#101513]' : 'bg-[#222B25] text-[#A2ACA3]' }}">
                    {{ $counts['tomorrow'] }}
                </span>
            </a>

            <a href="{{ route('matches.index', array_merge(request()->except('page'), ['date_filter' => 'upcoming'])) }}"
               class="px-3 py-2 rounded-md border flex items-center gap-2 transition-colors {{ $dateFilter === 'upcoming' ? 'bg-[#C3A16A] text-[#101513] font-bold border-[#C3A16A]' : 'bg-[#141A17] text-[#A2ACA3] border-[#303A32] hover:border-[#C3A16A] hover:text-[#F0F0E8]' }}">
                <span>À venir</span>
                <span class="px-1.5 py-0.2 rounded-full text-[10px] {{ $dateFilter === 'upcoming' ? 'bg-[#101513]/20 text-[#101513]' : 'bg-[#222B25] text-[#A2ACA3]' }}">
                    {{ $counts['upcoming'] }}
                </span>
            </a>

            <a href="{{ route('matches.index', array_merge(request()->except('page'), ['date_filter' => 'live'])) }}"
               class="px-3 py-2 rounded-md border flex items-center gap-2 transition-colors {{ $dateFilter === 'live' ? 'bg-[#88B69A] text-[#101513] font-bold border-[#88B69A]' : 'bg-[#141A17] text-[#A2ACA3] border-[#303A32] hover:border-[#88B69A] hover:text-[#88B69A]' }}">
                <span class="w-2 h-2 rounded-full bg-[#88B69A] animate-pulse"></span>
                <span>En direct</span>
                <span class="px-1.5 py-0.2 rounded-full text-[10px] {{ $dateFilter === 'live' ? 'bg-[#101513]/20 text-[#101513]' : 'bg-[#222B25] text-[#88B69A]' }}">
                    {{ $counts['live'] }}
                </span>
            </a>

            <a href="{{ route('matches.index', array_merge(request()->except('page'), ['date_filter' => 'finished'])) }}"
               class="px-3 py-2 rounded-md border flex items-center gap-2 transition-colors {{ $dateFilter === 'finished' ? 'bg-[#C3A16A] text-[#101513] font-bold border-[#C3A16A]' : 'bg-[#141A17] text-[#A2ACA3] border-[#303A32] hover:border-[#C3A16A] hover:text-[#F0F0E8]' }}">
                <span>Terminés</span>
                <span class="px-1.5 py-0.2 rounded-full text-[10px] {{ $dateFilter === 'finished' ? 'bg-[#101513]/20 text-[#101513]' : 'bg-[#222B25] text-[#A2ACA3]' }}">
                    {{ $counts['finished'] }}
                </span>
            </a>

            <a href="{{ route('matches.index', array_merge(request()->except('page'), ['date_filter' => 'all'])) }}"
               class="px-3 py-2 rounded-md border flex items-center gap-2 transition-colors {{ $dateFilter === 'all' ? 'bg-[#C3A16A] text-[#101513] font-bold border-[#C3A16A]' : 'bg-[#141A17] text-[#A2ACA3] border-[#303A32] hover:border-[#C3A16A] hover:text-[#F0F0E8]' }}">
                <span>Tous</span>
            </a>
            <a href="{{ route('matches.index', array_merge(request()->except('page'), ['reliable' => 1])) }}"
               class="px-3 py-2 rounded-md border flex items-center gap-2 transition-colors {{ request()->boolean('reliable') ? 'bg-[#88B69A] text-[#101513] font-bold border-[#88B69A]' : 'bg-[#141A17] text-[#A2ACA3] border-[#303A32] hover:border-[#88B69A] hover:text-[#88B69A]' }}">
                <span>Fiables uniquement</span>
                <span class="px-1.5 py-0.2 rounded-full text-[10px] {{ request()->boolean('reliable') ? 'bg-[#101513]/20 text-[#101513]' : 'bg-[#222B25] text-[#A2ACA3]' }}">
                    {{ $reliableCount }}
                </span>
            </a>
        </div>

        <!-- Barre de Filtres Complémentaires -->
        <form method="GET" action="{{ route('matches.index') }}" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 items-end pb-4 border-b border-[#303A32]">
            <input type="hidden" name="date_filter" value="{{ $dateFilter }}">

            <div>
                <label class="block text-xs font-semibold text-[#A2ACA3] uppercase tracking-wider mb-1.5">Championnat</label>
                <select name="league_id" class="w-full bg-[#222B25] border border-[#303A32] rounded-md px-3 py-2 text-xs text-[#F0F0E8] focus:border-[#C3A16A] focus:outline-none">
                    <option value="">Tous les championnats</option>
                    @foreach($leagues as $league)
                        <option value="{{ $league->id }}" {{ request('league_id') == $league->id ? 'selected' : '' }}>
                            {{ $league->name }} ({{ $league->country }})
                        </option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block text-xs font-semibold text-[#A2ACA3] uppercase tracking-wider mb-1.5">Recherche Équipe</label>
                <input type="text" name="search" value="{{ request('search') }}" placeholder="Ex: Arsenal, Real Madrid, PSG..." class="w-full bg-[#222B25] border border-[#303A32] rounded-md px-3 py-2 text-xs text-[#F0F0E8] placeholder-[#A2ACA3]/50 focus:border-[#C3A16A] focus:outline-none">
            </div>

            <div>
                <label class="block text-xs font-semibold text-[#A2ACA3] uppercase tracking-wider mb-1.5">Tri chronologique</label>
                <select name="sort" class="w-full bg-[#222B25] border border-[#303A32] rounded-md px-3 py-2 text-xs text-[#F0F0E8] focus:border-[#C3A16A] focus:outline-none">
                    <option value="date_asc" {{ request('sort', 'date_asc') === 'date_asc' ? 'selected' : '' }}>Chronologique (croissant)</option>
                    <option value="date_desc" {{ request('sort') === 'date_desc' ? 'selected' : '' }}>Antéchronologique (décroissant)</option>
                </select>
            </div>

            <div class="flex items-center gap-2">
                <button type="submit" class="flex-1 bg-[#C3A16A] hover:bg-[#d8b57c] text-[#101513] font-bold py-2 px-4 rounded-md text-xs transition-colors cursor-pointer">
                    Filtrer
                </button>
                <a href="{{ route('matches.index', ['date_filter' => $dateFilter]) }}" class="px-3 py-2 rounded-md bg-[#222B25] border border-[#303A32] text-xs text-[#A2ACA3] hover:text-[#F0F0E8] transition-colors">
                    Réinitialiser
                </a>
            </div>
        </form>

        <!-- Liste des Matchs -->
        <div class="lab-panel overflow-hidden">
            <!-- Mobile View (Cartes cliquables) -->
            <div class="md:hidden divide-y divide-[#303A32]">
                @forelse($matches as $m)
                    @php 
                        $p = $m->officialPrediction; 
                        $mDateWat = $m->match_date_utc->timezone('Africa/Douala');
                    @endphp
                    <article data-match-id="{{ $m->id }}" onclick="openMatchModal({{ $m->id }})" class="p-4 space-y-2 hover:bg-[#181F1B] cursor-pointer transition-colors">
                        <div class="flex items-center justify-between gap-3 text-[11px] text-[#A2ACA3]">
                            <span>{{ $mDateWat->translatedFormat('d M · H:i') }} WAT</span>
                            <span class="text-[#C3A16A]">{{ $m->league->name }}</span>
                        </div>
                        <div class="font-bold text-sm text-[#F0F0E8] leading-snug">
                            {{ $m->homeTeam->name }} <span class="text-[#A2ACA3] font-normal text-xs">vs</span> {{ $m->awayTeam->name }}
                        </div>
                        <div class="flex items-center justify-between gap-3 text-xs">
                            <span data-live-score class="text-[#A2ACA3]">
                                @if($m->status === 'FINISHED')
                                    <strong class="text-[#C3A16A]">{{ $m->home_score }} - {{ $m->away_score }}</strong>
                                @elseif(in_array($m->status, ['LIVE', 'IN_PLAY', 'PAUSED'], true))
                                    <span class="text-[#88B69A] font-bold animate-pulse">DIRECT {{ $m->home_score ?? 0 }}-{{ $m->away_score ?? 0 }}</span>
                                @else
                                    <span class="text-[10px] px-1.5 py-0.5 rounded bg-[#222B25] text-[#A2ACA3] mono">À VENIR</span>
                                @endif
                            </span>
                            @if($p)
                                <span class="mono text-[#F0F0E8] text-xs" aria-label="Chances 1N2">
                                    <span class="text-[#88B69A]">{{ round($p->prob_home * 100) }}%</span> · 
                                    <span class="text-[#A2ACA3]">{{ round($p->prob_draw * 100) }}%</span> · 
                                    <span class="text-[#D68D86]">{{ round($p->prob_away * 100) }}%</span>
                                </span>
                            @else
                                <span class="text-[#A2ACA3] text-[11px]">Analyse disponible</span>
                            @endif
                        </div>
                    </article>
                @empty
                    <div class="p-8 text-center text-xs text-[#A2ACA3] space-y-2">
                        <div>Aucune rencontre ne correspond au filtre sélectionné pour cette date.</div>
                        <div class="text-[11px] text-[#A2ACA3]/70">Les rencontres sont synchronisées quotidiennement depuis l'API football-data.org.</div>
                    </div>
                @endforelse
            </div>

            <!-- Desktop View (Tableau cliquable) -->
            <div class="hidden md:block overflow-x-auto">
                <table class="w-full min-w-[720px] text-left text-xs">
                    <thead class="bg-[#141A17] text-[#A2ACA3] border-b border-[#303A32] uppercase tracking-wider mono text-[11px]">
                        <tr>
                            <th class="py-3 px-4">Date / Heure (WAT)</th>
                            <th class="py-3 px-4">Rencontre</th>
                            <th class="py-3 px-4 text-center">Statut / Score</th>
                            <th class="py-3 px-4 text-center">Probabilités 1N2</th>
                            <th class="py-3 px-4 text-center">Score Probable</th>
                            <th class="py-3 px-4 text-center">Qualité Données</th>
                            <th class="py-3 px-4 text-right">Analyse Détaillée</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#303A32]">
                        @forelse($matches as $m)
                            @php 
                                $p = $m->officialPrediction;
                                $mDateWat = $m->match_date_utc->timezone('Africa/Douala');
                            @endphp
                            <tr data-match-id="{{ $m->id }}" onclick="openMatchModal({{ $m->id }})" class="hover:bg-[#181F1B]/90 transition-colors cursor-pointer group">
                                <td class="py-3.5 px-4 text-[#A2ACA3] mono">
                                    <div class="font-bold text-[#F0F0E8]">{{ $mDateWat->translatedFormat('d M Y') }}</div>
                                    <div class="text-[10px]">{{ $mDateWat->format('H:i') }} WAT · {{ $m->league->name }}</div>
                                </td>
                                <td class="py-3.5 px-4">
                                    <div class="font-bold text-sm text-[#F0F0E8] flex items-center gap-2 group-hover:text-[#C3A16A] transition-colors">
                                        <span>{{ $m->homeTeam->name }}</span>
                                        <span class="text-[#A2ACA3] font-normal text-xs">vs</span>
                                        <span>{{ $m->awayTeam->name }}</span>
                                    </div>
                                    @if($p)
                                        <div class="text-[10px] text-[#A2ACA3] mono mt-0.5">
                                            Buts attendus : <span class="text-[#88B69A]">{{ $p->home_lambda }}</span> - <span class="text-[#D68D86]">{{ $p->away_lambda }}</span>
                                        </div>
                                    @endif
                                </td>
                                <td data-live-score class="py-3.5 px-4 text-center">
                                    @if($m->status === 'FINISHED')
                                        <span class="mono font-extrabold text-sm px-2 py-0.5 rounded bg-[#222B25] text-[#C3A16A]">
                                            {{ $m->home_score }} - {{ $m->away_score }}
                                        </span>
                                    @elseif(in_array($m->status, ['LIVE', 'IN_PLAY', 'PAUSED'], true))
                                        <span class="text-[10px] px-2 py-0.5 rounded bg-[#88B69A]/20 text-[#88B69A] border border-[#88B69A]/40 mono font-bold animate-pulse">
                                            DIRECT {{ $m->home_score ?? 0 }}-{{ $m->away_score ?? 0 }}
                                        </span>
                                    @else
                                        <span class="text-[10px] px-2 py-0.5 rounded bg-[#222B25] text-[#A2ACA3] border border-[#303A32] mono">
                                            {{ $mDateWat->format('H:i') }}
                                        </span>
                                    @endif
                                </td>
                                <td class="py-3.5 px-4 text-center">
                                    @if($p)
                                        <div class="flex items-center justify-center gap-1.5 mono font-semibold">
                                            <span class="text-[#88B69A]" title="Victoire Domicile">{{ round($p->prob_home * 100) }}%</span>
                                            <span class="text-[#A2ACA3]" title="Nul">{{ round($p->prob_draw * 100) }}%</span>
                                            <span class="text-[#D68D86]" title="Victoire Extérieur">{{ round($p->prob_away * 100) }}%</span>
                                        </div>
                                        @if(!empty($p->intervals_json['ci80']['home']))
                                            <div class="text-[9px] text-[#A2ACA3]/70 mono">
                                                CI80: {{ round($p->intervals_json['ci80']['home'][0] * 100) }}%–{{ round($p->intervals_json['ci80']['home'][1] * 100) }}%
                                            </div>
                                        @endif
                                    @else
                                        <span class="text-[#A2ACA3] italic">Calcul automatique</span>
                                    @endif
                                </td>
                                <td class="py-3.5 px-4 text-center mono">
                                    @if($p && !empty($p->top_scores_json))
                                        <span class="px-2 py-0.5 rounded bg-[#222B25] text-[#F0F0E8] font-bold">
                                            {{ $p->top_scores_json[0]['score'] }}
                                        </span>
                                        <div class="text-[9px] text-[#A2ACA3]">({{ round($p->top_scores_json[0]['p'] * 100) }}%)</div>
                                    @else
                                        <span class="text-[#A2ACA3]">—</span>
                                    @endif
                                </td>
                                <td class="py-3.5 px-4 text-center mono">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold {{ $m->quality_score >= 80 ? 'bg-[#88B69A]/15 text-[#88B69A]' : 'bg-[#C3A16A]/15 text-[#C3A16A]' }}">
                                        {{ round($m->quality_score) }}/100
                                    </span>
                                </td>
                                <td class="py-3.5 px-4 text-right">
                                    <button type="button" onclick="event.stopPropagation(); openMatchModal({{ $m->id }})" class="inline-flex items-center gap-1 px-3 py-1.5 rounded bg-[#222B25] border border-[#303A32] text-xs font-medium text-[#C3A16A] hover:border-[#C3A16A] transition-colors cursor-pointer">
                                        Analyse →
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="py-12 text-center text-[#A2ACA3] space-y-2">
                                    <div class="text-sm font-semibold text-[#F0F0E8]">
                                        {{ request()->boolean('reliable') ? 'Aucune prédiction finale validée actuellement' : 'Aucune rencontre disponible pour ce filtre' }}
                                    </div>
                                    <p class="text-xs text-[#A2ACA3]/70 max-w-md mx-auto">
                                        {{ request()->boolean('reliable')
                                            ? 'Le modèle s’abstient lorsqu’il ne dispose pas d’un signal suffisamment solide. Aucun choix n’est ajouté artificiellement.'
                                            : 'Oracle Foot affiche exclusivement les données officielles des compétitions configurées sans inventer de calendrier fictif.' }}
                                    </p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            @if($matches->hasPages())
                <div class="p-4 border-t border-[#303A32]">
                    {{ $matches->links() }}
                </div>
            @endif
        </div>
    </div>

    <!-- Inclusion du Modal d'Analyse (4 Onglets) -->
    @include('partials.match-modal')

    <script>
        (() => {
            const rows = document.querySelectorAll('[data-match-id]');
            if (!rows.length) return;

            const renderScore = (match) => {
                const status = match.status;
                const home = match.home_score ?? 0;
                const away = match.away_score ?? 0;
                const minute = (status === 'LIVE' || status === 'IN_PLAY') && match.minute ? ' · ' + match.minute + "'" : '';
                const label = match.status_label || 'À VENIR';
                const live = match.is_live;
                const classes = live
                    ? 'text-[10px] px-2 py-0.5 rounded bg-[#88B69A]/20 text-[#88B69A] border border-[#88B69A]/40 mono font-bold animate-pulse whitespace-nowrap'
                    : status === 'FINISHED'
                        ? 'mono font-extrabold text-sm px-2 py-0.5 rounded bg-[#222B25] text-[#C3A16A]'
                        : 'text-[10px] px-2 py-0.5 rounded bg-[#222B25] text-[#A2ACA3] border border-[#303A32] mono';
                const score = (status === 'SCHEDULED' || status === 'TIMED') ? '' : ' ' + home + '-' + away;
                return '<span class="' + classes + '">' + label + score + minute + '</span>';
            };

            const refreshLiveScores = async () => {
                try {
                    const response = await fetch('{{ url('/api/matches/live') }}', {
                        headers: { Accept: 'application/json' },
                        cache: 'no-store',
                    });
                    if (!response.ok) return;
                    const payload = await response.json();
                    (payload.data || []).forEach((match) => {
                        document.querySelectorAll('[data-match-id="' + match.id + '"] [data-live-score]')
                            .forEach((cell) => { cell.innerHTML = renderScore(match); });
                    });
                } catch (_) {
                    // L'affichage serveur reste disponible si le polling échoue.
                }
            };

            refreshLiveScores();
            window.setInterval(refreshLiveScores, 30000);
        })();
    </script>
</x-app-layout>
