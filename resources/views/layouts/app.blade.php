<!DOCTYPE html>
<html lang="fr" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? 'PRONO IA' }} — Football Intelligence Lab</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body class="bg-[#101513] text-[#F0F0E8] min-h-screen flex flex-col font-sans antialiased selection:bg-[#C3A16A]/30 selection:text-[#F0F0E8] overflow-x-hidden">

    <!-- En-tête Global (CDC Section 4.1) -->
    <header class="border-b border-[#303A32] bg-[#141A17] sticky top-0 z-30 px-4 sm:px-6 py-2.5 flex items-center justify-between">
        <div class="flex items-center space-x-3">
            <!-- Bouton Hamburger Mobile (< lg) -->
            <button id="mobile-menu-toggle" type="button" class="lg:hidden p-2 rounded-md border border-[#303A32] bg-[#181F1B] hover:border-[#C3A16A] text-[#A2ACA3] hover:text-[#F0F0E8] transition-colors focus:outline-none" aria-label="Ouvrir le menu">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                </svg>
            </button>

            <a href="{{ route('dashboard') }}" class="flex items-center space-x-3 group">
                <div class="w-8 h-8 rounded bg-[#C3A16A]/15 border border-[#C3A16A]/40 flex items-center justify-center text-[#C3A16A] font-bold tracking-wider mono text-sm shrink-0">
                    Ω
                </div>
                <div>
                    <div class="text-base font-extrabold tracking-tight text-[#F0F0E8] flex items-center gap-1.5 sm:gap-2">
                        <span>Prono AI</span>
                    </div>
                </div>
            </a>
        </div>

        <div class="flex items-center space-x-3 text-xs">
            <div class="hidden sm:block text-[#A2ACA3] mono">
                <span>{{ now()->translatedFormat('d M Y') }}</span>
            </div>

            <a href="{{ route('settings.index') }}" class="p-2 rounded border border-[#303A32] bg-[#181F1B] hover:border-[#C3A16A]/60 text-[#A2ACA3] hover:text-[#F0F0E8] transition-colors" title="Réglages">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
            </a>
        </div>
    </header>

    <!-- Tiroir Mobile (Drawer) pour écrans < lg -->
    <div id="mobile-drawer-backdrop" class="fixed inset-0 bg-black/75 backdrop-blur-sm z-40 hidden transition-opacity duration-300 opacity-0 lg:hidden"></div>

    <aside id="mobile-drawer" class="fixed inset-y-0 left-0 w-72 max-w-[85vw] bg-[#141A17] border-r border-[#303A32] z-50 flex flex-col justify-between p-4 shadow-2xl -translate-x-full transition-transform duration-300 ease-in-out lg:hidden">
        <div>
            <!-- Header du tiroir -->
            <div class="flex items-center justify-between pb-3 mb-3 border-b border-[#303A32]">
                <div class="flex items-center space-x-2">
                    <div class="w-7 h-7 rounded bg-[#C3A16A]/15 border border-[#C3A16A]/40 flex items-center justify-center text-[#C3A16A] font-bold mono text-xs">
                        Ω
                    </div>
                    <span class="font-extrabold text-sm text-[#F0F0E8]">Navigation</span>
                </div>
                <button id="mobile-drawer-close" type="button" class="p-1.5 rounded-md text-[#A2ACA3] hover:text-[#F0F0E8] hover:bg-[#181F1B] transition-colors" aria-label="Fermer le menu">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>

            <!-- Liens de navigation mobile -->
            <nav class="space-y-1">
                <a href="{{ route('dashboard') }}" class="flex items-center space-x-3 px-3 py-2.5 rounded-md text-sm font-medium transition-colors {{ request()->routeIs('dashboard') ? 'bg-[#222B25] text-[#C3A16A] border border-[#303A32]' : 'text-[#A2ACA3] hover:text-[#F0F0E8] hover:bg-[#181F1B]' }}">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"></path></svg>
                    <span>Vue d'ensemble</span>
                </a>

                <a href="{{ route('matches.index') }}" class="flex items-center space-x-3 px-3 py-2.5 rounded-md text-sm font-medium transition-colors {{ request()->routeIs('matches.*') ? 'bg-[#222B25] text-[#C3A16A] border border-[#303A32]' : 'text-[#A2ACA3] hover:text-[#F0F0E8] hover:bg-[#181F1B]' }}">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                    <span>Matchs</span>
                </a>

                <a href="{{ route('comparator.index') }}" class="flex items-center space-x-3 px-3 py-2.5 rounded-md text-sm font-medium transition-colors {{ request()->routeIs('comparator.*') ? 'bg-[#222B25] text-[#C3A16A] border border-[#303A32]' : 'text-[#A2ACA3] hover:text-[#F0F0E8] hover:bg-[#181F1B]' }}">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path></svg>
                    <span>Équipes</span>
                </a>

                <a href="{{ route('odds.index') }}" class="flex items-center space-x-3 px-3 py-2.5 rounded-md text-sm font-medium transition-colors {{ request()->routeIs('odds.*') ? 'bg-[#222B25] text-[#C3A16A] border border-[#303A32]' : 'text-[#A2ACA3] hover:text-[#F0F0E8] hover:bg-[#181F1B]' }}">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    <span>Cotes</span>
                </a>

                <a href="{{ route('laboratory.index') }}" class="flex items-center space-x-3 px-3 py-2.5 rounded-md text-sm font-medium transition-colors {{ request()->routeIs('laboratory.*') ? 'bg-[#222B25] text-[#C3A16A] border border-[#303A32]' : 'text-[#A2ACA3] hover:text-[#F0F0E8] hover:bg-[#181F1B]' }}">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"></path></svg>
                    <span>Analyse avancée</span>
                </a>

                <a href="{{ route('data.index') }}" class="flex items-center space-x-3 px-3 py-2.5 rounded-md text-sm font-medium transition-colors {{ request()->routeIs('data.*') ? 'bg-[#222B25] text-[#C3A16A] border border-[#303A32]' : 'text-[#A2ACA3] hover:text-[#F0F0E8] hover:bg-[#181F1B]' }}">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"></path></svg>
                    <span>Données</span>
                </a>

                <a href="{{ route('settings.index') }}" class="flex items-center space-x-3 px-3 py-2.5 rounded-md text-sm font-medium transition-colors {{ request()->routeIs('settings.*') ? 'bg-[#222B25] text-[#C3A16A] border border-[#303A32]' : 'text-[#A2ACA3] hover:text-[#F0F0E8] hover:bg-[#181F1B]' }}">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"></path></svg>
                    <span>Paramètres</span>
                </a>
            </nav>
        </div>

        <!-- Mode & Éthique Notice (Mobile) -->
        <div class="p-3 rounded bg-[#181F1B] border border-[#303A32] text-[11px] text-[#A2ACA3] space-y-1.5 mt-4">
            <div class="flex items-center justify-between font-semibold text-[#F0F0E8]">
                <span>Cadre Analytique</span>
                <span class="text-[9px] px-1.5 py-0.5 rounded bg-[#88B69A]/20 text-[#88B69A]">SANS ENJEU</span>
            </div>
            <p class="leading-relaxed">
                Outil personnel de modélisation mathématique. Aucun placement de pari d'argent.
            </p>
        </div>
    </aside>

    <div class="flex flex-1 min-w-0">
        <!-- Navigation Latérale Bureau (CDC Section 3.7) -->
        <aside class="hidden lg:flex w-56 border-r border-[#303A32] bg-[#141A17] flex-col p-3 shrink-0">
            <nav class="space-y-1">
                <a href="{{ route('dashboard') }}" class="flex items-center space-x-3 px-3 py-2.5 rounded-md text-sm font-medium transition-colors {{ request()->routeIs('dashboard') ? 'bg-[#222B25] text-[#C3A16A] border border-[#303A32]' : 'text-[#A2ACA3] hover:text-[#F0F0E8] hover:bg-[#181F1B]' }}">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"></path></svg>
                    <span>Vue d'ensemble</span>
                </a>

                <a href="{{ route('matches.index') }}" class="flex items-center space-x-3 px-3 py-2.5 rounded-md text-sm font-medium transition-colors {{ request()->routeIs('matches.*') ? 'bg-[#222B25] text-[#C3A16A] border border-[#303A32]' : 'text-[#A2ACA3] hover:text-[#F0F0E8] hover:bg-[#181F1B]' }}">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                    <span>Matchs</span>
                </a>

                <a href="{{ route('comparator.index') }}" class="flex items-center space-x-3 px-3 py-2.5 rounded-md text-sm font-medium transition-colors {{ request()->routeIs('comparator.*') ? 'bg-[#222B25] text-[#C3A16A] border border-[#303A32]' : 'text-[#A2ACA3] hover:text-[#F0F0E8] hover:bg-[#181F1B]' }}">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path></svg>
                    <span>Équipes</span>
                </a>

                <a href="{{ route('odds.index') }}" class="flex items-center space-x-3 px-3 py-2.5 rounded-md text-sm font-medium transition-colors {{ request()->routeIs('odds.*') ? 'bg-[#222B25] text-[#C3A16A] border border-[#303A32]' : 'text-[#A2ACA3] hover:text-[#F0F0E8] hover:bg-[#181F1B]' }}">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    <span>Cotes</span>
                </a>

                <a href="{{ route('laboratory.index') }}" class="flex items-center space-x-3 px-3 py-2.5 rounded-md text-sm font-medium transition-colors {{ request()->routeIs('laboratory.*') ? 'bg-[#222B25] text-[#C3A16A] border border-[#303A32]' : 'text-[#A2ACA3] hover:text-[#F0F0E8] hover:bg-[#181F1B]' }}">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"></path></svg>
                    <span>Analyse avancée</span>
                </a>

                <a href="{{ route('data.index') }}" class="flex items-center space-x-3 px-3 py-2.5 rounded-md text-sm font-medium transition-colors {{ request()->routeIs('data.*') ? 'bg-[#222B25] text-[#C3A16A] border border-[#303A32]' : 'text-[#A2ACA3] hover:text-[#F0F0E8] hover:bg-[#181F1B]' }}">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"></path></svg>
                    <span>Données</span>
                </a>

                <a href="{{ route('settings.index') }}" class="flex items-center space-x-3 px-3 py-2.5 rounded-md text-sm font-medium transition-colors {{ request()->routeIs('settings.*') ? 'bg-[#222B25] text-[#C3A16A] border border-[#303A32]' : 'text-[#A2ACA3] hover:text-[#F0F0E8] hover:bg-[#181F1B]' }}">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"></path></svg>
                    <span>Paramètres</span>
                </a>
            </nav>

        </aside>

        <!-- Zone de Contenu Principal (min-w-0 évite les débordements flex) -->
        <main class="flex-1 min-w-0 overflow-y-auto p-4 sm:p-5 lg:p-6 space-y-5">
            @if(session('success'))
                <div class="p-4 rounded-md bg-[#88B69A]/15 border border-[#88B69A]/40 text-[#88B69A] text-sm flex items-center justify-between">
                    <span>{{ session('success') }}</span>
                </div>
            @endif

            @if(session('error'))
                <div class="p-4 rounded-md bg-[#D68D86]/15 border border-[#D68D86]/40 text-[#D68D86] text-sm flex items-center justify-between">
                    <span>{{ session('error') }}</span>
                </div>
            @endif

            {{ $slot }}
        </main>
    </div>

    <!-- Bannière Éthique & Jeu Responsable Obligatoire (CDC 28.2) -->
    <footer class="border-t border-[#303A32] bg-[#141A17] px-4 sm:px-6 py-2.5 text-[10px] text-[#A2ACA3] flex items-center justify-center sm:justify-between gap-2 text-center sm:text-left">
        <div class="flex flex-col sm:flex-row items-center sm:space-x-2 gap-1">
            <span class="text-[#C3A16A] font-semibold shrink-0">Note :</span>
            <span>Les probabilités ne sont pas des certitudes; aucune mise réelle n’est effectuée.</span>
        </div>
        <a href="{{ route('settings.index') }}" class="hidden sm:inline mono text-[#A2ACA3]/70 hover:text-[#F0F0E8]">Diagnostic</a>
    </footer>

    <!-- Script de gestion du tiroir mobile -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const toggleBtn = document.getElementById('mobile-menu-toggle');
            const closeBtn = document.getElementById('mobile-drawer-close');
            const drawer = document.getElementById('mobile-drawer');
            const backdrop = document.getElementById('mobile-drawer-backdrop');

            function openDrawer() {
                backdrop.classList.remove('hidden');
                requestAnimationFrame(() => {
                    backdrop.classList.remove('opacity-0');
                    backdrop.classList.add('opacity-100');
                    drawer.classList.remove('-translate-x-full');
                    drawer.classList.add('translate-x-0');
                });
                document.body.classList.add('overflow-hidden');
            }

            function closeDrawer() {
                backdrop.classList.remove('opacity-100');
                backdrop.classList.add('opacity-0');
                drawer.classList.remove('translate-x-0');
                drawer.classList.add('-translate-x-full');
                setTimeout(() => {
                    backdrop.classList.add('hidden');
                    document.body.classList.remove('overflow-hidden');
                }, 300);
            }

            if (toggleBtn) toggleBtn.addEventListener('click', openDrawer);
            if (closeBtn) closeBtn.addEventListener('click', closeDrawer);
            if (backdrop) backdrop.addEventListener('click', closeDrawer);

            // Fermer si redimensionnement vers desktop
            window.addEventListener('resize', function() {
                if (window.innerWidth >= 1024) {
                    closeDrawer();
                }
            });
        });
    </script>
</body>
</html>
