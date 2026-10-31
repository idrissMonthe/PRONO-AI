<x-app-layout>
    <div class="space-y-6">
        <!-- En-tête -->
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 pb-2 border-b border-[#303A32]">
            <div>
                <h1 class="text-xl font-bold tracking-tight text-[#F0F0E8]">Cotes</h1>
                <p class="text-xs text-[#A2ACA3] mt-1">Comparez les cotes d’un match à l’analyse disponible.</p>
            </div>
        </div>

        <details class="border-b border-[#303A32] pb-3">
            <summary class="cursor-pointer text-sm font-semibold text-[#A2ACA3] hover:text-[#F0F0E8]">Indicateurs de suivi avancés</summary>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 pt-3">
            <div class="lab-panel p-4">
                <div class="text-[#A2ACA3] text-xs mono mb-1">Total Pronostics Virtuels</div>
                <div class="text-2xl font-extrabold mono text-[#F0F0E8]">{{ $totalBets }}</div>
                <div class="text-[10px] text-[#A2ACA3] mt-1">Simulations enregistrées</div>
            </div>

            <div class="lab-panel p-4">
                <div class="text-[#A2ACA3] text-xs mono mb-1">Résultat des simulations</div>
                <div class="text-2xl font-extrabold mono {{ $yield !== null && $yield >= 0 ? 'text-[#88B69A]' : 'text-[#A2ACA3]' }}">
                    {{ $yield !== null ? ($yield > 0 ? "+{$yield}" : $yield) . '%' : '—' }}
                </div>
                <div class="text-[10px] text-[#A2ACA3] mt-1">Sur base de mise fixe 1 unité</div>
            </div>

            <div class="lab-panel p-4">
                <div class="text-[#A2ACA3] text-xs mono mb-1">Cote Moyenne Prise</div>
                <div class="text-2xl font-extrabold mono text-[#C3A16A]">{{ $avgOdds !== null ? $avgOdds : '—' }}</div>
                <div class="text-[10px] text-[#A2ACA3] mt-1">Pinnacle / Bookmaker de référence</div>
            </div>

            <div class="lab-panel p-4">
                <div class="text-[#A2ACA3] text-xs mono mb-1">Écart à la cote finale</div>
                <div class="text-2xl font-extrabold mono text-[#88B69A]">{{ $avgClv !== null ? number_format($avgClv * 100, 1) . '%' : '—' }}</div>
                <div class="text-[10px] text-[#A2ACA3] mt-1">Indicateur précoce d'avantage mathématique</div>
            </div>
            </div>
        </details>

        <!-- 2. Simulateur de Retrait de Marge (Devigging) Interactif (CDC 25.3) -->
        <div class="lab-panel p-5 space-y-4">
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-sm font-bold text-[#F0F0E8] uppercase tracking-wider mono flex items-center gap-2">
                        <span class="w-1.5 h-3 bg-[#C3A16A] rounded-full"></span>
                        Comparer trois cotes
                    </h2>
                    <p class="text-[11px] text-[#A2ACA3]">Saisissez les trois cotes d’un même match.</p>
                </div>
            </div>

            <form method="GET" action="{{ route('odds.index') }}" class="grid grid-cols-1 sm:grid-cols-4 gap-4 items-end text-xs">
                <div>
                    <label class="block text-[#A2ACA3] mb-1">Cote 1 (Domicile)</label>
                    <input type="number" step="0.01" min="1.01" name="odds_home" value="{{ request('odds_home', '2.15') }}" required class="w-full bg-[#222B25] border border-[#303A32] rounded px-3 py-2 text-[#F0F0E8] mono focus:border-[#C3A16A] focus:outline-none">
                </div>
                <div>
                    <label class="block text-[#A2ACA3] mb-1">Cote N (Nul)</label>
                    <input type="number" step="0.01" min="1.01" name="odds_draw" value="{{ request('odds_draw', '3.45') }}" required class="w-full bg-[#222B25] border border-[#303A32] rounded px-3 py-2 text-[#F0F0E8] mono focus:border-[#C3A16A] focus:outline-none">
                </div>
                <div>
                    <label class="block text-[#A2ACA3] mb-1">Cote 2 (Extérieur)</label>
                    <input type="number" step="0.01" min="1.01" name="odds_away" value="{{ request('odds_away', '3.60') }}" required class="w-full bg-[#222B25] border border-[#303A32] rounded px-3 py-2 text-[#F0F0E8] mono focus:border-[#C3A16A] focus:outline-none">
                </div>
                <div>
                    <div class="flex gap-2 items-end">
                        <details class="flex-1">
                            <summary class="cursor-pointer text-[#A2ACA3] mb-1">Méthode avancée</summary>
                            <select name="devig_method" class="w-full bg-[#222B25] border border-[#303A32] rounded px-2 py-2 text-[#F0F0E8] mono focus:border-[#C3A16A] focus:outline-none">
                                <option value="shin" {{ request('devig_method', 'shin') === 'shin' ? 'selected' : '' }}>Shin</option>
                                <option value="power" {{ request('devig_method') === 'power' ? 'selected' : '' }}>Puissance</option>
                                <option value="proportional" {{ request('devig_method') === 'proportional' ? 'selected' : '' }}>Proportionnelle</option>
                            </select>
                        </details>
                        <button type="submit" class="bg-[#C3A16A] text-[#101513] font-bold px-4 py-2 rounded hover:bg-[#d8b57c] transition-colors">
                            Comparer
                        </button>
                    </div>
                </div>
            </form>

            @if($calculatorResult)
                <div class="p-4 rounded bg-[#222B25] border border-[#303A32] grid grid-cols-1 md:grid-cols-4 gap-4 text-xs mono">
                    <div>
                        <span class="text-[#A2ACA3]">Marge retirée :</span>
                        <div class="text-base font-bold text-[#D68D86] mt-0.5">{{ number_format($calculatorResult['overround'] * 100, 2) }}%</div>
                    </div>
                    <div>
                        <span class="text-[#A2ACA3]">Issue 1 (Sans Marge) :</span>
                        <div class="text-base font-bold text-[#88B69A] mt-0.5">
                            {{ round($calculatorResult['true_probabilities'][0] * 100, 1) }}% (Cote {{ $calculatorResult['fair_odds'][0] }})
                        </div>
                    </div>
                    <div>
                        <span class="text-[#A2ACA3]">Issue N (Sans Marge) :</span>
                        <div class="text-base font-bold text-[#F0F0E8] mt-0.5">
                            {{ round($calculatorResult['true_probabilities'][1] * 100, 1) }}% (Cote {{ $calculatorResult['fair_odds'][1] }})
                        </div>
                    </div>
                    <div>
                        <span class="text-[#A2ACA3]">Issue 2 (Sans Marge) :</span>
                        <div class="text-base font-bold text-[#88B69A] mt-0.5">
                            {{ round($calculatorResult['true_probabilities'][2] * 100, 1) }}% (Cote {{ $calculatorResult['fair_odds'][2] }})
                        </div>
                    </div>
                </div>
            @endif
        </div>

        <details class="border-t border-[#303A32] pt-3">
            <summary class="cursor-pointer text-sm font-semibold text-[#A2ACA3] hover:text-[#F0F0E8]">Comparaisons détaillées ({{ count($valueOpportunities) }})</summary>
            <div class="pt-4">
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-sm font-bold text-[#F0F0E8] uppercase tracking-wider mono flex items-center gap-2">
                        <span class="w-1.5 h-3 bg-[#88B69A] rounded-full"></span>
                        Tableau d'Écarts et Valeur Supposée (EV)
                    </h2>
                    <p class="text-[11px] text-[#A2ACA3]">Seuls les écarts respectant la qualité minimale et la fraîcheur des cotes sont listés.</p>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[700px] text-left text-xs">
                    <thead class="bg-[#141A17] text-[#A2ACA3] border-b border-[#303A32] mono text-[11px]">
                        <tr>
                            <th class="py-2.5 px-3">Rencontre</th>
                            <th class="py-2.5 px-3">Sélection</th>
                            <th class="py-2.5 px-3 text-center">Cote Marché</th>
                            <th class="py-2.5 px-3 text-center">Cote Juste Modèle</th>
                            <th class="py-2.5 px-3 text-center">Probabilité Modèle</th>
                            <th class="py-2.5 px-3 text-center">Espérance (EV)</th>
                            <th class="py-2.5 px-3 text-center">Robustesse</th>
                            <th class="py-2.5 px-3 text-right">Paper Trading</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#303A32]">
                        @forelse($valueOpportunities as $vo)
                            <tr class="hover:bg-[#181F1B] transition-colors">
                                <td class="py-3 px-3">
                                    <div class="font-bold text-[#F0F0E8]">{{ $vo['match']->homeTeam->name }} vs {{ $vo['match']->awayTeam->name }}</div>
                                    <div class="text-[10px] text-[#A2ACA3] mono">{{ $vo['match']->match_date_utc->translatedFormat('d M H:i') }} UTC</div>
                                </td>
                                <td class="py-3 px-3 font-semibold text-[#F0F0E8]">{{ $vo['selection_label'] }}</td>
                                <td class="py-3 px-3 text-center font-bold text-[#F0F0E8] mono">{{ $vo['market_odds'] }}</td>
                                <td class="py-3 px-3 text-center text-[#88B69A] mono font-bold">{{ $vo['fair_odds'] }}</td>
                                <td class="py-3 px-3 text-center mono">{{ round($vo['model_prob'] * 100, 1) }}%</td>
                                <td class="py-3 px-3 text-center font-bold text-[#88B69A] mono">+{{ $vo['ev_pct'] }}%</td>
                                <td class="py-3 px-3 text-center">
                                    <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase {{ $vo['classification'] === 'robuste' ? 'bg-[#88B69A]/20 text-[#88B69A] border border-[#88B69A]/40' : 'bg-[#C3A16A]/20 text-[#C3A16A] border border-[#C3A16A]/40' }}">
                                        {{ $vo['classification'] }}
                                    </span>
                                </td>
                                <td class="py-3 px-3 text-right">
                                    <form method="POST" action="{{ route('odds.paper-bet') }}">
                                        @csrf
                                        <input type="hidden" name="match_id" value="{{ $vo['match']->id }}">
                                        <input type="hidden" name="market" value="1N2">
                                        <input type="hidden" name="selection" value="{{ $vo['selection_code'] }}">
                                        <input type="hidden" name="odds_snapshot_id" value="{{ $vo['odds_snapshot_id'] }}">
                                        <button type="submit" class="px-2.5 py-1 rounded bg-[#222B25] border border-[#303A32] hover:border-[#C3A16A] text-[11px] text-[#C3A16A] font-semibold transition-colors">
                                            + Suivre (1u)
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="py-6 text-center text-[#A2ACA3]">
                                    Aucun écart significatif détecté sur les rencontres à venir.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="p-3 rounded bg-[#101513] border border-[#303A32] text-[10px] text-[#A2ACA3]">
                <strong class="text-[#C3A16A]">Clause de transparence :</strong> Un écart n'est classé comme <strong class="text-[#88B69A]">« robuste »</strong> que si l'espérance mathématique reste strictement positive même en considérant la borne basse de l'intervalle de crédibilité à 80%.
            </div>
            </div>
        </details>

        <details class="border-t border-[#303A32] pt-3">
            <summary class="cursor-pointer text-sm font-semibold text-[#A2ACA3] hover:text-[#F0F0E8]">Historique des simulations ({{ $totalBets }})</summary>
            <div class="pt-4">
            <h2 class="text-sm font-bold text-[#F0F0E8] uppercase tracking-wider mono flex items-center gap-2">
                <span class="w-1.5 h-3 bg-[#A2ACA3] rounded-full"></span>
                Journal des Pronostics Virtuels (Paper Trading)
            </h2>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[700px] text-left text-xs">
                    <thead class="bg-[#141A17] text-[#A2ACA3] border-b border-[#303A32] mono text-[11px]">
                        <tr>
                            <th class="py-2 px-3">Date Prise</th>
                            <th class="py-2 px-3">Match</th>
                            <th class="py-2 px-3">Sélection</th>
                            <th class="py-2 px-3 text-center">Cote Prise</th>
                            <th class="py-2 px-3 text-center">Mise</th>
                            <th class="py-2 px-3 text-center">CLV</th>
                            <th class="py-2 px-3 text-center">Statut</th>
                            <th class="py-2 px-3 text-right">Profit Virtuel</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#303A32] mono">
                        @forelse($paperBets as $b)
                            <tr>
                                <td class="py-2.5 px-3 text-[#A2ACA3]">{{ $b->placed_at_utc->format('d/m H:i') }}</td>
                                <td class="py-2.5 px-3 font-semibold text-[#F0F0E8]">{{ $b->match->homeTeam->short_name }} vs {{ $b->match->awayTeam->short_name }}</td>
                                <td class="py-2.5 px-3 text-[#C3A16A]">{{ $b->selection }}</td>
                                <td class="py-2.5 px-3 text-center font-bold text-[#F0F0E8]">{{ $b->placed_odds }}</td>
                                <td class="py-2.5 px-3 text-center">{{ $b->stake_units }} u</td>
                                <td class="py-2.5 px-3 text-center text-[#88B69A]">+{{ number_format($b->clv * 100, 1) }}%</td>
                                <td class="py-2.5 px-3 text-center">
                                    <span class="px-2 py-0.5 rounded text-[10px] font-bold {{ $b->status === 'WON' ? 'bg-[#88B69A]/20 text-[#88B69A]' : ($b->status === 'LOST' ? 'bg-[#D68D86]/20 text-[#D68D86]' : 'bg-[#222B25] text-[#A2ACA3]') }}">
                                        {{ $b->status }}
                                    </span>
                                </td>
                                <td class="py-2.5 px-3 text-right font-bold {{ $b->virtual_profit > 0 ? 'text-[#88B69A]' : 'text-[#A2ACA3]' }}">
                                    {{ $b->virtual_profit !== null ? ($b->virtual_profit > 0 ? "+{$b->virtual_profit}u" : "{$b->virtual_profit}u") : 'En cours' }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="py-6 text-center text-[#A2ACA3]">
                                    Aucun pronostic virtuel n'a encore été enregistré.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="text-[11px] text-[#A2ACA3] italic text-center pt-2">
                Rappel méthodologique (CDC 25.7) : Un échantillon de moins de 1 000 pronostics n'est pas statistiquement concluant pour valider un rendement financier.
            </div>
            </div>
        </details>
    </div>
</x-app-layout>

