<x-app-layout>
    <div class="space-y-6">
        <!-- En-tête -->
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 pb-2 border-b border-[#303A32]">
            <div>
                <h1 class="text-xl font-bold tracking-tight text-[#F0F0E8]">Réglages</h1>
                <span class="sr-only">Centre de Diagnostic</span>
                <p class="text-xs text-[#A2ACA3] mt-1">État des services et préférences de l’analyse.</p>
            </div>
            <form method="POST" action="{{ route('settings.verify-ledger') }}">
                @csrf
                <button type="submit" class="bg-[#222B25] border border-[#303A32] hover:border-[#88B69A] text-xs font-semibold text-[#88B69A] py-2 px-4 rounded-md transition-colors mono">
                    Vérifier l’intégrité des analyses
                </button>
            </form>
        </div>

        <!-- 1. Centre de Diagnostic & Intégrité (CDC Section 10.1 & 15.10) -->
        <div class="lab-panel p-5 space-y-4">
                <h2 class="text-sm font-semibold text-[#F0F0E8]">
                État des services
            </h2>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-xs">
                <!-- Statut de la Chaîne -->
                <div class="p-4 rounded bg-[#222B25] border border-[#303A32] space-y-2">
                    <div class="text-[#A2ACA3]">Intégrité des analyses</div>
                    <div class="text-lg font-bold text-[#88B69A] flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-[#88B69A]"></span>
                        {{ $integrity['status'] === 'valid' ? 'Intégrité vérifiée' : 'Intégrité à vérifier' }}
                    </div>
                    <div class="text-[10px] text-[#A2ACA3]">{{ $integrity['total_blocks'] }} analyse(s) contrôlée(s)</div>
                </div>

                <!-- FastAPI Service IA -->
                <div class="p-4 rounded bg-[#222B25] border border-[#303A32] space-y-2">
                    <div class="text-[#A2ACA3]">Calcul des analyses</div>
                    <div class="text-sm font-semibold flex items-center gap-2 {{ $aiServiceOnline ? 'text-[#88B69A]' : 'text-[#C3A16A]' }}">
                        <span class="w-2.5 h-2.5 rounded-full {{ $aiServiceOnline ? 'bg-[#88B69A]' : 'bg-[#C3A16A]' }}"></span>
                        {{ !$aiServiceOnline ? 'Indisponible' : ($aiModelLoaded ? 'Prêt' : 'En attente du modèle') }}
                    </div>
                    <div class="text-[10px] text-[#A2ACA3]">{{ $aiModelLoaded ? 'Analyses calculées normalement' : 'Les résultats sont indiqués comme non validés' }}</div>
                </div>

                <!-- Assistant Gemini -->
                <div class="p-4 rounded bg-[#222B25] border border-[#303A32] space-y-2 flex flex-col justify-between">
                    <div>
                        <div class="text-[#A2ACA3] mono">Assistant Explicatif IA</div>
                        <div class="text-lg font-bold flex items-center gap-2 {{ $geminiConfigured ? 'text-[#88B69A]' : 'text-[#A2ACA3]' }}">
                            <span class="w-2.5 h-2.5 rounded-full {{ $geminiConfigured ? 'bg-[#88B69A]' : 'bg-[#A2ACA3]' }}"></span>
                            {{ $geminiConfigured ? 'Configuré · test à lancer' : 'Explication standard' }}
                        </div>
                        <div class="text-[10px] text-[#A2ACA3]">Réponse expliquée sur la fiche match · chiffres vérifiés contre son contexte</div>
                    </div>
                    <div class="pt-2">
                        <button type="button" id="btn-test-gemini" onclick="testGeminiConnection()" class="w-full py-1.5 px-3 rounded bg-[#181F1B] border border-[#303A32] hover:border-[#C3A16A] text-[11px] font-semibold text-[#C3A16A] transition-colors mono">
                          Tester l'appel Gemini
                        </button>
                        <div id="gemini-test-result" class="text-[10px] mt-1.5 hidden"></div>
                    </div>
                </div>
            </div>
        </div>

        <details class="border-t border-[#303A32] pt-3">
            <summary class="cursor-pointer text-sm font-semibold text-[#A2ACA3] hover:text-[#F0F0E8]">Détails des fournisseurs</summary>
            <div class="pt-4">
            <h2 class="text-sm font-bold text-[#F0F0E8] uppercase tracking-wider mono flex items-center gap-2">
                <span class="w-1.5 h-3 bg-[#C3A16A] rounded-full"></span>
                État des Fournisseurs de Données & Cotes
            </h2>

            <div class="divide-y divide-[#303A32] text-xs">
                @foreach($providers as $code => $prov)
                    <div class="py-3 flex items-center justify-between">
                        <div>
                            <span class="font-bold text-[#F0F0E8]">{{ $prov['name'] }}</span>
                            <span class="text-[11px] text-[#A2ACA3] ml-2">({{ $code }})</span>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="px-2 py-0.5 rounded text-[10px] mono font-bold {{ $prov['configured'] ? 'bg-[#88B69A]/20 text-[#88B69A]' : 'bg-[#222B25] text-[#A2ACA3]' }}">
                                {{ $prov['status'] }}
                            </span>
                        </div>
                    </div>
                @endforeach
            </div>
            </div>
        </details>

        <details class="border-t border-[#303A32] pt-3">
            <summary class="cursor-pointer text-sm font-semibold text-[#A2ACA3] hover:text-[#F0F0E8]">Options avancées</summary>
            <div class="pt-4">
            <h2 class="text-sm font-bold text-[#F0F0E8] uppercase tracking-wider mono flex items-center gap-2">
                <span class="w-1.5 h-3 bg-[#88B69A] rounded-full"></span>
                Seuils Statistiques & Préférences Métier
            </h2>

            <form method="POST" action="{{ route('settings.update') }}" class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
                @csrf
                <div>
                    <label class="block text-[#A2ACA3] mb-1">Méthode de comparaison des cotes</label>
                    <select name="devig_method" class="w-full bg-[#222B25] border border-[#303A32] rounded px-3 py-2 text-[#F0F0E8] mono focus:border-[#C3A16A] focus:outline-none">
                        <option value="shin" {{ ($settings['devig_method']['value'] ?? 'shin') === 'shin' ? 'selected' : '' }}>Shin</option>
                        <option value="power" {{ ($settings['devig_method']['value'] ?? '') === 'power' ? 'selected' : '' }}>Puissance</option>
                        <option value="proportional" {{ ($settings['devig_method']['value'] ?? '') === 'proportional' ? 'selected' : '' }}>Proportionnelle</option>
                    </select>
                </div>

                <div>
                    <label class="block text-[#A2ACA3] mb-1">Période de prise en compte des anciens matchs (jours)</label>
                    <input type="number" name="time_decay_half_life_days" value="{{ $settings['time_decay_half_life_days']['value'] ?? 365 }}" class="w-full bg-[#222B25] border border-[#303A32] rounded px-3 py-2 text-[#F0F0E8] mono focus:border-[#C3A16A] focus:outline-none">
                </div>

                <div>
                    <label class="block text-[#A2ACA3] mb-1">Seuil Qualité Minimale (Score 0 - 100)</label>
                    <input type="number" name="data_quality_min_score" value="{{ $settings['data_quality_min_score']['value'] ?? 60 }}" class="w-full bg-[#222B25] border border-[#303A32] rounded px-3 py-2 text-[#F0F0E8] mono focus:border-[#C3A16A] focus:outline-none">
                </div>

                <div>
                    <label class="block text-[#A2ACA3] mb-1">Écart minimal signalé (en %)</label>
                    <input type="number" step="0.1" name="min_edge_alert_pct" value="{{ $settings['min_edge_alert_pct']['value'] ?? 3.0 }}" class="w-full bg-[#222B25] border border-[#303A32] rounded px-3 py-2 text-[#F0F0E8] mono focus:border-[#C3A16A] focus:outline-none">
                </div>

                <div class="md:col-span-2 pt-2">
                    <label class="flex items-center gap-2 text-xs text-[#F0F0E8] cursor-pointer">
                        <input type="checkbox" name="analysis_only_mode" value="1" {{ !empty($settings['analysis_only_mode']['value']) ? 'checked' : '' }} class="rounded border-[#303A32] text-[#C3A16A]">
                        <span>Masquer les cotes et simulations de pronostics</span>
                    </label>
                </div>

                <div class="md:col-span-2 pt-2">
                    <button type="submit" class="bg-[#C3A16A] hover:bg-[#d8b57c] text-[#101513] font-bold py-2 px-6 rounded text-xs transition-colors">
                        Enregistrer les Paramètres
                    </button>
                </div>
            </form>
            </div>
        </details>

        <details class="border-t border-[#303A32] pt-3">
            <summary class="cursor-pointer text-sm font-semibold text-[#A2ACA3] hover:text-[#F0F0E8]">Historique des événements techniques</summary>
            <div class="pt-4">
            <h2 class="text-sm font-bold text-[#F0F0E8] uppercase tracking-wider mono flex items-center gap-2">
                <span class="w-1.5 h-3 bg-[#A2ACA3] rounded-full"></span>
                Journal d'Audit des Événements Techniques (audit_events)
            </h2>

            <div class="divide-y divide-[#303A32] text-xs mono">
                @forelse($auditLogs as $log)
                    <div class="py-2.5 flex flex-col sm:flex-row sm:items-center justify-between gap-1">
                        <div class="flex items-center gap-2">
                            <span class="text-[#A2ACA3]">{{ $log->occurred_at_utc->format('d/m/Y H:i:s') }} UTC</span>
                            <span class="font-bold text-[#C3A16A]">{{ $log->event_type }}</span>
                        </div>
                        <span class="text-[#A2ACA3] text-[11px] truncate max-w-full sm:max-w-md">{{ json_encode($log->details) }}</span>
                    </div>
                @empty
                    <div class="text-[#A2ACA3] py-2 text-center">Aucun événement d'audit récent.</div>
                @endforelse
            </div>
            </div>
        </details>
    </div>

    <script>
        async function testGeminiConnection() {
            const btn = document.getElementById('btn-test-gemini');
            const resDiv = document.getElementById('gemini-test-result');

            btn.disabled = true;
            btn.innerText = 'Appel en cours...';
            resDiv.className = 'text-[10px] mt-1.5 mono text-[#A2ACA3]';
            resDiv.innerText = 'Interrogation de Google Gemini en direct...';
            resDiv.classList.remove('hidden');

            try {
                const response = await fetch('/gemini/test', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        'Accept': 'application/json',
                    }
                });

                const data = await response.json();

                if (data.success) {
                    resDiv.className = 'text-[10px] mt-1.5 mono text-[#88B69A]';
                    resDiv.innerText = `✓ Connecté (${data.latency_ms}ms) · ${data.model}`;
                } else {
                    resDiv.className = 'text-[10px] mt-1.5 mono text-[#D68D86]';
                    resDiv.innerText = `✕ Erreur : ${data.message || 'Échec de réponse'}`;
                }
            } catch (err) {
                resDiv.className = 'text-[10px] mt-1.5 mono text-[#D68D86]';
                resDiv.innerText = '✕ Erreur réseau lors du test.';
            } finally {
                btn.disabled = false;
                btn.innerText = '⚡ Tester l\'appel Gemini';
            }
        }
    </script>
</x-app-layout>

