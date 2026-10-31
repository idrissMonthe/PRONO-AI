<x-app-layout>
    <!-- En-tête Aujourd'hui (Dashboard) -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-3 border-b border-[#303A32]">
        <div>
            <div class="flex items-center gap-2">
                <h1 class="text-xl font-bold tracking-tight text-[#F0F0E8]">Aujourd’hui</h1>
                <span class="sr-only">Tableau de Bord Analytique</span>
                <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-[#88B69A]/20 text-[#88B69A] border border-[#88B69A]/40 mono">
                    ASSISTANT PERSONNEL
                </span>
            </div>
            <p class="text-xs text-[#A2ACA3] mt-1">
                Rencontres clés, opportunités de valeur et combinés probabilistes du jour · Fuseau : <strong>Africa/Douala (WAT)</strong>
            </p>
        </div>
        <div class="flex items-center gap-3 text-xs">
            @if($lastSyncFormatted)
                <span class="px-2.5 py-1 rounded bg-[#181F1B] border border-[#303A32] text-[#A2ACA3] mono text-[11px] hidden md:inline-block">
                    Sync API : <strong class="text-[#F0F0E8]">{{ $lastSyncFormatted }}</strong>
                </span>
            @endif
            <span class="px-2.5 py-1 rounded bg-[#181F1B] border border-[#303A32] text-[#A2ACA3] mono text-[11px]">
                Moteur : <strong class="text-[#88B69A]">{{ $championModel?->version_name ?? 'Dixon-Coles M3' }}</strong>
            </span>
            <a href="{{ route('settings.index') }}" class="p-1.5 rounded border border-[#303A32] hover:border-[#C3A16A] text-[#A2ACA3] hover:text-[#F0F0E8] transition-colors" title="Paramètres & API">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
            </a>
        </div>
    </div>

    <!-- 1. KPIs Clés -->
    <div class="grid grid-cols-2 sm:grid-cols-4 divide-x divide-y sm:divide-y-0 divide-[#303A32] border-y border-[#303A32] py-2">
        <div class="px-3 py-2 first:pl-0">
            <div class="text-[11px] text-[#A2ACA3]">Rencontres au calendrier</div>
            <div class="text-xl font-bold mono text-[#F0F0E8]">{{ $totalMatches }}</div>
        </div>
        <div class="px-3 py-2">
            <div class="text-[11px] text-[#A2ACA3]">Matchs programmés</div>
            <div class="text-xl font-bold mono text-[#F0F0E8]">{{ $upcomingCount }}</div>
        </div>
        <div class="px-3 py-2">
            <div class="text-[11px] text-[#A2ACA3]">Analyses probabilistes</div>
            <div class="text-xl font-bold mono text-[#88B69A]">{{ $predictedMatches }}</div>
        </div>
        <div class="px-3 py-2 pr-0">
            <div class="text-[11px] text-[#A2ACA3]">Combinés du jour</div>
            <div class="text-xl font-bold mono text-[#C3A16A]">{{ $dailyParlays->count() }}</div>
        </div>
    </div>

    <!-- 2. Section Combinés Proposés Automatiquement (Section 9 & 28) -->
    @if($dailyParlays->isNotEmpty())
        <div class="space-y-3">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <span class="w-2 h-2 rounded-full bg-[#C3A16A]"></span>
                    <h2 class="text-sm font-bold text-[#F0F0E8] uppercase tracking-wider mono">
                        Combinés Probabilistes du Jour (Génération Automatique)
                    </h2>
                </div>
                <a href="{{ route('parlays.index') }}" class="text-xs text-[#C3A16A] hover:underline mono">
                    Consulter les combinés & détails →
                </a>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                @foreach($dailyParlays as $parlay)
                    <div class="p-4 rounded bg-[#141A17] border border-[#303A32] hover:border-[#C3A16A]/50 transition-colors flex flex-col justify-between space-y-3">
                        <div class="space-y-2">
                            <div class="flex items-center justify-between">
                                <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase mono {{ $parlay->risk_level === 'MODERE' ? 'bg-[#88B69A]/20 text-[#88B69A]' : ($parlay->risk_level === 'EQUILIBRE' ? 'bg-[#C3A16A]/20 text-[#C3A16A]' : 'bg-[#D68D86]/20 text-[#D68D86]') }}">
                                    {{ $parlay->risk_level }}
                                </span>
                                <span class="text-xs font-bold mono text-[#F0F0E8]">Cote : x{{ number_format($parlay->combined_odds, 2) }}</span>
                            </div>

                            <div class="font-bold text-sm text-[#F0F0E8] leading-snug">
                                {{ $parlay->title }}
                            </div>

                            <div class="flex items-center justify-between py-1 px-2 rounded bg-[#101513] mono text-xs">
                                <div>
                                    <span class="text-[10px] text-[#A2ACA3]">Chances : </span>
                                    <strong class="text-[#88B69A] text-sm">{{ round($parlay->combined_prob * 100, 1) }}%</strong>
                                </div>
                                <div>
                                    <span class="text-[10px] text-[#A2ACA3]">Risque : </span>
                                    <strong class="text-[#C3A16A] text-sm">{{ $parlay->risk_score }}/10</strong>
                                </div>
                            </div>

                            <ul class="space-y-1 text-xs">
                                @foreach(array_slice($parlay->selections_json, 0, 3) as $sel)
                                    <li class="flex items-center justify-between text-[11px] text-[#A2ACA3]">
                                        <span class="truncate max-w-[170px] text-[#F0F0E8]">{{ $sel['selection_label'] }}</span>
                                        <span class="mono text-[#C3A16A]">{{ number_format($sel['odds'], 2) }} ({{ $sel['model_prob_pct'] }}%)</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>

                        <a href="{{ route('parlays.index') }}" class="w-full text-center py-1.5 rounded bg-[#222B25] hover:bg-[#2c372f] border border-[#303A32] text-xs font-semibold text-[#F0F0E8] transition-colors mono">
                            Voir l'analyse complète
                        </a>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <!-- 3. Section Opportunités de Valeur (Écarts Modèle vs Marché) -->
    <div class="space-y-3">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-2">
                <span class="w-2 h-2 rounded-full bg-[#88B69A]"></span>
                <h2 class="text-sm font-bold text-[#F0F0E8] uppercase tracking-wider mono">
                    Opportunités de Valeur Détectées (EV > 0)
                </h2>
            </div>
            <a href="{{ route('odds.index') }}" class="text-xs text-[#88B69A] hover:underline mono">
                Toutes les cotes & simulateur →
            </a>
        </div>

        @if(count($edges) > 0)
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                @foreach(array_slice($edges, 0, 3) as $edge)
                    <div onclick="openMatchModal({{ $edge['match']->id }})" class="p-3.5 rounded bg-[#181F1B] border border-[#303A32] hover:border-[#C3A16A] transition-colors cursor-pointer space-y-2">
                        <div class="flex items-center justify-between text-xs gap-2">
                            <span class="font-bold text-[#F0F0E8] truncate">{{ $edge['selection'] }}</span>
                            <span class="px-1.5 py-0.5 text-[10px] font-bold rounded uppercase shrink-0 {{ $edge['classification'] === 'robuste' ? 'bg-[#88B69A]/20 text-[#88B69A] border border-[#88B69A]/40' : 'bg-[#C3A16A]/20 text-[#C3A16A] border border-[#C3A16A]/40' }}">
                                {{ $edge['classification'] }}
                            </span>
                        </div>
                        <div class="text-[11px] text-[#A2ACA3] flex flex-wrap items-center justify-between gap-1.5 mono">
                            <span>Cote marché : <strong class="text-[#F0F0E8]">{{ $edge['market_odds'] }}</strong></span>
                            <span>Cote juste : <strong class="text-[#88B69A]">{{ $edge['fair_odds'] }}</strong></span>
                            <span class="text-[#88B69A] font-bold">+{{ $edge['ev_pct'] }}% EV</span>
                        </div>
                        <div class="text-[10px] text-[#A2ACA3] italic pt-1 border-t border-[#303A32]/40 truncate flex justify-between items-center">
                            <span>{{ $edge['match']->homeTeam->name }} vs {{ $edge['match']->awayTeam->name }}</span>
                            <span class="text-[#C3A16A] font-semibold mono">Analyse ↗</span>
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <div class="p-4 rounded bg-[#141A17] border border-[#303A32] text-xs text-[#A2ACA3] text-center">
                Aucun écart de marché significatif actuellement selon les seuils de robustesse mathématique.
            </div>
        @endif
    </div>

    <!-- 4. Prochaines Rencontres & Analyse Probabiliste -->
    <div class="space-y-3">
        <div class="flex items-center justify-between">
            <h2 class="text-sm font-bold text-[#F0F0E8] uppercase tracking-wider mono">
                Prochaines Rencontres & Estimations
            </h2>
            <a href="{{ route('matches.index') }}" class="text-xs text-[#C3A16A] hover:underline mono">
                Toutes les rencontres ({{ $upcomingCount }}) →
            </a>
        </div>

        <div class="divide-y divide-[#303A32] border border-[#303A32] rounded-md overflow-hidden bg-[#141A17]">
            @forelse($upcomingMatches as $match)
                @php 
                    $pred = $match->officialPrediction;
                    $mDateWat = $match->match_date_utc->timezone('Africa/Douala');
                @endphp
                <div onclick="openMatchModal({{ $match->id }})" class="p-3.5 flex flex-col sm:flex-row sm:items-center justify-between gap-3 hover:bg-[#181F1B] transition-colors cursor-pointer group">
                    <div class="min-w-0">
                        <div class="text-[11px] text-[#A2ACA3] mono">
                            {{ $match->league->name ?? 'Ligue' }} · {{ $mDateWat->translatedFormat('d M, H:i') }} WAT
                        </div>
                        <div class="font-bold text-sm text-[#F0F0E8] group-hover:text-[#C3A16A] transition-colors mt-0.5">
                            {{ $match->homeTeam->name }} <span class="text-[#A2ACA3] font-normal text-xs">vs</span> {{ $match->awayTeam->name }}
                        </div>
                    </div>

                    <div class="flex items-center gap-3">
                        @if($pred)
                            <div class="flex items-center gap-1.5 text-xs mono">
                                <span class="px-2 py-1 rounded bg-[#101513] border border-[#303A32]">
                                    1: <strong class="text-[#88B69A]">{{ round($pred->prob_home * 100) }}%</strong>
                                </span>
                                <span class="px-2 py-1 rounded bg-[#101513] border border-[#303A32]">
                                    N: <strong class="text-[#A2ACA3]">{{ round($pred->prob_draw * 100) }}%</strong>
                                </span>
                                <span class="px-2 py-1 rounded bg-[#101513] border border-[#303A32]">
                                    2: <strong class="text-[#D68D86]">{{ round($pred->prob_away * 100) }}%</strong>
                                </span>
                            </div>
                        @else
                            <span class="text-xs text-[#A2ACA3] mono">Calcul disponible</span>
                        @endif

                        <button type="button" onclick="event.stopPropagation(); openMatchModal({{ $match->id }})" class="px-3 py-1 rounded bg-[#222B25] hover:bg-[#2c372f] border border-[#303A32] text-xs font-semibold text-[#C3A16A] transition-colors mono shrink-0 cursor-pointer">
                            Fiche & IA →
                        </button>
                    </div>
                </div>
            @empty
                <div class="p-8 text-center text-xs text-[#A2ACA3]">
                    Aucune rencontre programmée. Utilisez les fonctions de synchronisation dans Paramètres.
                </div>
            @endforelse
        </div>
    </div>

    <!-- 5. Résultats Récents -->
    @if($recentFinished->isNotEmpty())
        <div class="space-y-2 pt-2">
            <h2 class="text-xs font-bold text-[#A2ACA3] uppercase tracking-wider mono">
                Derniers Résultats Enregistrés
            </h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-2">
                @foreach($recentFinished as $fm)
                    <div onclick="openMatchModal({{ $fm->id }})" class="p-2.5 rounded bg-[#141A17] border border-[#303A32] hover:border-[#C3A16A] transition-colors cursor-pointer flex items-center justify-between text-xs">
                        <span class="text-[#F0F0E8] font-medium truncate">{{ $fm->homeTeam->short_name }} vs {{ $fm->awayTeam->short_name }}</span>
                        <span class="mono font-bold text-[#C3A16A] px-2 py-0.5 rounded bg-[#222B25] shrink-0">
                            {{ $fm->home_score }} - {{ $fm->away_score }}
                        </span>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <!-- Inclusion du Modal d'Analyse (4 Onglets) -->
    @include('partials.match-modal')
</x-app-layout>
