{{-- Rekursive Rubrik der Speisekarte (fa-pass 2026-10-05, Werkbank-Modus auf Bausteinen <x-fa::…>).
     Kopf: Auf/zu · Ziehen · Titel · Art · Summen. Rechts nur zwei Menüs („Hinzufügen“ und „Weitere
     Aktionen“) statt dreizehn Einzelknöpfen, damit die Zeile auch auf dem Laptop in eine Reihe passt.
     Positionen: Name (öffnet Gericht/Konzept im Editor) · Wareneinsatz · EK · VK · Bearbeiten · Menü.
     Erwartet: $rubrik, $depth, $karte, $preise, $rubrikAgg, $editPosId (+ edit*-Felder der Komponente).
     Alle wire:-Aufrufe, wire:keys, Drag-and-drop-Ausdrücke und data-Marker unverändert. --}}
@php
    $artLabel = ['speisen' => 'Speisen', 'getraenke' => 'Getränke', 'menue' => 'Menü', 'dessert' => 'Dessert', 'sonstiges' => 'Sonstiges'];
    // Board-Integration (Dominique 2026-08-27): Summen je Rubrik. $rubrikAgg erbt aus der Render-Scope.
    $agg = ($rubrikAgg ?? [])[$rubrik->id] ?? null;
    // Wareneinsatz-Ampel wie im Cockpit: bis 30 % gut · bis 38 % prüfen · darüber zu hoch.
    $weTon = fn ($we) => $we === null ? 'text-[var(--fa-ink-3)]' : ($we <= 30 ? 'text-[var(--fa-ok)]' : ($we <= 38 ? 'text-[var(--fa-warn)]' : 'text-[var(--fa-crit)]'));
    $geld = fn ($wert) => number_format((float) $wert, 2, ',', '.') . ' €';
    $menuePunkt = 'flex w-full items-center gap-2 px-3 py-2 text-left text-[length:var(--fa-text-md)] text-[var(--fa-ink)] hover:bg-[var(--fa-hover)]';
    $menueLoeschen = 'flex w-full items-center gap-2 px-3 py-2 text-left text-[length:var(--fa-text-md)] text-[var(--fa-crit)] hover:bg-[var(--fa-crit-soft)]';
    $rubrikTitel = $rubrik->consumer_title ?: $rubrik->title;
    $andereRubriken = $karte->sections->where('id', '!=', $rubrik->id);
@endphp

<div wire:key="sk-rubrik-{{ $rubrik->id }}" class="mb-3 min-w-0" style="margin-left: {{ $depth * 1.25 }}rem">
    {{-- Rubrik-Kopf = Ziehgriff (Rubrik umsortieren) + Ablageziel (Rubrik ablegen ODER eine gezogene
         Position in diese Rubrik verschieben). --}}
    <div class="flex flex-wrap items-center gap-x-2 gap-y-1 rounded-[var(--fa-radius-control)] bg-[var(--fa-neutral-soft)] px-2 py-1.5"
         x-on:dragover.prevent
         x-on:drop="if (dragPosId) { $wire.positionInRubrik(dragPosId, {{ $rubrik->id }}); dragPosId = null } else if (dragRubrikId && dragRubrikId !== {{ $rubrik->id }}) { $wire.rubrikAblegen(dragRubrikId, {{ $rubrik->id }}); dragRubrikId = null }">
        <button type="button" class="inline-flex items-center justify-center w-6 h-6 shrink-0 rounded-[var(--fa-radius-control)] text-[var(--fa-ink-3)] hover:text-[var(--fa-ink)] hover:bg-[var(--fa-hover)]"
                title="Rubrik auf- oder zuklappen" aria-label="Rubrik auf- oder zuklappen"
                @click="zu = {...zu, {{ $rubrik->id }}: !zu[{{ $rubrik->id }}]}">
            <span class="inline-flex transition-transform duration-150" x-bind:class="zu[{{ $rubrik->id }}] ? '-rotate-90' : ''">@svg('heroicon-m-chevron-down', 'w-4 h-4')</span>
        </button>
        <span class="inline-flex shrink-0 cursor-move select-none text-[var(--fa-ink-3)] hover:text-[var(--fa-ink)]" title="Ziehen, um die Rubrik umzusortieren"
              draggable="true" x-on:dragstart="dragRubrikId = {{ $rubrik->id }}" x-on:dragend="dragRubrikId = null">@svg('heroicon-m-bars-2', 'w-4 h-4')</span>
        <span class="min-w-0 break-words font-semibold text-[length:var(--fa-text-base)] text-[var(--fa-ink)]">{{ $rubrikTitel }}</span>
        <x-fa::badge>{{ $artLabel[$rubrik->art] ?? ucfirst((string) $rubrik->art) }}</x-fa::badge>
        @if($agg && $agg['n'] > 0)
            <span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] tabular-nums whitespace-nowrap" title="Summe Verkaufspreis, Summe Einkauf und mittlerer Wareneinsatz, inklusive Unter-Rubriken" data-sk-rubrik-agg>
                VK {{ $geld($agg['vk']) }} · EK {{ $geld($agg['ek']) }} · <span class="font-medium {{ $weTon($agg['we']) }}">Wareneinsatz {{ $agg['we'] !== null ? number_format($agg['we'], 0, ',', '.') . ' %' : 'offen' }}</span>
            </span>
        @endif
        <span class="flex-1"></span>

        <div class="flex items-center gap-1 shrink-0">
            {{-- Hinzufügen: Gericht/Konzept/Paket setzen diese Rubrik als Ziel im Katalog rechts; Layout-Blöcke landen direkt. --}}
            <div class="relative" x-data="faMenu()" x-on:keydown.escape="offen = false" x-on:click.outside="offen = false">
                <x-fa::button size="sm" variant="ghost" icon="heroicon-m-plus" icon-right="heroicon-m-chevron-down"
                    x-on:click="toggle($event)" aria-haspopup="menu" x-bind:aria-expanded="offen">Hinzufügen</x-fa::button>
                <div x-bind:class="{ hidden: ! offen }" x-bind:style="pos" role="menu" class="hidden w-56 fa-surface shadow-lg py-1">
                    <button type="button" role="menuitem" wire:click="pickerOeffnen({{ $rubrik->id }}, 'gericht')" x-on:click="offen = false" class="{{ $menuePunkt }}">
                        @svg('heroicon-o-cake', 'w-4 h-4 text-[var(--fa-ink-3)]') Gericht
                    </button>
                    <button type="button" role="menuitem" wire:click="pickerOeffnen({{ $rubrik->id }}, 'konzept')" x-on:click="offen = false" class="{{ $menuePunkt }}">
                        @svg('heroicon-o-rectangle-stack', 'w-4 h-4 text-[var(--fa-ink-3)]') Konzept
                    </button>
                    <button type="button" role="menuitem" wire:click="pickerOeffnen({{ $rubrik->id }}, 'paket')" x-on:click="offen = false" class="{{ $menuePunkt }}">
                        @svg('heroicon-o-cube', 'w-4 h-4 text-[var(--fa-ink-3)]') Paket
                    </button>
                    <div class="my-1 border-t border-[var(--fa-line)]"></div>
                    {{-- Werkstrang M Phase D: Layout-Blöcke. --}}
                    <button type="button" role="menuitem" wire:click="layoutBlockNeu({{ $rubrik->id }}, 'header')" x-on:click="offen = false" class="{{ $menuePunkt }}">
                        @svg('heroicon-o-h1', 'w-4 h-4 text-[var(--fa-ink-3)]') Zwischenüberschrift
                    </button>
                    <button type="button" role="menuitem" wire:click="layoutBlockNeu({{ $rubrik->id }}, 'text')" x-on:click="offen = false" class="{{ $menuePunkt }}">
                        @svg('heroicon-o-bars-3-bottom-left', 'w-4 h-4 text-[var(--fa-ink-3)]') Textblock
                    </button>
                    <button type="button" role="menuitem" wire:click="layoutBlockNeu({{ $rubrik->id }}, 'spacer')" x-on:click="offen = false" class="{{ $menuePunkt }}">
                        @svg('heroicon-o-arrows-up-down', 'w-4 h-4 text-[var(--fa-ink-3)]') Abstand
                    </button>
                </div>
            </div>

            {{-- Weitere Aktionen: Reihenfolge (Werkstrang M Phase C) und Löschen ganz unten, rot. --}}
            <div class="relative" x-data="faMenu()" x-on:keydown.escape="offen = false" x-on:click.outside="offen = false">
                <x-fa::icon-button icon="heroicon-m-ellipsis-horizontal" label="Weitere Aktionen zur Rubrik" size="sm" x-on:click="toggle($event)" aria-haspopup="menu" x-bind:aria-expanded="offen" />
                <div x-bind:class="{ hidden: ! offen }" x-bind:style="pos" role="menu" class="hidden w-56 fa-surface shadow-lg py-1">
                    <button type="button" role="menuitem" wire:click="rubrikHochRunter({{ $rubrik->id }}, 'hoch')" x-on:click="offen = false" class="{{ $menuePunkt }}">
                        @svg('heroicon-o-arrow-up', 'w-4 h-4 text-[var(--fa-ink-3)]') Rubrik nach oben
                    </button>
                    <button type="button" role="menuitem" wire:click="rubrikHochRunter({{ $rubrik->id }}, 'runter')" x-on:click="offen = false" class="{{ $menuePunkt }}">
                        @svg('heroicon-o-arrow-down', 'w-4 h-4 text-[var(--fa-ink-3)]') Rubrik nach unten
                    </button>
                    <div class="my-1 border-t border-[var(--fa-line)]"></div>
                    <button type="button" role="menuitem" wire:click="rubrikLoeschen({{ $rubrik->id }})" wire:confirm="Rubrik „{{ $rubrik->title }}“ löschen?" x-on:click="offen = false" class="{{ $menueLoeschen }}">
                        @svg('heroicon-o-trash', 'w-4 h-4') Rubrik löschen
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- Positionen und Unter-Rubriken zusammen einklappbar (zu[id]); der Kopf mit den Summen bleibt sichtbar. --}}
    <div x-show="!zu[{{ $rubrik->id }}]" x-cloak class="mt-1">
    @forelse($rubrik->items as $pos)
        @php
            // Bug-Runde 2026-09-17 #1: aus der Karte ins Gericht/Konzept — der Editor öffnet sich ÜBER
            // der Karte. Dieselben Events wie im Rezept-/Concepter-Browser; die Modale hängen auf
            // Seitenebene in der index.blade.
            $sprungAufruf = match ($pos->type) {
                'gericht_ref' => $pos->sales_recipe_id
                    ? sprintf("\$dispatch('%s', { id: %d })", $pos->dish?->is_sales_recipe ? 'vk-modal.oeffnen' : 'recipe-modal.oeffnen', (int) $pos->sales_recipe_id)
                    : null,
                'menue_ref' => $pos->concept_id
                    ? sprintf("\$dispatch('concepter-editor.oeffnen', { type: '%s', id: %d })", $pos->concept?->kind === 'paket' ? 'pakete' : 'concepts', (int) $pos->concept_id)
                    : null,
                default => null,
            };
            $sprungTitel = $pos->type === 'gericht_ref' ? 'Gericht im Editor öffnen' : 'Konzept im Editor öffnen';
            $p = $preise[$pos->id] ?? null;
            $mitPreis = in_array($pos->type, ['gericht_ref', 'menue_ref'], true);
            $posName = match ($pos->type) {
                'gericht_ref' => $pos->wording ?: ($pos->dish?->name ?? $pos->label ?? 'Gericht ohne Namen'),
                'menue_ref' => $pos->wording ?: ($pos->concept?->name ?? 'Menü'),
                default => null,
            };
        @endphp
        {{-- Position ziehbar + Ablageziel (ablegen VOR dieser Position). --}}
        <div wire:key="sk-pos-{{ $pos->id }}" class="flex items-center gap-2 min-w-0 px-2 py-1 rounded-[var(--fa-radius-control)] text-[length:var(--fa-text-md)] hover:bg-[var(--fa-hover)] {{ $editPosId === $pos->id ? 'bg-[var(--fa-accent-soft)]' : '' }}"
             draggable="true"
             x-on:dragstart="dragPosId = {{ $pos->id }}" x-on:dragend="dragPosId = null"
             x-on:dragover.prevent
             x-on:drop="if (dragPosId && dragPosId !== {{ $pos->id }}) { $wire.positionAblegen(dragPosId, {{ $pos->id }}); dragPosId = null }"
             x-bind:class="dragPosId === {{ $pos->id }} ? 'opacity-40' : ''">
            <span class="inline-flex shrink-0 cursor-move select-none text-[var(--fa-ink-3)]" title="Ziehen, um die Position zu verschieben">@svg('heroicon-m-bars-2', 'w-4 h-4')</span>

            <span class="min-w-0 flex-1 break-words leading-snug text-[var(--fa-ink)]">
                @if($posName !== null)
                    @if($sprungAufruf)
                        <button type="button" draggable="false" class="text-left hover:text-[var(--fa-accent)] hover:underline underline-offset-2"
                                wire:click="{{ $sprungAufruf }}"
                                title="{{ $sprungTitel }}">{{ $posName }}</button>
                    @else
                        {{ $posName }}
                    @endif
                @elseif($pos->type === 'header')
                    <span class="font-semibold uppercase tracking-wide text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]">{{ $pos->label }}</span>
                @elseif($pos->type === 'spacer')
                    <span class="italic text-[var(--fa-ink-3)]">Abstand</span>
                @else
                    <span class="italic text-[var(--fa-ink-2)]">{{ $pos->consumer_text ?: $pos->label ?: 'Textblock' }}</span>
                @endif
            </span>

            {{-- Intern: Wareneinsatz und EK je Gericht/Menü; Layout-Blöcke ohne Preis. --}}
            @if($mitPreis)
                @if($p)
                    <span class="hidden sm:inline tabular-nums text-[length:var(--fa-text-sm)] font-medium {{ $weTon($p['we'] ?? null) }} w-10 text-right shrink-0" title="Wareneinsatz">{{ ($p['we'] ?? null) !== null ? number_format($p['we'], 0, ',', '.') . ' %' : 'offen' }}</span>
                    <span class="hidden md:inline tabular-nums text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] w-20 text-right shrink-0" title="Einkauf netto">{{ ($p['ek'] ?? null) !== null ? 'EK ' . $geld($p['ek']) : 'EK offen' }}</span>
                @endif
                <span class="inline-flex items-center justify-end gap-1 w-24 shrink-0 text-right text-[var(--fa-ink)]">
                    <x-fa::money :value="$p['vk'] ?? null" />
                    @if(($p['quelle'] ?? null) === 'manuell')<span class="inline-flex text-[var(--fa-accent)]" title="Preis von Hand gesetzt">@svg('heroicon-m-hand-raised', 'w-3.5 h-3.5')</span>@endif
                    @if(($p['vk'] ?? null) !== null && ($p['quelle'] ?? null) === 'keine')<span class="inline-flex text-[var(--fa-warn)]" title="Preis ohne Quelle, bitte prüfen">@svg('heroicon-m-exclamation-triangle', 'w-3.5 h-3.5')</span>@endif
                </span>
            @endif

            <div class="flex items-center shrink-0">
                {{-- Bug-Runde 2026-09-17 #1: sichtbarer Einstieg ins Gericht/Konzept — der Name allein wurde nicht gefunden. --}}
                @if($sprungAufruf)
                    <x-fa::icon-button icon="heroicon-o-arrow-top-right-on-square" :label="$sprungTitel" size="sm"
                        wire:click="{{ $sprungAufruf }}" data-sk-pos-oeffnen />
                @endif
                @if(in_array($pos->type, ['gericht_ref', 'menue_ref', 'header', 'text']))
                    <x-fa::icon-button icon="heroicon-o-pencil-square" label="Position auf der Karte bearbeiten" size="sm" wire:click="positionBearbeiten({{ $pos->id }})" />
                @endif
                <div class="relative" x-data="faMenu()" x-on:keydown.escape="offen = false" x-on:click.outside="offen = false">
                    <x-fa::icon-button icon="heroicon-m-ellipsis-horizontal" label="Weitere Aktionen zur Position" size="sm" x-on:click="toggle($event)" aria-haspopup="menu" x-bind:aria-expanded="offen" />
                    <div x-bind:class="{ hidden: ! offen }" x-bind:style="pos" role="menu" class="hidden w-52 fa-surface shadow-lg py-1">
                        {{-- Werkstrang M Phase C: Position in ihrer Rubrik hoch/runter. --}}
                        <button type="button" role="menuitem" wire:click="positionHochRunter({{ $pos->id }}, 'hoch')" x-on:click="offen = false" class="{{ $menuePunkt }}">
                            @svg('heroicon-o-arrow-up', 'w-4 h-4 text-[var(--fa-ink-3)]') Nach oben
                        </button>
                        <button type="button" role="menuitem" wire:click="positionHochRunter({{ $pos->id }}, 'runter')" x-on:click="offen = false" class="{{ $menuePunkt }}">
                            @svg('heroicon-o-arrow-down', 'w-4 h-4 text-[var(--fa-ink-3)]') Nach unten
                        </button>
                        <div class="my-1 border-t border-[var(--fa-line)]"></div>
                        <button type="button" role="menuitem" wire:click="positionLoeschen({{ $pos->id }})" x-on:click="offen = false" class="{{ $menueLoeschen }}">
                            @svg('heroicon-o-trash', 'w-4 h-4') Von der Karte nehmen
                        </button>
                    </div>
                </div>
            </div>
        </div>

        @if($editPosId === $pos->id)
            <div wire:key="sk-edit-{{ $pos->id }}" class="ml-6 mt-1 mb-3 p-3 fa-surface flex flex-col gap-3">
                @if(in_array($pos->type, ['gericht_ref', 'menue_ref']))
                    <x-fa::field label="Name auf der Karte" for="sk-edit-wording-{{ $pos->id }}" hint="Leer lassen, dann gilt der Name aus dem Gericht." error="editWording">
                        <div class="flex items-center gap-2">
                            <x-fa::input id="sk-edit-wording-{{ $pos->id }}" wire:model="editWording" placeholder="Standardname verwenden" class="flex-1" />
                            <x-fa::button size="sm" variant="ai" icon="heroicon-m-sparkles" wire:click="kiWording" wire:loading.attr="disabled" wire:target="kiWording">Namen vorschlagen</x-fa::button>
                        </div>
                    </x-fa::field>
                @endif
                @if($pos->type === 'header')
                    {{-- Werkstrang M Phase D: Überschrift-Block bearbeiten. --}}
                    <x-fa::field label="Zwischenüberschrift" for="sk-edit-label-{{ $pos->id }}">
                        <x-fa::input id="sk-edit-label-{{ $pos->id }}" wire:model="editLabel" />
                    </x-fa::field>
                @endif
                @if($pos->type !== 'header' && $pos->type !== 'spacer')
                    <x-fa::field label="Beschreibung" for="sk-edit-text-{{ $pos->id }}">
                        <x-fa::input id="sk-edit-text-{{ $pos->id }}" wire:model="editConsumerText" />
                    </x-fa::field>
                @endif
                <div class="flex flex-wrap items-end gap-3">
                    @if(in_array($pos->type, ['gericht_ref', 'menue_ref']))
                        <x-fa::choice name="editPriceMode" label="Preis" :options="['auto' => 'Automatisch', 'manuell' => 'Von Hand']" id-prefix="sk-pos-{{ $pos->id }}" />
                        @if($editPriceMode === 'manuell')
                            <x-fa::field label="Preis netto" for="sk-edit-preis-{{ $pos->id }}">
                                <x-fa::input id="sk-edit-preis-{{ $pos->id }}" wire:model="editPriceValue" placeholder="0,00 €" numeric class="w-28" />
                            </x-fa::field>
                        @endif
                        {{-- Werkstrang M Phase D: Wahl-Gruppe (gleiche Nummer = „A oder B“). --}}
                        <x-fa::field label="Wahl-Gruppe" for="sk-edit-gruppe-{{ $pos->id }}">
                            <div class="flex items-center gap-1">
                                <x-fa::input type="number" id="sk-edit-gruppe-{{ $pos->id }}" wire:model="editVariantGroupId" placeholder="Nummer" numeric class="w-24" min="1" />
                                <x-fa::icon-button icon="heroicon-m-plus" label="Nächste freie Gruppe vergeben" size="sm" wire:click="variantGruppeVorschlag({{ $rubrik->id }})" />
                            </div>
                        </x-fa::field>
                    @endif
                    @if($andereRubriken->isNotEmpty())
                        {{-- Werkstrang M Phase C: Position in eine andere Rubrik derselben Karte verschieben. --}}
                        <x-fa::field label="In andere Rubrik verschieben" for="sk-edit-ziel-{{ $pos->id }}">
                            <x-fa::select id="sk-edit-ziel-{{ $pos->id }}" class="w-48" x-on:change="if ($event.target.value) $wire.positionInRubrik({{ $pos->id }}, $event.target.value)">
                                <option value="">Rubrik wählen</option>
                                @foreach($andereRubriken as $ziel)
                                    <option value="{{ $ziel->id }}">{{ $ziel->consumer_title ?: $ziel->title }}</option>
                                @endforeach
                            </x-fa::select>
                        </x-fa::field>
                    @endif
                    <span class="flex-1"></span>
                    <div class="flex items-center gap-2">
                        <x-fa::button size="sm" variant="ghost" wire:click="positionAbbrechen">Abbrechen</x-fa::button>
                        <x-fa::button size="sm" variant="primary" icon="heroicon-m-check" wire:click="positionSpeichern">Übernehmen</x-fa::button>
                    </div>
                </div>
                @if($editVariantGroupId)
                    {{-- Werkstrang M Phase D: Wahl-Gruppe wird im Druck und in der Vorschau als „A oder B“ gezeigt. --}}
                    <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Wahl-Gruppe {{ $editVariantGroupId }}: Positionen mit derselben Nummer erscheinen als „… oder …“. Im Aufbau direkt untereinander platzieren.</p>
                @endif
            </div>
        @endif
    @empty
        <p class="px-2 py-2 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Noch leer. Über „Hinzufügen“ ein Gericht, Konzept oder Paket einsetzen.</p>
    @endforelse

    {{-- Unter-Rubriken (rekursiv) --}}
    @foreach($karte->sections->where('parent_id', $rubrik->id) as $kind)
        @include('foodalchemist::livewire.speisekarte.partials.rubrik', ['rubrik' => $kind, 'depth' => $depth + 1])
    @endforeach
    </div>{{-- /einklappbarer Inhalt --}}
</div>
