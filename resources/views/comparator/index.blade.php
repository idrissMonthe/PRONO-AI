<x-app-layout>
    <div class="consumer-page space-y-6">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 pb-2 border-b border-[#303A32]">
            <div>
                <h1 class="text-xl font-bold tracking-tight text-[#F0F0E8]">Comparer deux équipes</h1>
                <span class="sr-only">Comparateur de Performances</span>
                <p class="text-xs text-[#A2ACA3] mt-1">Comparez leurs résultats récents ou essayez un match simulé.</p>
            </div>
        </div>

        <!-- Sélecteur d'équipes -->
        <form method="GET" action="{{ route('comparator.index') }}" class="lab-panel p-4 grid grid-cols-1 md:grid-cols-3 gap-4 items-end">
            <div>
                <label class="block text-xs font-semibold text-[#88B69A] uppercase tracking-wider mb-1.5">Équipe à domicile</label>
                <select name="team_a" onchange="this.form.submit()" class="w-full bg-[#222B25] border border-[#303A32] rounded-md px-3 py-2 text-xs text-[#F0F0E8] focus:border-[#C3A16A] focus:outline-none">
                    @foreach($teams as $t)
                        <option value="{{ $t->id }}" {{ $teamA && $teamA->id == $t->id ? 'selected' : '' }}>{{ $t->name }} ({{ $t->short_name }})</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block text-xs font-semibold text-[#D68D86] uppercase tracking-wider mb-1.5">Équipe adverse</label>
                <select name="team_b" onchange="this.form.submit()" class="w-full bg-[#222B25] border border-[#303A32] rounded-md px-3 py-2 text-xs text-[#F0F0E8] focus:border-[#C3A16A] focus:outline-none">
                    @foreach($teams as $t)
                        <option value="{{ $t->id }}" {{ $teamB && $teamB->id == $t->id ? 'selected' : '' }}>{{ $t->name }} ({{ $t->short_name }})</option>
                    @endforeach
                </select>
            </div>

            <div class="flex items-center gap-3">
                <label class="flex items-center gap-2 text-xs text-[#A2ACA3]">
                    <input type="checkbox" name="is_neutral" value="1" {{ request('is_neutral') ? 'checked' : '' }} onchange="this.form.submit()" class="rounded border-[#303A32] text-[#C3A16A]">
                    <span>Terrain Neutre</span>
                </label>
                <button type="submit" class="bg-[#C3A16A] text-[#101513] font-bold py-2 px-4 rounded-md text-xs hover:bg-[#d8b57c] transition-colors ml-auto">
                    Comparer
                </button>
            </div>
        </form>

        @if($teamA && $teamB)
            <!-- Matrice comparative côte-à-côte (CDC Section 12) -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 items-stretch">
                <!-- Carte Équipe A -->
                <div class="lab-panel p-5 space-y-4">
                    <div class="text-center pb-3 border-b border-[#303A32]">
                        <h2 class="text-xl font-black text-[#88B69A]">{{ $teamA->name }}</h2>
                        <span class="text-xs text-[#A2ACA3]">{{ $teamA->country }}</span>
                    </div>

                    <div class="space-y-3 text-xs mono">
                        <div class="flex justify-between items-center py-1 border-b border-[#303A32]/50">
                            <span class="text-[#A2ACA3]">Rating Élo :</span>
                            <strong class="text-base text-[#F0F0E8]">{{ $teamA->ratings->first()->elo_rating ?? 1750 }}</strong>
                        </div>
                        <div class="flex justify-between items-center py-1 border-b border-[#303A32]/50">
                            <span class="text-[#A2ACA3]">Force Offensive (att) :</span>
                            <span class="text-[#88B69A] font-bold">+{{ round(($teamA->ratings->first()->attack_strength ?? 1.25) * 10, 1) }}%</span>
                        </div>
                        <div class="flex justify-between items-center py-1 border-b border-[#303A32]/50">
                            <span class="text-[#A2ACA3]">Solidité Défensive (def) :</span>
                            <span class="text-[#88B69A] font-bold">{{ $teamA->ratings->first()->defense_strength ?? 0.85 }}</span>
                        </div>
                    </div>
                </div>

                <!-- Simulation Fictive Centrale (CDC 12.1) -->
                <div class="lab-panel p-5 space-y-4 flex flex-col justify-between">
                    <div>
                        <div class="text-center text-xs font-bold text-[#C3A16A] mb-1">
                            Simulation
                        </div>
                        <div class="text-[10px] text-[#A2ACA3] text-center mb-3">
                            Essai temporaire, non enregistré
                        </div>

                        @if($simulation)
                            <div class="p-3 rounded bg-[#222B25] border border-[#303A32] space-y-2 text-center">
                                <div class="text-xs text-[#A2ACA3]">Chances des trois résultats</div>
                                <div class="flex items-center justify-around font-extrabold mono text-base">
                                    <div class="text-[#88B69A]">
                                        <div>{{ round($simulation['probabilities']['home']['p'] * 100) }}%</div>
                                        <div class="text-[10px] font-normal text-[#A2ACA3]">{{ $teamA->short_name }}</div>
                                    </div>
                                    <div class="text-[#F0F0E8]">
                                        <div>{{ round($simulation['probabilities']['draw']['p'] * 100) }}%</div>
                                        <div class="text-[10px] font-normal text-[#A2ACA3]">Nul</div>
                                    </div>
                                    <div class="text-[#D68D86]">
                                        <div>{{ round($simulation['probabilities']['away']['p'] * 100) }}%</div>
                                        <div class="text-[10px] font-normal text-[#A2ACA3]">{{ $teamB->short_name }}</div>
                                    </div>
                                </div>
                            </div>

                            <div class="text-center mt-3 text-xs">
                                <span class="text-[#A2ACA3]">Score le plus probable :</span>
                                <strong class="text-[#C3A16A] ml-1">{{ $simulation['top_scores'][0]['score'] ?? '1-1' }}</strong>
                            </div>
                        @endif
                    </div>

                    <div class="text-[10px] text-[#A2ACA3] text-center">Cette simulation ne modifie pas l’analyse enregistrée.</div>
                </div>

                <!-- Carte Équipe B -->
                <div class="lab-panel p-5 space-y-4">
                    <div class="text-center pb-3 border-b border-[#303A32]">
                        <h2 class="text-xl font-black text-[#D68D86]">{{ $teamB->name }}</h2>
                        <span class="text-xs text-[#A2ACA3]">{{ $teamB->country }}</span>
                    </div>

                    <div class="space-y-3 text-xs mono">
                        <div class="flex justify-between items-center py-1 border-b border-[#303A32]/50">
                            <span class="text-[#A2ACA3]">Rating Élo :</span>
                            <strong class="text-base text-[#F0F0E8]">{{ $teamB->ratings->first()->elo_rating ?? 1720 }}</strong>
                        </div>
                        <div class="flex justify-between items-center py-1 border-b border-[#303A32]/50">
                            <span class="text-[#A2ACA3]">Force Offensive (att) :</span>
                            <span class="text-[#D68D86] font-bold">+{{ round(($teamB->ratings->first()->attack_strength ?? 1.15) * 10, 1) }}%</span>
                        </div>
                        <div class="flex justify-between items-center py-1 border-b border-[#303A32]/50">
                            <span class="text-[#A2ACA3]">Solidité Défensive (def) :</span>
                            <span class="text-[#D68D86] font-bold">{{ $teamB->ratings->first()->defense_strength ?? 1.05 }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Historique des confrontations -->
            <div class="lab-panel p-5 space-y-3">
                <h3 class="text-sm font-bold text-[#F0F0E8]">Rencontres précédentes</h3>

                <div class="divide-y divide-[#303A32] text-xs">
                    @forelse($h2hMatches as $hm)
                        <div class="py-2.5 flex items-center justify-between">
                            <span class="mono text-[#A2ACA3]">{{ $hm->match_date_utc->translatedFormat('d M Y') }}</span>
                            <span class="font-bold text-[#F0F0E8]">{{ $hm->homeTeam->name }} vs {{ $hm->awayTeam->name }}</span>
                            <span class="mono font-extrabold px-2 py-0.5 rounded bg-[#222B25] text-[#C3A16A]">
                                {{ $hm->home_score }} - {{ $hm->away_score }}
                            </span>
                        </div>
                    @empty
                        <div class="text-[#A2ACA3] py-2">Aucune confrontation directe enregistrée dans la base.</div>
                    @endforelse
                </div>
            </div>
        @endif
    </div>
</x-app-layout>

