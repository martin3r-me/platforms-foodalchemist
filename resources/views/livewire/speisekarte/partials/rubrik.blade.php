{{-- Rekursive Rubrik-Zeile: Titel + Positionen + Gericht-Picker + Unter-Rubriken.
     Erwartet: $rubrik, $depth, $karte, $preise, $pickerRubrikId, $pickerErgebnisse (+ Ui-Maps). --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))
@php($artLabel = ['speisen' => 'Speisen', 'getraenke' => 'Getränke', 'menue' => 'Menü', 'dessert' => 'Dessert', 'sonstiges' => 'Sonstiges'])

<div wire:key="sk-rubrik-{{ $rubrik->id }}" class="mb-3" style="margin-left: {{ $depth * 1.25 }}rem">
    {{-- Werkstrang M (UX-Ausbau): Rubrik-Header = Drag-Handle (Rubrik umsortieren) + Drop-Ziel
         (Rubrik ablegen ODER eine gezogene Position in diese Rubrik verschieben). --}}
    <div class="flex items-center gap-2 border-b border-black/10 pb-1 mb-2"
         x-on:dragover.prevent
         x-on:drop="if (dragPosId) { $wire.positionInRubrik(dragPosId, {{ $rubrik->id }}); dragPosId = null } else if (dragRubrikId && dragRubrikId !== {{ $rubrik->id }}) { $wire.rubrikAblegen(dragRubrikId, {{ $rubrik->id }}); dragRubrikId = null }">
        <span class="cursor-move select-none text-gray-300 hover:text-gray-500 shrink-0" title="Ziehen zum Umsortieren"
              draggable="true" x-on:dragstart="dragRubrikId = {{ $rubrik->id }}" x-on:dragend="dragRubrikId = null">⠿</span>
        <span class="font-semibold text-gray-900 text-sm">{{ $rubrik->consumer_title ?: $rubrik->title }}</span>
        <span class="{{ $pill }} {{ $variantPill['secondary'] }}">{{ $artLabel[$rubrik->art] ?? $rubrik->art }}</span>
        <span class="flex-1"></span>
        <button type="button" wire:click="pickerOeffnen({{ $rubrik->id }}, 'gericht')" class="{{ $btnGhostXs }}">+ Gericht</button>
        <button type="button" wire:click="pickerOeffnen({{ $rubrik->id }}, 'menue')" class="{{ $btnGhostXs }}">+ Menü</button>
        {{-- Werkstrang M Phase D: Layout-Blöcke. --}}
        <button type="button" wire:click="layoutBlockNeu({{ $rubrik->id }}, 'header')" class="{{ $btnGhostXs }}" title="Überschrift einfügen">+ Ü</button>
        <button type="button" wire:click="layoutBlockNeu({{ $rubrik->id }}, 'text')" class="{{ $btnGhostXs }}" title="Text-Block einfügen">+ Text</button>
        <button type="button" wire:click="layoutBlockNeu({{ $rubrik->id }}, 'spacer')" class="{{ $btnGhostXs }}" title="Abstand einfügen">+ ␣</button>
        {{-- Werkstrang M Phase C: Rubrik in ihrer Ebene hoch/runter. --}}
        <button type="button" wire:click="rubrikHochRunter({{ $rubrik->id }}, 'hoch')" class="{{ $btnGhostXs }}" title="Rubrik hoch">▲</button>
        <button type="button" wire:click="rubrikHochRunter({{ $rubrik->id }}, 'runter')" class="{{ $btnGhostXs }}" title="Rubrik runter">▼</button>
        <button type="button" wire:click="rubrikLoeschen({{ $rubrik->id }})" wire:confirm="Rubrik „{{ $rubrik->title }}“ löschen?" class="{{ $btnGhostXs }} text-red-600">✕</button>
    </div>

    {{-- Positionen --}}
    @forelse($rubrik->items as $pos)
        {{-- Werkstrang M (UX-Ausbau): Position draggable + Drop-Ziel (ablegen VOR dieser Position). --}}
        <div wire:key="sk-pos-{{ $pos->id }}" class="flex items-center gap-2 py-0.5 text-sm"
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
            <span class="tabular-nums text-gray-700 w-24 text-right">
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
</div>
