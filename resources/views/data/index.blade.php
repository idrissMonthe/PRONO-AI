<x-app-layout>
    <div class="space-y-6">
        <!-- En-tête -->
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 pb-2 border-b border-[#303A32]">
            <div>
                <h1 class="text-xl font-bold tracking-tight text-[#F0F0E8]">Données</h1>
                <p class="text-xs text-[#A2ACA3] mt-1">Matchs importés, noms d’équipes et données à vérifier.</p>
            </div>
            <div class="flex items-center gap-3">
                <form method="POST" action="{{ route('data.sync-api') }}">
                    @csrf
                    <button type="submit" class="bg-[#222B25] border border-[#303A32] hover:border-[#C3A16A] text-xs font-semibold text-[#F0F0E8] py-2 px-4 rounded-md transition-colors">
                        Synchroniser les matchs
                    </button>
                </form>
            </div>
        </div>

        <!-- 1. Scores de Qualité & Complétude (CDC Section 13.5) -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="lab-panel p-4">
                <div class="text-[#A2ACA3] text-xs mb-1">Matchs importés</div>
                <div class="text-2xl font-extrabold mono text-[#F0F0E8]">{{ $matchesCount }}</div>
                <div class="text-[10px] text-[#A2ACA3] mt-1">{{ $teamsCount }} équipes enregistrées</div>
            </div>

            <div class="lab-panel p-4">
                <div class="text-[#A2ACA3] text-xs mb-1">Données complètes</div>
                <div class="text-2xl font-extrabold mono text-[#88B69A]">{{ $usableCount }}</div>
                <div class="text-[10px] text-[#A2ACA3] mt-1">Score qualité &ge; 80/100</div>
            </div>

            <div class="lab-panel p-4">
                <div class="text-[#A2ACA3] text-xs mb-1">Données à compléter</div>
                <div class="text-2xl font-extrabold mono text-[#C3A16A]">{{ $degradedCount }}</div>
                <div class="text-[10px] text-[#A2ACA3] mt-1">Données partielles ou sans cotes</div>
            </div>

            <div class="lab-panel p-4">
                <div class="text-[#A2ACA3] text-xs mb-1">Importations rejetées</div>
                <div class="text-2xl font-extrabold mono text-[#D68D86]">{{ $quarantineCount }}</div>
                <div class="text-[10px] text-[#A2ACA3] mt-1">Rejets schéma ou anomalies</div>
            </div>
        </div>

        <!-- 2. Résolution d'Entités & Table des Alias (CDC Section 8.10) -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Liste des Alias d'Équipes -->
            <div class="lg:col-span-2 lab-panel p-5 space-y-4">
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="text-sm font-bold text-[#F0F0E8] uppercase tracking-wider mono flex items-center gap-2">
                            <span class="w-1.5 h-3 bg-[#C3A16A] rounded-full"></span>
                            Noms d’équipes reconnus
                        </h2>
                        <span class="sr-only">team_aliases</span>
                        <p class="text-[11px] text-[#A2ACA3]">Correspondance des noms reçus avec les équipes enregistrées.</p>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full min-w-[500px] text-left text-xs">
                        <thead class="bg-[#141A17] text-[#A2ACA3] border-b border-[#303A32] mono text-[11px]">
                            <tr>
                                <th class="py-2.5 px-3">Nom reçu</th>
                                <th class="py-2.5 px-3">Équipe reconnue</th>
                                <th class="py-2.5 px-3">Source</th>
                                <th class="py-2.5 px-3 text-center">Confiance</th>
                                <th class="py-2.5 px-3 text-right">Statut</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[#303A32] mono">
                            @forelse($aliases as $al)
                                <tr>
                                    <td class="py-2.5 px-3 font-semibold text-[#F0F0E8]">{{ $al->alias }}</td>
                                    <td class="py-2.5 px-3 font-bold text-[#C3A16A]">{{ $al->team->name ?? '—' }}</td>
                                    <td class="py-2.5 px-3 text-[#A2ACA3]">{{ $al->source }}</td>
                                    <td class="py-2.5 px-3 text-center text-[#88B69A]">{{ round($al->confidence) }}%</td>
                                    <td class="py-2.5 px-3 text-right">
                                        <span class="px-2 py-0.5 rounded text-[10px] bg-[#88B69A]/20 text-[#88B69A]">Validé</span>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="py-4 text-center text-[#A2ACA3]">Aucun autre nom d’équipe enregistré.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Ajouter un Alias -->
            <div class="lab-panel p-5 space-y-4">
                <h2 class="text-sm font-bold text-[#F0F0E8] uppercase tracking-wider mono flex items-center gap-2">
                    <span class="w-1.5 h-3 bg-[#88B69A] rounded-full"></span>
                    Ajouter un autre nom
                </h2>

                <form method="POST" action="{{ route('data.add-alias') }}" class="space-y-4 text-xs">
                    @csrf
                    <div>
                        <label class="block text-[#A2ACA3] mb-1">Équipe correspondante</label>
                        <select name="team_id" required class="w-full bg-[#222B25] border border-[#303A32] rounded px-3 py-2 text-[#F0F0E8] focus:border-[#C3A16A] focus:outline-none">
                            @foreach(\App\Models\Team::orderBy('name')->get() as $t)
                                <option value="{{ $t->id }}">{{ $t->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="block text-[#A2ACA3] mb-1">Autre nom connu</label>
                        <input type="text" name="alias" required placeholder="Ex. PSG, Barça..." class="w-full bg-[#222B25] border border-[#303A32] rounded px-3 py-2 text-[#F0F0E8] focus:border-[#C3A16A] focus:outline-none">
                    </div>

                    <button type="submit" class="w-full bg-[#C3A16A] text-[#101513] font-bold py-2 rounded hover:bg-[#d8b57c] transition-colors">
                        Ajouter l'Alias
                    </button>
                </form>
            </div>
        </div>

        <!-- Importations rejetées -->
        <div class="lab-panel p-5 space-y-4">
            <h2 class="text-sm font-bold text-[#F0F0E8] flex items-center gap-2">
                <span class="w-1.5 h-3 bg-[#D68D86] rounded-full"></span>
                Importations rejetées
            </h2>

            <div class="divide-y divide-[#303A32] text-xs">
                @forelse($quarantines as $q)
                    <div class="py-2.5 flex items-center justify-between">
                        <span class="mono text-[#A2ACA3]">{{ $q->quarantined_at_utc->format('d/m H:i') }}</span>
                        <span class="font-bold text-[#F0F0E8]">{{ $q->source }} · {{ $q->entity_type }}</span>
                        <span class="text-[#D68D86] font-medium">{{ $q->rejection_reason }}</span>
                    </div>
                @empty
                    <div class="text-[#A2ACA3] py-3 text-center">Aucune donnée rejetée.</div>
                @endforelse
            </div>
        </div>
    </div>
</x-app-layout>

