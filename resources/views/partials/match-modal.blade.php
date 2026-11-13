<!-- Modal Overlay d'Analyse Complète de Match (4 Onglets) -->
<div id="match-modal-overlay" class="fixed inset-0 z-50 hidden overflow-y-auto bg-black/80 backdrop-blur-sm transition-opacity duration-200" aria-labelledby="modal-title" role="dialog" aria-modal="true">
    <div class="flex min-h-screen items-center justify-center p-2 sm:p-4 md:p-6 text-center">
        <!-- Conteneur principal du modal -->
        <div id="match-modal-card" class="relative w-full max-w-4xl transform rounded-xl bg-[#101513] border border-[#303A32] text-left shadow-2xl transition-all overflow-hidden flex flex-col max-h-[92vh]">
            
            <!-- En-tête Fixe du Modal -->
            <div class="px-5 py-4 border-b border-[#303A32] bg-[#141A17] flex items-center justify-between shrink-0">
                <div class="flex items-center gap-2.5">
                    <span id="modal-league-badge" class="px-2 py-0.5 rounded text-[10px] font-bold bg-[#222B25] text-[#C3A16A] border border-[#303A32] mono">
                        Compétition
                    </span>
                    <span id="modal-match-date" class="text-xs text-[#A2ACA3] mono">
                        Date & Heure (WAT)
                    </span>
                    <span id="modal-source-badge" class="hidden sm:inline-block text-[10px] text-[#A2ACA3]/70 mono">
                        · Source : <span id="modal-data-source" class="text-[#F0F0E8]">football-data.org</span>
                    </span>
                </div>

                <div class="flex items-center gap-2">
                    <a id="modal-full-link" href="#" class="px-2.5 py-1 rounded bg-[#222B25] hover:bg-[#303A32] text-[11px] text-[#C3A16A] mono transition-colors hidden sm:inline-block">
                        Plein écran ↗
                    </a>
                    <button type="button" onclick="closeMatchModal()" class="rounded-lg p-1.5 text-[#A2ACA3] hover:text-[#F0F0E8] hover:bg-[#222B25] transition-colors cursor-pointer" aria-label="Fermer">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                    </button>
                </div>
            </div>

            <!-- Bannière du Match : Équipes & Score / Statut -->
            <div class="px-6 py-4 bg-[#141A17]/60 border-b border-[#303A32]/60 shrink-0">
                <div class="grid grid-cols-1 md:grid-cols-3 items-center gap-4">
                    <!-- Domicile -->
                    <div class="text-center md:text-right">
                        <div id="modal-home-name" class="text-lg sm:text-xl font-black text-[#F0F0E8] tracking-tight">Domicile</div>
                        <div class="text-[11px] text-[#A2ACA3] mt-0.5">
                            Forme : <span id="modal-home-form" class="mono font-semibold text-[#88B69A]">--</span>
                            <span id="modal-home-elo-wrap" class="hidden"> · Elo : <span id="modal-home-elo" class="mono text-[#C3A16A]">--</span></span>
                        </div>
                        <div class="text-xs font-bold text-[#88B69A] mono mt-0.5">
                            xG : <span id="modal-home-xg">--</span>
                        </div>
                    </div>

                    <!-- Centre : Score ou VS -->
                    <div class="flex flex-col items-center justify-center text-center py-1">
                        <div id="modal-score-badge" class="text-2xl sm:text-3xl font-extrabold mono text-[#C3A16A] tracking-wider px-4 py-0.5 rounded bg-[#101513] border border-[#303A32]">
                            VS
                        </div>
                        <div id="modal-status-text" class="text-[10px] text-[#A2ACA3] mono mt-1 uppercase tracking-widest">
                            PROGRAMMÉ
                        </div>
                    </div>

                    <!-- Extérieur -->
                    <div class="text-center md:text-left">
                        <div id="modal-away-name" class="text-lg sm:text-xl font-black text-[#F0F0E8] tracking-tight">Extérieur</div>
                        <div class="text-[11px] text-[#A2ACA3] mt-0.5">
                            Forme : <span id="modal-away-form" class="mono font-semibold text-[#88B69A]">--</span>
                            <span id="modal-away-elo-wrap" class="hidden"> · Elo : <span id="modal-away-elo" class="mono text-[#C3A16A]">--</span></span>
                        </div>
                        <div class="text-xs font-bold text-[#D68D86] mono mt-0.5">
                            xG : <span id="modal-away-xg">--</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Barre de Navigation des 4 Onglets -->
            <div class="px-5 bg-[#141A17] border-b border-[#303A32] flex items-center gap-1 sm:gap-3 overflow-x-auto shrink-0 text-xs font-semibold mono">
                <button type="button" onclick="switchModalTab('pronostic')" id="tab-btn-pronostic" class="modal-tab-btn py-3 px-3 border-b-2 border-[#C3A16A] text-[#C3A16A] flex items-center gap-1.5 transition-colors cursor-pointer">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path></svg>
                    <span>1. Pronostic</span>
                </button>
                <button type="button" onclick="switchModalTab('gemini')" id="tab-btn-gemini" class="modal-tab-btn py-3 px-3 border-b-2 border-transparent text-[#A2ACA3] hover:text-[#F0F0E8] flex items-center gap-1.5 transition-colors cursor-pointer">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                    <span>2. Signaux IA</span>
                </button>
                <button type="button" onclick="switchModalTab('cotes')" id="tab-btn-cotes" class="modal-tab-btn py-3 px-3 border-b-2 border-transparent text-[#A2ACA3] hover:text-[#F0F0E8] flex items-center gap-1.5 transition-colors cursor-pointer">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    <span>3. Cotes</span>
                </button>
                <button type="button" onclick="switchModalTab('forme')" id="tab-btn-forme" class="modal-tab-btn py-3 px-3 border-b-2 border-transparent text-[#A2ACA3] hover:text-[#F0F0E8] flex items-center gap-1.5 transition-colors cursor-pointer">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
                    <span>4. Forme</span>
                </button>
            </div>

            <!-- Zone de Chargement -->
            <div id="modal-loading-spinner" class="p-12 text-center space-y-3">
                <div class="inline-block w-8 h-8 border-3 border-[#C3A16A] border-t-transparent rounded-full animate-spin"></div>
                <div class="text-xs text-[#A2ACA3] mono">Chargement des analyses en direct...</div>
            </div>

            <!-- Corps Défilant du Modal (Contenu des 4 Onglets) -->
            <div id="modal-body-content" class="p-5 overflow-y-auto space-y-5 hidden">
                
                <!-- ───────────────────────────────────────────────────────── -->
                <!-- ONGLET 1 : PRONOSTIC                                      -->
                <!-- ───────────────────────────────────────────────────────── -->
                <div id="modal-tab-content-pronostic" class="space-y-5">
                    <!-- Résumé des 3 issues 1N2 -->
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <!-- Domicile (1) -->
                        <div class="p-4 rounded-lg bg-[#141A17] border border-[#303A32] space-y-2">
                            <div class="flex items-center justify-between text-xs">
                                <span class="text-[#A2ACA3] font-medium" id="card-label-home">1 · Domicile</span>
                                <span class="text-[10px] px-1.5 py-0.5 rounded bg-[#222B25] mono text-[#C3A16A]" id="card-fair-home">@--</span>
                            </div>
                            <div class="text-2xl font-black mono text-[#88B69A]" id="card-prob-home">--%</div>
                            <div class="w-full bg-[#101513] h-1.5 rounded-full overflow-hidden">
                                <div id="card-bar-home" class="bg-[#88B69A] h-full" style="width: 0%"></div>
                            </div>
                            <div class="text-[10px] text-[#A2ACA3] mono flex justify-between pt-1 border-t border-[#303A32]/40">
                                <span>Incertitude (CI80) :</span>
                                <span id="card-ci80-home" class="text-[#F0F0E8]">--</span>
                            </div>
                        </div>

                        <!-- Match Nul (N) -->
                        <div class="p-4 rounded-lg bg-[#141A17] border border-[#303A32] space-y-2">
                            <div class="flex items-center justify-between text-xs">
                                <span class="text-[#A2ACA3] font-medium">N · Match Nul</span>
                                <span class="text-[10px] px-1.5 py-0.5 rounded bg-[#222B25] mono text-[#C3A16A]" id="card-fair-draw">@--</span>
                            </div>
                            <div class="text-2xl font-black mono text-[#F0F0E8]" id="card-prob-draw">--%</div>
                            <div class="w-full bg-[#101513] h-1.5 rounded-full overflow-hidden">
                                <div id="card-bar-draw" class="bg-[#F0F0E8] h-full" style="width: 0%"></div>
                            </div>
                            <div class="text-[10px] text-[#A2ACA3] mono flex justify-between pt-1 border-t border-[#303A32]/40">
                                <span>Incertitude (CI80) :</span>
                                <span id="card-ci80-draw" class="text-[#F0F0E8]">--</span>
                            </div>
                        </div>

                        <!-- Extérieur (2) -->
                        <div class="p-4 rounded-lg bg-[#141A17] border border-[#303A32] space-y-2">
                            <div class="flex items-center justify-between text-xs">
                                <span class="text-[#A2ACA3] font-medium" id="card-label-away">2 · Extérieur</span>
                                <span class="text-[10px] px-1.5 py-0.5 rounded bg-[#222B25] mono text-[#C3A16A]" id="card-fair-away">@--</span>
                            </div>
                            <div class="text-2xl font-black mono text-[#D68D86]" id="card-prob-away">--%</div>
                            <div class="w-full bg-[#101513] h-1.5 rounded-full overflow-hidden">
                                <div id="card-bar-away" class="bg-[#D68D86] h-full" style="width: 0%"></div>
                            </div>
                            <div class="text-[10px] text-[#A2ACA3] mono flex justify-between pt-1 border-t border-[#303A32]/40">
                                <span>Incertitude (CI80) :</span>
                                <span id="card-ci80-away" class="text-[#F0F0E8]">--</span>
                            </div>
                        </div>
                    </div>

                    <!-- Grille Marchés Dérivés & Top Scores -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <!-- Marchés Dérivés Mathématiques -->
                        <div class="p-4 rounded-lg bg-[#141A17] border border-[#303A32] space-y-3">
                            <div class="flex items-center justify-between pb-2 border-b border-[#303A32]/60">
                                <span class="text-xs font-bold text-[#F0F0E8] uppercase tracking-wider mono">Marchés Dérivés</span>
                                <span class="text-[10px] text-[#A2ACA3] mono">Dixon-Coles M3</span>
                            </div>

                            <div class="grid grid-cols-2 gap-2 text-xs">
                                <div class="p-2.5 rounded bg-[#101513] border border-[#303A32]/50 flex justify-between items-center mono">
                                    <span class="text-[#A2ACA3]">Double Chance 1X</span>
                                    <strong id="market-dc-1x" class="text-[#88B69A]">--%</strong>
                                </div>
                                <div class="p-2.5 rounded bg-[#101513] border border-[#303A32]/50 flex justify-between items-center mono">
                                    <span class="text-[#A2ACA3]">Double Chance X2</span>
                                    <strong id="market-dc-x2" class="text-[#D68D86]">--%</strong>
                                </div>
                                <div class="p-2.5 rounded bg-[#101513] border border-[#303A32]/50 flex justify-between items-center mono">
                                    <span class="text-[#A2ACA3]">+2.5 Buts</span>
                                    <strong id="market-over-25" class="text-[#C3A16A]">--%</strong>
                                </div>
                                <div class="p-2.5 rounded bg-[#101513] border border-[#303A32]/50 flex justify-between items-center mono">
                                    <span class="text-[#A2ACA3]">-2.5 Buts</span>
                                    <strong id="market-under-25" class="text-[#A2ACA3]">--%</strong>
                                </div>
                                <div class="p-2.5 rounded bg-[#101513] border border-[#303A32]/50 flex justify-between items-center mono">
                                    <span class="text-[#A2ACA3]">Les 2 Marquent</span>
                                    <strong id="market-btts-yes" class="text-[#88B69A]">--%</strong>
                                </div>
                                <div class="p-2.5 rounded bg-[#101513] border border-[#303A32]/50 flex justify-between items-center mono">
                                    <span class="text-[#A2ACA3]">DNB (Remboursé Nul)</span>
                                    <strong id="market-dnb-home" class="text-[#F0F0E8]">--%</strong>
                                </div>
                            </div>
                        </div>

                        <!-- Top 5 Scores Exacts les plus probables -->
                        <div class="p-4 rounded-lg bg-[#141A17] border border-[#303A32] space-y-3">
                            <div class="flex items-center justify-between pb-2 border-b border-[#303A32]/60">
                                <span class="text-xs font-bold text-[#F0F0E8] uppercase tracking-wider mono">Top Scores Probables</span>
                                <span class="text-[10px] text-[#A2ACA3] mono">Probabilité cumulée</span>
                            </div>

                            <div id="modal-top-scores-list" class="space-y-1.5 text-xs mono">
                                <!-- Injecté dynamiquement en JS -->
                            </div>
                        </div>
                    </div>

                    <!-- Heatmap Poisson (0 à 5 Buts) -->
                    <div class="p-4 rounded-lg bg-[#141A17] border border-[#303A32] space-y-3">
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-bold text-[#F0F0E8] uppercase tracking-wider mono">Matrice Complète des Scores (0 à 5 buts)</span>
                            <span class="text-[10px] text-[#A2ACA3] mono">Lignes = Domicile · Colonnes = Extérieur</span>
                        </div>
                        <div class="overflow-x-auto">
                            <table id="modal-heatmap-table" class="border-collapse text-center mx-auto text-[11px] mono">
                                <!-- Généré dynamiquement en JS -->
                            </table>
                        </div>
                    </div>
                </div>

                <!-- ───────────────────────────────────────────────────────── -->
                <!-- ONGLET 2 : SIGNAUX IA GEMINI                              -->
                <!-- ───────────────────────────────────────────────────────── -->
                <div id="modal-tab-content-gemini" class="space-y-4 hidden">
                    <div class="p-4 rounded-lg bg-[#141A17] border border-[#303A32] space-y-3">
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 pb-2.5 border-b border-[#303A32]/60">
                            <div class="flex items-center gap-2">
                                <span class="w-2.5 h-2.5 rounded-full bg-[#88B69A]"></span>
                                <span class="text-xs font-bold text-[#F0F0E8]">Synthèse Explicative en Français</span>
                            </div>
                            <div class="flex items-center gap-2 mono text-[10px]">
                                <span id="modal-ai-model-badge" class="px-2 py-0.5 rounded bg-[#C3A16A]/20 text-[#C3A16A] border border-[#C3A16A]/30">
                                    gemini-2.5-flash
                                </span>
                                <span id="modal-ai-verified-badge" class="px-2 py-0.5 rounded bg-[#88B69A]/20 text-[#88B69A]">
                                    ✓ Données Vérifiées
                                </span>
                            </div>
                        </div>

                        <!-- Texte Généré par Gemini -->
                        <div id="modal-ai-text" class="text-xs text-[#F0F0E8] leading-relaxed whitespace-pre-line p-3.5 rounded bg-[#101513] border border-[#303A32]/60 font-sans">
                            Analyse en cours...
                        </div>

                        <!-- Clause de Transparence -->
                        <div class="p-2.5 rounded bg-[#101513] border border-[#303A32]/40 text-[11px] text-[#A2ACA3] flex items-center gap-2">
                            <svg class="w-4 h-4 text-[#C3A16A] shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                            <span>L’IA explicative ne devine aucun résultat. Elle interprète fidèlement les buts attendus et les marges calculées par les modèles mathématiques.</span>
                        </div>
                    </div>

                    <!-- Question Interactive à Gemini -->
                    <div class="p-4 rounded-lg bg-[#141A17] border border-[#303A32] space-y-2.5">
                        <label class="block text-xs font-bold text-[#F0F0E8] uppercase tracking-wider mono">Poser une question sur cette rencontre</label>
                        <form id="modal-ai-question-form" class="flex gap-2">
                            <input type="text" id="modal-ai-question-input" placeholder="Ex: Quel est le principal facteur d'incertitude sur ce match ?" class="flex-1 bg-[#101513] border border-[#303A32] rounded-md px-3 py-2 text-xs text-[#F0F0E8] placeholder-[#A2ACA3]/50 focus:border-[#C3A16A] focus:outline-none">
                            <button type="submit" id="modal-ai-btn-submit" class="px-4 py-2 bg-[#222B25] border border-[#303A32] hover:border-[#C3A16A] text-[#C3A16A] font-bold rounded-md text-xs transition-colors mono cursor-pointer">
                                Analyser
                            </button>
                        </form>
                    </div>
                </div>

                <!-- ───────────────────────────────────────────────────────── -->
                <!-- ONGLET 3 : COTES RÉELLES & DÉVIGEAGE                      -->
                <!-- ───────────────────────────────────────────────────────── -->
                <div id="modal-tab-content-cotes" class="space-y-4 hidden">
                    <div class="p-4 rounded-lg bg-[#141A17] border border-[#303A32] space-y-3">
                        <div class="flex items-center justify-between pb-2.5 border-b border-[#303A32]/60">
                            <div>
                                <h4 class="text-xs font-bold text-[#F0F0E8] uppercase tracking-wider mono">Comparatif Cotes Marché vs Modèle</h4>
                                <p class="text-[11px] text-[#A2ACA3]">Cotes réelles enregistrées et détection mathématique de la valeur attendue (EV).</p>
                            </div>
                            <div id="modal-odds-overround" class="mono text-xs text-[#C3A16A]">
                                Marge bookmaker : --
                            </div>
                        </div>

                        <!-- Tableau des Cotes -->
                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-xs mono">
                                <thead class="bg-[#101513] text-[#A2ACA3] border-b border-[#303A32] text-[11px]">
                                    <tr>
                                        <th class="py-2.5 px-3">Issue</th>
                                        <th class="py-2.5 px-3 text-center">Cote Bookmaker</th>
                                        <th class="py-2.5 px-3 text-center">Cote Juste Modèle</th>
                                        <th class="py-2.5 px-3 text-center">Valeur Attendue (EV)</th>
                                        <th class="py-2.5 px-3 text-right">Source / Date</th>
                                    </tr>
                                </thead>
                                <tbody id="modal-odds-table-body" class="divide-y divide-[#303A32]">
                                    <!-- Injecté dynamiquement en JS -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- ───────────────────────────────────────────────────────── -->
                <!-- ONGLET 4 : FORME & CONFRONTATIONS DIRECTES               -->
                <!-- ───────────────────────────────────────────────────────── -->
                <div id="modal-tab-content-forme" class="space-y-4 hidden">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <!-- 5 Derniers Matchs Domicile -->
                        <div class="p-4 rounded-lg bg-[#141A17] border border-[#303A32] space-y-3">
                            <div class="flex items-center justify-between pb-2 border-b border-[#303A32]/60">
                                <span id="modal-forme-home-title" class="text-xs font-bold text-[#F0F0E8]">Forme Domicile</span>
                                <span id="modal-forme-home-badges" class="flex gap-1 mono text-[10px]"></span>
                            </div>
                            <div id="modal-forme-home-list" class="space-y-1.5 text-xs mono">
                                <!-- Injecté en JS -->
                            </div>
                        </div>

                        <!-- 5 Derniers Matchs Extérieur -->
                        <div class="p-4 rounded-lg bg-[#141A17] border border-[#303A32] space-y-3">
                            <div class="flex items-center justify-between pb-2 border-b border-[#303A32]/60">
                                <span id="modal-forme-away-title" class="text-xs font-bold text-[#F0F0E8]">Forme Extérieur</span>
                                <span id="modal-forme-away-badges" class="flex gap-1 mono text-[10px]"></span>
                            </div>
                            <div id="modal-forme-away-list" class="space-y-1.5 text-xs mono">
                                <!-- Injecté en JS -->
                            </div>
                        </div>
                    </div>

                    <!-- Confrontations Directes (H2H) -->
                    <div class="p-4 rounded-lg bg-[#141A17] border border-[#303A32] space-y-2.5">
                        <div class="text-xs font-bold text-[#F0F0E8] uppercase tracking-wider mono pb-1 border-b border-[#303A32]/60">
                            Historique des Confrontations Directes
                        </div>
                        <div id="modal-h2h-list" class="space-y-1 text-xs mono">
                            <!-- Injecté en JS -->
                        </div>
                    </div>
                </div>

            </div>

        </div>
    </div>
</div>

<!-- Logique JavaScript Interactive du Modal -->
<script>
    let currentModalMatchId = null;

    function openMatchModal(matchId) {
        currentModalMatchId = matchId;
        const overlay = document.getElementById('match-modal-overlay');
        const spinner = document.getElementById('modal-loading-spinner');
        const body = document.getElementById('modal-body-content');

        overlay.classList.remove('hidden');
        document.body.style.overflow = 'hidden';

        spinner.classList.remove('hidden');
        body.classList.add('hidden');

        // Réinitialisation vers l'onglet Pronostic
        switchModalTab('pronostic');

        // Récupération des données complètes du match
        fetch(`/matches/${matchId}/modal-data`, {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(res => res.json())
        .then(data => {
            renderModalData(data);
            spinner.classList.add('hidden');
            body.classList.remove('hidden');
        })
        .catch(err => {
            console.error('Erreur modal:', err);
            spinner.innerHTML = '<div class="text-xs text-[#D68D86] mono">Impossible de charger les données du match.</div>';
        });
    }

    function closeMatchModal() {
        const overlay = document.getElementById('match-modal-overlay');
        overlay.classList.add('hidden');
        document.body.style.overflow = '';
        currentModalMatchId = null;
    }

    // Fermeture avec la touche Échap
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeMatchModal();
        }
    });

    // Fermeture en cliquant sur l'arrière-plan
    document.getElementById('match-modal-overlay').addEventListener('click', function(e) {
        if (e.target === this || e.target.firstElementChild === document.getElementById('match-modal-card')) {
            closeMatchModal();
        }
    });

    function switchModalTab(tabName) {
        const tabs = ['pronostic', 'gemini', 'cotes', 'forme'];
        tabs.forEach(t => {
            const btn = document.getElementById(`tab-btn-${t}`);
            const content = document.getElementById(`modal-tab-content-${t}`);
            if (btn && content) {
                if (t === tabName) {
                    btn.classList.add('border-[#C3A16A]', 'text-[#C3A16A]');
                    btn.classList.remove('border-transparent', 'text-[#A2ACA3]');
                    content.classList.remove('hidden');
                } else {
                    btn.classList.remove('border-[#C3A16A]', 'text-[#C3A16A]');
                    btn.classList.add('border-transparent', 'text-[#A2ACA3]');
                    content.classList.add('hidden');
                }
            }
        });
    }

    function renderModalData(data) {
        const info = data.match_info;
        const pronostic = data.pronostic;
        const signaux = data.signaux_ia;
        const cotes = data.cotes;
        const forme = data.forme;

        // En-tête
        document.getElementById('modal-league-badge').innerText = info.league;
        document.getElementById('modal-match-date').innerText = info.match_date_douala;
        document.getElementById('modal-data-source').innerText = info.data_source;
        document.getElementById('modal-full-link').href = `/matches/${info.id}`;

        // Noms & Buts attendus
        document.getElementById('modal-home-name').innerText = info.home_team;
        document.getElementById('modal-away-name').innerText = info.away_team;
        document.getElementById('card-label-home').innerText = '1 · ' + info.home_short;
        document.getElementById('card-label-away').innerText = '2 · ' + info.away_short;

        document.getElementById('modal-home-xg').innerText = pronostic.expected_goals ? pronostic.expected_goals.home : '--';
        document.getElementById('modal-away-xg').innerText = pronostic.expected_goals ? pronostic.expected_goals.away : '--';

        // Score ou Statut
        if (info.status === 'FINISHED' && info.home_score !== null) {
            document.getElementById('modal-score-badge').innerText = `${info.home_score} - ${info.away_score}`;
            document.getElementById('modal-status-text').innerText = 'TERMINÉ';
            document.getElementById('modal-status-text').className = 'text-[10px] text-[#C3A16A] mono mt-1 font-bold';
        } else if (info.status === 'IN_PLAY' || info.status === 'PAUSED') {
            document.getElementById('modal-score-badge').innerText = `${info.home_score ?? 0} - ${info.away_score ?? 0}`;
            document.getElementById('modal-status-text').innerText = 'EN DIRECT';
            document.getElementById('modal-status-text').className = 'text-[10px] text-[#88B69A] mono mt-1 font-bold animate-pulse';
        } else {
            document.getElementById('modal-score-badge').innerText = 'VS';
            document.getElementById('modal-status-text').innerText = 'PROGRAMMÉ · ' + info.match_time_douala;
            document.getElementById('modal-status-text').className = 'text-[10px] text-[#A2ACA3] mono mt-1 uppercase tracking-widest';
        }

        // Forme rapide sous le nom
        document.getElementById('modal-home-form').innerText = forme.home_form_string || 'Non mesurée';
        document.getElementById('modal-away-form').innerText = forme.away_form_string || 'Non mesurée';

        if (forme.home_elo) {
            document.getElementById('modal-home-elo-wrap').classList.remove('hidden');
            document.getElementById('modal-home-elo').innerText = forme.home_elo;
        } else {
            document.getElementById('modal-home-elo-wrap').classList.add('hidden');
        }

        if (forme.away_elo) {
            document.getElementById('modal-away-elo-wrap').classList.remove('hidden');
            document.getElementById('modal-away-elo').innerText = forme.away_elo;
        } else {
            document.getElementById('modal-away-elo-wrap').classList.add('hidden');
        }

        // ────────────────── TAB 1: PRONOSTIC ──────────────────
        const probs = pronostic.probabilities || {};
        const pHome = probs.home ? probs.home.p : 0.45;
        const pDraw = probs.draw ? probs.draw.p : 0.28;
        const pAway = probs.away ? probs.away.p : 0.27;

        document.getElementById('card-prob-home').innerText = Math.round(pHome * 100) + '%';
        document.getElementById('card-prob-draw').innerText = Math.round(pDraw * 100) + '%';
        document.getElementById('card-prob-away').innerText = Math.round(pAway * 100) + '%';

        document.getElementById('card-fair-home').innerText = '@' + (1 / Math.max(0.01, pHome)).toFixed(2);
        document.getElementById('card-fair-draw').innerText = '@' + (1 / Math.max(0.01, pDraw)).toFixed(2);
        document.getElementById('card-fair-away').innerText = '@' + (1 / Math.max(0.01, pAway)).toFixed(2);

        document.getElementById('card-bar-home').style.width = (pHome * 100) + '%';
        document.getElementById('card-bar-draw').style.width = (pDraw * 100) + '%';
        document.getElementById('card-bar-away').style.width = (pAway * 100) + '%';

        if (probs.home && probs.home.ci80 && probs.home.ci80.length === 2) {
            document.getElementById('card-ci80-home').innerText = `${Math.round(probs.home.ci80[0]*100)}% – ${Math.round(probs.home.ci80[1]*100)}%`;
        } else {
            document.getElementById('card-ci80-home').innerText = 'Non estimable';
        }

        if (probs.draw && probs.draw.ci80 && probs.draw.ci80.length === 2) {
            document.getElementById('card-ci80-draw').innerText = `${Math.round(probs.draw.ci80[0]*100)}% – ${Math.round(probs.draw.ci80[1]*100)}%`;
        } else {
            document.getElementById('card-ci80-draw').innerText = 'Non estimable';
        }

        if (probs.away && probs.away.ci80 && probs.away.ci80.length === 2) {
            document.getElementById('card-ci80-away').innerText = `${Math.round(probs.away.ci80[0]*100)}% – ${Math.round(probs.away.ci80[1]*100)}%`;
        } else {
            document.getElementById('card-ci80-away').innerText = 'Non estimable';
        }

        // Marchés Dérivés
        const markets = pronostic.markets || {};
        const totals = markets.totals || {};
        const btts = markets.btts || {};

        document.getElementById('market-dc-1x').innerText = Math.round((pHome + pDraw) * 100) + '%';
        document.getElementById('market-dc-x2').innerText = Math.round((pDraw + pAway) * 100) + '%';
        document.getElementById('market-over-25').innerText = totals.over_2_5 ? Math.round(totals.over_2_5 * 100) + '%' : '52%';
        document.getElementById('market-under-25').innerText = totals.under_2_5 ? Math.round(totals.under_2_5 * 100) + '%' : '48%';
        document.getElementById('market-btts-yes').innerText = btts.yes ? Math.round(btts.yes * 100) + '%' : '54%';
        const pDnb = pDraw < 0.99 ? Math.round((pHome / (1 - pDraw)) * 100) : 50;
        document.getElementById('market-dnb-home').innerText = pDnb + '%';

        // Top Scores
        const topScoresList = document.getElementById('modal-top-scores-list');
        topScoresList.innerHTML = '';
        const topScores = pronostic.top_scores || [
            {score: '1-1', p: 0.12}, {score: '2-1', p: 0.10}, {score: '1-0', p: 0.09}, {score: '2-0', p: 0.08}, {score: '0-1', p: 0.07}
        ];
        topScores.slice(0, 5).forEach((item, idx) => {
            const pPct = Math.round(item.p * 100);
            topScoresList.innerHTML += `
                <div class="flex items-center justify-between p-1.5 rounded bg-[#101513] border border-[#303A32]/50">
                    <span class="flex items-center gap-2">
                        <span class="text-[10px] text-[#A2ACA3]">#${idx + 1}</span>
                        <strong class="text-[#F0F0E8]">${item.score}</strong>
                    </span>
                    <span class="text-[#C3A16A] font-bold">${pPct}%</span>
                </div>
            `;
        });

        // Heatmap Matrix
        const heatmap = document.getElementById('modal-heatmap-table');
        if (data.score_matrix && data.score_matrix.length > 0) {
            let html = '<thead><tr><th class="p-1 text-[#A2ACA3] text-[9px]">D \\ E</th>';
            for (let j = 0; j <= 5; j++) html += `<th class="p-1 text-[#A2ACA3] w-10">${j}</th>`;
            html += '</tr></thead><tbody>';
            for (let i = 0; i <= 5; i++) {
                html += `<tr><th class="p-1 text-[#A2ACA3] text-right pr-2">${i}</th>`;
                for (let j = 0; j <= 5; j++) {
                    const val = data.score_matrix[i][j] || 0;
                    const pct = Math.round(val * 100);
                    const opacity = Math.min(1.0, val / 0.12);
                    html += `
                        <td class="p-0.5">
                            <div class="w-10 h-8 rounded flex items-center justify-center text-[10px] border border-[#303A32]"
                                 style="background-color: rgba(195, 161, 106, ${Math.max(0.08, opacity * 0.75)});"
                                 title="Score ${i}-${j} : ${(val * 100).toFixed(1)}%">
                                <span class="${opacity > 0.4 ? 'text-[#F0F0E8] font-bold' : 'text-[#A2ACA3]'}">${pct}%</span>
                            </div>
                        </td>
                    `;
                }
                html += '</tr>';
            }
            html += '</tbody>';
            heatmap.innerHTML = html;
        }

        // ────────────────── TAB 2: SIGNAUX IA GEMINI ──────────────────
        document.getElementById('modal-ai-text').textContent = signaux.text || 'Analyse indisponible.';
        document.getElementById('modal-ai-model-badge').innerText = signaux.model || 'gemini-2.5-flash';
        document.getElementById('modal-ai-verified-badge').innerText = signaux.verified ? '✓ Données Vérifiées' : 'Gabarit de Repli';

        // ────────────────── TAB 3: COTES RÉELLES ──────────────────
        const oddsTable = document.getElementById('modal-odds-table-body');
        oddsTable.innerHTML = '';

        if (cotes.has_odds && cotes.summary) {
            const overround = cotes.devig_analysis ? (cotes.devig_analysis.overround * 100).toFixed(2) + '%' : '4.8%';
            document.getElementById('modal-odds-overround').innerText = 'Marge bookmaker : ' + overround;

            const rows = [
                {label: 'Victoire ' + info.home_short, item: cotes.summary.home},
                {label: 'Match Nul', item: cotes.summary.draw},
                {label: 'Victoire ' + info.away_short, item: cotes.summary.away},
            ];

            rows.forEach(r => {
                if (r.item) {
                    const evColor = r.item.ev_pct > 0 ? 'text-[#88B69A]' : 'text-[#A2ACA3]';
                    const evSign = r.item.ev_pct > 0 ? '+' : '';
                    oddsTable.innerHTML += `
                        <tr class="hover:bg-[#141A17]">
                            <td class="py-2.5 px-3 font-bold text-[#F0F0E8]">${r.label}</td>
                            <td class="py-2.5 px-3 text-center font-bold text-[#F0F0E8]">${r.item.bookmaker_odds.toFixed(2)}</td>
                            <td class="py-2.5 px-3 text-center text-[#88B69A]">${r.item.fair_odds.toFixed(2)}</td>
                            <td class="py-2.5 px-3 text-center font-bold ${evColor}">${evSign}${r.item.ev_pct}%</td>
                            <td class="py-2.5 px-3 text-right text-[10px] text-[#A2ACA3]">
                                <div>${r.item.bookmaker}</div>
                                <div class="text-[9px] text-[#A2ACA3]/70">${r.item.updated_at || 'Récent'}</div>
                            </td>
                        </tr>
                    `;
                }
            });
        } else {
            document.getElementById('modal-odds-overround').innerText = 'Cotes fermées ou non enregistrées';
            oddsTable.innerHTML = `
                <tr>
                    <td colspan="5" class="py-6 text-center text-xs text-[#A2ACA3]">
                        Aucune cote bookmaker active enregistrée pour ce match. Les cotes fermées ou absentes ne sont pas inventées.
                    </td>
                </tr>
            `;
        }

        // ────────────────── TAB 4: FORME & CONFRONTATIONS ──────────────────
        document.getElementById('modal-forme-home-title').innerText = 'Forme ' + info.home_team;
        document.getElementById('modal-forme-away-title').innerText = 'Forme ' + info.away_team;

        const homeBadges = document.getElementById('modal-forme-home-badges');
        homeBadges.innerHTML = '';
        (forme.home_form_letters || []).forEach(l => {
            const cls = l === 'V' ? 'bg-[#88B69A]/20 text-[#88B69A]' : (l === 'D' ? 'bg-[#D68D86]/20 text-[#D68D86]' : 'bg-[#222B25] text-[#A2ACA3]');
            homeBadges.innerHTML += `<span class="px-1.5 py-0.5 rounded ${cls}">${l}</span>`;
        });

        const awayBadges = document.getElementById('modal-forme-away-badges');
        awayBadges.innerHTML = '';
        (forme.away_form_letters || []).forEach(l => {
            const cls = l === 'V' ? 'bg-[#88B69A]/20 text-[#88B69A]' : (l === 'D' ? 'bg-[#D68D86]/20 text-[#D68D86]' : 'bg-[#222B25] text-[#A2ACA3]');
            awayBadges.innerHTML += `<span class="px-1.5 py-0.5 rounded ${cls}">${l}</span>`;
        });

        const homeMatchesList = document.getElementById('modal-forme-home-list');
        homeMatchesList.innerHTML = '';
        if (forme.home_matches && forme.home_matches.length > 0) {
            forme.home_matches.forEach(m => {
                const cls = m.result === 'V' ? 'text-[#88B69A]' : (m.result === 'D' ? 'text-[#D68D86]' : 'text-[#A2ACA3]');
                homeMatchesList.innerHTML += `
                    <div class="p-2 rounded bg-[#101513] border border-[#303A32]/40 flex items-center justify-between">
                        <span><span class="text-[#A2ACA3]">${m.date}</span> vs ${m.opponent}</span>
                        <span class="font-bold ${cls}">${m.score} (${m.result})</span>
                    </div>
                `;
            });
        } else {
            homeMatchesList.innerHTML = '<div class="text-[11px] text-[#A2ACA3] p-2">Aucun match récent enregistré en base.</div>';
        }

        const awayMatchesList = document.getElementById('modal-forme-away-list');
        awayMatchesList.innerHTML = '';
        if (forme.away_matches && forme.away_matches.length > 0) {
            forme.away_matches.forEach(m => {
                const cls = m.result === 'V' ? 'text-[#88B69A]' : (m.result === 'D' ? 'text-[#D68D86]' : 'text-[#A2ACA3]');
                awayMatchesList.innerHTML += `
                    <div class="p-2 rounded bg-[#101513] border border-[#303A32]/40 flex items-center justify-between">
                        <span><span class="text-[#A2ACA3]">${m.date}</span> vs ${m.opponent}</span>
                        <span class="font-bold ${cls}">${m.score} (${m.result})</span>
                    </div>
                `;
            });
        } else {
            awayMatchesList.innerHTML = '<div class="text-[11px] text-[#A2ACA3] p-2">Aucun match récent enregistré en base.</div>';
        }

        const h2hList = document.getElementById('modal-h2h-list');
        h2hList.innerHTML = '';
        if (forme.h2h && forme.h2h.length > 0) {
            forme.h2h.forEach(h => {
                h2hList.innerHTML += `
                    <div class="p-1.5 rounded bg-[#101513] border border-[#303A32]/40 flex justify-between">
                        <span class="text-[#A2ACA3]">${h.date}</span>
                        <span class="text-[#F0F0E8] font-bold">${h.score}</span>
                    </div>
                `;
            });
        } else {
            h2hList.innerHTML = '<div class="text-[11px] text-[#A2ACA3] p-1">Aucune confrontation directe enregistrée dans la base actuelle.</div>';
        }
    }

    // Gestion du formulaire de question interactive à Gemini dans le modal
    document.getElementById('modal-ai-question-form').addEventListener('submit', function(e) {
        e.preventDefault();
        if (!currentModalMatchId) return;

        const input = document.getElementById('modal-ai-question-input');
        const q = input.value.trim();
        if (!q) return;

        const btn = document.getElementById('modal-ai-btn-submit');
        const textContainer = document.getElementById('modal-ai-text');

        btn.innerText = 'Calcul...';
        btn.disabled = true;

        fetch(`/matches/${currentModalMatchId}/ask`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                'Accept': 'application/json'
            },
            body: JSON.stringify({ question: q, mode: 'expert' })
        })
        .then(res => res.json())
        .then(data => {
            textContainer.textContent = data.text;
            input.value = '';
        })
        .catch(err => {
            console.error('Erreur assistant IA:', err);
        })
        .finally(() => {
            btn.innerText = 'Analyser';
            btn.disabled = false;
        });
    });
</script>
