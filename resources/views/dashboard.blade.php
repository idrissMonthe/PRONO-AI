<x-app-layout>
    <!-- Titre et Vue Générale -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-3 border-b border-[#303A32]">
        <div>
            <h1 class="text-xl font-bold tracking-tight text-[#F0F0E8]" aria-label="Tableau de Bord Analytique">Vue d’ensemble</h1>
            <span class="sr-only">Tableau de Bord Analytique</span>
            <p class="text-xs text-[#A2ACA3] mt-1">Rencontres à venir, données et état du modèle.</p>
        </div>
        <div class="flex items-center gap-3">
            <a href="{{ route('settings.index') }}" class="text-xs text-[#A2ACA3] hover:text-[#C3A16A]">État des services</a>
        </div>
    </div>

    <div class="grid grid-cols-2 sm:grid-cols-4 divide-x divide-y sm:divide-y-0 divide-[#303A32] border-y border-[#303A32] py-2">
        <div class="px-3 py-2 first:pl-0">
            <div class="text-[11px] text-[#A2ACA3]">Rencontres importées</div>
            <div class="text-xl font-bold mono text-[#F0F0E8]">{{ $totalMatches }}</div>
        </div>
        <div class="px-3 py-2">
            <div class="text-[11px] text-[#A2ACA3]">Rencontres à venir</div>
            <div class="text-xl font-bold mono text-[#F0F0E8]">{{ $upcomingCount }}</div>
        </div>
        <div class="px-3 py-2">
            <div class="text-[11px] text-[#A2ACA3]">Analyses disponibles</div>
            <div class="text-xl font-bold mono text-[#F0F0E8]">{{ $predictedMatches }}</div>
        </div>
        <div class="px-3 py-2 pr-0">
                        <div class="text-[11px] text-[#A2ACA3]">Moteur d’analyse</div>
                        <div class="text-sm font-semibold text-[#F0F0E8]">{{ $championModel ? 'Prêt' : 'En préparation' }}</div>
        </div>
    </div>

    <!-- Section Asymétrique : À Surveiller & Écarts de Marché (CDC 4.2 & 4.7) -->
    <div class="space-y-5">
        <!-- Colonne Principale (2/3) : Matchs à surveiller -->
        <div class="space-y-4">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-bold text-[#F0F0E8]">Prochaines rencontres</h2>
                <a href="{{ route('matches.index') }}" class="text-xs text-[#C3A16A] hover:underline mono">Voir tout →</a>
            </div>

            <div class="divide-y divide-[#303A32]">
                @forelse($upcomingMatches as $match)
                    @php $pred = $match->officialPrediction; @endphp
                    <div class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-3 py-3 hover:bg-[#181F1B]">
                        <div class="min-w-0">
                            <div class="text-[11px] text-[#A2ACA3]">{{ $match->league->name }} · {{ $match->match_date_utc->translatedFormat('d M, H:i') }} UTC</div>
                            <a href="{{ route('matches.show', $match->id) }}" class="font-semibold text-sm text-[#F0F0E8] hover:text-[#C3A16A]">{{ $match->homeTeam->name }} <span class="text-[#A2ACA3] font-normal">vs</span> {{ $match->awayTeam->name }}</a>
                        </div>
                        <div class="flex flex-col items-end gap-1 text-right">
                            @if($pred)
                                <details class="text-xs text-[#A2ACA3]">
                                    <summary class="cursor-pointer hover:text-[#F0F0E8]">Voir les chances</summary>
                                    <span class="mono">1 {{ round($pred->prob_home * 100) }}% · N {{ round($pred->prob_draw * 100) }}% · 2 {{ round($pred->prob_away * 100) }}%</span>
                                </details>
                            @else
                                <span class="text-xs text-[#A2ACA3]">À calculer</span>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="lab-panel p-8 text-center text-sm text-[#A2ACA3]">
                        Aucun match à venir programmé. Utilisez le module <a href="{{ route('data.index') }}" class="text-[#C3A16A] underline">Données</a> pour synchroniser ou importer un jeu de données.
                    </div>
                @endforelse
            </div>
        </div>

        <!-- Indicateurs détaillés, accessibles à la demande -->
        <div class="space-y-6">
            <details class="border-b border-[#303A32] pb-3">
                <summary class="cursor-pointer text-sm font-semibold text-[#A2ACA3] hover:text-[#F0F0E8]">Comparer avec les cotes du marché</summary>
                <div class="pt-3 space-y-3">
                    @forelse($edges as $edge)
                        <div class="p-3 rounded bg-[#222B25] border border-[#303A32] space-y-2">
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
                            <div class="text-[10px] text-[#A2ACA3] italic pt-1 border-t border-[#303A32]/40 truncate">
                                Match : {{ $edge['match']->homeTeam->name }} vs {{ $edge['match']->awayTeam->name }}
                            </div>
                        </div>
                    @empty
                        <div class="text-xs text-[#A2ACA3] text-center py-4">
                            Aucun écart significatif détecté respectant les seuils de robustesse.
                        </div>
                    @endforelse

                    <div class="text-[11px] text-[#A2ACA3] leading-relaxed">
                        L’écart n’est pas une garantie : les cotes peuvent intégrer des informations absentes de l’analyse.
                    </div>
                </div>
            </details>

            <details>
                <summary class="cursor-pointer text-sm font-semibold text-[#A2ACA3] hover:text-[#F0F0E8]">Résultats récents</summary>
                <div class="pt-3 space-y-2 text-xs">
                    @forelse($recentFinished as $fm)
                        <div class="flex items-center justify-between py-1.5 border-b border-[#303A32]/50 last:border-none gap-2">
                            <span class="text-[#F0F0E8] font-medium truncate">{{ $fm->homeTeam->short_name }} vs {{ $fm->awayTeam->short_name }}</span>
                            <span class="mono font-bold text-[#C3A16A] px-2 py-0.5 rounded bg-[#222B25] shrink-0">{{ $fm->home_score }} - {{ $fm->away_score }}</span>
                        </div>
                    @empty
                        <div class="text-[#A2ACA3] text-center py-2">Aucun match terminé récent.</div>
                    @endforelse
                </div>
            </details>
        </div>
    </div>
</x-app-layout>
