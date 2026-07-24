{{-- Spec 19 E5.3 — Leitstelle-Rail (Nested-Livewire, rechte activity-Sidebar).
     Kontextsensitiv: Kopf-Modus = 3-Panel-Umschalter (Fortschritt/Speisen/Kalkulation,
     Alpine + localStorage-Pin); Kapitel-Modus = Kapitel-Planung (Ziele-Editing) +
     Coverage + Kalkulation + Ideen-Stand. --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))
@php($aktiv = 'bg-gradient-to-r from-violet-500/10 to-indigo-500/10 text-violet-700')
@php($hover = 'text-gray-600 hover:bg-black/[0.03]')
@php($weStil =['gruen' => 'text-emerald-600', 'gelb' => 'text-amber-600', 'rot' => 'text-red-600', 'unbekannt' => 'text-gray-400'])
@php($wePunkt = ['gruen' => 'bg-emerald-500', 'gelb' => 'bg-amber-500', 'rot' => 'bg-red-500', 'unbekannt' => 'bg-gray-300'])
@php($statusBadge = [
    'bepreist' => $variantPill['success'], 'angelegt' => $variantPill['primary'],
    'entwurf' => $variantPill['secondary'], 'ki_queue' => $variantPill['info'],
])

<div data-leitstelle-rail>
@if($modus === 'leer' || ! $fb)
    <div class="p-6 text-center text-sm text-gray-500">Foodbook auswählen.</div>

@elseif($modus === 'kapitel')
    {{-- ═══════════════ KAPITEL-PLANUNG ═══════════════ --}}
    <div class="p-4 space-y-4" data-rail-kapitel data-fb-anker="kapitel-rail">
        <div>
            <p class="{{ $label }}">Kapitel-Planung</p>
            <p class="text-sm font-medium text-gray-900 truncate">{{ $stand['titel'] ?? '—' }}</p>
        </div>

        {{-- Zielgruppen-Chips (Stempel; Kapitel schlägt Foodbook-Default in der Kaskade) --}}
        <div class="space-y-1.5">
            <span class="{{ $label }}">Zielgruppen</span>
            <div class="flex flex-wrap gap-1" data-rail-zielgruppen>
                @forelse($zielgruppenVokab as $z)
                    @php($an = in_array($z->id, $zielgruppenIds, true))
                    <button type="button" wire:click="zielgruppeToggle({{ $z->id }})" wire:key="rzg-{{ $z->id }}"
                            class="inline-flex px-2 py-0.5 rounded-full text-[11px] border transition-colors {{ $an ? 'bg-violet-500/10 border-violet-500/30 text-violet-700' : 'bg-black/[0.03] border-black/10 text-gray-500 hover:bg-black/[0.06]' }}"
                            data-an="{{ $an ? '1' : '0' }}">{{ $z->name }}</button>
                @empty
                    <span class="text-[11px] text-gray-400">Kein Zielgruppen-Vokabular — in den Einstellungen pflegen.</span>
                @endforelse
            </div>
        </div>

        {{-- Ziele-Editing (M3-Spalten) --}}
        <div class="space-y-2" data-rail-ziele>
            <div class="grid grid-cols-2 gap-2">
                <div>
                    <label class="{{ $label }}">Niveau</label>
                    <select wire:model="ziel.niveau" class="{{ $input }}">
                        <option value="">— erben —</option>
                        @foreach($niveauLabels as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label class="{{ $label }}">Preis-Modus</label>
                    <select wire:model="ziel.pricing_mode" class="{{ $input }}">
                        <option value="">— offen —</option>
                        @foreach($pricingModes as $pm)<option value="{{ $pm }}">{{ ucfirst($pm) }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label class="{{ $label }}">Einsatzmoment</label>
                    <select wire:model="ziel.service_moment_id" class="{{ $input }}">
                        <option value="">— erben —</option>
                        @foreach($einsatzmomente as $e)<option value="{{ $e->id }}">{{ $e->name }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label class="{{ $label }}">Servierform</label>
                    <select wire:model="ziel.serving_form_id" class="{{ $input }}">
                        <option value="">— erben —</option>
                        @foreach($servierformen as $s)<option value="{{ $s->id }}">{{ $s->label }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label class="{{ $label }}">Mengenziel (Positionen)</label>
                    <input type="number" min="0" step="1" wire:model="ziel.target_count" class="{{ $input }}" placeholder="—" />
                </div>
                <div>
                    <label class="{{ $label }}">WE-Ziel %</label>
                    <input type="number" min="0" step="0.1" wire:model="ziel.target_food_cost_pct" class="{{ $input }}" placeholder="—" />
                </div>
                <div>
                    <label class="{{ $label }}">Preis-Anker €</label>
                    <input type="number" min="0" step="0.01" wire:model="ziel.price_anchor" class="{{ $input }}" placeholder="—" />
                </div>
                <div class="grid grid-cols-2 gap-1">
                    <div><label class="{{ $label }}">min €</label><input type="number" min="0" step="0.01" wire:model="ziel.price_min" class="{{ $input }}" placeholder="—" /></div>
                    <div><label class="{{ $label }}">max €</label><input type="number" min="0" step="0.01" wire:model="ziel.price_max" class="{{ $input }}" placeholder="—" /></div>
                </div>
            </div>
            <button type="button" wire:click="zieleSpeichern" class="{{ $btnPrimary }} w-full justify-center" data-rail-ziele-speichern>Ziele speichern</button>
        </div>

        {{-- Kapitel-Kalkulation --}}
        @if($stand)
            <div class="pt-3 border-t border-black/5 space-y-1 text-xs" data-rail-kalk>
                <span class="{{ $label }}">Kalkulation</span>
                <div class="flex justify-between"><span class="text-gray-600">€/Person</span><span class="tabular-nums">{{ number_format($stand['aggregat']['vk_pro_person'], 2, ',', '.') }} €</span></div>
                <div class="flex justify-between"><span class="text-gray-600">EK/Person</span><span class="tabular-nums">{{ number_format($stand['aggregat']['ek_per_person'], 2, ',', '.') }} €</span></div>
                @php($we = $stand['wareneinsatz'])
                <div class="flex justify-between items-center">
                    <span class="text-gray-600">Wareneinsatz</span>
                    <span class="inline-flex items-center gap-1 {{ $weStil[$we['status']] ?? '' }}">
                        <span class="w-1.5 h-1.5 rounded-full {{ $wePunkt[$we['status']] ?? 'bg-gray-300' }}"></span>
                        <span class="tabular-nums">{{ $we['ist_pct'] !== null ? number_format($we['ist_pct'], 1, ',', '.') . ' %' : '—' }}</span>
                        <span class="text-gray-400">/ Ziel {{ number_format($we['ziel_pct'], 1, ',', '.') }} %</span>
                    </span>
                </div>
                @if($we['partiell'])<p class="text-[10px] text-amber-600">⚠ partiell — Pauschal-Blöcke ohne EK, IST unterschätzt.</p>@endif
            </div>
        @endif

        {{-- Kapitel-Coverage (Scope = Kapitel + Nachfahren) --}}
        @if(! empty($befunde))
            <div class="pt-3 border-t border-black/5 space-y-1" data-rail-coverage>
                <span class="{{ $label }}">Coverage</span>
                @foreach($befunde as $b)
                    @php($amp = ['erfuellt' => $variantPill['success'], 'teilerfuellt' => $variantPill['warning'], 'verletzt' => $variantPill['danger'], 'info' => $variantPill['info']][$b['ampel']] ?? $variantPill['secondary'])
                    <div class="flex items-start justify-between gap-2 text-[11px]">
                        <span class="text-gray-600">{{ $b['label'] }}</span>
                        <span class="{{ $pill }} {{ $amp }} shrink-0">{{ $b['ist'] }}</span>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- Ideen-Stand + Anlegen-Shortcut (Kapitel-Go = E7, hier deaktiviert) --}}
        @if($stand)
            <div class="pt-3 border-t border-black/5 space-y-2" data-rail-ideen>
                <div class="flex items-center justify-between text-xs">
                    <span class="{{ $label }} !mb-0">Inhalt / Ideen</span>
                    <span class="text-gray-500">{{ $stand['inhalt']['pakete'] }} Paket · {{ $stand['inhalt']['einzel'] }} Einzel · {{ $stand['inhalt']['ideen'] }} Ideen</span>
                </div>
                @if($stand['released'])
                    <p class="text-[11px] text-emerald-600" data-rail-released>✓ Kapitel angelegt.</p>
                @else
                    <button type="button" disabled title="Kapitel anlegen (Konzepte/Blöcke aus Ideen) — folgt E7" class="{{ $btnGhostXs }} w-full justify-center opacity-50 cursor-not-allowed" data-rail-go>Anlegen (folgt E7)</button>
                @endif
            </div>
        @endif
    </div>

@else
    {{-- ═══════════════ KOPF-MODUS: 3-Panel-Umschalter ═══════════════ --}}
    {{-- Auto-Default je Cockpit-Tab NUR ohne manuellen Pin (localStorage). Der Cockpit-Root
         dispatcht `fb-cockpit-tab` beim Tab-Wechsel; ohne Pin folgt die Rail der tabMap. --}}
    <div class="p-4 space-y-3"
         x-data="{
            pin: localStorage.getItem('fbRailPin') || null,
            panel: 'fortschritt',
            tabMap: { briefing:'fortschritt', planung:'fortschritt', kreativ:'speisen', vorschau:'speisen', preise:'kalkulation', trend:'fortschritt', branding:'fortschritt' },
            init() { this.panel = this.pin || 'fortschritt'; },
            setPanel(p) { this.panel = p; this.pin = p; localStorage.setItem('fbRailPin', p); },
            loesePin() { this.pin = null; localStorage.removeItem('fbRailPin'); },
         }"
         @fb-cockpit-tab.window="if (!pin && tabMap[$event.detail.tab]) panel = tabMap[$event.detail.tab]"
         data-rail-kopf>

        {{-- Umschalter --}}
        <div class="flex items-center gap-1" role="tablist" data-rail-umschalter>
            @foreach(['fortschritt' => 'Fortschritt', 'speisen' => 'Speisen', 'kalkulation' => 'Kalkulation'] as $pk => $pl)
                <button type="button" @click="setPanel(@js($pk))"
                        :class="panel === @js($pk) ? '{{ $aktiv }}' : '{{ $hover }}'"
                        class="px-2.5 py-1 rounded-lg text-[11px] font-medium transition-colors" data-rail-panel-btn="{{ $pk }}">{{ $pl }}</button>
            @endforeach
            <button type="button" x-show="pin" x-cloak @click="loesePin()" class="ml-auto text-[10px] text-gray-400 hover:text-violet-500" title="Auto-Umschaltung je Tab wieder aktivieren">📌 lösen</button>
        </div>

        {{-- ── Panel: FORTSCHRITT ── --}}
        {{-- Der volle 7-Chip-Strip lebt in der Tab-Leiste (E5.2, aus allen Tabs sichtbar) —
             hier nur ein kompakter Zähler, um Doppel-Darstellung zu vermeiden. --}}
        @php($erledigt = collect($checkliste)->where('status', 'erledigt')->count())
        @php($teil = collect($checkliste)->where('status', 'teil')->count())
        <div x-show="panel === 'fortschritt'" x-cloak class="space-y-3" data-rail-fortschritt>
            <div class="flex items-center justify-between text-[11px]" data-rail-progress>
                <span class="{{ $label }} !mb-0">Fortschritt</span>
                <span class="text-gray-600">{{ $erledigt }}/{{ count($checkliste) }} erledigt @if($teil) · {{ $teil }} teil @endif</span>
            </div>

            @if($komplex)
                <p class="text-[11px] text-amber-600 bg-amber-500/5 rounded-lg px-2 py-1" data-rail-komplex>
                    ⚠ Komplex: {{ $kapitelAnzahl }} Kapitel · {{ $positionenGesamt }} Positionen — Struktur prüfen (Bündeln/Unterkapitel).
                </p>
            @endif

            <div class="space-y-1" data-rail-matrix>
                <span class="{{ $label }}">Kapitel-Matrix</span>
                @forelse($matrix as $m)
                    @php($we = $m['wareneinsatz'])
                    <div class="flex items-center gap-2 text-[11px] py-0.5" wire:key="rm-{{ $m['kapitel_id'] }}" style="padding-left: {{ ($m['depth'] - 1) * 10 }}px">
                        <span class="w-1.5 h-1.5 rounded-full shrink-0 {{ $wePunkt[$we['status']] ?? 'bg-gray-300' }}" title="Wareneinsatz {{ $we['status'] }}"></span>
                        <span class="flex-1 min-w-0 truncate text-gray-700">{{ $m['titel'] }}</span>
                        <span class="shrink-0 flex items-center gap-0.5">
                            <span class="{{ $pill }} {{ $m['hat_ziele'] ? $variantPill['primary'] : $variantPill['secondary'] }}" title="Ziele/Dimensionen">{{ $m['hat_ziele'] ? 'Z' : '·' }}</span>
                            <span class="{{ $pill }} {{ $m['positionen'] > 0 ? $variantPill['info'] : $variantPill['secondary'] }}" title="Positionen">{{ $m['positionen'] }}</span>
                            <span class="{{ $pill }} {{ $m['bepreist'] ? $variantPill['success'] : ($m['hat_inhalt'] ? $variantPill['warning'] : $variantPill['secondary']) }}" title="{{ $m['bepreist'] ? 'bepreist' : ($m['hat_inhalt'] ? 'angelegt/ohne Preis' : 'leer') }}">€</span>
                        </span>
                        @if($m['released'])
                            <span class="text-emerald-500 text-[10px] shrink-0" title="angelegt">✓</span>
                        @else
                            <button type="button" disabled title="Kapitel anlegen — folgt E7" class="text-gray-300 text-[10px] shrink-0 cursor-not-allowed" data-rail-matrix-go>Go</button>
                        @endif
                    </div>
                @empty
                    <p class="text-[11px] text-gray-400">Noch keine Kapitel.</p>
                @endforelse
            </div>
        </div>

        {{-- ── Panel: SPEISEN (heterogener Baum) ── --}}
        <div x-show="panel === 'speisen'" x-cloak class="space-y-2" data-rail-speisen>
            @forelse($baum as $k)
                <div wire:key="rb-{{ $k['kapitel_id'] }}" style="padding-left: {{ ($k['depth'] - 1) * 8 }}px">
                    <div class="flex items-center gap-1 text-[11px] font-medium text-gray-700">
                        <span class="truncate">{{ $k['titel'] }}</span>
                        @if($k['released'])<span class="text-emerald-500 text-[10px]">✓</span>@endif
                    </div>
                    @foreach($k['positionen'] as $p)
                        <div class="flex items-center gap-1.5 text-[11px] pl-2 py-px">
                            <span class="{{ $pill }} {{ $statusBadge[$p['status']] ?? $variantPill['secondary'] }} shrink-0">{{ ['paket' => 'Paket', 'einzel' => 'Einzel', 'idee' => 'Idee'][$p['art']] ?? $p['art'] }}</span>
                            <span class="flex-1 min-w-0 truncate text-gray-600">{{ $p['label'] }}</span>
                            @if($p['preis'] !== null)
                                <span class="shrink-0 tabular-nums text-gray-500">{{ number_format($p['preis'], 2, ',', '.') }} €{{ $p['preis_einheit'] === 'gast' ? '/G' : '/Pos' }}</span>
                            @endif
                        </div>
                    @endforeach
                    @if(empty($k['positionen']))<p class="text-[10px] text-gray-400 pl-2">leer</p>@endif
                </div>
            @empty
                <p class="text-[11px] text-gray-400">Noch keine Kapitel.</p>
            @endforelse
        </div>

        {{-- ── Panel: KALKULATION (Portfolio + WE-Ampel je Kapitel) ── --}}
        <div x-show="panel === 'kalkulation'" x-cloak class="space-y-3" data-rail-kalkulation>
            <div class="text-center py-1">
                <div class="text-2xl font-semibold text-gray-900 tabular-nums">{{ number_format($gesamt['vk_pro_person'], 2, ',', '.') }} €</div>
                <div class="{{ $label }}">pro Person · EK {{ number_format($gesamt['ek_per_person'], 2, ',', '.') }} €</div>
                @if($gesamt['gesamt_vk'] !== null)
                    <div class="text-[11px] text-gray-500 mt-0.5">{{ $gesamt['personen'] }} Gäste · gesamt {{ number_format($gesamt['gesamt_vk'], 2, ',', '.') }} €</div>
                @else
                    <div class="text-[11px] text-gray-400 mt-0.5">Pax + Gesamtpreis liegen im Angebot.</div>
                @endif
            </div>
            <div class="space-y-0.5" data-rail-we-matrix>
                <span class="{{ $label }}">Wareneinsatz je Kapitel</span>
                @forelse($matrix as $m)
                    @php($we = $m['wareneinsatz'])
                    <div class="flex items-center gap-2 text-[11px] py-0.5" wire:key="rwe-{{ $m['kapitel_id'] }}" style="padding-left: {{ ($m['depth'] - 1) * 10 }}px">
                        <span class="flex-1 min-w-0 truncate text-gray-600">{{ $m['titel'] }}</span>
                        <span class="inline-flex items-center gap-1 shrink-0 {{ $weStil[$we['status']] ?? '' }}">
                            <span class="w-1.5 h-1.5 rounded-full {{ $wePunkt[$we['status']] ?? 'bg-gray-300' }}"></span>
                            <span class="tabular-nums">{{ $we['ist_pct'] !== null ? number_format($we['ist_pct'], 1, ',', '.') . '%' : '—' }}</span>
                            @if($we['partiell'])<span title="partiell — Pauschal ohne EK">⚠</span>@endif
                        </span>
                    </div>
                @empty
                    <p class="text-[11px] text-gray-400">Noch keine Kapitel.</p>
                @endforelse
            </div>
        </div>
    </div>
@endif
</div>
