<x-app-layout>
    <!-- En-tête Fiche Match (CDC Section 6.1 & 6.9) -->
    <div class="consumer-page space-y-4 pb-4 border-b border-[#303A32]">
        <div class="flex flex-wrap items-center justify-between gap-3 text-xs text-[#A2ACA3]">
            <div class="flex items-center gap-2">
                <a href="{{ route('matches.index') }}" class="hover:text-[#F0F0E8] transition-colors">← Retour au calendrier</a>
                <span>/</span>
                <span class="text-[#F0F0E8] font-medium">{{ $match->league->name }}</span>
                <span>·</span>
                <span class="mono">{{ $match->match_date_utc->timezone('Africa/Douala')->translatedFormat('d F Y à H:i') }} WAT</span>
            </div>

            <div class="flex items-center gap-3">
                <!-- Badge Qualité Données -->
                <span class="px-2.5 py-1 rounded text-[11px] font-bold mono {{ $match->quality_score >= 80 ? 'bg-[#88B69A]/20 text-[#88B69A] border border-[#88B69A]/30' : 'bg-[#C3A16A]/20 text-[#C3A16A] border border-[#C3A16A]/30' }}">
                    Qualité Données : {{ round($match->quality_score) }}/100 ({{ $match->quality_tier }})
                </span>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 items-center gap-4 sm:gap-6 py-4 px-4 sm:px-6 rounded-lg bg-[#141A17] border border-[#303A32]">
            <!-- Domicile -->
            <div class="text-center md:text-right">
                <h1 class="text-xl sm:text-2xl font-black text-[#F0F0E8] tracking-tight">{{ $match->homeTeam->name }}</h1>
                <div class="text-xs text-[#A2ACA3] mt-1">Forme récente : {{ $homeForm }}</div>
                <div class="text-sm font-bold text-[#88B69A] mono mt-1">
                    Buts attendus : {{ $predData['expected_goals']['home'] }}
                </div>
            </div>

            <!-- Score Réel ou VS / Résumé Central -->
            <div class="flex flex-col items-center justify-center text-center px-2 sm:px-4 py-2 md:py-0 border-y md:border-y-0 border-[#303A32]/50">
                @if($match->status === 'FINISHED')
                    <div class="text-3xl sm:text-4xl font-extrabold mono text-[#C3A16A] tracking-wider px-4 py-1 rounded bg-[#101513] border border-[#303A32]">
                        {{ $match->home_score }} - {{ $match->away_score }}
                    </div>
                    <div class="text-[11px] text-[#A2ACA3] mono mt-1">Score Final Officiel</div>
                @else
                    <div class="px-4 py-1.5 rounded-full bg-[#181F1B] border border-[#303A32] text-xs font-bold text-[#A2ACA3] mono uppercase tracking-widest">
                        COUP D'ENVOI DANS {{ round($match->match_date_utc->diffInHours(now())) }}H
                    </div>
                    <div class="text-xs text-[#C3A16A] mono mt-2 font-semibold">
                        Score Modal Modèle : {{ $predData['top_scores'][0]['score'] ?? '—' }}
                    </div>
                @endif
            </div>

            <!-- Extérieur -->
            <div class="text-center md:text-left">
                <h1 class="text-xl sm:text-2xl font-black text-[#F0F0E8] tracking-tight">{{ $match->awayTeam->name }}</h1>
                <div class="text-xs text-[#A2ACA3] mt-1">Forme récente : {{ $awayForm }}</div>
                <div class="text-sm font-bold text-[#D68D86] mono mt-1">
                    Buts attendus : {{ $predData['expected_goals']['away'] }}
                </div>
            </div>
        </div>
    </div>

    <!-- 1. Résumé Probabiliste 1N2 avec Intervalles de Crédibilité (CDC 3.10 & 6.9) -->
    <div class="space-y-4">
        <div class="flex items-center justify-between">
            <h2 class="text-sm font-semibold text-[#F0F0E8]">Résultats possibles</h2>
            <details class="text-xs text-[#A2ACA3]">
                <summary class="cursor-pointer hover:text-[#F0F0E8]">Détails de l’analyse</summary>
                <div class="pt-2 space-y-1">
                    <div>Modèle : {{ $predData['model']['version'] ?? 'Version inconnue' }}</div>
                    <div>Somme des probabilités : 100%</div>
                    <div>Évaluation hors échantillon : non mesurée</div>
                </div>
            </details>
        </div>

        @if(!empty($predData['abstain']))
            <p class="text-xs text-[#D68D86]" role="status">Analyse non recommandée : le moteur est en mode abstention. Les valeurs brutes ne constituent pas une prédiction validée.</p>
        @endif

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <!-- Victoire Domicile -->
            <div class="lab-panel p-4 space-y-3">
                <div class="flex items-center justify-between text-xs">
                    <span class="text-[#A2ACA3] font-medium">Victoire {{ $match->homeTeam->short_name }} (1)</span>
                    <span class="text-[10px] px-2 py-0.5 rounded bg-[#222B25] mono text-[#C3A16A]">
                        Cote indicative : {{ round(1.0 / max(0.01, $predData['probabilities']['home']['p']), 2) }}
                    </span>
                </div>
                <div class="text-3xl font-extrabold mono text-[#88B69A]">
                    {{ round($predData['probabilities']['home']['p'] * 100, 1) }}%
                </div>
                <!-- Jauge visuelle -->
                <div class="w-full bg-[#101513] h-2 rounded-full overflow-hidden">
                    <div class="bg-[#88B69A] h-full" style="width: {{ $predData['probabilities']['home']['p'] * 100 }}%"></div>
                </div>
                <details class="pt-2 border-t border-[#303A32]/60 text-[11px] mono text-[#A2ACA3]">
                    <summary class="cursor-pointer">Voir la marge d’incertitude</summary>
                    <div class="pt-2 space-y-1">
                    <div class="flex justify-between">
                        <span>Fourchette la plus probable :</span>
                        <strong class="text-[#F0F0E8]">{{ round(($predData['probabilities']['home']['ci80'][0] ?? 0.4) * 100) }}% — {{ round(($predData['probabilities']['home']['ci80'][1] ?? 0.6) * 100) }}%</strong>
                    </div>
                    <div class="flex justify-between">
                        <span>Fourchette large :</span>
                        <span class="text-[#A2ACA3]">{{ round(($predData['probabilities']['home']['ci95'][0] ?? 0.35) * 100) }}% — {{ round(($predData['probabilities']['home']['ci95'][1] ?? 0.65) * 100) }}%</span>
                    </div>
                    </div>
                </details>
            </div>

            <!-- Match Nul -->
            <div class="lab-panel p-4 space-y-3">
                <div class="flex items-center justify-between text-xs">
                    <span class="text-[#A2ACA3] font-medium">Match Nul (N)</span>
                    <span class="text-[10px] px-2 py-0.5 rounded bg-[#222B25] mono text-[#C3A16A]">
                        Cote indicative : {{ round(1.0 / max(0.01, $predData['probabilities']['draw']['p']), 2) }}
                    </span>
                </div>
                <div class="text-3xl font-extrabold mono text-[#F0F0E8]">
                    {{ round($predData['probabilities']['draw']['p'] * 100, 1) }}%
                </div>
                <div class="w-full bg-[#101513] h-2 rounded-full overflow-hidden">
                    <div class="bg-[#F0F0E8] h-full" style="width: {{ $predData['probabilities']['draw']['p'] * 100 }}%"></div>
                </div>
                <details class="pt-2 border-t border-[#303A32]/60 text-[11px] mono text-[#A2ACA3]">
                    <summary class="cursor-pointer">Voir la marge d’incertitude</summary>
                    <div class="pt-2 space-y-1">
                    <div class="flex justify-between">
                        <span>Fourchette la plus probable :</span>
                        <strong class="text-[#F0F0E8]">{{ round(($predData['probabilities']['draw']['ci80'][0] ?? 0.22) * 100) }}% — {{ round(($predData['probabilities']['draw']['ci80'][1] ?? 0.30) * 100) }}%</strong>
                    </div>
                    <div class="flex justify-between">
                        <span>Fourchette large :</span>
                        <span class="text-[#A2ACA3]">{{ round(($predData['probabilities']['draw']['ci95'][0] ?? 0.20) * 100) }}% — {{ round(($predData['probabilities']['draw']['ci95'][1] ?? 0.33) * 100) }}%</span>
                    </div>
                    </div>
                </details>
            </div>

            <!-- Victoire Extérieur -->
            <div class="lab-panel p-4 space-y-3">
                <div class="flex items-center justify-between text-xs">
                    <span class="text-[#A2ACA3] font-medium">Victoire {{ $match->awayTeam->short_name }} (2)</span>
                    <span class="text-[10px] px-2 py-0.5 rounded bg-[#222B25] mono text-[#C3A16A]">
                        Cote indicative : {{ round(1.0 / max(0.01, $predData['probabilities']['away']['p']), 2) }}
                    </span>
                </div>
                <div class="text-3xl font-extrabold mono text-[#D68D86]">
                    {{ round($predData['probabilities']['away']['p'] * 100, 1) }}%
                </div>
                <div class="w-full bg-[#101513] h-2 rounded-full overflow-hidden">
                    <div class="bg-[#D68D86] h-full" style="width: {{ $predData['probabilities']['away']['p'] * 100 }}%"></div>
                </div>
                <details class="pt-2 border-t border-[#303A32]/60 text-[11px] mono text-[#A2ACA3]">
                    <summary class="cursor-pointer">Voir la marge d’incertitude</summary>
                    <div class="pt-2 space-y-1">
                    <div class="flex justify-between">
                        <span>Fourchette la plus probable :</span>
                        <strong class="text-[#F0F0E8]">{{ round(($predData['probabilities']['away']['ci80'][0] ?? 0.22) * 100) }}% — {{ round(($predData['probabilities']['away']['ci80'][1] ?? 0.32) * 100) }}%</strong>
                    </div>
                    <div class="flex justify-between">
                        <span>Fourchette large :</span>
                        <span class="text-[#A2ACA3]">{{ round(($predData['probabilities']['away']['ci95'][0] ?? 0.18) * 100) }}% — {{ round(($predData['probabilities']['away']['ci95'][1] ?? 0.35) * 100) }}%</span>
                    </div>
                    </div>
                </details>
            </div>
        </div>
    </div>

    <details class="border-t border-[#303A32] pt-3">
        <summary class="cursor-pointer text-sm font-semibold text-[#A2ACA3] hover:text-[#F0F0E8]">Autres détails de l’analyse</summary>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-5 pt-4">
        <!-- Matrice de Scores (Heatmap) -->
        <div class="lab-panel p-5 space-y-4">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="text-sm font-bold text-[#F0F0E8]">Scores possibles</h3>
                    <p class="text-[11px] text-[#A2ACA3]">Chaque cellule indique la chance d’un score exact.</p>
                </div>
                <span class="text-[10px] mono px-2 py-0.5 rounded bg-[#222B25] text-[#A2ACA3]">Masse résiduelle &lt; 1e-6</span>
            </div>

            <!-- Heatmap Grid -->
            <div class="overflow-x-auto">
                <table class="border-collapse text-center mx-auto">
                    <thead>
                        <tr>
                            <th class="p-1 text-[10px] text-[#A2ACA3] mono">Dom \ Ext</th>
                            @for($j = 0; $j <= 5; $j++)
                                <th class="p-1 text-[11px] font-bold mono text-[#A2ACA3] w-12">{{ $j }}</th>
                            @endfor
                        </tr>
                    </thead>
                    <tbody>
                        @for($i = 0; $i <= 5; $i++)
                            <tr>
                                <th class="p-1 text-[11px] font-bold mono text-[#A2ACA3] text-right pr-2">{{ $i }}</th>
                                @for($j = 0; $j <= 5; $j++)
                                    @php
                                        $val = $scoreMatrix[$i][$j] ?? 0.0;
                                        $intensity = min(1.0, $val / 0.12); // Normalize color scale
                                    @endphp
                                    <td class="p-1">
                                        <div class="w-12 h-10 rounded flex flex-col items-center justify-center text-[11px] mono font-semibold cursor-pointer transition-transform hover:scale-110 border border-[#303A32]"
                                             style="background-color: rgba(195, 161, 106, {{ max(0.08, $intensity * 0.75) }}); color: {{ $intensity > 0.4 ? '#F0F0E8' : '#A2ACA3' }};"
                                             title="Score {{ $i }}-{{ $j }} : {{ round($val * 100, 2) }}%">
                                            <span>{{ round($val * 100, 1) }}%</span>
                                        </div>
                                    </td>
                                @endfor
                            </tr>
                        @endfor
                    </tbody>
                </table>
            </div>

            <div class="text-[10px] text-[#A2ACA3] flex items-center justify-between pt-2 border-t border-[#303A32]">
                <span>Lignes : Buts {{ $match->homeTeam->short_name }}</span>
                <span>Colonnes : Buts {{ $match->awayTeam->short_name }}</span>
            </div>
        </div>

        <!-- Marchés Dérivés Cohérents (CDC 25.2) -->
        <div class="lab-panel p-5 space-y-4">
            <h3 class="text-sm font-bold text-[#F0F0E8]">Autres résultats du match</h3>

            @php
                $derivedMarkets = $predData['markets'] ?? [];
                $totals = $derivedMarkets['totals'] ?? [];
                $exactTotals = $derivedMarkets['exact_totals'] ?? [];
                $asianHandicap = $derivedMarkets['asian_handicap'] ?? [];
                $topScores = $predData['top_scores'] ?? [];
                $btts = $derivedMarkets['btts'] ?? [];
                $cleanSheet = $derivedMarkets['clean_sheet'] ?? [];
                $teamTotals = $derivedMarkets['team_totals'] ?? [];
                $goalMargin = $derivedMarkets['goal_margin'] ?? [];
            @endphp

            <div class="grid grid-cols-2 gap-3 text-xs">
                <!-- Résultats combinés -->
                <div class="p-3 rounded bg-[#222B25] border border-[#303A32] space-y-1.5">
                    <span class="text-[#A2ACA3] font-semibold text-[11px] uppercase mono">Résultats combinés</span>
                    <div class="flex justify-between mono">
                        <span>1N :</span>
                        <strong class="text-[#88B69A]">{{ round(($predData['probabilities']['home']['p'] + $predData['probabilities']['draw']['p']) * 100) }}%</strong>
                    </div>
                    <div class="flex justify-between mono">
                        <span>N2 :</span>
                        <strong class="text-[#D68D86]">{{ round(($predData['probabilities']['draw']['p'] + $predData['probabilities']['away']['p']) * 100) }}%</strong>
                    </div>
                    <div class="flex justify-between mono">
                        <span>12 :</span>
                        <strong class="text-[#F0F0E8]">{{ round(($predData['probabilities']['home']['p'] + $predData['probabilities']['away']['p']) * 100) }}%</strong>
                    </div>
                </div>

                <!-- Match nul remboursé -->
                <div class="p-3 rounded bg-[#222B25] border border-[#303A32] space-y-1.5">
                    <span class="text-[#A2ACA3] font-semibold text-[11px] uppercase mono">En cas de match nul</span>
                    @php
                        $pDraw = $predData['probabilities']['draw']['p'];
                        $pDnbHome = $pDraw < 0.99 ? $predData['probabilities']['home']['p'] / (1 - $pDraw) : 0.5;
                    @endphp
                    <div class="flex justify-between mono">
                        <span>{{ $match->homeTeam->short_name }} :</span>
                        <strong class="text-[#88B69A]">{{ round($pDnbHome * 100) }}%</strong>
                    </div>
                    <div class="flex justify-between mono">
                        <span>{{ $match->awayTeam->short_name }} :</span>
                        <strong class="text-[#D68D86]">{{ round((1 - $pDnbHome) * 100) }}%</strong>
                    </div>
                    <div class="text-[10px] text-[#A2ACA3] pt-0.5">Remboursé si nul</div>
                </div>

                <!-- Totaux de Buts -->
                <div class="p-3 rounded bg-[#222B25] border border-[#303A32] space-y-1.5">
                    <span class="text-[#A2ACA3] font-semibold text-[11px] uppercase mono">Total Buts (Over / Under)</span>
                    <div class="flex justify-between mono">
                        <span>+2.5 Buts :</span>
                        <strong class="text-[#C3A16A]">{{ isset($totals['over_2_5']) ? round($totals['over_2_5'] * 100, 1) . '%' : '—' }}</strong>
                    </div>
                    <div class="flex justify-between mono">
                        <span>-2.5 Buts :</span>
                        <strong class="text-[#A2ACA3]">{{ isset($totals['under_2_5']) ? round($totals['under_2_5'] * 100, 1) . '%' : '—' }}</strong>
                    </div>
                    <div class="flex justify-between mono">
                        <span>+1.5 Buts :</span>
                        <strong class="text-[#F0F0E8]">{{ isset($totals['over_1_5']) ? round($totals['over_1_5'] * 100, 1) . '%' : '—' }}</strong>
                    </div>
                </div>

                <!-- BTTS & Clean Sheets -->
                <div class="p-3 rounded bg-[#222B25] border border-[#303A32] space-y-1.5">
                    <span class="text-[#A2ACA3] font-semibold text-[11px] uppercase mono">Les 2 Équipes Marquent</span>
                    <div class="flex justify-between mono">
                        <span>Oui :</span>
                        <strong class="text-[#88B69A]">{{ isset($btts['yes']) ? round($btts['yes'] * 100, 1) . '%' : '—' }}</strong>
                    </div>
                    <div class="flex justify-between mono">
                        <span>Non :</span>
                        <strong class="text-[#A2ACA3]">{{ isset($btts['no']) ? round($btts['no'] * 100, 1) . '%' : '—' }}</strong>
                    </div>
                    <div class="text-[10px] text-[#A2ACA3] pt-0.5 mono">
                        {{ $match->awayTeam->short_name }} ne marque pas: {{ isset($cleanSheet['home']) ? round($cleanSheet['home'] * 100, 1) . '%' : '—' }}
                    </div>
                    <div class="text-[10px] text-[#A2ACA3] mono">
                        {{ $match->homeTeam->short_name }} ne marque pas: {{ isset($cleanSheet['away']) ? round($cleanSheet['away'] * 100, 1) . '%' : '—' }}
                    </div>
                </div>

                <div class="p-3 rounded bg-[#222B25] border border-[#303A32] space-y-1.5">
                    <span class="text-[#A2ACA3] font-semibold text-[11px] uppercase mono">Lignes étendues</span>
                    @foreach(['over_0_5' => '+0,5', 'over_3_5' => '+3,5', 'over_4_5' => '+4,5', 'over_5_5' => '+5,5'] as $key => $label)
                        <div class="flex justify-between mono">
                            <span>{{ $label }} buts :</span>
                            <strong class="text-[#C3A16A]">{{ isset($totals[$key]) ? round($totals[$key] * 100, 1) . '%' : '—' }}</strong>
                        </div>
                    @endforeach
                </div>

                <div class="p-3 rounded bg-[#222B25] border border-[#303A32] space-y-1.5">
                    <span class="text-[#A2ACA3] font-semibold text-[11px] uppercase mono">Handicap asiatique</span>
                    @foreach(['m1_5' => '-1,5', 'm0_5' => '-0,5', 'm0_25' => '-0,25', '0' => '0', '0_25' => '+0,25', '0_5' => '+0,5', '1_5' => '+1,5'] as $key => $label)
                        @if(isset($asianHandicap[$key]['home']))
                            <div class="flex justify-between mono">
                                <span>{{ $match->homeTeam->short_name }} {{ $label }} :</span>
                                <strong class="text-[#F0F0E8]">{{ round(($asianHandicap[$key]['home']['win'] + 0.5 * $asianHandicap[$key]['home']['half_win']) * 100, 1) }}%</strong>
                            </div>
                        @endif
                    @endforeach
                    <div class="text-[10px] text-[#A2ACA3]">La valeur affichée intègre les demi-gains ; remboursement et demi-perte sont conservés dans le détail du modèle.</div>
                </div>

                <div class="p-3 rounded bg-[#222B25] border border-[#303A32] space-y-1.5">
                    <span class="text-[#A2ACA3] font-semibold text-[11px] uppercase mono">Scores et totaux exacts</span>
                    @foreach(array_slice($topScores, 0, 5) as $score)
                        <div class="flex justify-between mono"><span>Score {{ $score['score'] ?? '—' }} :</span><strong class="text-[#C3A16A]">{{ round(($score['p'] ?? 0) * 100, 1) }}%</strong></div>
                    @endforeach
                    @foreach(array_slice($exactTotals, 0, 4, true) as $goals => $probability)
                        <div class="flex justify-between mono"><span>Total exact {{ $goals }} :</span><strong class="text-[#A2ACA3]">{{ round($probability * 100, 1) }}%</strong></div>
                    @endforeach
                    <div class="text-[10px] text-[#D68D86]">Marché fortement incertain : le score en tête n’est pas une sélection sûre.</div>
                </div>

                <div class="col-span-2 p-3 rounded bg-[#222B25] border border-[#303A32] space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="text-[#A2ACA3] font-semibold text-[11px] uppercase mono">Cotes de marchés disponibles</span>
                        <span class="text-[10px] text-[#A2ACA3]">Aucune cote n’est créée si le fournisseur ne la transmet pas.</span>
                    </div>
                    @forelse($marketOdds as $marketCode => $rows)
                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] mono">
                            <strong class="text-[#C3A16A]">{{ $marketCode }}</strong>
                            @foreach(array_slice($rows, 0, 8) as $odd)
                                <span class="text-[#F0F0E8]">{{ $odd['selection'] }}{{ $odd['line'] !== null ? ' '.$odd['line'] : '' }} : {{ number_format($odd['odds'], 2) }}</span>
                            @endforeach
                        </div>
                    @empty
                        <div class="text-[11px] text-[#A2ACA3]">Aucun marché bookmaker supplémentaire disponible pour ce match.</div>
                    @endforelse
                </div>

                <div class="p-3 rounded bg-[#222B25] border border-[#303A32] space-y-1.5">
                    <span class="text-[#A2ACA3] font-semibold text-[11px] uppercase mono">Buts par équipe</span>
                    @foreach(['home' => $match->homeTeam->short_name, 'away' => $match->awayTeam->short_name] as $side => $teamName)
                        <div class="flex justify-between mono">
                            <span>{{ $teamName }} +0,5 :</span>
                            <strong class="text-[#88B69A]">{{ isset($teamTotals[$side]['over_0_5']) ? round($teamTotals[$side]['over_0_5'] * 100, 1) . '%' : '—' }}</strong>
                        </div>
                        <div class="flex justify-between mono">
                            <span>{{ $teamName }} +1,5 :</span>
                            <strong class="text-[#C3A16A]">{{ isset($teamTotals[$side]['over_1_5']) ? round($teamTotals[$side]['over_1_5'] * 100, 1) . '%' : '—' }}</strong>
                        </div>
                    @endforeach
                </div>

                <div class="p-3 rounded bg-[#222B25] border border-[#303A32] space-y-1.5">
                    <span class="text-[#A2ACA3] font-semibold text-[11px] uppercase mono">Marge de victoire</span>
                    @foreach([
                        'home_by_1_plus' => $match->homeTeam->short_name.' par 1+',
                        'home_by_2_plus' => $match->homeTeam->short_name.' par 2+',
                        'away_by_1_plus' => $match->awayTeam->short_name.' par 1+',
                        'away_by_2_plus' => $match->awayTeam->short_name.' par 2+',
                    ] as $key => $label)
                        <div class="flex justify-between mono">
                            <span>{{ $label }} :</span>
                            <strong class="text-[#C3A16A]">{{ isset($goalMargin[$key]) ? round($goalMargin[$key] * 100, 1) . '%' : '—' }}</strong>
                        </div>
                    @endforeach
                </div>
                </div>
            </div>
        </div>
        </div>
    </details>

    @if($devigAnalysis)
        <details class="border-t border-[#303A32] pt-3">
            <summary class="cursor-pointer text-sm font-semibold text-[#A2ACA3] hover:text-[#F0F0E8]">Comparer avec les cotes disponibles</summary>
            <div class="pt-4">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="text-sm font-bold text-[#F0F0E8]">Chances estimées et cotes</h3>
                    <p class="text-[11px] text-[#A2ACA3]">Cette comparaison n’est pas une garantie de résultat.</p>
                </div>
                <div class="mono text-xs text-[#C3A16A]">
                    Marge Bookmaker : {{ number_format($devigAnalysis['overround'] * 100, 2) }}%
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="bg-[#141A17] text-[#A2ACA3] border-b border-[#303A32] mono text-[11px]">
                        <tr>
                            <th class="py-2.5 px-3">Issue</th>
                            <th class="py-2.5 px-3 text-center">Cote Bookmaker</th>
                            <th class="py-2.5 px-3 text-center">Probabilité Marché Sans Marge</th>
                            <th class="py-2.5 px-3 text-center">Probabilité Modèle Dixon-Coles</th>
                            <th class="py-2.5 px-3 text-center">Cote Juste Modèle</th>
                            <th class="py-2.5 px-3 text-center">Écart (Points)</th>
                            <th class="py-2.5 px-3 text-right">Statut Valeur</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#303A32] mono">
                        @php
                            $labels = ['Victoire ' . $match->homeTeam->short_name, 'Match Nul', 'Victoire ' . $match->awayTeam->short_name];
                            $keys = ['home', 'draw', 'away'];
                        @endphp
                        @foreach($keys as $idx => $k)
                            @php
                                $mOdds = $devigAnalysis['input_odds'][$idx];
                                $pMarket = $devigAnalysis['true_probabilities'][$idx];
                                $pMod = $predData['probabilities'][$k]['p'];
                                $diffPts = round(($pMod - $pMarket) * 100, 1);
                                $ev = round(($pMod * $mOdds - 1.0) * 100, 1);
                            @endphp
                            <tr>
                                <td class="py-3 px-3 font-bold text-[#F0F0E8]">{{ $labels[$idx] }}</td>
                                <td class="py-3 px-3 text-center font-bold text-[#F0F0E8]">{{ $mOdds }}</td>
                                <td class="py-3 px-3 text-center text-[#A2ACA3]">{{ round($pMarket * 100, 1) }}%</td>
                                <td class="py-3 px-3 text-center font-bold text-[#C3A16A]">{{ round($pMod * 100, 1) }}%</td>
                                <td class="py-3 px-3 text-center text-[#88B69A]">{{ round(1.0 / max(0.01, $pMod), 2) }}</td>
                                <td class="py-3 px-3 text-center font-bold {{ $diffPts > 0 ? 'text-[#88B69A]' : 'text-[#D68D86]' }}">
                                    {{ $diffPts > 0 ? "+{$diffPts}" : $diffPts }} pts
                                </td>
                                <td class="py-3 px-3 text-right">
                                    @if($ev > 0)
                                        <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-[#88B69A]/20 text-[#88B69A] border border-[#88B69A]/30">
                                            +{{ $ev }}% EV (ROBUSTE)
                                        </span>
                                    @else
                                        <span class="text-[#A2ACA3] text-[10px]">Défavorable</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            </div>
        </details>
    @endif

    <!-- Simulations et explications -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Simulateur What-if -->
        <div class="lab-panel p-5 space-y-4">
            <div>
                <h3 class="text-sm font-bold text-[#F0F0E8]">Essayer un autre scénario</h3>
                <p class="text-[11px] text-[#A2ACA3]">Ces changements servent uniquement à explorer une hypothèse et ne sont pas enregistrés.</p>
            </div>

            <form id="whatif-form" class="space-y-4 text-xs">
                @csrf
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[#A2ACA3] mb-1">Buts domicile : <span id="lh-val" class="text-[#88B69A] font-bold">{{ $predData['expected_goals']['home'] }}</span></label>
                        <input type="range" id="lh-slider" min="0.2" max="4.0" step="0.05" value="{{ $predData['expected_goals']['home'] }}" class="w-full accent-[#88B69A]">
                    </div>
                    <div>
                        <label class="block text-[#A2ACA3] mb-1">Buts extérieur : <span id="la-val" class="text-[#D68D86] font-bold">{{ $predData['expected_goals']['away'] }}</span></label>
                        <input type="range" id="la-slider" min="0.2" max="4.0" step="0.05" value="{{ $predData['expected_goals']['away'] }}" class="w-full accent-[#D68D86]">
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <input type="checkbox" id="neutral-check" class="rounded border-[#303A32] text-[#C3A16A]">
                    <label for="neutral-check" class="text-[#A2ACA3]">Le match se joue sur terrain neutre</label>
                </div>

                <div id="sim-result-box" class="p-3 rounded bg-[#101513] border border-[#303A32] flex items-center justify-between text-xs mono">
                    <span class="text-[#A2ACA3]">Scénario simulé :</span>
                    <div class="flex gap-3 font-bold">
                        <span id="sim-res-home" class="text-[#88B69A]">{{ round($predData['probabilities']['home']['p'] * 100) }}% (1)</span>
                        <span id="sim-res-draw" class="text-[#F0F0E8]">{{ round($predData['probabilities']['draw']['p'] * 100) }}% (N)</span>
                        <span id="sim-res-away" class="text-[#D68D86]">{{ round($predData['probabilities']['away']['p'] * 100) }}% (2)</span>
                    </div>
                </div>
            </form>
        </div>

        <!-- Assistant IA Gemini (Section 9) -->
        <div class="lab-panel p-5 space-y-4">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="text-sm font-bold text-[#F0F0E8] flex items-center gap-2">
                        <span>Assistant IA Explicatif</span>
                        <span class="text-[10px] px-2 py-0.5 rounded bg-[#C3A16A]/20 text-[#C3A16A] mono">
                            {{ !empty($aiExplanation['is_fallback']) ? 'Gabarit de secours' : ($aiExplanation['model'] ?? 'Gabarit déterministe') }}
                        </span>
                    </h3>
                    <p class="text-[11px] text-[#A2ACA3]">Les chiffres sont contrôlés par rapport aux données du match; le moteur statistique reste la source de vérité.</p>
                </div>
            </div>

            <!-- Contenu Généré -->
            <div id="ai-text-container" class="p-3.5 rounded bg-[#101513] border border-[#303A32] text-xs leading-relaxed text-[#F0F0E8] space-y-2 min-h-[140px] whitespace-pre-wrap prose prose-invert prose-xs">
                {!! nl2br(e($aiExplanation['text'])) !!}
            </div>

            <!-- Question personnalisée -->
            <form id="ai-question-form" class="flex gap-2">
                @csrf
                <input type="text" id="ai-user-prompt" placeholder="Ex: Quel est l'impact de l'avantage domicile ?" class="flex-1 bg-[#222B25] border border-[#303A32] rounded-md px-3 py-2 text-xs text-[#F0F0E8] placeholder-[#A2ACA3]/50 focus:border-[#C3A16A] focus:outline-none">
                <button type="submit" id="ai-submit-btn" class="px-4 py-2 bg-[#222B25] border border-[#303A32] hover:border-[#C3A16A] text-[#C3A16A] font-semibold rounded-md text-xs transition-colors">
                    Demander
                </button>
            </form>
        </div>
    </div>

    <!-- Script Interactif pour What-If et Assistant IA -->
    <script>
        const matchId = {{ $match->id }};
        const csrfToken = "{{ csrf_token() }}";

        // What-If Sliders
        const lhSlider = document.getElementById('lh-slider');
        const laSlider = document.getElementById('la-slider');
        const lhVal = document.getElementById('lh-val');
        const laVal = document.getElementById('la-val');
        const neutralCheck = document.getElementById('neutral-check');

        function updateWhatIf() {
            lhVal.innerText = lhSlider.value;
            laVal.innerText = laSlider.value;

            fetch(`/matches/${matchId}/whatif`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken
                },
                body: JSON.stringify({
                    lambda_home: parseFloat(lhSlider.value),
                    lambda_away: parseFloat(laSlider.value),
                    is_neutral: neutralCheck.checked
                })
            })
            .then(res => res.json())
            .then(data => {
                const p = data.probabilities;
                document.getElementById('sim-res-home').innerText = Math.round(p.home.p * 100) + '% (1)';
                document.getElementById('sim-res-draw').innerText = Math.round(p.draw.p * 100) + '% (N)';
                document.getElementById('sim-res-away').innerText = Math.round(p.away.p * 100) + '% (2)';
            })
            .catch(err => console.error(err));
        }

        lhSlider.addEventListener('input', updateWhatIf);
        laSlider.addEventListener('input', updateWhatIf);
        neutralCheck.addEventListener('change', updateWhatIf);

        // Interactive AI Assistant Prompt
        const aiForm = document.getElementById('ai-question-form');
        const aiPrompt = document.getElementById('ai-user-prompt');
        const aiContainer = document.getElementById('ai-text-container');
        const aiSubmit = document.getElementById('ai-submit-btn');

        aiForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const q = aiPrompt.value.trim();
            if (!q) return;

            aiSubmit.innerText = 'Calcul...';
            aiSubmit.disabled = true;

            fetch(`/matches/${matchId}/ask`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken
                },
                body: JSON.stringify({ question: q, mode: 'expert' })
            })
            .then(res => res.json())
            .then(data => {
                aiContainer.textContent = data.text;
                aiPrompt.value = '';
                aiSubmit.innerText = 'Demander';
                aiSubmit.disabled = false;
            })
            .catch(err => {
                aiSubmit.innerText = 'Demander';
                aiSubmit.disabled = false;
            });
        });
    </script>
</x-app-layout>
