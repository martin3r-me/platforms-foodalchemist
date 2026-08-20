{{-- Rekursive Rubrik-Zeile: Titel + Positionen + Gericht-Picker + Unter-Rubriken.
     Erwartet: $rubrik, $depth, $karte, $preise, $pickerRubrikId, $pickerErgebnisse (+ Ui-Maps). --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))
@php($artLabel = ['speisen' => 'Speisen', 'getraenke' => 'Getränke', 'menue' => 'Menü', 'dessert' => 'Dessert', 'sonstiges' => 'Sonstiges'])

<div wire:key="sk-rubrik-{{ $rubrik->id }}" class="mb-3" style="margin-left: {{ $depth * 1.25 }}rem">
    <div class="flex items-center gap-2 border-b border-black/10 pb-1 mb-2">
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
        <div wire:key="sk-pos-{{ $pos->id }}" class="flex items-center gap-2 py-0.5 text-sm">
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
                    {{-- Werkstrang M Phase D: ehrlicher Hinweis — die Gruppierungs-Optik ist noch Editor-only. --}}
                    <p class="text-[10px] text-amber-600/80">Wahl-Gruppe {{ $editVariantGroupId }} gesetzt — die „A oder B"-Gruppierung erscheint noch NICHT im Druck (Editor-only vorerst; Renderer folgt).</p>
                @endif
            </div>
        @endif
    @empty
        <div class="text-[11px] text-gray-400 py-1">Keine Positionen.</div>
    @endforelse

    {{-- Gericht-/Menü-Picker (nur für die geöffnete Rubrik) --}}
    @if($pickerRubrikId === $rubrik->id)
        <div class="mt-2 p-2 rounded-lg bg-black/[0.03]">
            <input type="search" wire:model.live.debounce.300ms="pickerSuche" placeholder="{{ $pickerModus === 'menue' ? 'Fix-Menü (Concept) suchen …' : 'Gericht/Getränk suchen …' }}" class="{{ $input }} mb-2" autofocus />
            {{-- Werkstrang M Phase B: Facetten (Hauptgruppe → Unterklasse) — nur Gericht-Modus. --}}
            @if($pickerModus === 'gericht' && ($pickerHauptgruppen ?? collect())->isNotEmpty())
                <div class="flex flex-wrap gap-1 mb-2">
                    @foreach($pickerHauptgruppen as $hg)
                        <button type="button" wire:key="sk-pf-hg-{{ $rubrik->id }}-{{ $hg->id }}"
                            wire:click="pickerWaehleHg({{ $hg->id }})"
                            class="px-1.5 py-0.5 rounded text-[10px] {{ $pickerHauptgruppe === $hg->id ? 'bg-violet-500/20 text-violet-700 border border-violet-500/40' : 'bg-black/[0.04] text-gray-600 hover:bg-black/[0.07]' }}">
                            {{ $hg->label ?? $hg->code }}
                        </button>
                    @endforeach
                </div>
                @if(($pickerUntergruppen ?? collect())->isNotEmpty())
                    <div class="flex flex-wrap gap-1 mb-2 pl-2 border-l-2 border-violet-500/20">
                        @foreach($pickerUntergruppen as $kl)
                            <button type="button" wire:key="sk-pf-kl-{{ $rubrik->id }}-{{ $kl->id }}"
                                wire:click="pickerWaehleKlasse({{ $kl->id }})"
                                class="px-1.5 py-0.5 rounded text-[10px] {{ $pickerDishClass === $kl->id ? 'bg-violet-500/20 text-violet-700 border border-violet-500/40' : 'bg-black/[0.04] text-gray-600 hover:bg-black/[0.07]' }}">
                                {{ $kl->label }}
                            </button>
                        @endforeach
                    </div>
                @endif
            @endif
            <div class="max-h-56 overflow-auto space-y-0.5">
                @forelse($pickerErgebnisse as $g)
                    <button type="button" wire:key="sk-pick-{{ $rubrik->id }}-{{ $g->id }}"
                        wire:click="{{ $pickerModus === 'menue' ? 'positionAusMenue' : 'positionAusGericht' }}({{ $rubrik->id }}, {{ $g->id }})"
                        class="w-full flex items-center gap-2 px-2 py-1 rounded-md text-xs hover:bg-white/80 text-left">
                        <span class="flex-1 text-gray-800">{{ $g->name }}</span>
                        @if($pickerModus === 'gericht' && ($g->dishClass?->diet_form))
                            {{-- Werkstrang M Phase B: Diät-Form-Label je Treffer (aus dish_class). --}}
                            <span class="px-1 rounded bg-black/[0.05] text-[9px] text-gray-500">{{ $g->dishClass->diet_form }}</span>
                        @endif
                        @if($pickerModus === 'menue')
                            <span class="tabular-nums text-gray-500">{{ $g->price_per_person_cache !== null ? number_format((float) $g->price_per_person_cache, 2, ',', '.') . ' €' : '—' }}</span>
                        @else
                            <span class="tabular-nums text-gray-500">{{ $g->sales_net !== null ? number_format((float) $g->sales_net, 2, ',', '.') . ' €' : '—' }}</span>
                        @endif
                    </button>
                @empty
                    <div class="px-2 py-3 text-[11px] text-gray-500 text-center">Kein Treffer.</div>
                @endforelse
            </div>
        </div>
    @endif

    {{-- Unter-Rubriken (rekursiv) --}}
    @foreach($karte->sections->where('parent_id', $rubrik->id) as $kind)
        @include('foodalchemist::livewire.speisekarte.partials.rubrik', ['rubrik' => $kind, 'depth' => $depth + 1])
    @endforeach
</div>
