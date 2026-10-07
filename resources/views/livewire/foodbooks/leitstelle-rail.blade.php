{{-- Spec 19 E5.3 — Leitstelle-Rail (Nested-Livewire, rechte Seitenleiste).
     Kontextsensitiv: Kopf-Modus = 3-Panel-Umschalter (Fortschritt/Speisen/Kalkulation,
     Alpine + localStorage-Pin); Kapitel-Modus = Kuration/QC des Kapitels (Kalkulation + Abdeckung).
     fa-pass: Tokens und x-fa-Bausteine (hell und Werkbank), Zustände als Wort statt Kürzel. --}}
@php
    $aktiv = 'bg-[var(--fa-accent-soft)] text-[var(--fa-accent)]';
    $hover = 'text-[var(--fa-ink-2)] hover:bg-[var(--fa-hover)] hover:text-[var(--fa-ink)]';
    $weStil = ['gruen' => 'text-[var(--fa-ok)]', 'gelb' => 'text-[var(--fa-warn)]', 'rot' => 'text-[var(--fa-crit)]', 'unbekannt' => 'text-[var(--fa-ink-3)]'];
    $wePunkt = ['gruen' => 'bg-[var(--fa-ok)]', 'gelb' => 'bg-[var(--fa-warn)]', 'rot' => 'bg-[var(--fa-crit)]', 'unbekannt' => 'bg-[var(--fa-line-strong)]'];
    $weWort = ['gruen' => 'im Ziel', 'gelb' => 'knapp am Ziel', 'rot' => 'über dem Ziel', 'unbekannt' => 'nicht berechenbar'];
    $statusTon = ['bepreist' => 'ok', 'angelegt' => 'accent', 'entwurf' => 'neutral', 'ki_queue' => 'info'];
    $befundTon = ['erfuellt' => 'ok', 'teilerfuellt' => 'warn', 'verletzt' => 'crit', 'info' => 'info'];
    $artText = ['paket' => 'Paket', 'einzel' => 'Einzel', 'idee' => 'Idee'];
    $titel = 'text-[length:var(--fa-text-sm)] font-semibold text-[var(--fa-ink-2)]';
    $zeile = 'text-[length:var(--fa-text-md)]';
    $leer = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]';
    $pct = fn ($wert, $stellen = 1) => $wert !== null ? number_format((float) $wert, $stellen, ',', '.') . ' %' : '–';
    $euro = fn ($wert) => number_format((float) $wert, 2, ',', '.') . ' €';
@endphp

<div data-leitstelle-rail>
@if($modus === 'leer' || ! $fb)
    <x-fa::empty icon="heroicon-o-book-open" title="Kein Foodbook gewählt" compact class="px-4">Ein Foodbook auswählen, dann stehen hier Stand und Kalkulation.</x-fa::empty>

@elseif($modus === 'kapitel')
    {{-- ═══════════════ KAPITEL: Kuration und Prüfung ═══════════════ --}}
    <div class="p-4 flex flex-col gap-4" data-rail-kapitel data-fb-anker="kapitel-rail">
        <div class="min-w-0">
            <p class="{{ $titel }}">Kapitel</p>
            <p class="text-[length:var(--fa-text-base)] font-semibold text-[var(--fa-ink)] break-words">{{ $stand['titel'] ?? 'Ohne Titel' }}</p>
        </div>

        {{-- S3b: Zielgruppen-Stempel + M3-Ziele-Editor → Leitstelle (Planung\KapitelRail). Hier bleibt nur
             Kuration/QC (Kalkulation + Coverage). --}}

        {{-- Kapitel-Kalkulation --}}
        @if($stand)
            @php $we = $stand['wareneinsatz']; @endphp
            <section class="flex flex-col gap-1.5 pt-3 border-t border-[var(--fa-line)]" data-rail-kalk>
                <h3 class="{{ $titel }}">Kalkulation</h3>
                <dl class="flex flex-col gap-1 {{ $zeile }}">
                    <div class="flex items-baseline justify-between gap-2"><dt class="text-[var(--fa-ink-2)]">Verkauf pro Person</dt><dd class="tabular-nums font-semibold text-[var(--fa-ink)]">{{ $euro($stand['aggregat']['vk_pro_person']) }}</dd></div>
                    <div class="flex items-baseline justify-between gap-2"><dt class="text-[var(--fa-ink-2)]">Wareneinsatz pro Person</dt><dd class="tabular-nums text-[var(--fa-ink)]">{{ $euro($stand['aggregat']['ek_per_person']) }}</dd></div>
                    <div class="flex items-baseline justify-between gap-2">
                        <dt class="text-[var(--fa-ink-2)]">Wareneinsatz-Quote</dt>
                        <dd class="inline-flex items-center gap-1.5 {{ $weStil[$we['status']] ?? '' }}" title="{{ $weWort[$we['status']] ?? '' }}">
                            <span class="w-2 h-2 rounded-full {{ $wePunkt[$we['status']] ?? $wePunkt['unbekannt'] }}"></span>
                            <span class="tabular-nums font-medium">{{ $pct($we['ist_pct']) }}</span>
                            <span class="text-[var(--fa-ink-3)] tabular-nums">Ziel {{ $pct($we['ziel_pct']) }}</span>
                        </dd>
                    </div>
                </dl>
                @if($we['partiell'])<x-fa::signal tone="warn">Pauschal-Positionen ohne Einkaufspreis. Die Quote ist zu niedrig.</x-fa::signal>@endif
            </section>
        @endif

        {{-- Kapitel-Abdeckung (Scope = Kapitel + Nachfahren) --}}
        @if(! empty($befunde))
            <section class="flex flex-col gap-1.5 pt-3 border-t border-[var(--fa-line)]" data-rail-coverage>
                <h3 class="{{ $titel }}">Abdeckung der Vorgaben</h3>
                @foreach($befunde as $b)
                    <div class="flex items-start justify-between gap-2 {{ $zeile }}">
                        <span class="min-w-0 text-[var(--fa-ink-2)] break-words">{{ $b['label'] }}</span>
                        <x-fa::badge :tone="$befundTon[$b['ampel']] ?? 'neutral'" class="shrink-0">{{ $b['ist'] }}</x-fa::badge>
                    </div>
                @endforeach
            </section>
        @endif

        {{-- S3b: Ideen-Stand + Kapitel-Go „Anlegen" (kapitelFreigeben-Bypass) + Anlage/Undo-Modals ENTFERNT.
             Die Erzeugung läuft jetzt über die Leitstelle-Kaskade (Planung\KapitelRail → starteKapitelKaskade). --}}
    </div>

@else
    {{-- ═══════════════ KOPF-MODUS: 3-Panel-Umschalter ═══════════════ --}}
    {{-- Auto-Default je Cockpit-Tab NUR ohne manuellen Pin (localStorage). Der Cockpit-Root
         dispatcht `fb-cockpit-tab` beim Tab-Wechsel; ohne Pin folgt die Rail der tabMap. --}}
    <div class="p-4 flex flex-col gap-4"
         x-data="{
            pin: localStorage.getItem('fbRailPin') || null,
            panel: 'fortschritt',
            tabMap: { briefing:'fortschritt', speisen:'speisen', preise:'kalkulation', branding:'fortschritt' },
            init() { this.panel = this.pin || 'fortschritt'; },
            setPanel(p) { this.panel = p; this.pin = p; localStorage.setItem('fbRailPin', p); },
            loesePin() { this.pin = null; localStorage.removeItem('fbRailPin'); },
         }"
         @fb-cockpit-tab.window="if (!pin && tabMap[$event.detail.tab]) panel = tabMap[$event.detail.tab]"
         data-rail-kopf>

        {{-- Umschalter --}}
        <div class="flex items-center gap-1 p-0.5 rounded-[var(--fa-radius-control)] bg-[var(--fa-neutral-soft)]" role="tablist" data-rail-umschalter>
            @foreach(['fortschritt' => 'Fortschritt', 'speisen' => 'Speisen', 'kalkulation' => 'Kalkulation'] as $pk => $pl)
                <button type="button" role="tab" @click="setPanel(@js($pk))"
                        :class="panel === @js($pk) ? '{{ $aktiv }}' : '{{ $hover }}'"
                        :aria-selected="panel === @js($pk)"
                        class="flex-1 h-7 px-2 rounded-[var(--fa-radius-control)] text-[length:var(--fa-text-sm)] font-medium transition-colors duration-150" data-rail-panel-btn="{{ $pk }}">{{ $pl }}</button>
            @endforeach
        </div>
        <button type="button" x-show="pin" x-cloak @click="loesePin()"
                class="-mt-2 self-end inline-flex items-center gap-1 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] hover:text-[var(--fa-ink)]"
                title="Die Ansicht wechselt dann wieder mit dem Reiter im Editor">@svg('heroicon-m-map-pin', 'w-3.5 h-3.5') Ansicht nicht mehr festhalten</button>

        {{-- ── Panel: FORTSCHRITT ── --}}
        {{-- S3b: Fortschritt-Zähler (Checkliste) + Komplex-Hinweis entfallen (Planung → Leitstelle).
             Die Kapitel-Matrix (Kuration/Status-Übersicht) bleibt. --}}
        <div x-show="panel === 'fortschritt'" x-cloak class="flex flex-col gap-3" data-rail-fortschritt>
            <section class="flex flex-col gap-1" data-rail-matrix>
                <h3 class="{{ $titel }}">Kapitel im Überblick</h3>
                @forelse($matrix as $m)
                    @php $we = $m['wareneinsatz']; @endphp
                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1 py-1.5 border-b border-[var(--fa-line)] last:border-b-0 {{ $zeile }}" wire:key="rm-{{ $m['kapitel_id'] }}" style="padding-left: {{ ($m['depth'] - 1) * 10 }}px">
                        <span class="w-2 h-2 rounded-full shrink-0 {{ $wePunkt[$we['status']] ?? $wePunkt['unbekannt'] }}" title="Wareneinsatz {{ $weWort[$we['status']] ?? '' }}"></span>
                        <span class="flex-1 min-w-[8rem] break-words text-[var(--fa-ink)]">{{ $m['titel'] }}</span>
                        <span class="shrink-0 flex flex-wrap items-center gap-1">
                            @if($m['hat_ziele'])<x-fa::badge tone="accent" title="Vorgaben für dieses Kapitel gesetzt">Vorgaben</x-fa::badge>@endif
                            <x-fa::badge :tone="$m['positionen'] > 0 ? 'info' : 'neutral'">{{ $m['positionen'] }} {{ (int) $m['positionen'] === 1 ? 'Position' : 'Positionen' }}</x-fa::badge>
                            @if($m['bepreist'])
                                <x-fa::badge tone="ok">Bepreist</x-fa::badge>
                            @elseif($m['hat_inhalt'])
                                <x-fa::badge tone="warn">Preis fehlt</x-fa::badge>
                            @endif
                        </span>
                        @if($m['released'])
                            <span class="shrink-0 text-[var(--fa-ok)]" title="Angelegt">@svg('heroicon-m-check-circle', 'w-4 h-4')</span>
                        @else
                            {{-- Shortcut: Kapitel selektieren → Rail flippt in den Kapitel-Modus (E7.5). --}}
                            <button type="button" wire:click="$parent.kapitelWaehle({{ $m['kapitel_id'] }})" title="Kapitel öffnen, um es anzulegen"
                                    class="shrink-0 text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-accent)] hover:text-[var(--fa-accent-hover)]" data-rail-matrix-go>Öffnen</button>
                        @endif
                    </div>
                @empty
                    <p class="{{ $leer }}">Noch keine Kapitel.</p>
                @endforelse
            </section>
        </div>

        {{-- ── Panel: SPEISEN (Baum je Kapitel) ── --}}
        <div x-show="panel === 'speisen'" x-cloak class="flex flex-col gap-3" data-rail-speisen>
            @forelse($baum as $k)
                <div class="flex flex-col gap-1" wire:key="rb-{{ $k['kapitel_id'] }}" style="padding-left: {{ ($k['depth'] - 1) * 8 }}px">
                    <div class="flex items-center gap-1.5 text-[length:var(--fa-text-md)] font-semibold text-[var(--fa-ink)]">
                        <span class="min-w-0 break-words">{{ $k['titel'] }}</span>
                        @if($k['released'])<span class="shrink-0 text-[var(--fa-ok)]" title="Angelegt">@svg('heroicon-m-check-circle', 'w-4 h-4')</span>@endif
                    </div>
                    @foreach($k['positionen'] as $p)
                        <div class="flex items-start gap-1.5 pl-2 {{ $zeile }}">
                            <x-fa::badge :tone="$statusTon[$p['status']] ?? 'neutral'" class="shrink-0">{{ $artText[$p['art']] ?? ucfirst((string) $p['art']) }}</x-fa::badge>
                            <span class="flex-1 min-w-0 break-words text-[var(--fa-ink-2)]">{{ $p['label'] }}</span>
                            @if($p['preis'] !== null)
                                <span class="shrink-0 tabular-nums text-[var(--fa-ink-2)]">{{ $euro($p['preis']) }}<span class="text-[var(--fa-ink-3)]">{{ $p['preis_einheit'] === 'gast' ? ' pro Gast' : ' pro Position' }}</span></span>
                            @endif
                        </div>
                    @endforeach
                    @if(empty($k['positionen']))<p class="pl-2 {{ $leer }}">Noch leer</p>@endif
                </div>
            @empty
                <p class="{{ $leer }}">Noch keine Kapitel.</p>
            @endforelse
        </div>

        {{-- ── Panel: KALKULATION (Portfolio + WE-Ampel je Kapitel) ── --}}
        <div x-show="panel === 'kalkulation'" x-cloak class="flex flex-col gap-3" data-rail-kalkulation>
            <div class="fa-surface px-4 py-3 text-center">
                <p class="text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]">Verkauf pro Person</p>
                <p class="text-[length:var(--fa-text-2xl)] font-semibold tracking-tight text-[var(--fa-accent)] tabular-nums">{{ $euro($gesamt['vk_pro_person']) }}</p>
                <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)] tabular-nums">Wareneinsatz {{ $euro($gesamt['ek_per_person']) }}</p>
                @if($gesamt['gesamt_vk'] !== null)
                    <p class="mt-1 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)] tabular-nums">{{ $gesamt['personen'] }} Gäste, zusammen {{ $euro($gesamt['gesamt_vk']) }}</p>
                @else
                    <p class="mt-1 {{ $leer }}">Gästezahl und Gesamtpreis stehen im Angebot.</p>
                @endif
            </div>
            {{-- Portfolio-WE-Ampel (E8.2): Gesamt-Wareneinsatz des Foodbooks gegen Ziel + Toleranz. --}}
            <div class="flex items-center justify-between gap-2 rounded-[var(--fa-radius-control)] bg-[var(--fa-neutral-soft)] px-3 py-2" data-rail-we-portfolio>
                <span class="{{ $titel }}">Wareneinsatz gesamt</span>
                <span class="inline-flex items-center gap-1.5 text-[length:var(--fa-text-md)] font-medium {{ $weStil[$weGesamt['status']] ?? '' }}"
                      title="Ist {{ $pct($weGesamt['ist_pct']) }}, Ziel {{ $pct($weGesamt['ziel_pct']) }} (Spielraum {{ number_format((float) $weGesamt['toleranz_pp'], 1, ',', '.') }} Prozentpunkte){{ $weGesamt['partiell'] ? '. Pauschal-Positionen ohne Einkaufspreis sind nicht mitgezählt.' : '' }}">
                    <span class="w-2 h-2 rounded-full {{ $wePunkt[$weGesamt['status']] ?? $wePunkt['unbekannt'] }}"></span>
                    <span class="tabular-nums">{{ $pct($weGesamt['ist_pct']) }}</span>
                    <span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] tabular-nums">Ziel {{ $pct($weGesamt['ziel_pct'], 0) }}</span>
                    @if($weGesamt['partiell'])@svg('heroicon-m-exclamation-triangle', 'w-4 h-4 text-[var(--fa-warn)]')@endif
                </span>
            </div>
            <section class="flex flex-col gap-1" data-rail-we-matrix>
                <h3 class="{{ $titel }}">Wareneinsatz je Kapitel</h3>
                @forelse($matrix as $m)
                    @php $we = $m['wareneinsatz']; @endphp
                    <div class="flex items-center gap-2 py-1 {{ $zeile }}" wire:key="rwe-{{ $m['kapitel_id'] }}" style="padding-left: {{ ($m['depth'] - 1) * 10 }}px">
                        <span class="flex-1 min-w-0 break-words text-[var(--fa-ink-2)]">{{ $m['titel'] }}</span>
                        <span class="inline-flex items-center gap-1 shrink-0 {{ $weStil[$we['status']] ?? '' }}" title="{{ $weWort[$we['status']] ?? '' }}">
                            <span class="w-2 h-2 rounded-full {{ $wePunkt[$we['status']] ?? $wePunkt['unbekannt'] }}"></span>
                            <span class="tabular-nums">{{ $pct($we['ist_pct']) }}</span>
                            @if($we['partiell'])<span title="Pauschal-Positionen ohne Einkaufspreis">@svg('heroicon-m-exclamation-triangle', 'w-4 h-4 text-[var(--fa-warn)]')</span>@endif
                        </span>
                    </div>
                @empty
                    <p class="{{ $leer }}">Noch keine Kapitel.</p>
                @endforelse
            </section>
        </div>
    </div>
@endif
</div>
