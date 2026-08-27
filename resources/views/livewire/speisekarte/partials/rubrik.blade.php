{{-- Rekursive Rubrik-Zeile: Titel + Positionen + Gericht-Picker + Unter-Rubriken.
     Erwartet: $rubrik, $depth, $karte, $preise, $pickerRubrikId, $pickerErgebnisse (+ Ui-Maps). --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))
@php($artLabel = ['speisen' => 'Speisen', 'getraenke' => 'Getränke', 'menue' => 'Menü', 'dessert' => 'Dessert', 'sonstiges' => 'Sonstiges'])
{{-- Board-Integration (Dominique 2026-08-27): Σ-Rollup (EK/VK/WE) je Rubrik + Collapse-Chevron.
     WE-Ampel wie im Cockpit (≤30 grün · ≤38 amber · >38 rot). $rubrikAgg erbt aus der Render-Scope. --}}
@php($agg = ($rubrikAgg ?? [])[$rubrik->id] ?? null)
@php($weTon = fn ($we) => $we === null ? 'text-gray-400' : ($we <= 30 ? 'text-emerald-600' : ($we <= 38 ? 'text-amber-600' : 'text-rose-600')))

<div wire:key="sk-rubrik-{{ $rubrik->id }}" class="mb-2" style="margin-left: {{ $depth * 1.25 }}rem">
    {{-- Werkstrang M (UX-Ausbau): Rubrik-Header = Drag-Handle (Rubrik umsortieren) + Drop-Ziel
         (Rubrik ablegen ODER eine gezogene Position in diese Rubrik verschieben). --}}
    <div class="flex items-center gap-2 border-b border-black/10 pb-1 mb-1.5"
         x-on:dragover.prevent
         x-on:drop="if (dragPosId) { $wire.positionInRubrik(dragPosId, {{ $rubrik->id }}); dragPosId = null } else if (dragRubrikId && dragRubrikId !== {{ $rubrik->id }}) { $wire.rubrikAblegen(dragRubrikId, {{ $rubrik->id }}); dragRubrikId = null }">
        <button type="button" class="shrink-0 w-4 text-center text-gray-400 hover:text-gray-700" title="Rubrik auf/zu"
                @click="zu = {...zu, {{ $rubrik->id }}: !zu[{{ $rubrik->id }}]}" x-text="zu[{{ $rubrik->id }}] ? '▸' : '▾'">▾</button>
        <span class="cursor-move select-none text-gray-300 hover:text-gray-500 shrink-0" title="Ziehen zum Umsortieren"
              draggable="true" x-on:dragstart="dragRubrikId = {{ $rubrik->id }}" x-on:dragend="dragRubrikId = null">⠿</span>
        <span class="font-semibold text-gray-900 text-sm">{{ $rubrik->consumer_title ?: $rubrik->title }}</span>
        <span class="{{ $pill }} {{ $variantPill['secondary'] }}">{{ $artLabel[$rubrik->art] ?? $rubrik->art }}</span>
        @if($agg && $agg['n'] > 0)
            <span class="text-[10px] text-gray-400 tabular-nums whitespace-nowrap" title="Σ VK · Σ EK · Ø Wareneinsatz (inkl. Unter-Rubriken)" data-sk-rubrik-agg>Σ {{ number_format($agg['vk'], 2, ',', '.') }} € · EK {{ number_format($agg['ek'], 2, ',', '.') }} € · <span class="{{ $weTon($agg['we']) }}">WE {{ $agg['we'] !== null ? number_format($agg['we'], 0, ',', '.') . '%' : '—' }}</span></span>
        @endif
        <span class="flex-1"></span>
        <button type="button" wire:click="pickerOeffnen({{ $rubrik->id }}, 'gericht')" class="{{ $btnGhostXs }}">+ Gericht</button>
        <button type="button" wire:click="pickerOeffnen({{ $rubrik->id }}, 'konzept')" class="{{ $btnGhostXs }}">+ Konzept</button>
        <button type="button" wire:click="pickerOeffnen({{ $rubrik->id }}, 'paket')" class="{{ $btnGhostXs }}">+ Paket</button>
        {{-- Werkstrang M Phase D: Layout-Blöcke. --}}
        <button type="button" wire:click="layoutBlockNeu({{ $rubrik->id }}, 'header')" class="{{ $btnGhostXs }}" title="Überschrift einfügen">+ Ü</button>
        <button type="button" wire:click="layoutBlockNeu({{ $rubrik->id }}, 'text')" class="{{ $btnGhostXs }}" title="Text-Block einfügen">+ Text</button>
        <button type="button" wire:click="layoutBlockNeu({{ $rubrik->id }}, 'spacer')" class="{{ $btnGhostXs }}" title="Abstand einfügen">+ ␣</button>
        {{-- Werkstrang M Phase C: Rubrik in ihrer Ebene hoch/runter. --}}
        <button type="button" wire:click="rubrikHochRunter({{ $rubrik->id }}, 'hoch')" class="{{ $btnGhostXs }}" title="Rubrik hoch">▲</button>
        <button type="button" wire:click="rubrikHochRunter({{ $rubrik->id }}, 'runter')" class="{{ $btnGhostXs }}" title="Rubrik runter">▼</button>
        <button type="button" wire:click="rubrikLoeschen({{ $rubrik->id }})" wire:confirm="Rubrik „{{ $rubrik->title }}“ löschen?" class="{{ $btnGhostXs }} text-red-600">✕</button>
    </div>

    {{-- Board: Positionen + Unter-Rubriken zusammen kollabierbar (zu[id]) — der Rubrik-Kopf mit Σ bleibt sichtbar. --}}
    <div x-show="!zu[{{ $rubrik->id }}]" x-cloak>
    {{-- Positionen --}}
    @forelse($rubrik->items as $pos)
        {{-- Werkstrang M (UX-Ausbau): Position draggable + Drop-Ziel (ablegen VOR dieser Position). --}}
        <div wire:key="sk-pos-{{ $pos->id }}" class="flex items-center gap-2 py-0.5 px-1 rounded text-sm {{ $loop->odd ? 'bg-black/[0.02]' : '' }}"
             draggable="true"
             x-on:dragstart="dragPosId = {{ $pos->id }}" x-on:dragend="dragPosId = null"
             x-on:dragover.prevent
             x-on:drop="if (dragPosId && dragPosId !== {{ $pos->id }}) { $wire.positionAblegen(dragPosId, {{ $pos->id }}); dragPosId = null }"
             x-bind:class="dragPosId === {{ $pos->id }} ? 'opacity-40' : ''">
            <span class="cursor-move select-none text-gray-300 shrink-0" title="Ziehen">⠿</span>
            <span class="flex-1 text-gray-800">
                @if($pos->type === 'gericht_ref')
                    {{ $pos->wording ?: ($pos->dish?->name ?? $pos->label ?? '— Gericht —') }}
                @elseif($pos->type === 'menue_ref')
                    {{ $pos->wording ?: ($pos->concept?->name ?? 'Menü') }}
                @elseif($pos->type === 'header')
                    <span class="font-medium uppercase text-[11px] tracking-wide text-gray-500">{{ $pos->label }}</span>
                @else
                    <span class="text-gray-500 italic">{{ $pos->consumer_text ?: $pos->label ?: $pos->type }}</span>
                @endif
            </span>
            @php($p = $preise[$pos->id] ?? null)
            {{-- Board (intern): WE-Ampel + EK je Gericht/Menü-Position; Layout-Blöcke (header/text/spacer) ohne Preis. --}}
            @if($p && in_array($pos->type, ['gericht_ref', 'menue_ref']))
                <span class="tabular-nums text-[10px] {{ $weTon($p['we'] ?? null) }} w-9 text-right shrink-0" title="Wareneinsatz">{{ ($p['we'] ?? null) !== null ? number_format($p['we'], 0, ',', '.') . '%' : '—' }}</span>
                <span class="tabular-nums text-[11px] text-gray-400 w-16 text-right shrink-0" title="EK netto">{{ ($p['ek'] ?? null) !== null ? number_format($p['ek'], 2, ',', '.') . ' €' : '—' }}</span>
            @endif
            <span class="tabular-nums text-gray-700 w-24 text-right shrink-0">
                @if($p && $p['vk'] !== null)
                    {{ number_format($p['vk'], 2, ',', '.') }} €
                    @if($p['quelle'] === 'manuell')<span class="text-violet-400" title="manueller Preis">✎</span>@endif
                    @if($p['quelle'] === 'keine')<span class="text-red-500">?</span>@endif
                @else
                    <span class="text-gray-300">—</span>
                @endif
            </span>
            {{-- Werkstrang M Phase C: Position in ihrer Rubrik hoch/runter. --}}
            <button type="button" wire:click="positionHochRunter({{ $pos->id }}, 'hoch')" class="{{ $btnGhostXs }}" title="hoch">▲</button>
            <button type="button" wire:click="positionHochRunter({{ $pos->id }}, 'runter')" class="{{ $btnGhostXs }}" title="runter">▼</button>
            @if(in_array($pos->type, ['gericht_ref', 'menue_ref', 'header', 'text']))
                <button type="button" wire:click="positionBearbeiten({{ $pos->id }})" class="{{ $btnGhostXs }}">✎</button>
            @endif
            <button type="button" wire:click="positionLoeschen({{ $pos->id }})" class="{{ $btnGhostXs }} text-red-600">✕</button>
        </div>
        @if($editPosId === $pos->id)
            <div wire:key="sk-edit-{{ $pos->id }}" class="ml-2 mb-2 p-2 rounded-lg bg-violet-500/[0.04] space-y-2">
                @if(in_array($pos->type, ['gericht_ref', 'menue_ref']))
                    <div>
                        <div class="{{ $label }} mb-1 flex items-center gap-2">
                            <span>Anzeige-Name (Wording-Override)</span>
                            <button type="button" wire:click="kiWording" class="{{ $btnAi }} !py-0.5">✨ KI</button>
                        </div>
                        <input type="text" wire:model="editWording" placeholder="leer = Standard-Wording" class="{{ $input }}" />
                        @error('editWording')<div class="text-[11px] text-red-500 mt-1">{{ $message }}</div>@enderror
                    </div>
                @endif
                @if($pos->type === 'header')
                    {{-- Werkstrang M Phase D: Überschrift-Block bearbeiten. --}}
                    <div>
                        <div class="{{ $label }} mb-1">Überschrift-Text</div>
                        <input type="text" wire:model="editLabel" class="{{ $input }}" />
                    </div>
                @endif
                @if($pos->type !== 'header' && $pos->type !== 'spacer')
                    <div>
                        <div class="{{ $label }} mb-1">Beschreibung</div>
                        <input type="text" wire:model="editConsumerText" class="{{ $input }}" />
                    </div>
                @endif
                <div class="flex items-end gap-2 flex-wrap">
                    @if(in_array($pos->type, ['gericht_ref', 'menue_ref']))
                        <div>
                            <div class="{{ $label }} mb-1">Preis</div>
                            <select wire:model.live="editPriceMode" class="{{ $input }} w-28">
                                <option value="auto">Automatik</option>
                                <option value="manuell">Manuell</option>
                            </select>
                        </div>
                        @if($editPriceMode === 'manuell')
                            <input type="text" wire:model="editPriceValue" placeholder="€ netto" class="{{ $input }} w-24" />
                        @endif
                        {{-- Werkstrang M Phase D: Wahl-Gruppe (gleiche Nr. = „A oder B"). --}}
                        <div>
                            <div class="{{ $label }} mb-1 flex items-center gap-1">
                                <span>Wahl-Gruppe</span>
                                <button type="button" wire:click="variantGruppeVorschlag({{ $rubrik->id }})" class="{{ $btnGhostXs }} !py-0" title="nächste freie Gruppe">+</button>
                            </div>
                            <input type="number" wire:model="editVariantGroupId" placeholder="A|B = gleiche Nr." class="{{ $input }} w-28" min="1" />
                        </div>
                    @endif
                    @if($karte->sections->where('id', '!=', $rubrik->id)->isNotEmpty())
                        {{-- Werkstrang M Phase C: Position in eine andere Rubrik derselben Karte verschieben. --}}
                        <div>
                            <div class="{{ $label }} mb-1">In Rubrik verschieben</div>
                            <select class="{{ $input }} w-40" @change="if ($event.target.value) $wire.positionInRubrik({{ $pos->id }}, $event.target.value)">
                                <option value="">— wählen —</option>
                                @foreach($karte->sections->where('id', '!=', $rubrik->id) as $ziel)
                                    <option value="{{ $ziel->id }}">{{ $ziel->consumer_title ?: $ziel->title }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                    <span class="flex-1"></span>
                    <button type="button" wire:click="positionSpeichern" class="{{ $btnGhostXs }}">Übernehmen</button>
                    <button type="button" wire:click="positionAbbrechen" class="{{ $btnGhostXs }}">Abbrechen</button>
                </div>
                @if($editVariantGroupId)
                    {{-- Werkstrang M Phase D: Wahl-Gruppe wird jetzt auch im Druck/Vorschau als „A oder B" gerendert. --}}
                    <p class="text-[10px] text-gray-500">Wahl-Gruppe {{ $editVariantGroupId }} — Positionen mit derselben Nummer erscheinen als „… oder …" (im Editor benachbart platzieren).</p>
                @endif
            </div>
        @endif
    @empty
        <div class="text-[11px] text-gray-400 py-1">Keine Positionen.</div>
    @endforelse

    {{-- Picker-Umbau: der Gericht/Menü-Picker lebt jetzt im permanenten Katalog rechts. Die „+ Gericht"/
         „+ Menü"-Buttons oben setzen diese Rubrik als Ziel (pickerOeffnen → pickerRubrikId). --}}

    {{-- Unter-Rubriken (rekursiv) --}}
    @foreach($karte->sections->where('parent_id', $rubrik->id) as $kind)
        @include('foodalchemist::livewire.speisekarte.partials.rubrik', ['rubrik' => $kind, 'depth' => $depth + 1])
    @endforeach
    </div>{{-- /kollabierbarer Body --}}
</div>
