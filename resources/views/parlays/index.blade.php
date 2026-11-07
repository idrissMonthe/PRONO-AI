<x-app-layout>
    <!-- En-tête de la page Combinés -->
    <div class="consumer-page space-y-6">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-3 border-b border-[#303A32]">
        <div>
            <div class="flex items-center gap-2">
                <h1 class="text-xl font-bold tracking-tight text-[#F0F0E8]">Combinés Quotidiens</h1>
                <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-[#C3A16A]/20 text-[#C3A16A] border border-[#C3A16A]/40 mono">
                    PROBABILISTE
                </span>
            </div>
            <p class="text-xs text-[#A2ACA3] mt-1">
                Coupons 1N2 évalués avec les cotes réelles ; scénarios de buts, BTTS, clean sheet et marges issus de la matrice Dixon-Coles.
            </p>
        </div>

        <div class="flex items-center gap-2">
            <form id="regenerate-parlay-form" method="POST" action="{{ route('parlays.generate', ['date' => $date->toDateString()]) }}">
                @csrf
                <button type="submit" id="btn-regenerate" class="flex items-center gap-1.5 px-3 py-2 rounded-md bg-[#222B25] border border-[#303A32] hover:border-[#C3A16A] text-xs font-semibold text-[#F0F0E8] transition-colors mono cursor-pointer">
                    <svg id="regenerate-icon" class="w-3.5 h-3.5 text-[#C3A16A]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                    </svg>
                    <span id="regenerate-text">Régénérer</span>
                </button>
            </form>
        </div>
    </div>

    <!-- Feedback AJAX Dynamique -->
    <div id="ajax-feedback" class="hidden p-3 rounded bg-[#88B69A]/15 border border-[#88B69A]/30 text-xs text-[#88B69A] flex items-center justify-between">
        <span id="ajax-feedback-text"></span>
        <span id="ajax-feedback-time" class="mono text-[10px] text-[#A2ACA3]"></span>
    </div>

    <!-- Bannière de Transparence & Éthique (CDC Section 9 & 28) -->
    <div class="p-3.5 rounded bg-[#181F1B] border border-[#303A32] text-xs text-[#A2ACA3] flex flex-col sm:flex-row items-start sm:items-center justify-between gap-2">
        <div class="flex items-center gap-2">
            <span class="w-2 h-2 rounded-full bg-[#88B69A] shrink-0"></span>
            <span>
                Les scénarios de marchés alternatifs utilisent les probabilités de la matrice de scores ; leurs cotes sont théoriques, sans EV bookmaker. Aucune issue n’est garantie.
            </span>
        </div>
        <div class="mono text-[11px] text-[#C3A16A]">
            Date d'évaluation : {{ $date->translatedFormat('d F Y') }} (Africa/Douala)
        </div>
    </div>

    <!-- Grille des Combinés Générés -->
    <div id="parlays-container" class="space-y-6">
        @forelse($parlays as $parlay)
            @php
                $modelPriced = collect($parlay->selections_json)->every(
                    fn (array $selection) => ($selection['price_basis'] ?? 'bookmaker') === 'model_fair'
                );
                $limitedHistory = collect($parlay->selections_json)->contains(
                    fn (array $selection) => (bool) ($selection['limited_team_history'] ?? false)
                );
            @endphp
            <div class="p-5 rounded-lg bg-[#141A17] border border-[#303A32] hover:border-[#3a473d] transition-all space-y-4">
                <!-- En-tête de la carte Combiné -->
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-3 border-b border-[#303A32]/60">
                    <div>
                        <div class="flex items-center gap-2">
                            <span class="text-sm font-bold text-[#F0F0E8]">{{ $parlay->title }}</span>
                            @if($parlay->risk_level === 'MODERE')
                                <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-[#88B69A]/20 text-[#88B69A] border border-[#88B69A]/40 uppercase mono">
                                    Risque Modéré
                                </span>
                            @elseif($parlay->risk_level === 'EQUILIBRE')
                                <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-[#C3A16A]/20 text-[#C3A16A] border border-[#C3A16A]/40 uppercase mono">
                                    Équilibré
                                </span>
                            @else
                                <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-[#D68D86]/20 text-[#D68D86] border border-[#D68D86]/40 uppercase mono">
                                    Audacieux
                                </span>
                            @endif
                        </div>
                        <div class="text-[11px] text-[#A2ACA3] mt-0.5 mono">
                            Modèle : {{ $parlay->model_version }} · {{ $parlay->selections_count }} sélections · Généré le {{ $parlay->created_at->format('d/m à H:i') }}
                        </div>
                    </div>

                    <!-- Métriques Globales du Combiné -->
                    <div class="flex items-center gap-3">
                        <div class="text-right">
                            <div class="text-[10px] text-[#A2ACA3]">{{ $modelPriced ? 'Cote juste indicative' : 'Cote totale marché' }}</div>
                            <div class="text-lg font-extrabold mono text-[#F0F0E8]">{{ number_format($parlay->combined_odds, 2) }}</div>
                        </div>
                        <div class="text-right border-l border-[#303A32] pl-3">
                            <div class="text-[10px] text-[#A2ACA3]">Chances Modèle</div>
                            <div class="text-lg font-extrabold mono text-[#88B69A]">{{ round($parlay->combined_prob * 100, 1) }}%</div>
                        </div>
                        <div class="text-right border-l border-[#303A32] pl-3">
                            <div class="text-[10px] text-[#A2ACA3]">Indice Risque</div>
                            <div class="text-lg font-extrabold mono text-[#C3A16A]">{{ $parlay->risk_score }}/10</div>
                        </div>
                    </div>
                </div>

                @if($modelPriced)
                    <p class="text-[11px] text-[#C3A16A]">
                        Scénario calculé depuis les probabilités du modèle : cette cote n’est pas une cote proposée par un bookmaker et l’espérance de valeur n’est pas calculée.
                        @if($limitedHistory)
                            Seuils renforcés en raison d’un historique limité pour une ou plusieurs équipes.
                        @endif
                    </p>
                @endif

                <!-- Liste des Sélections Détaillées -->
                <div class="space-y-2">
                    <div class="text-xs font-semibold text-[#A2ACA3] uppercase tracking-wider mono">
                        Sélections incluses :
                    </div>
                    <div class="divide-y divide-[#303A32]/40 border border-[#303A32]/60 rounded-md overflow-hidden bg-[#101513]">
                        @foreach($parlay->selections_json as $sel)
                            <div class="p-3 flex flex-col md:flex-row md:items-center justify-between gap-3 text-xs hover:bg-[#141A17] transition-colors">
                                <div class="min-w-0">
                                    <div class="flex items-center gap-2 text-[10px] text-[#A2ACA3] mono">
                                        <span>{{ $sel['league'] ?? 'Ligue' }}</span>
                                        <span>·</span>
                                        <span>{{ \Carbon\Carbon::parse($sel['match_date_utc'])->timezone('Africa/Douala')->translatedFormat('d M H:i') }} WAT</span>
                                    </div>
                                    <div class="font-bold text-[#F0F0E8] text-sm mt-0.5">
                                        {{ $sel['match_label'] }}
                                    </div>
                                </div>

                                <div class="flex flex-wrap items-center gap-4 text-right">
                                    <div>
                                        <div class="text-[10px] text-[#A2ACA3]">Sélection</div>
                                        <div class="font-bold text-[#C3A16A]">{{ $sel['selection_label'] }}</div>
                                    </div>
                                    <div class="mono">
                                        <div class="text-[10px] text-[#A2ACA3]">Probabilité Modèle</div>
                                        <div class="font-semibold text-[#F0F0E8]">{{ $sel['model_prob_pct'] }}%</div>
                                    </div>
                                    <div class="mono">
                                        <div class="text-[10px] text-[#A2ACA3]">{{ ($sel['price_basis'] ?? 'bookmaker') === 'model_fair' ? 'Cote juste modèle' : 'Cote Marché' }}</div>
                                        <div class="font-bold text-[#F0F0E8]">{{ number_format($sel['odds'], 2) }}</div>
                                    </div>
                                    <div class="mono text-[10px] text-[#A2ACA3] hidden sm:block">
                                        <div>Source : <span class="text-[#F0F0E8]">{{ $sel['odds_source'] ?? 'Pinnacle' }}</span></div>
                                        @if(($sel['ev_pct'] ?? null) !== null)
                                            <div>EV : <span class="{{ $sel['ev_pct'] >= 0 ? 'text-[#88B69A]' : 'text-[#A2ACA3]' }}">{{ $sel['ev_pct'] > 0 ? '+' : '' }}{{ $sel['ev_pct'] }}%</span></div>
                                        @else
                                            <div>EV : non calculée</div>
                                        @endif
                                    </div>
                                    <div>
                                        <button type="button" onclick="openMatchModal({{ $sel['match_id'] }})" class="p-1.5 rounded hover:bg-[#222B25] text-[#A2ACA3] hover:text-[#C3A16A] transition-colors cursor-pointer" title="Consulter l'analyse complète">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path></svg>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <!-- Analyse & Avertissement de Risque de l'IA Gemini -->
                @if(!empty($parlay->ai_analysis))
                    <div class="p-3.5 rounded bg-[#181F1B] border border-[#303A32] space-y-2">
                        <div class="flex items-center justify-between text-xs pb-1.5 border-b border-[#303A32]/40">
                            <span class="font-semibold text-[#C3A16A] flex items-center gap-1.5">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                                Analyse du Risque par l'IA Explicative
                            </span>
                            <span class="text-[10px] text-[#A2ACA3] mono">
                                {{ $parlay->ai_model_used }}
                            </span>
                        </div>
                        <div class="text-xs text-[#F0F0E8] leading-relaxed whitespace-pre-line">
                            {{ $parlay->ai_analysis }}
                        </div>
                    </div>
                @endif
            </div>
        @empty
            <div class="p-12 text-center rounded-lg bg-[#141A17] border border-[#303A32] space-y-3">
                <div class="text-sm font-semibold text-[#F0F0E8]">Aucun combiné disponible pour cette date</div>
                <p class="text-xs text-[#A2ACA3] max-w-md mx-auto">
                    Les combinés marché nécessitent des cotes réelles. Les scénarios de marchés alternatifs sont proposés uniquement si le modèle fournit des probabilités suffisamment fiables.
                </p>
                <button type="button" onclick="document.getElementById('regenerate-parlay-form').requestSubmit()" class="mt-2 px-4 py-2 rounded bg-[#C3A16A] text-[#101513] font-bold text-xs hover:bg-[#b08e58] transition-colors mono cursor-pointer">
                    Lancer l’évaluation des combinés
                </button>
            </div>
        @endforelse
    </div>

    <!-- Section Historique Récent -->
    @if($history->isNotEmpty())
        <div class="pt-6 border-t border-[#303A32] space-y-3">
            <h2 class="text-sm font-bold text-[#F0F0E8]">Historique des Combinés Récents</h2>
            <div class="divide-y divide-[#303A32] border border-[#303A32] rounded-md overflow-hidden bg-[#141A17]">
                @foreach($history as $item)
                    <div class="p-3 flex items-center justify-between text-xs">
                        <div>
                            <span class="font-bold text-[#F0F0E8]">{{ $item->title }}</span>
                            <span class="text-[10px] text-[#A2ACA3] ml-2 mono">{{ $item->date_for->translatedFormat('d M Y') }}</span>
                        </div>
                        <div class="flex items-center gap-4 mono">
                            <span>Cote : <strong class="text-[#F0F0E8]">{{ number_format($item->combined_odds, 2) }}</strong></span>
                            <span>Proba : <strong class="text-[#88B69A]">{{ round($item->combined_prob * 100, 1) }}%</strong></span>
                            <span class="px-2 py-0.5 rounded text-[10px] {{ $item->status === 'WON' ? 'bg-[#88B69A]/20 text-[#88B69A]' : ($item->status === 'LOST' ? 'bg-[#D68D86]/20 text-[#D68D86]' : 'bg-[#222B25] text-[#A2ACA3]') }}">
                                {{ $item->status }}
                            </span>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <!-- Script AJAX pour le bouton Régénérer -->
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const form = document.getElementById('regenerate-parlay-form');
            if (!form) return;

            const btn = document.getElementById('btn-regenerate');
            const icon = document.getElementById('regenerate-icon');
            const btnText = document.getElementById('regenerate-text');
            const feedback = document.getElementById('ajax-feedback');
            const feedbackText = document.getElementById('ajax-feedback-text');
            const feedbackTime = document.getElementById('ajax-feedback-time');

            form.addEventListener('submit', function (e) {
                e.preventDefault();

                // État de chargement
                btn.disabled = true;
                icon.classList.add('animate-spin');
                btnText.innerText = 'Calcul en cours...';

                fetch(form.action, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('input[name="_token"]').value,
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    }
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        feedback.classList.remove('hidden');
                        feedbackText.innerText = '✅ ' + data.message;
                        feedbackTime.innerText = 'Actualisé à ' + data.generated_at;

                        // Rechargement fluide de la page pour actualiser les cartes avec les données fraîches
                        setTimeout(() => {
                            window.location.reload();
                        }, 600);
                    }
                })
                .catch(err => {
                    console.error('Erreur régénération combinés:', err);
                    // Repli sur soumission classique
                    form.submit();
                })
                .finally(() => {
                    btn.disabled = false;
                    icon.classList.remove('animate-spin');
                    btnText.innerText = 'Régénérer';
                });
            });
        });
    </script>
</x-app-layout>
