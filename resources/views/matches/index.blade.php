<x-app-layout>
    <div class="space-y-5">
        <!-- En-tête -->
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 pb-2 border-b border-[#303A32]">
            <div>
                <h1 class="text-xl font-bold tracking-tight text-[#F0F0E8]">Matchs</h1>
                <span class="sr-only">Calendrier</span>
                <p class="text-xs text-[#A2ACA3] mt-1">Calendrier et analyses disponibles.</p>
            </div>
            <div class="mono text-xs text-[#A2ACA3]">
                Total affiché : <strong class="text-[#F0F0E8]">{{ $matches->total() }}</strong> rencontre(s)
            </div>
        </div>

        <!-- Barre de Filtres (CDC Section 5.1 & 5.5) -->
        <form method="GET" action="{{ route('matches.index') }}" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 items-end pb-4 border-b border-[#303A32]">
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
                <label class="block text-xs font-semibold text-[#A2ACA3] uppercase tracking-wider mb-1.5">Statut</label>
                <select name="status" class="w-full bg-[#222B25] border border-[#303A32] rounded-md px-3 py-2 text-xs text-[#F0F0E8] focus:border-[#C3A16A] focus:outline-none">
                    <option value="">Tous les statuts</option>
                    <option value="SCHEDULED" {{ request('status') === 'SCHEDULED' ? 'selected' : '' }}>À venir (SCHEDULED)</option>
                    <option value="FINISHED" {{ request('status') === 'FINISHED' ? 'selected' : '' }}>Terminés (FINISHED)</option>
                </select>
            </div>

            <div>
                <label class="block text-xs font-semibold text-[#A2ACA3] uppercase tracking-wider mb-1.5">Recherche Équipe</label>
                <input type="text" name="search" value="{{ request('search') }}" placeholder="Ex: Arsenal, Real Madrid..." class="w-full bg-[#222B25] border border-[#303A32] rounded-md px-3 py-2 text-xs text-[#F0F0E8] placeholder-[#A2ACA3]/50 focus:border-[#C3A16A] focus:outline-none">
            </div>

            <div class="flex items-center gap-2">
                <button type="submit" class="flex-1 bg-[#C3A16A] hover:bg-[#d8b57c] text-[#101513] font-bold py-2 px-4 rounded-md text-xs transition-colors">
                    Filtrer
                </button>
                <a href="{{ route('matches.index') }}" class="px-3 py-2 rounded-md bg-[#222B25] border border-[#303A32] text-xs text-[#A2ACA3] hover:text-[#F0F0E8] transition-colors">
                    Réinitialiser
                </a>
            </div>
        </form>

        <!-- Liste des Matchs (CDC Section 5.2) -->
        <div class="lab-panel overflow-hidden">
            <div class="md:hidden divide-y divide-[#303A32]">
                @forelse($matches as $m)
                    @php $p = $m->officialPrediction; @endphp
                    <article class="p-4 space-y-2">
                        <div class="flex items-center justify-between gap-3 text-[11px] text-[#A2ACA3]">
                            <span>{{ $m->match_date_utc->translatedFormat('d M Y · H:i') }} UTC</span>
                            <span>{{ $m->league->name }}</span>
                        </div>
                        <div class="font-semibold text-sm text-[#F0F0E8] leading-snug">
                            {{ $m->homeTeam->name }} <span class="text-[#A2ACA3] font-normal">vs</span> {{ $m->awayTeam->name }}
                        </div>
                        <div class="flex items-center justify-between gap-3 text-xs">
                            <span class="text-[#A2ACA3]">
                                @if($m->status === 'FINISHED')
                                    {{ $m->home_score }} - {{ $m->away_score }}
                                @else
                                    À venir
                                @endif
                            </span>
                            @if($p)
                                <span class="mono text-[#F0F0E8]" aria-label="Chances domicile, nul, extérieur">
                                    {{ round($p->prob_home * 100) }} · {{ round($p->prob_draw * 100) }} · {{ round($p->prob_away * 100) }}%
                                </span>
                            @else
                                <span class="text-[#A2ACA3]">Analyse en attente</span>
                            @endif
                        </div>
                        <a href="{{ route('matches.show', $m->id) }}" class="inline-flex pt-1 text-xs font-medium text-[#C3A16A]">Voir l’analyse →</a>
                    </article>
                @empty
                    <div class="p-6 text-center text-sm text-[#A2ACA3]">Aucun match ne correspond aux filtres.</div>
                @endforelse
            </div>

            <div class="hidden md:block overflow-x-auto">
                <table class="w-full min-w-[720px] text-left text-xs">
                    <thead class="bg-[#141A17] text-[#A2ACA3] border-b border-[#303A32] uppercase tracking-wider mono text-[11px]">
                        <tr>
                            <th class="py-3 px-4">Date / Compétition</th>
                            <th class="py-3 px-4">Rencontre</th>
                            <th class="py-3 px-4 text-center">Statut / Score</th>
                            <th class="py-3 px-4 text-center">Probabilités 1N2</th>
                            <th class="py-3 px-4 text-center">Score probable</th>
                            <th class="py-3 px-4 text-center">Qualité Données</th>
                            <th class="py-3 px-4 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#303A32]">
                        @forelse($matches as $m)
                            @php $p = $m->officialPrediction; @endphp
                            <tr class="hover:bg-[#181F1B]/80 transition-colors">
                                <td class="py-3.5 px-4 text-[#A2ACA3] mono">
                                    <div class="font-bold text-[#F0F0E8]">{{ $m->match_date_utc->translatedFormat('d M Y') }}</div>
                                    <div class="text-[10px]">{{ $m->match_date_utc->format('H:i') }} UTC · {{ $m->league->name }}</div>
                                </td>
                                <td class="py-3.5 px-4">
                                    <div class="font-bold text-sm text-[#F0F0E8] flex items-center gap-2">
                                        <span>{{ $m->homeTeam->name }}</span>
                                        <span class="text-[#A2ACA3] font-normal text-xs">vs</span>
                                        <span>{{ $m->awayTeam->name }}</span>
                                    </div>
                                    @if($p)
                                        <div class="text-[10px] text-[#A2ACA3] mono mt-0.5">
                                            Buts attendus : {{ $p->home_lambda }} - {{ $p->away_lambda }}
                                        </div>
                                    @endif
                                </td>
                                <td class="py-3.5 px-4 text-center">
                                    @if($m->status === 'FINISHED')
                                        <span class="mono font-extrabold text-sm px-2 py-0.5 rounded bg-[#222B25] text-[#C3A16A]">
                                            {{ $m->home_score }} - {{ $m->away_score }}
                                        </span>
                                    @else
                                        <span class="text-[10px] px-2 py-0.5 rounded bg-[#88B69A]/15 text-[#88B69A] border border-[#88B69A]/30 mono">
                                            PROGRAMMÉ
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
                                            <details class="text-[9px] text-[#A2ACA3] mono">
                                                <summary class="cursor-pointer">Marge d’incertitude</summary>
                                                <span>{{ round($p->intervals_json['ci80']['home'][0] * 100) }}% – {{ round($p->intervals_json['ci80']['home'][1] * 100) }}%</span>
                                            </details>
                                        @endif
                                    @else
                                        <span class="text-[#A2ACA3] italic">Non calculé</span>
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
                                    <a href="{{ route('matches.show', $m->id) }}" class="inline-flex items-center gap-1 px-3 py-1.5 rounded bg-[#222B25] border border-[#303A32] text-xs font-medium text-[#C3A16A] hover:border-[#C3A16A] transition-colors">
                                        Analyse →
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="py-8 text-center text-[#A2ACA3]">
                                    Aucun match ne correspond aux filtres sélectionnés.
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
</x-app-layout>

