<x-app-layout>
    <div class="consumer-page space-y-6">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-3 border-b border-[#303A32]">
            <div>
                <div class="flex items-center gap-2">
                    <h1 class="text-xl font-bold tracking-tight text-[#F0F0E8]">{{ $parlay->title }}</h1>
                    <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-[#C3A16A]/20 text-[#C3A16A] border border-[#C3A16A]/40 mono">
                        {{ $parlay->risk_level }}
                    </span>
                </div>
                <p class="text-xs text-[#A2ACA3] mt-1">
                    {{ $parlay->selections_count }} sélection(s) · modèle {{ $parlay->model_version }} ·
                    évalué pour le {{ \Carbon\Carbon::parse($parlay->date_for)->translatedFormat('d F Y') }} (Africa/Douala)
                </p>
            </div>
            <a href="{{ route('parlays.index', ['date' => \Carbon\Carbon::parse($parlay->date_for)->toDateString()]) }}"
               class="px-3 py-2 rounded-md bg-[#222B25] border border-[#303A32] hover:border-[#C3A16A] text-xs font-semibold text-[#F0F0E8] mono">
                ← Retour aux combinés
            </a>
        </div>

        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
            <div class="p-3 rounded bg-[#141A17] border border-[#303A32]">
                <div class="text-[10px] text-[#A2ACA3]">Cote totale</div>
                <div class="text-lg font-extrabold mono text-[#F0F0E8]">{{ number_format($parlay->combined_odds, 2) }}</div>
            </div>
            <div class="p-3 rounded bg-[#141A17] border border-[#303A32]">
                <div class="text-[10px] text-[#A2ACA3]">Chances modèle</div>
                <div class="text-lg font-extrabold mono text-[#88B69A]">{{ round($parlay->combined_prob * 100, 1) }}%</div>
            </div>
            <div class="p-3 rounded bg-[#141A17] border border-[#303A32]">
                <div class="text-[10px] text-[#A2ACA3]">Indice risque</div>
                <div class="text-lg font-extrabold mono text-[#C3A16A]">{{ $parlay->risk_score }}/10</div>
            </div>
            <div class="p-3 rounded bg-[#141A17] border border-[#303A32]">
                <div class="text-[10px] text-[#A2ACA3]">Statut</div>
                <div class="text-lg font-extrabold mono text-[#F0F0E8]">{{ $parlay->status }}</div>
            </div>
        </div>

        <div class="space-y-2">
            <div class="text-xs font-semibold text-[#A2ACA3] uppercase tracking-wider mono">Sélections incluses</div>
            <div class="divide-y divide-[#303A32]/40 border border-[#303A32]/60 rounded-md overflow-hidden bg-[#101513]">
                @foreach(($parlay->selections_json ?? []) as $sel)
                    <div class="p-3 flex flex-col md:flex-row md:items-center justify-between gap-3 text-xs">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2 text-[10px] text-[#A2ACA3] mono">
                                <span>{{ $sel['league'] ?? 'Ligue' }}</span>
                                @if(!empty($sel['match_date_utc']))
                                    <span>·</span>
                                    <span>{{ \Carbon\Carbon::parse($sel['match_date_utc'])->timezone('Africa/Douala')->translatedFormat('d M H:i') }} WAT</span>
                                @endif
                            </div>
                            <div class="font-bold text-[#F0F0E8] text-sm mt-0.5">{{ $sel['match_label'] ?? 'Rencontre' }}</div>
                        </div>
                        <div class="flex flex-wrap items-center gap-4">
                            <div>
                                <div class="text-[10px] text-[#A2ACA3]">Sélection</div>
                                <div class="font-bold text-[#C3A16A]">{{ $sel['selection_label'] ?? '—' }}</div>
                            </div>
                            <div class="mono">
                                <div class="text-[10px] text-[#A2ACA3]">Probabilité modèle</div>
                                <div class="font-semibold text-[#F0F0E8]">{{ $sel['model_prob_pct'] ?? '—' }}%</div>
                            </div>
                            <div class="mono">
                                <div class="text-[10px] text-[#A2ACA3]">{{ ($sel['price_basis'] ?? 'bookmaker') === 'model_fair' ? 'Cote juste modèle' : 'Cote marché' }}</div>
                                <div class="font-bold text-[#F0F0E8]">{{ isset($sel['odds']) ? number_format($sel['odds'], 2) : '—' }}</div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        @if(!empty($parlay->ai_analysis))
            <div class="p-3.5 rounded bg-[#181F1B] border border-[#303A32] space-y-2">
                <div class="text-xs font-semibold text-[#C3A16A]">Analyse du risque — {{ $parlay->ai_model_used }}</div>
                <div class="text-xs text-[#F0F0E8] leading-relaxed whitespace-pre-line">{{ $parlay->ai_analysis }}</div>
            </div>
        @endif
    </div>
</x-app-layout>
