{{-- #1003 Lieferanten am Handy (Suppliers\Index::handyAnsicht): drei Stufen statt Seite mit Leiste und Tabelle.
     1 Lieferanten als Karten (inaktive ausgeblendet wie am Computer) + Artikelsuche über alle Lieferanten
     2 Lieferant: Kontakt-Karte (erreichen und bestellen, keine Konditionen) + seine lieferbaren Artikel
     3 Artikel-Karte (handy/artikel-karte)
     Nur lesen: kein Anlegen, kein Lead, kein Zuordnen, kein Stammblatt. --}}
@php
    $leise = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]';
    $euro = fn ($v) => number_format((float) $v, 2, ',', '.') . ' €';
    $liste = 'flex flex-col rounded-[var(--fa-radius-surface)] border border-[var(--fa-line)] bg-[var(--fa-surface)] divide-y divide-[var(--fa-line)]';
    $zeile = 'flex w-full items-center gap-3 px-4 py-3 min-h-[60px] text-left';
@endphp
<x-ui-page>
    <x-slot:navbar>
        <x-foodalchemist::shell.page-navbar title="Lieferanten" icon="heroicon-o-truck" />
    </x-slot:navbar>

    <div class="flex-1 min-w-0 h-full overflow-y-auto px-4 py-4 space-y-3" data-fa-handy-lieferanten>
        @if($artikelKarte !== null || $artikelFehlt || $lieferant !== null)
            <button type="button" wire:click="handyZurueck" class="-ml-1 inline-flex items-center gap-1 min-h-11 pr-3 text-[length:var(--fa-text-md)] font-medium text-[var(--fa-accent)]" data-fa-handy-zurueck>
                @svg('heroicon-m-chevron-left', 'w-5 h-5') Zurück
            </button>
        @endif

        @if($artikelKarte !== null)
            @include('foodalchemist::livewire.handy.artikel-karte', ['a' => $artikelKarte])
        @elseif($artikelFehlt)
            <x-fa::empty compact icon="heroicon-o-cube" title="Artikel nicht gefunden">Der Artikel ist für diesen Mandanten nicht sichtbar oder wurde gelöscht.</x-fa::empty>
        @else
            @if($lieferant !== null)
                {{-- Stufe 2: Kopf + Kontakt-Karte --}}
                <div>
                    <h1 class="text-[length:var(--fa-text-2xl)] font-semibold leading-tight text-[var(--fa-ink)] wrap-anywhere">{{ $lieferant['name'] }}</h1>
                    <p class="{{ $leise }}">
                        {{ $lieferant['ort'] ?? '' }}
                        @if($lieferant['inaktiv'])<x-fa::badge data-fa-lieferant-inaktiv>inaktiv</x-fa::badge>@endif
                    </p>
                </div>

                <section class="{{ $liste }}" data-fa-lieferant-kontakt>
                    @foreach($lieferant['kontakte'] as $k)
                        <div class="px-4 py-3">
                            <p class="font-medium text-[var(--fa-ink)]">{{ $k->name }}@if($k->role)<span class="font-normal {{ $leise }}"> · {{ $k->role }}</span>@endif</p>
                            <div class="flex flex-wrap gap-x-4">
                                @if($k->phone)
                                    <a href="tel:{{ preg_replace('/[^\d+]/', '', $k->phone) }}" class="inline-flex items-center gap-1.5 min-h-11 text-[var(--fa-accent)]" data-fa-kontakt-tel>@svg('heroicon-m-phone', 'w-4 h-4') {{ $k->phone }}</a>
                                @endif
                                @if($k->email)
                                    <a href="mailto:{{ $k->email }}" class="inline-flex items-center gap-1.5 min-h-11 text-[var(--fa-accent)] wrap-anywhere">@svg('heroicon-m-envelope', 'w-4 h-4 shrink-0') {{ $k->email }}</a>
                                @endif
                            </div>
                        </div>
                    @endforeach
                    @php
                        $bestellen = array_filter([
                            'Bestellen per Mail' => $lieferant['email_order'],
                            'Bestellschluss' => $lieferant['bestellschluss'] !== null ? $lieferant['bestellschluss'] . ' Uhr' : null,
                            'Vorlauf' => $lieferant['vorlauf_tage'] !== null ? $lieferant['vorlauf_tage'] . ($lieferant['vorlauf_tage'] === 1 ? ' Tag' : ' Tage') : null,
                            'Liefertage' => $lieferant['liefertage'],
                            'Mindestbestellwert' => $lieferant['mindestbestellwert'] !== null ? $euro($lieferant['mindestbestellwert']) : null,
                        ], fn ($v) => $v !== null);
                    @endphp
                    @if($bestellen !== [])
                        <dl class="px-4 py-2">
                            @foreach($bestellen as $label => $wert)
                                <div class="flex items-baseline justify-between gap-3 py-1.5">
                                    <dt class="{{ $leise }}">{{ $label }}</dt>
                                    <dd class="min-w-0 text-right text-[var(--fa-ink)] wrap-anywhere">
                                        @if($label === 'Bestellen per Mail')
                                            <a href="mailto:{{ $wert }}" class="inline-flex items-center min-h-11 text-[var(--fa-accent)]">{{ $wert }}</a>
                                        @else
                                            {{ $wert }}
                                        @endif
                                    </dd>
                                </div>
                            @endforeach
                        </dl>
                    @endif
                    @if($lieferant['kontakte'] === [] && $bestellen === [])
                        <p class="px-4 py-3 {{ $leise }}">Keine Ansprechpartner und Bestelldaten hinterlegt.</p>
                    @endif
                </section>

                <input type="search" wire:model.live.debounce.300ms="artikelSuche" placeholder="Artikel dieses Lieferanten" aria-label="Artikel dieses Lieferanten durchsuchen"
                       class="fa-control h-12 text-[length:var(--fa-text-base)]" data-fa-handy-suche>
            @else
                {{-- Stufe 1: Artikelsuche über alle Lieferanten, darunter die Lieferanten --}}
                <h1 class="text-[length:var(--fa-text-2xl)] font-semibold text-[var(--fa-ink)]">Lieferanten</h1>
                <input type="search" wire:model.live.debounce.300ms="q" placeholder="Artikel in allen Lieferanten (Name, Art.-Nr.)" aria-label="Artikel in allen Lieferanten suchen"
                       class="fa-control h-12 text-[length:var(--fa-text-base)]" data-fa-handy-artikelsuche>
                @if($artikel === null)
                    <input type="search" wire:model.live.debounce.300ms="supplierSuche" placeholder="Lieferant suchen" aria-label="Lieferanten durchsuchen"
                           class="fa-control h-12 text-[length:var(--fa-text-base)]" data-fa-handy-suche>
                    <ul class="{{ $liste }}">
                        @forelse($lieferanten as $l)
                            <li wire:key="hl-{{ $l->id }}">
                                <button type="button" wire:click="waehleLieferant({{ $l->id }})" class="{{ $zeile }}" data-fa-handy-lieferant="{{ $l->id }}">
                                    <span class="min-w-0 flex-1">
                                        <span class="block font-medium leading-snug text-[var(--fa-ink)] wrap-anywhere">{{ $l->name }}</span>
                                        @if(trim(($l->postal_code ?? '') . ' ' . ($l->city ?? '')) !== '')
                                            <span class="block truncate {{ $leise }}">{{ trim(($l->postal_code ?? '') . ' ' . ($l->city ?? '')) }}</span>
                                        @endif
                                    </span>
                                    <span class="shrink-0 {{ $leise }} tabular-nums">{{ number_format($l->item_count, 0, ',', '.') }} Artikel</span>
                                    @svg('heroicon-m-chevron-right', 'w-5 h-5 shrink-0 text-[var(--fa-ink-3)]')
                                </button>
                            </li>
                        @empty
                            <li class="p-4"><x-fa::empty compact icon="heroicon-o-truck" title="Kein Lieferant gefunden">Andere Suche versuchen.</x-fa::empty></li>
                        @endforelse
                    </ul>
                @endif
            @endif

            {{-- Artikel als Karten (Stufe 2: des Lieferanten · Stufe 1 mit Suche: aller Lieferanten) --}}
            @if($artikel !== null)
                <p class="{{ $leise }} tabular-nums">{{ number_format($artikel->total(), 0, ',', '.') }} lieferbare Artikel</p>
                <ul class="{{ $liste }}">
                    @forelse($artikel as $item)
                        <li wire:key="ha-{{ $item->id }}">
                            <button type="button" wire:click="handyArtikel({{ $item->id }})" class="{{ $zeile }}" data-fa-handy-artikel="{{ $item->id }}">
                                <span class="min-w-0 flex-1">
                                    <span class="block font-medium leading-snug text-[var(--fa-ink)] wrap-anywhere">{{ $item->designation }}</span>
                                    <span class="block truncate {{ $leise }}">{{ implode(' · ', array_filter([
                                        $lieferant === null ? $item->supplier?->name : null,
                                        $item->article_number ? 'Art.-Nr. ' . $item->article_number : null,
                                        $item->qty !== null ? rtrim(rtrim(number_format((float) $item->qty, 3, ',', '.'), '0'), ',') . ' ' . ($item->unit_code ?? $item->ordering_unit ?? '') : null,
                                    ])) }}</span>
                                </span>
                                <span class="shrink-0 text-right tabular-nums">
                                    <span class="block text-[length:var(--fa-text-md)] font-medium text-[var(--fa-ink-2)]">{{ $item->aktiver_preis !== null ? $euro($item->aktiver_preis) : '–' }}</span>
                                    @if($item->vergleichspreis !== null)
                                        <span class="block {{ $leise }}">{{ number_format($item->vergleichspreis['value'], 2, ',', '.') }} {{ $item->vergleichspreis['unit'] }}</span>
                                    @endif
                                </span>
                                @svg('heroicon-m-chevron-right', 'w-5 h-5 shrink-0 text-[var(--fa-ink-3)]')
                            </button>
                        </li>
                    @empty
                        <li class="p-4"><x-fa::empty compact icon="heroicon-o-magnifying-glass" title="Kein Artikel gefunden">Andere Suche versuchen.</x-fa::empty></li>
                    @endforelse
                </ul>
                <div>{{ $artikel->links('foodalchemist::components.fa.pagination') }}</div>
            @endif
        @endif
    </div>
</x-ui-page>
