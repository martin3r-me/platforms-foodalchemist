{{-- #1003 Untere Leiste der Handy-Oberfläche (Daumenzone): Suche · Rezepte · Planung · Signale · Mehr.
     Nur sichtbar auf der Handy-Oberfläche ([data-fa-handy], foodalchemist-pass.css), am Computer nie.
     Einträge und Sichtbarkeit: Support\HandyNavigation (dieselbe Regel wie die Desktop-Navigation).
     Slot „konto“: die Hülle reicht Benutzer und Mandantenwechsel herein (Host: user-menu), kein Nachbau. --}}
@php
    $user = auth()->user();
    $nav = \Platform\FoodAlchemist\Support\HandyNavigation::fuer($user);
    $signale = collect($nav['reiter'])->contains('route', 'foodalchemist.review') ? \Platform\FoodAlchemist\Support\HandyNavigation::offeneSignale($user) : null;
    $aktivRoute = fn (string $route) => request()->routeIs($route) || request()->routeIs(\Illuminate\Support\Str::beforeLast($route, '.index').'.*');
    $reiterKlasse = 'flex-1 min-w-0 flex flex-col items-center justify-center gap-0.5 h-16 text-[length:var(--fa-text-sm)]';
@endphp
<div data-fa-handy-nav
     x-data="{
        mehr: false,
        iosHinweis: false,
        init() {
            let gesehen = true;
            try { gesehen = localStorage.getItem('fa-ios-hinweis') === '1'; } catch (e) {}
            const ios = /iphone|ipad|ipod/i.test(navigator.userAgent);
            this.iosHinweis = ios && ! window.navigator.standalone && ! gesehen;
        },
        hinweisWeg() { this.iosHinweis = false; try { localStorage.setItem('fa-ios-hinweis', '1'); } catch (e) {} },
        desktop() { window.faAnsicht('desktop'); },
     }"
     @keydown.escape.window="mehr = false">

    {{-- iOS: einmaliger Hinweis „Zum Home-Bildschirm“ (Android bietet die Installation selbst an) --}}
    <div x-show="iosHinweis" x-cloak
         class="fixed inset-x-3 bottom-[calc(4.75rem+env(safe-area-inset-bottom))] z-40 fa-surface shadow-lg p-4 flex items-start gap-3"
         data-fa-ios-hinweis>
        @svg('heroicon-o-arrow-up-on-square', 'w-6 h-6 shrink-0 text-[var(--fa-accent)]')
        <p class="flex-1 text-[length:var(--fa-text-base)] text-[var(--fa-ink)]">Als App nutzen: unten auf <b>Teilen</b> tippen, dann <b>Zum Home-Bildschirm</b>.</p>
        <button type="button" class="w-11 h-11 -m-2 inline-flex items-center justify-center text-[var(--fa-ink-3)]" x-on:click="hinweisWeg()" aria-label="Hinweis schließen">@svg('heroicon-m-x-mark', 'w-5 h-5')</button>
    </div>

    {{-- Blatt „Mehr“ --}}
    <div x-show="mehr" x-cloak x-transition.opacity class="fixed inset-0 z-50 bg-black/40" x-on:click="mehr = false"></div>
    <div x-show="mehr" x-cloak
         x-transition:enter="transition ease-out duration-200" x-transition:enter-start="translate-y-full" x-transition:enter-end="translate-y-0"
         x-transition:leave="transition ease-in duration-150" x-transition:leave-start="translate-y-0" x-transition:leave-end="translate-y-full"
         class="fixed inset-x-0 bottom-0 z-50 max-h-[85dvh] overflow-y-auto rounded-t-[var(--fa-radius-surface)] bg-[var(--fa-surface)] shadow-2xl pb-[env(safe-area-inset-bottom)]"
         role="dialog" aria-label="Mehr" data-fa-handy-mehr>
        <div class="sticky top-0 flex items-center justify-between px-5 h-14 bg-[var(--fa-surface)] border-b border-[var(--fa-line)]">
            <h2 class="text-[length:var(--fa-text-lg)] font-semibold text-[var(--fa-ink)]">Mehr</h2>
            <button type="button" class="w-11 h-11 -mr-2 inline-flex items-center justify-center text-[var(--fa-ink-3)]" x-on:click="mehr = false" aria-label="Schließen">@svg('heroicon-m-x-mark', 'w-5 h-5')</button>
        </div>
        <nav class="flex flex-col py-2" aria-label="Alle Bereiche">
            @foreach($nav['mehr'] as $item)
                <a href="{{ route($item['route']) }}" wire:navigate
                   class="flex items-center gap-4 px-5 min-h-[52px] text-[length:var(--fa-text-lg)] {{ $aktivRoute($item['route']) ? 'text-[var(--fa-accent)] font-semibold' : 'text-[var(--fa-ink)]' }}"
                   @if($aktivRoute($item['route'])) aria-current="page" @endif>
                    @svg($item['icon'], 'w-6 h-6 shrink-0 text-[var(--fa-ink-3)]')
                    <span class="truncate">{{ $item['label'] }}</span>
                </a>
            @endforeach
        </nav>
        <div class="border-t border-[var(--fa-line)] px-5 py-3 flex flex-col gap-1" data-fa-handy-konto>
            {{ $konto ?? '' }}
            @if(! isset($konto) && $user)
                <p class="py-2 text-[length:var(--fa-text-base)] text-[var(--fa-ink-2)]">{{ $user->name }} · {{ $user->currentTeam?->name }}</p>
            @endif
            <button type="button" x-on:click="desktop()"
                    class="flex items-center gap-4 min-h-[52px] text-[length:var(--fa-text-lg)] text-[var(--fa-ink)]" data-fa-desktop-ansicht>
                @svg('heroicon-o-computer-desktop', 'w-6 h-6 shrink-0 text-[var(--fa-ink-3)]')
                <span>Desktop-Ansicht</span>
            </button>
        </div>
    </div>

    {{-- Leiste --}}
    <nav class="fixed inset-x-0 bottom-0 z-40 flex items-stretch bg-[var(--fa-surface)] border-t border-[var(--fa-line)] pb-[env(safe-area-inset-bottom)]" aria-label="Handy-Navigation">
        <a href="{{ route('foodalchemist.suche') }}" wire:navigate
           class="{{ $reiterKlasse }} {{ request()->routeIs('foodalchemist.suche') ? 'text-[var(--fa-accent)] font-semibold' : 'text-[var(--fa-ink-3)]' }}"
           @if(request()->routeIs('foodalchemist.suche')) aria-current="page" @endif>
            @svg('heroicon-o-magnifying-glass', 'w-6 h-6')
            <span>Suche</span>
        </a>
        @foreach($nav['reiter'] as $item)
            <a href="{{ route($item['route']) }}" wire:navigate
               class="{{ $reiterKlasse }} {{ $aktivRoute($item['route']) ? 'text-[var(--fa-accent)] font-semibold' : 'text-[var(--fa-ink-3)]' }}"
               @if($aktivRoute($item['route'])) aria-current="page" @endif>
                <span class="relative">
                    @svg($item['icon'], 'w-6 h-6')
                    @if($item['route'] === 'foodalchemist.review' && $signale)
                        <span class="absolute -top-1.5 -right-3 min-w-5 h-5 px-1 rounded-[var(--fa-radius-pill)] bg-[var(--fa-crit)] text-[var(--fa-on-accent)] text-[length:var(--fa-text-sm)] font-semibold leading-5 text-center"
                              data-fa-signal-zaehler>{{ $signale > 99 ? '99+' : $signale }}</span>
                    @endif
                </span>
                <span class="truncate">{{ $item['label'] }}</span>
            </a>
        @endforeach
        <button type="button" x-on:click="mehr = true"
                class="{{ $reiterKlasse }} text-[var(--fa-ink-3)]" aria-haspopup="dialog" data-fa-handy-mehr-oeffnen>
            @svg('heroicon-o-squares-2x2', 'w-6 h-6')
            <span>Mehr</span>
        </button>
    </nav>
</div>
