@if(is_array($review ?? null) && ! empty($review['status']))
    <section class="p-4 rounded-lg bg-[#141A17] border border-[#303A32] space-y-3" aria-label="Décision Gemini">
        @if(is_array($review['python'] ?? null))
            <div class="space-y-1">
                <h3 class="text-xs font-bold uppercase tracking-wider text-[#F0F0E8]">Pronostic Python</h3>
                <p class="text-sm text-[#F0F0E8]">{{ $review['python']['label'] ?? $review['python']['selection'] }}</p>
                <p class="text-[11px] text-[#A2ACA3] mono">
                    Marché {{ $review['python']['market'] }}
                    · sélection {{ $review['python']['selection'] }}
                    · probabilité {{ $review['python']['probability_label'] ?? 'Non calculée' }}
                    · origine {{ $review['python']['origin_label'] ?? 'Python' }}
                    · {{ $review['python']['calibration_label'] ?? 'Non suffisamment calibré' }}
                </p>
            </div>
        @endif

        <div class="space-y-1 pt-2 border-t border-[#303A32]/60">
            <h3 class="text-xs font-bold uppercase tracking-wider text-[#F0F0E8]">Décision Gemini</h3>
            <p class="text-sm text-[#C3A16A]">{{ $review['status_label'] ?? 'Validation non effectuée' }}</p>
            @if(! empty($review['explanation']))
                <p class="text-xs text-[#A2ACA3] leading-relaxed">{{ $review['explanation'] }}</p>
            @endif
            <p class="text-[11px] text-[#A2ACA3]">Une validation Gemini n’est pas une garantie de résultat.</p>
        </div>

        @if(is_array($review['alternative'] ?? null))
            <div class="space-y-1 pt-2 border-t border-[#303A32]/60">
                <h3 class="text-xs font-bold uppercase tracking-wider text-[#F0F0E8]">Proposition de Gemini</h3>
                <p class="text-sm text-[#F0F0E8]">{{ $review['alternative']['label'] ?? $review['alternative']['selection'] }}</p>
                <p class="text-[11px] text-[#A2ACA3] mono">
                    Marché {{ $review['alternative']['market'] }}
                    · sélection {{ $review['alternative']['selection'] }}
                    · {{ ($review['alternative']['origin'] ?? '') === 'PYTHON' ? 'probabilité Python' : 'estimation IA' }}
                    {{ ($review['alternative']['origin'] ?? '') === 'PYTHON'
                        ? ($review['alternative']['probability_label'] ?? 'Non calculée par Python')
                        : ($review['alternative']['ai_probability_label'] ?? 'probabilité non calculée') }}
                    · origine {{ $review['alternative']['origin_label'] ?? 'Estimation IA' }}
                </p>
                @if(! empty($review['alternative']['justification']))
                    <p class="text-xs text-[#A2ACA3] leading-relaxed">{{ $review['alternative']['justification'] }}</p>
                @endif
                @if(! empty($review['alternative']['risks']))
                    <p class="text-[11px] text-[#A2ACA3]">Risques : {{ implode(', ', $review['alternative']['risks']) }}</p>
                @endif
            </div>
        @endif
    </section>
@endif
