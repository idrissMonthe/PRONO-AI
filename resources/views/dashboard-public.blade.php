<x-app-layout>
    @php
        $pickGroups = [
            'prudent' => ['title' => 'Les plus sûrs', 'subtitle' => 'Marchés simples et forte probabilité', 'class' => 'pick-tier-prudent'],
            'equilibre' => ['title' => 'Équilibrés', 'subtitle' => 'Un compromis entre probabilité et intérêt', 'class' => 'pick-tier-equilibre'],
            'audacieux' => ['title' => 'Audacieux', 'subtitle' => 'Plus risqués, pour tenter un scénario intéressant', 'class' => 'pick-tier-audacieux'],
        ];
        $bigParlayGroups = [
            'prudent' => ['title' => 'Version prudente', 'subtitle' => 'Doubles chances et marchés à probabilité élevée', 'class' => 'pick-tier-prudent'],
            'equilibre' => ['title' => 'Version équilibrée', 'subtitle' => 'Résultats 1N2 et marchés intermédiaires', 'class' => 'pick-tier-equilibre'],
            'audacieux' => ['title' => 'Version audacieuse', 'subtitle' => 'Issues moins probables mais plausibles, sans privilégier systématiquement le nul', 'class' => 'pick-tier-audacieux'],
        ];
        $pickCount = collect($dailyPicks)->flatten(1)->count();
        $liveFlashPickCount = count($liveFlashPicks);
        $bigParlayPickCount = collect($bigParlayPicks)->sum(fn ($picks) => count($picks));
    @endphp

    <section class="hero-card" aria-label="Tableau de Bord Analytique">
        <div>
            <span class="eyebrow">PRONO IA · ANALYSE FOOTBALL</span>
            <p>Des recommandations claires, des probabilités lisibles et une explication simple pour chaque rencontre.</p>
        </div>
        <a href="#today-picks" class="primary-btn">Voir les pronostics <span>↓</span></a>
    </section>

    <section class="stats-strip">
        <div><strong>{{ $pickCount + $liveFlashPickCount }}</strong><span>PRONOSTICS DISPONIBLES</span></div>
        <div><strong>{{ $upcomingCount }}</strong><span>MATCHS À VENIR</span></div>
        <div><strong>{{ $predictedMatches }}</strong><span>ANALYSES RÉALISÉES</span></div>
        <div><strong>24/7</strong><span>DONNÉES ACTUALISÉES</span></div>
    </section>

    <!-- Barre d'état & Contexte Opérationnel (Africa/Douala) -->
    <div class="flex flex-wrap items-center justify-between gap-2 p-2.5 rounded bg-[#141A17] border border-[#303A32] text-xs text-[#A2ACA3] mono">
        <div class="flex items-center gap-2">
            <span class="w-2 h-2 rounded-full bg-[#88B69A]"></span>
            <span>Moteur : <strong class="text-[#88B69A]">{{ $championModel?->version_name ?? 'Dixon-Coles M3' }}</strong></span>
            <span class="text-[#303A32]">|</span>
            <span>Fuseau : <strong class="text-[#F0F0E8]">Africa/Douala (WAT)</strong></span>
        </div>
        <div class="flex items-center gap-3">
            @if($lastSyncFormatted)
                <span>Dernière synchro : <strong class="text-[#F0F0E8]">{{ $lastSyncFormatted }}</strong></span>
            @endif
        </div>
    </div>

    <!-- Notification intelligente de trêve ou de faible volume aujourd'hui -->
    @if($upcomingCount > 0 && ($upcomingMatches->isNotEmpty() && $upcomingMatches->first()->match_date_utc->timezone('Africa/Douala')->isAfter(now('Africa/Douala')->endOfDay())))
        <div class="p-3.5 rounded bg-[#181F1B] border border-[#C3A16A]/40 text-xs text-[#A2ACA3] flex flex-col sm:flex-row sm:items-center justify-between gap-2.5">
            <div class="flex items-center gap-2.5">
                <span class="w-2.5 h-2.5 rounded-full bg-[#C3A16A] shrink-0 animate-pulse"></span>
                <span><strong>Calendrier :</strong> Pause ou trêve en cours aujourd'hui. Reprise officielle le <strong class="text-[#F0F0E8]">{{ $upcomingMatches->first()->match_date_utc->timezone('Africa/Douala')->translatedFormat('l d F Y à H:i') }}</strong> ({{ $upcomingCount }} rencontres programmées).</span>
            </div>
            <a href="{{ route('matches.index') }}" class="text-[#C3A16A] hover:underline font-bold shrink-0 mono">
                Consulter les matchs programmés →
            </a>
        </div>
    @endif

    <!-- Combinés du Jour Automatiques (Section 9 & 28) -->
    @if($dailyParlays->isNotEmpty())
        <section class="section-block">
            <div class="section-heading">
                <div>
                    <span class="eyebrow">PROPOSITIONS DE COMBINÉS</span>
                    <h2>Propositions de combinés</h2>
                    <p>Combinaisons calculées mathématiquement (probabilité conjointe, estimation réaliste du risque et valeur attendue).</p>
                </div>
                <a href="{{ route('parlays.index') }}" class="text-link">Gérer & régénérer →</a>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                @foreach($dailyParlays->take(3) as $parlay)
                    <div class="p-4 rounded-xl bg-[#141A17] border border-[#303A32] hover:border-[#C3A16A]/60 transition-all flex flex-col justify-between space-y-3.5">
                        <div class="space-y-3">
                            <div class="flex items-center justify-between pb-2 border-b border-[#303A32]/60">
                                <span class="px-2.5 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider mono {{ $parlay->risk_level === 'MODERE' ? 'bg-[#88B69A]/20 text-[#88B69A] border border-[#88B69A]/40' : ($parlay->risk_level === 'EQUILIBRE' ? 'bg-[#C3A16A]/20 text-[#C3A16A] border border-[#C3A16A]/40' : 'bg-[#D68D86]/20 text-[#D68D86] border border-[#D68D86]/40') }}">
                                    {{ $parlay->risk_level === 'MODERE' ? 'MODÉRÉ' : ($parlay->risk_level === 'EQUILIBRE' ? 'ÉQUILIBRÉ' : 'AUDACIEUX') }}
                                </span>
                                <span class="text-xs font-bold mono text-[#F0F0E8] bg-[#101513] px-2.5 py-1 rounded border border-[#303A32]/50">
                                    Cote : <span class="text-[#C3A16A]">x{{ number_format($parlay->combined_odds, 2) }}</span>
                                </span>
                            </div>

                            <div class="font-bold text-sm text-[#F0F0E8] leading-snug">
                                {{ $parlay->title }}
                            </div>

                            <div class="grid grid-cols-2 gap-2 py-2 px-3 rounded-lg bg-[#101513] mono text-xs border border-[#303A32]/40">
                                <div>
                                    <span class="text-[10px] text-[#A2ACA3] uppercase tracking-wider block">Chances</span>
                                    <strong class="text-[#88B69A] text-sm">{{ round($parlay->combined_prob * 100, 1) }}%</strong>
                                </div>
                                <div class="text-right">
                                    <span class="text-[10px] text-[#A2ACA3] uppercase tracking-wider block">Risque</span>
                                    <strong class="text-[#C3A16A] text-sm">{{ $parlay->risk_score }}/10</strong>
                                </div>
                            </div>

                            <div class="space-y-1.5 pt-1">
                                <div class="text-[10px] font-semibold text-[#A2ACA3] uppercase tracking-wider mono mb-1">
                                    Sélections incluses :
                                </div>
                                <ul class="space-y-2">
                                    @foreach(array_slice($parlay->selections_json, 0, 3) as $sel)
                                        <li class="p-2 rounded-lg bg-[#101513] border border-[#222B25] flex items-center justify-between gap-2.5">
                                            <div class="min-w-0 flex-1">
                                                <div class="text-[11px] font-medium text-[#F0F0E8] truncate">
                                                    {{ $sel['match_label'] ?? '' }}
                                                </div>
                                                <div class="text-[10px] text-[#88B69A] font-semibold truncate mt-0.5">
                                                    {{ $sel['selection_label'] ?? '' }}
                                                </div>
                                            </div>
                                            <div class="text-right shrink-0">
                                                <div class="mono text-xs font-bold text-[#C3A16A]">
                                                    {{ number_format($sel['odds'], 2) }}
                                                </div>
                                                <div class="mono text-[10px] text-[#A2ACA3]">
                                                    {{ $sel['model_prob_pct'] }}%
                                                </div>
                                            </div>
                                        </li>
                                    @endforeach
                                </ul>
                                @if(count($parlay->selections_json) > 3)
                                    <div class="text-center text-[10px] text-[#A2ACA3] mono pt-0.5">
                                        +{{ count($parlay->selections_json) - 3 }} autre(s) sélection(s)
                                    </div>
                                @endif
                            </div>
                        </div>

                        <a href="{{ route('parlays.show', $parlay->id) }}" class="w-full text-center py-2.5 rounded-lg bg-[#222B25] hover:bg-[#2c372f] border border-[#303A32] hover:border-[#C3A16A]/50 text-xs font-semibold text-[#F0F0E8] transition-all mono mt-2">
                            Voir la fiche détaillée →
                        </a>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    <!-- Opportunités de Valeur Détectées (EV > 0) -->
    @if(count($edges) > 0)
        <section class="section-block">
            <div class="section-heading">
                <div>
                    <span class="eyebrow">VALEUR ATTENDUE (+EV)</span>
                    <h2>Écarts détectés Modèle vs Marché</h2>
                    <p>Situations où l'estimation du modèle probabiliste dépasse la cote du marché avec une espérance positive.</p>
                </div>
                <a href="{{ route('odds.index') }}" class="text-link">Cotes & simulateur →</a>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                @foreach(array_slice($edges, 0, 3) as $edge)
                    <div onclick="openMatchModal({{ $edge['match']->id }})" class="p-3.5 rounded bg-[#181F1B] border border-[#303A32] hover:border-[#88B69A] transition-colors cursor-pointer space-y-2">
                        <div class="flex items-center justify-between text-xs gap-2">
                            <span class="font-bold text-[#F0F0E8] truncate">{{ $edge['selection'] }}</span>
                            <span class="px-1.5 py-0.5 text-[10px] font-bold rounded uppercase shrink-0 {{ $edge['classification'] === 'robuste' ? 'bg-[#88B69A]/20 text-[#88B69A] border border-[#88B69A]/40' : 'bg-[#C3A16A]/20 text-[#C3A16A] border border-[#C3A16A]/40' }}">
                                {{ $edge['classification'] }}
                            </span>
                        </div>
                        <div class="text-[11px] text-[#A2ACA3] flex flex-wrap items-center justify-between gap-1.5 mono">
                            <span>Marché : <strong class="text-[#F0F0E8]">{{ $edge['market_odds'] }}</strong></span>
                            <span>Cote juste : <strong class="text-[#88B69A]">{{ $edge['fair_odds'] }}</strong></span>
                            <span class="text-[#88B69A] font-bold">+{{ $edge['ev_pct'] }}% EV</span>
                        </div>
                        <div class="text-[10px] text-[#A2ACA3] italic pt-1 border-t border-[#303A32]/40 truncate flex justify-between items-center">
                            <span>{{ $edge['match']->homeTeam->name }} vs {{ $edge['match']->awayTeam->name }}</span>
                            <span class="text-[#C3A16A] font-semibold mono">Analyse ↗</span>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    @if($systemAlerts->isNotEmpty())
        <section class="section-block" style="padding-top: 1rem; padding-bottom: 0;">
            @foreach($systemAlerts as $alert)
                <div class="empty-card" style="border-color: {{ $alert->severity === 'critical' ? '#D68D86' : '#C3A16A' }}; margin-bottom: .6rem;">
                    <strong>{{ $alert->severity === 'critical' ? 'Problème de données' : 'Surveillance' }}</strong>
                    <span>{{ $alert->message }}</span>
                </div>
            @endforeach
        </section>
    @endif

    <section id="today-picks" class="section-block">
        <div class="section-heading">
            <div><span class="eyebrow">SÉLECTION DU JOUR</span><h2>Les matchs à surveiller</h2><p>Chaque pronostic repose sur les estimations du modèle. Les cotes peuvent l’aider à calibrer le résultat, mais ne remplacent jamais son analyse ; une fiabilité limitée est signalée.</p></div>
            <a href="{{ route('matches.index') }}" class="text-link">Tous les matchs →</a>
        </div>

        @if($pickCount > 0)
            @foreach($pickGroups as $risk => $group)
                @if(!empty($dailyPicks[$risk]))
                    <div class="pick-tier {{ $group['class'] }}">
                        <div class="pick-tier-heading">
                            <div><h3>{{ $group['title'] }}</h3><p>{{ $group['subtitle'] }}</p></div>
                            <span>{{ count($dailyPicks[$risk]) }} sélection{{ count($dailyPicks[$risk]) > 1 ? 's' : '' }}</span>
                        </div>
                        <div class="pick-grid">
                            @foreach($dailyPicks[$risk] as $pick)
                                @php $match = $pick['match']; @endphp
                                <a href="{{ route('matches.show', $match->id) }}" onclick="openMatchModal({{ $match->id }}); return false;" class="pick-card">
                                    <div class="pick-card-top"><span>{{ $match->league->name ?? 'Football' }}</span><span>{{ $match->match_date_utc->timezone('Africa/Douala')->format('d M · H:i') }}</span></div>
                                    <div class="teams"><strong>{{ $match->homeTeam->name }}</strong><span>vs</span><strong>{{ $match->awayTeam->name }}</strong></div>
                                    <!-- Bloc Pronostic Python -->
                                    <div class="space-y-1 pt-1">
                                        <div class="flex items-center justify-between gap-1.5">
                                            <span class="px-1.5 py-0.5 rounded text-[9px] font-bold uppercase tracking-wider mono bg-[#88B69A]/15 text-[#88B69A] border border-[#88B69A]/30">Pronostic Python</span>
                                            @if(!empty($pick['odds']))
                                                <span class="mono text-xs font-bold text-[#C3A16A]">@ {{ number_format($pick['odds'], 2) }}</span>
                                            @endif
                                        </div>
                                        <div class="pick-verdict">
                                            <span class="lock-dot"></span>
                                            <strong>{{ $pick['label'] }}</strong>
                                            <span class="pick-prob">{{ round($pick['prob'] * 100) }}%</span>
                                        </div>
                                    </div>

                                    <!-- Bloc Verdict et Alternative Gemini -->
                                    @php $badge = $pick['gemini'] ?? ['state' => 'not_evaluated', 'label' => 'Non évalué par Gemini']; @endphp
                                    <div class="pt-2 border-t border-[#303A32]/40 space-y-1.5 text-xs">
                                        <div class="flex items-center justify-between gap-1.5">
                                            <span class="text-[10px] uppercase font-semibold text-[#A2ACA3] mono">Verdict Gemini :</span>
                                            <span class="px-1.5 py-0.5 rounded text-[9px] font-bold uppercase tracking-wider mono {{ $badge['state'] === 'validated' ? 'bg-[#88B69A]/20 text-[#88B69A] border border-[#88B69A]/40' : ($badge['state'] === 'not_validated' ? 'bg-[#D68D86]/20 text-[#D68D86] border border-[#D68D86]/40' : 'bg-[#A2ACA3]/15 text-[#A2ACA3] border border-[#A2ACA3]/30') }}">
                                                {{ $badge['state'] === 'validated' ? 'VALIDÉ' : ($badge['state'] === 'not_validated' ? 'NON VALIDÉ' : 'NON ÉVALUÉ') }}
                                            </span>
                                        </div>

                                        @if(!empty($pick['gemini_alternative']))
                                            <div class="p-2 rounded bg-[#101513] border border-[#C3A16A]/30 space-y-1">
                                                <div class="flex items-center justify-between gap-1">
                                                    <span class="px-1.5 py-0.5 rounded text-[9px] font-bold uppercase tracking-wider mono bg-[#C3A16A]/20 text-[#C3A16A] border border-[#C3A16A]/40">Alternative Gemini</span>
                                                    @if(!empty($pick['gemini_alternative']['probability']))
                                                        <span class="mono text-[10px] text-[#C3A16A] font-bold">{{ round($pick['gemini_alternative']['probability'] * 100) }}%</span>
                                                    @endif
                                                </div>
                                                <div class="text-[11px] font-bold text-[#F0F0E8]">
                                                    {{ $pick['gemini_alternative']['label'] }}
                                                </div>
                                                @if(!empty($pick['gemini_alternative']['justification']))
                                                    <p class="text-[10px] text-[#A2ACA3] leading-tight italic">
                                                        Justification : {{ $pick['gemini_alternative']['justification'] }}
                                                    </p>
                                                @endif
                                            </div>
                                        @elseif(!empty($pick['gemini_reason']) && $badge['state'] === 'not_validated')
                                            <p class="text-[10px] text-[#A2ACA3] italic leading-tight">
                                                Justification : {{ $pick['gemini_reason'] }}
                                            </p>
                                        @endif
                                    </div>
                                    <div class="pick-footer"><span class="reliability">{{ $pick['source'] }} · {{ $pick['market'] }}</span><span class="score-pill">{{ $group['title'] }}</span></div>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endif
            @endforeach
        @else
            <div class="empty-card">Aucun pronostic fiable n’est disponible pour le moment. Synchronise les matchs depuis les paramètres.</div>
        @endif
    </section>

    @if($hasLiveMatches)
        <section class="section-block live-flash-section">
            <div class="section-heading">
                <div><span class="eyebrow">PRONO FLASH · EN DIRECT</span><h2>Scénarios pendant le match</h2><p>Projection indépendante des buts restants à partir du score, de la minute et du rythme observé. Ce ne sont pas des cotes live de bookmaker.</p></div>
                <span class="big-parlay-meta">{{ $liveFlashPickCount }} scénario{{ $liveFlashPickCount > 1 ? 's' : '' }}</span>
            </div>
            @if($liveFlashPickCount > 0)
                <div class="pick-grid">
                    @foreach($liveFlashPicks as $pick)
                        @php $match = $pick['match']; @endphp
                        <a href="{{ route('matches.show', $match->id) }}" class="pick-card live-flash-card">
                            <div class="pick-card-top">
                                <span>{{ $match->league->name ?? 'Football' }} · EN DIRECT</span>
                                <span>~{{ $pick['minute'] }}′</span>
                            </div>
                            <div class="teams"><strong>{{ $match->homeTeam->short_name }}</strong><span>{{ $pick['score'] }}</span><strong>{{ $match->awayTeam->short_name }}</strong></div>
                            <div class="pick-verdict"><span class="lock-dot"></span><strong>{{ $pick['label'] }}</strong><span class="pick-prob">{{ round($pick['prob'] * 100) }}%</span></div>
                            <div class="pick-footer"><span class="reliability">{{ $pick['source'] }} · projection du modèle · {{ $pick['market'] }}</span><span class="score-pill">Flash</span></div>
                        </a>
                    @endforeach
                </div>
            @else
                <div class="empty-card">Des matchs sont en direct, mais aucun scénario ne dépasse actuellement les seuils de fiabilité du modèle.</div>
            @endif
        </section>
        <script>window.setTimeout(() => window.location.reload(), 60000);</script>
    @endif

    <section class="section-block big-parlay-section">
        <div class="section-heading">
            <div><span class="eyebrow">SÉLECTION ÉLARGIE</span><h2>Gros combinés</h2><p>Une sélection de 10 matchs réels quand les données le permettent, diversifiée entre championnats et marchés. Une prédiction à fiabilité limitée est signalée, jamais masquée.</p></div>
            <span class="big-parlay-meta">{{ $bigParlayPickCount }} sélections · {{ $bigParlayStatus['validated_predictions'] }}/{{ $bigParlayStatus['future_matches'] }} matchs exploitables</span>
        </div>

        @foreach($bigParlayGroups as $risk => $group)
            @if(count($bigParlayPicks[$risk]) > 0)
                <div class="pick-tier {{ $group['class'] }} big-parlay-tier">
                    <div class="pick-tier-heading">
                        <div><h3>{{ $group['title'] }}</h3><p>{{ $group['subtitle'] }}</p></div>
                        <span>{{ count($bigParlayPicks[$risk]) }} pronostic{{ count($bigParlayPicks[$risk]) > 1 ? 's' : '' }}{{ count($bigParlayPicks[$risk]) < 10 ? ' · moins de 10 actuellement' : '' }} · probabilité conjointe indicative : {{ $bigParlayProbabilities[$risk] * 100 < 0.01 ? 'moins de 0,01 %' : number_format($bigParlayProbabilities[$risk] * 100, 2, ',', '').' %' }}</span>
                    </div>
                    <div class="big-parlay-card">
                        <div class="big-parlay-head"><span>Fiabilité croissante</span><span>{{ collect($bigParlayPicks[$risk])->pluck('league_key')->unique()->count() }} championnat{{ collect($bigParlayPicks[$risk])->pluck('league_key')->unique()->count() > 1 ? 's' : '' }}</span></div>
                        <div class="big-parlay-list">
                            @foreach($bigParlayPicks[$risk] as $index => $pick)
                                @php $match = $pick['match']; @endphp
                                <a href="{{ route('matches.show', $match->id) }}" onclick="openMatchModal({{ $match->id }}); return false;" class="big-parlay-row">
                                    <span class="big-parlay-rank">{{ $index + 1 }}</span>
                                    <span class="big-parlay-match"><small>{{ $match->league->name ?? 'Football' }} · {{ $match->match_date_utc->timezone('Africa/Douala')->format('d/m · H:i') }}</small><strong>{{ $match->homeTeam->short_name }} <i>vs</i> {{ $match->awayTeam->short_name }}</strong></span>
                                    <span class="big-parlay-market">{{ $pick['source'] }} · {{ $pick['market'] }}<strong>{{ $pick['label'] }}</strong></span>
                                    <span class="big-parlay-prob" data-probability="{{ number_format($pick['prob'], 6, '.', '') }}">{{ number_format($pick['prob'] * 100, 1, ',', '') }}%</span>
                                </a>
                            @endforeach
                        </div>
                        <div class="big-parlay-foot">La probabilité conjointe suppose une indépendance approximative entre les matchs. Le risque augmente de la version prudente à la version audacieuse.</div>
                    </div>
                </div>
            @else
                <div class="empty-card">{{ $group['title'] }} : aucune sélection future ne franchit actuellement les seuils du modèle. Aucun choix abstentionniste n’est ajouté artificiellement.</div>
            @endif
        @endforeach
        @if($bigParlayPickCount === 0)
            <div class="empty-card">{{ $bigParlayStatus['future_matches'] }} matchs futurs sont chargés, mais {{ $bigParlayStatus['official_predictions'] - $bigParlayStatus['validated_predictions'] }} prédiction{{ $bigParlayStatus['official_predictions'] - $bigParlayStatus['validated_predictions'] > 1 ? 's' : '' }} {{ $bigParlayStatus['official_predictions'] - $bigParlayStatus['validated_predictions'] > 1 ? 'sont' : 'est' }} encore en abstention. La rubrique se remplira automatiquement dès qu’un match franchira les contrôles de qualité et de séparation statistique.</div>
        @endif
    </section>

    <section class="how-section">
        <div><span class="eyebrow">COMMENT ÇA MARCHE</span><h2>Une analyse complète.<br>Une décision claire.</h2></div>
        <div class="how-grid">
            <div><b>01</b><h3>Données fraîches</h3><p>Calendrier, résultats, forme et cotes sont rassemblés avant chaque analyse.</p></div>
            <div><b>02</b><h3>Modèle probabiliste</h3><p>Les probabilités sont calculées avec une estimation Dixon-Coles et un contrôle de qualité.</p></div>
            <div><b>03</b><h3>Détection de valeur</h3><p>Les probabilités du modèle sont comparées au marché lorsqu’une cote est disponible.</p></div>
            <div><b>04</b><h3>Verdict final</h3><p>Le moteur peut s’abstenir quand les données sont trop faibles ou incertaines.</p></div>
        </div>
    </section>

    <section class="section-block">
        <div class="section-heading"><div><span class="eyebrow">RÉSULTATS</span><h2>Les dernières rencontres</h2></div><a href="{{ route('results') }}" class="text-link">Voir tout →</a></div>
        <div class="result-list">
            @forelse($recentFinished->take(6) as $match)
                <a href="{{ route('matches.show', $match->id) }}" onclick="openMatchModal({{ $match->id }}); return false;" class="result-row"><span>{{ $match->league->name ?? 'Football' }}</span><strong>{{ $match->homeTeam->short_name }} <b>{{ $match->home_score }}–{{ $match->away_score }}</b> {{ $match->awayTeam->short_name }}</strong><span>{{ $match->match_date_utc->timezone('Africa/Douala')->format('d/m/Y') }}</span></a>
            @empty
                <div class="empty-card">Les résultats apparaîtront ici après la synchronisation des matchs.</div>
            @endforelse
        </div>
    </section>

    <section class="responsible-note"><strong>À retenir</strong><span>Une probabilité n’est jamais une certitude. PronoIA est un outil d’analyse et ne garantit aucun gain.</span></section>

    {{-- Modal d'Analyse Complète de Match (4 Onglets) --}}
    @include('partials.match-modal')
</x-app-layout>
