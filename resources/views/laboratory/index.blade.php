<x-app-layout>
    <div class="space-y-6">
        <!-- En-tête -->
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 pb-2 border-b border-[#303A32]">
            <div>
                <h1 class="text-xl font-bold tracking-tight text-[#F0F0E8]">Analyse avancée</h1>
                <span class="sr-only">Banc d'essai</span>
                <p class="text-xs text-[#A2ACA3] mt-1">Comparaison des méthodes et mesures de fiabilité.</p>
            </div>
            <button type="button" disabled title="Aucun pipeline walk-forward réel n’est encore configuré" class="inline-flex items-center gap-2 bg-[#222B25] text-[#A2ACA3] font-bold py-2 px-4 rounded-md text-xs cursor-not-allowed">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    Backtest non configuré
            </button>
        </div>

        <details class="border-b border-[#303A32] pb-3">
            <summary class="cursor-pointer text-sm font-semibold text-[#A2ACA3] hover:text-[#F0F0E8]">Liste des méthodes et résultats (avancé)</summary>
            <div class="pt-4">
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-sm font-bold text-[#F0F0E8] uppercase tracking-wider mono flex items-center gap-2">
                        <span class="w-1.5 h-3 bg-[#C3A16A] rounded-full"></span>
                        Méthodes comparées
                    </h2>
                    <p class="text-[11px] text-[#A2ACA3]">Les métriques restent indisponibles tant qu'aucun backtest reproductible n'a été exécuté.</p>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[760px] text-left text-xs">
                    <thead class="bg-[#141A17] text-[#A2ACA3] border-b border-[#303A32] mono text-[11px]">
                        <tr>
                            <th class="py-2.5 px-3">Échelon</th>
                            <th class="py-2.5 px-3">Algorithme & Descriptif</th>
                            <th class="py-2.5 px-3">Famille</th>
                            <th class="py-2.5 px-3 text-center">Score RPS</th>
                            <th class="py-2.5 px-3 text-center">Log Loss</th>
                            <th class="py-2.5 px-3 text-center">Brier Multiclasse</th>
                            <th class="py-2.5 px-3 text-center">Calibration ECE</th>
                            <th class="py-2.5 px-3 text-center">Skill vs M0</th>
                            <th class="py-2.5 px-3 text-center">Test Diebold-Mariano</th>
                            <th class="py-2.5 px-3 text-right">Rôle / Statut</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#303A32] mono">
                        @foreach($benchmarks as $b)
                            <tr class="{{ $b['ref'] === 'M3' ? 'bg-[#C3A16A]/10 font-semibold' : 'hover:bg-[#181F1B]' }}">
                                <td class="py-3 px-3 font-bold text-[#F0F0E8]">{{ $b['ref'] }}</td>
                                <td class="py-3 px-3 font-sans font-medium text-[#F0F0E8]">{{ $b['name'] }}</td>
                                <td class="py-3 px-3 uppercase text-[10px] text-[#A2ACA3]">{{ $b['family'] }}</td>
                                <td class="py-3 px-3 text-center">{{ $b['rps'] ?? '—' }}</td>
                                <td class="py-3 px-3 text-center">{{ $b['log_loss'] ?? '—' }}</td>
                                <td class="py-3 px-3 text-center">{{ $b['brier'] ?? '—' }}</td>
                                <td class="py-3 px-3 text-center">{{ $b['ece'] ?? '—' }}</td>
                                <td class="py-3 px-3 text-center">{{ $b['skill_vs_m0'] ?? '—' }}</td>
                                <td class="py-3 px-3 text-center text-[#A2ACA3]">{{ $b['dm_pvalue'] ?? '—' }}</td>
                                <td class="py-3 px-3 text-right">
                                    <span class="px-2 py-0.5 rounded text-[10px] uppercase font-bold bg-[#222B25] text-[#A2ACA3]">
                                        {{ $b['status'] }}
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="p-3 rounded bg-[#101513] border border-[#303A32] text-[10px] text-[#A2ACA3] leading-relaxed">
                <strong class="text-[#C3A16A]">État des mesures :</strong> aucun résultat comparatif n'est enregistré. Les modèles et tests de significativité ne sont pas évalués dans cette installation.
            </div>
            </div>
        </details>

        <!-- 2. Registre des Modèles & Portes de Promotion (CDC Section 27) -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Registre des versions -->
            <div class="lab-panel p-5 space-y-4">
                <h3 class="text-sm font-bold text-[#F0F0E8] uppercase tracking-wider mono flex items-center gap-2">
                    <span class="w-1.5 h-3 bg-[#A2ACA3] rounded-full"></span>
                    Versions du modèle
                </h3>

                <div class="divide-y divide-[#303A32] text-xs">
                    @foreach($models as $m)
                        <div class="py-3 flex items-center justify-between">
                            <div>
                                <div class="font-bold text-[#F0F0E8] mono flex items-center gap-2">
                                    <span>{{ $m->version_name }}</span>
                                    <span class="text-[10px] px-1.5 py-0.2 rounded bg-[#222B25] text-[#A2ACA3]">{{ $m->family }}</span>
                                </div>
                                <div class="text-[11px] text-[#A2ACA3] mt-0.5">{{ $m->algorithm }}</div>
                            </div>
                            <div class="flex items-center gap-3">
                                <span class="px-2 py-0.5 text-[10px] font-bold rounded uppercase mono {{ $m->status === 'champion' ? 'bg-[#88B69A]/20 text-[#88B69A] border border-[#88B69A]/40' : 'bg-[#222B25] text-[#A2ACA3]' }}">
                                    {{ $m->status }}
                                </span>
                                @if($m->status !== 'champion')
                                    <form method="POST" action="{{ route('laboratory.promote') }}">
                                        @csrf
                                        <input type="hidden" name="target_version_id" value="{{ $m->id }}">
                                        <button type="submit" disabled class="px-2 py-1 rounded bg-[#222B25] border border-[#303A32] text-[10px] text-[#A2ACA3] cursor-not-allowed" title="Promotion désactivée tant que les critères ne sont pas mesurés">
                                            Promouvoir
                                        </button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            <!-- Historique des Backtests Récents -->
            <div class="lab-panel p-5 space-y-4">
                <h3 class="text-sm font-bold text-[#F0F0E8] uppercase tracking-wider mono flex items-center gap-2">
                    <span class="w-1.5 h-3 bg-[#88B69A] rounded-full"></span>
                    Tests passés
                </h3>

                <div class="divide-y divide-[#303A32] text-xs mono">
                    @forelse($backtests as $bt)
                        <div class="py-3 space-y-1">
                            <div class="flex justify-between items-center">
                                <span class="font-bold text-[#F0F0E8]">Run #{{ $bt->id }} — {{ $bt->time_step }}</span>
                                <span class="text-[10px] px-2 py-0.5 rounded bg-[#88B69A]/20 text-[#88B69A]">{{ $bt->status }}</span>
                            </div>
                            <div class="text-[11px] text-[#A2ACA3]">
                                Période : {{ $bt->period_start }} → {{ $bt->period_end }}
                            </div>
                            <div class="text-[11px] text-[#88B69A]">
                                RPS : {{ $bt->metrics['rps'] ?? '—' }} · Log Loss : {{ $bt->metrics['log_loss'] ?? '—' }} · ECE : {{ $bt->metrics['ece'] ?? '—' }}
                            </div>
                        </div>
                    @empty
                        <div class="text-[#A2ACA3] py-4 text-center">Aucun run de backtest enregistré.</div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</x-app-layout>

