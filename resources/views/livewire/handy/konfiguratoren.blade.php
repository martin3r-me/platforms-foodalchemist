{{-- Spec 94 Stufe 6 · Konfiguratoren am Handy (Livewire\Angebote\Konfiguratoren, HandyLesemodus): Liste mit Status, Link und
     offenen Anfragen; Detail mit Kennzahlen, Preisen je Paket (nur lesen), Link teilen, veröffentlichen/zurückziehen
     („Anfragen gehen an“, „Gültig bis“) und offenen Anfragen. Matrix, Leistungen, Vorlagen und Branding bleiben am Computer. --}}
@php
    $leise = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]';
    $karte = 'rounded-[var(--fa-radius-surface)] border border-[var(--fa-line)] bg-[var(--fa-surface)]';
    $euro = fn ($w) => $w !== null ? number_format((float) $w, 2, ',', '.') . ' €' : '–';
    $linkTon = ['live' => 'ok', 'abgelaufen' => 'warn', 'aus' => 'neutral'];
    $linkText = ['live' => 'Link live', 'abgelaufen' => 'Link abgelaufen', 'aus' => 'Kein Link'];
@endphp
<x-ui-page>
    <x-slot:navbar>
        <x-foodalchemist::shell.page-navbar title="Konfiguratoren" icon="heroicon-o-adjustments-horizontal" />
    </x-slot:navbar>

    <div class="flex-1 min-w-0 h-full overflow-y-auto px-4 py-4 space-y-3" data-fa-handy-konfiguratoren>
        @if($fehler)<x-fa::notice tone="crit">{{ $fehler }}</x-fa::notice>@endif
        @if($hinweis)<x-fa::notice tone="info" data-fa-handy-kf-hinweis>{{ $hinweis }}</x-fa::notice>@endif

        @if($k === null)
            <h1 class="text-[length:var(--fa-text-2xl)] font-semibold text-[var(--fa-ink)]">Konfiguratoren</h1>
            <input type="search" wire:model.live.debounce.300ms="suche" placeholder="Konfigurator suchen" aria-label="Konfiguratoren durchsuchen"
                   class="fa-control h-12 text-[length:var(--fa-text-base)]" data-fa-handy-suche>
            <ul class="flex flex-col {{ $karte }} divide-y divide-[var(--fa-line)]">
                @forelse($liste as $x)
                    <li wire:key="hkf-{{ $x['id'] }}">
                        <button type="button" wire:click="waehle({{ $x['id'] }})" class="flex w-full items-center gap-3 px-4 py-3 min-h-[60px] text-left" data-fa-handy-konfigurator="{{ $x['id'] }}">
                            <span class="min-w-0 flex-1">
                                <span class="block font-medium leading-snug text-[var(--fa-ink)] wrap-anywhere">{{ $x['titel'] ?: $x['name'] }}</span>
                                @if($x['titel'] && $x['titel'] !== $x['name'])<span class="block truncate {{ $leise }}">{{ $x['name'] }}</span>@endif
                                <span class="mt-1 flex flex-wrap gap-1">
                                    <x-fa::badge>{{ $statusWerte[$x['status']] ?? $x['status'] }}</x-fa::badge>
                                    <x-fa::badge :tone="$linkTon[$x['link']]">{{ $linkText[$x['link']] }}</x-fa::badge>
                                    @if($x['anfragen'] > 0)<x-fa::badge tone="accent" data-fa-handy-kf-anfragen="{{ $x['id'] }}">{{ $x['anfragen'] }} {{ $x['anfragen'] === 1 ? 'Anfrage' : 'Anfragen' }}</x-fa::badge>@endif
                                </span>
                            </span>
                            @svg('heroicon-m-chevron-right', 'w-5 h-5 shrink-0 text-[var(--fa-ink-3)]')
                        </button>
                    </li>
                @empty
                    <li class="p-4"><x-fa::empty compact icon="heroicon-o-adjustments-horizontal" title="Kein Konfigurator">Konfiguratoren legst du am Computer an.</x-fa::empty></li>
                @endforelse
            </ul>
        @else
            @php $kz = $kennzahlen; @endphp
            <button type="button" wire:click="handyZurListe" class="inline-flex items-center gap-1 min-h-11 {{ $leise }}" data-fa-handy-kf-zurueck>@svg('heroicon-m-chevron-left', 'w-4 h-4') Alle Konfiguratoren</button>
            <h1 class="text-[length:var(--fa-text-2xl)] font-semibold text-[var(--fa-ink)] wrap-anywhere">{{ $k->kunden_titel ?: $k->name }}</h1>
            <div class="grid grid-cols-2 gap-2" data-fa-handy-kf-kennzahlen>
                <div class="{{ $karte }} p-3"><p class="{{ $leise }}">All-in ab</p><p class="text-[length:var(--fa-text-lg)] font-semibold">{{ $euro($kz['ab']) }}</p></div>
                <div class="{{ $karte }} p-3"><p class="{{ $leise }}">Warnungen</p><p class="text-[length:var(--fa-text-lg)] font-semibold">{{ $kz['warnungen'] }}</p></div>
                <div class="{{ $karte }} p-3"><p class="{{ $leise }}">Konzepte</p><p class="font-semibold">{{ $kz['haupt'] }}{{ $kz['addons'] ? ' + ' . $kz['addons'] . ' Add-ons' : '' }}</p></div>
                <div class="{{ $karte }} p-3"><p class="{{ $leise }}">Schwächster DB</p><p class="font-semibold">{{ $kz['schwach'] !== null ? $euro($kz['schwach']['db']) : '–' }}</p></div>
            </div>
            @if($matrix['warnungen'] !== [])
                <details class="{{ $karte }} p-3"><summary class="font-medium">{{ count($matrix['warnungen']) }} Warnungen</summary>
                    <ul class="mt-2 list-disc pl-5 {{ $leise }}">@foreach($matrix['warnungen'] as $w)<li>{{ $w }}</li>@endforeach</ul></details>
            @endif

            <section class="{{ $karte }} p-3 space-y-3" data-fa-handy-kf-link>
                <h2 class="font-semibold">Kundenlink</h2>
                <p class="{{ $leise }}" data-fa-handy-kf-reihenfolge>Reihenfolge im Link: {{ ($k->reihenfolge ?? 'tageszeit_zuerst') === 'konzept_zuerst' ? 'Konzept zuerst' : 'Zeitfenster zuerst' }} (am Computer änderbar)</p>
                @if($live && $url)
                    <p class="flex flex-wrap items-center gap-2"><x-fa::badge tone="ok">Live bis {{ $k->presentation_expires_at?->format('d.m.Y') }}</x-fa::badge></p>
                    <p class="break-all {{ $leise }}" data-fa-handy-kf-url>{{ $url }}</p>
                    <div class="flex flex-wrap gap-2" x-data="{ kopiert: false }">
                        <x-fa::button icon="heroicon-m-share" x-on:click="navigator.share ? navigator.share({ title: @js($k->kunden_titel ?: $k->name), url: @js($url) }) : (navigator.clipboard.writeText(@js($url)), kopiert = true)" data-fa-handy-kf-teilen>Teilen</x-fa::button>
                        <x-fa::button variant="ghost" icon="heroicon-m-clipboard" x-on:click="navigator.clipboard.writeText(@js($url)); kopiert = true"><span x-text="kopiert ? 'Kopiert' : 'Link kopieren'">Link kopieren</span></x-fa::button>
                        <x-fa::button variant="ghost" icon="heroicon-m-arrow-top-right-on-square" :href="$url" target="_blank">Öffnen</x-fa::button>
                    </div>
                @else
                    <p class="{{ $leise }}">Nicht veröffentlicht.</p>
                @endif
                @if($eigen)
                    <x-fa::field label="Anfragen gehen an" for="hkf-verantw"><x-fa::select id="hkf-verantw" wire:model="handyLink.verantwortlich_user_id" :options="$nutzer" placeholder="Niemand" /></x-fa::field>
                    <x-fa::field label="Gültig bis" for="hkf-bis" hint="zugleich Ablauf des Links"><x-fa::input id="hkf-bis" type="date" wire:model="handyLink.gueltig_bis" /></x-fa::field>
                    <div class="flex flex-wrap gap-2">
                        <x-fa::button variant="primary" icon="heroicon-m-globe-alt" wire:click="handyVeroeffentlichen" data-fa-handy-kf-veroeffentlichen>{{ $live ? 'Neu veröffentlichen' : 'Veröffentlichen' }}</x-fa::button>
                        @if($live)<x-fa::button variant="danger" wire:click="handyZurueckziehen" wire:confirm="Link zurückziehen? Kunden sehen den Konfigurator dann nicht mehr." data-fa-handy-kf-zurueckziehen>Zurückziehen</x-fa::button>@endif
                    </div>
                @else
                    <p class="{{ $leise }}">Geerbt: veröffentlichen kann nur das Team, dem der Konfigurator gehört.</p>
                @endif
            </section>

            <section class="{{ $karte }} p-3 space-y-2" data-fa-handy-kf-anfragen-liste>
                <h2 class="font-semibold">Offene Anfragen <span class="{{ $leise }} tabular-nums">{{ $anfragen->count() }}</span></h2>
                @forelse($anfragen as $a)
                    <a wire:key="hkfa-{{ $a->id }}" href="{{ route('foodalchemist.angebote.index', ['sel' => $a->id]) }}" class="flex items-center gap-3 min-h-11 py-1" data-fa-handy-kf-anfrage="{{ $a->id }}">
                        <span class="min-w-0 flex-1"><span class="block font-medium wrap-anywhere">{{ $a->name }}</span>
                            <span class="block {{ $leise }}">{{ $a->created_at?->format('d.m. H:i') }} · {{ $a->personen ?? '–' }} Gäste · {{ $euro($a->total_price) }}</span></span>
                        @svg('heroicon-m-chevron-right', 'w-5 h-5 shrink-0 text-[var(--fa-ink-3)]')
                    </a>
                @empty
                    <p class="{{ $leise }}">Keine offenen Anfragen.</p>
                @endforelse
            </section>

            <section class="space-y-2" data-fa-handy-kf-preise>
                <h2 class="font-semibold">Preise je Paket</h2>
                @forelse($matrix['pakete'] as $p)
                    <details wire:key="hkfp-{{ $p['paket_id'] }}" class="{{ $karte }} p-3" @if($loop->first) open @endif>
                        <summary class="font-medium">{{ $p['name'] }} <span class="{{ $leise }}">· Referenz {{ $p['referenz_pax'] }}</span></summary>
                        <ul class="mt-2 divide-y divide-[var(--fa-line)]">
                            @foreach($p['faelle'] as $f)
                                <li class="flex items-baseline justify-between gap-3 py-1.5">
                                    <span class="min-w-0">{{ $f['name'] }}</span>
                                    <span class="text-right tabular-nums"><span class="font-semibold">{{ $euro($f['preis_pp']) }}</span><span class="block {{ $leise }}">DB {{ $euro($f['db_pp']) }}</span></span>
                                </li>
                            @endforeach
                        </ul>
                    </details>
                @empty
                    <p class="{{ $leise }}">Noch keine Pakete. Pakete legst du am Computer an.</p>
                @endforelse
            </section>
            <p class="{{ $leise }}">Mengen, Leistungen, Vorlagen und Branding bearbeitest du am Computer.</p>
        @endif
    </div>
</x-ui-page>
