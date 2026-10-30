{{-- #1003 Globale Suche (Handy-Einstieg). Ein Thema je Bildschirm: oben das Feld, darunter Treffer je Art als
     kurze Karten-Liste; ohne Suchwort „Zuletzt angesehen“ (pro Gerät, localStorage fa-zuletzt) und Favoriten.
     Antippen merkt sich den Eintrag als zuletzt angesehen und öffnet die Detailseite (Vollbild). --}}
<div class="min-h-full bg-[var(--fa-ground)]"
     x-data="{
        zuletzt: [],
        init() { try { this.zuletzt = JSON.parse(localStorage.getItem('fa-zuletzt') || '[]'); } catch (e) { this.zuletzt = []; } },
        merke(titel, url) {
            let liste = [];
            try { liste = JSON.parse(localStorage.getItem('fa-zuletzt') || '[]'); } catch (e) {}
            liste = [{ titel, url }].concat(liste.filter((e) => e.url !== url)).slice(0, 10);
            try { localStorage.setItem('fa-zuletzt', JSON.stringify(liste)); } catch (e) {}
        },
     }"
     data-fa-handy-suche>
    <x-foodalchemist::shell.page-navbar title="Suche" icon="heroicon-o-magnifying-glass" />

    <div class="mx-auto max-w-2xl px-4 py-4 flex flex-col gap-5">
        <div class="relative">
            <label for="fa-handy-suche" class="sr-only">Suchen</label>
            @svg('heroicon-m-magnifying-glass', 'w-5 h-5 absolute left-3.5 top-1/2 -translate-y-1/2 text-[var(--fa-ink-3)] pointer-events-none')
            <input id="fa-handy-suche" type="search" inputmode="search" autocomplete="off" autofocus enterkeyhint="search"
                   wire:model.live.debounce.300ms="q"
                   placeholder="Rezept, Gericht, Produkt, Lieferant, EAN"
                   class="w-full h-12 pl-11 pr-4 rounded-[var(--fa-radius-surface)] border border-[var(--fa-line-strong)] bg-[var(--fa-surface)] text-[length:var(--fa-text-lg)] text-[var(--fa-ink)] placeholder:text-[var(--fa-ink-3)] focus:outline-none focus:border-[var(--fa-accent)]"
                   data-fa-handy-suchfeld>
        </div>

        <div wire:loading.delay class="text-[length:var(--fa-text-base)] text-[var(--fa-ink-3)]">Suche läuft …</div>

        @if($gesucht)
            @forelse($abschnitte as $abschnitt)
                <section class="flex flex-col gap-2" wire:key="handy-suche-{{ $abschnitt['titel'] }}" data-fa-handy-treffer="{{ $abschnitt['titel'] }}">
                    <h2 class="text-[length:var(--fa-text-base)] font-semibold text-[var(--fa-ink-2)]">{{ $abschnitt['titel'] }}</h2>
                    <ul class="fa-surface divide-y divide-[var(--fa-line)] overflow-hidden">
                        @foreach($abschnitt['treffer'] as $t)
                            <li>
                                <a href="{{ $t['url'] }}" wire:navigate x-on:click="merke(@js($t['titel']), @js($t['url']))"
                                   class="flex items-center gap-3 px-4 min-h-[52px] py-2">
                                    <span class="min-w-0 flex-1">
                                        <span class="block text-[length:var(--fa-text-base)] text-[var(--fa-ink)] break-words">{{ $t['titel'] }}</span>
                                        @if($t['zusatz'])<span class="block text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] truncate">{{ $t['zusatz'] }}</span>@endif
                                    </span>
                                    @svg('heroicon-m-chevron-right', 'w-5 h-5 shrink-0 text-[var(--fa-ink-3)]')
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @empty
                <x-fa::empty title="Nichts gefunden" description="Anderes Wort versuchen, z. B. nur den Anfang des Namens." />
            @endforelse
        @else
            <section class="flex flex-col gap-2" x-show="zuletzt.length" x-cloak data-fa-handy-zuletzt>
                <h2 class="text-[length:var(--fa-text-base)] font-semibold text-[var(--fa-ink-2)]">Zuletzt angesehen</h2>
                <ul class="fa-surface divide-y divide-[var(--fa-line)] overflow-hidden">
                    <template x-for="e in zuletzt" :key="e.url">
                        <li>
                            <a :href="e.url" wire:navigate class="flex items-center gap-3 px-4 min-h-[52px] py-2">
                                @svg('heroicon-o-clock', 'w-5 h-5 shrink-0 text-[var(--fa-ink-3)]')
                                <span class="min-w-0 flex-1 text-[length:var(--fa-text-base)] text-[var(--fa-ink)] break-words" x-text="e.titel"></span>
                                @svg('heroicon-m-chevron-right', 'w-5 h-5 shrink-0 text-[var(--fa-ink-3)]')
                            </a>
                        </li>
                    </template>
                </ul>
            </section>

            @if($favoriten->isNotEmpty())
                <section class="flex flex-col gap-2" data-fa-handy-favoriten>
                    <h2 class="text-[length:var(--fa-text-base)] font-semibold text-[var(--fa-ink-2)]">Favoriten</h2>
                    <ul class="fa-surface divide-y divide-[var(--fa-line)] overflow-hidden">
                        @foreach($favoriten as $t)
                            <li>
                                <a href="{{ $t['url'] }}" wire:navigate x-on:click="merke(@js($t['titel']), @js($t['url']))"
                                   class="flex items-center gap-3 px-4 min-h-[52px] py-2">
                                    @svg('heroicon-o-star', 'w-5 h-5 shrink-0 text-[var(--fa-ink-3)]')
                                    <span class="min-w-0 flex-1 text-[length:var(--fa-text-base)] text-[var(--fa-ink)] break-words">{{ $t['titel'] }}</span>
                                    @svg('heroicon-m-chevron-right', 'w-5 h-5 shrink-0 text-[var(--fa-ink-3)]')
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            <p class="text-[length:var(--fa-text-base)] text-[var(--fa-ink-3)]">Ab zwei Buchstaben suchen. Artikelnummer oder EAN geht auch.</p>
        @endif
    </div>
</div>
