{{-- R4.1 Planungs-Gerüst (Trait ManagesPlanningFrame) — die messbaren Vorgaben neben dem
     Freitext-Canvas: Preisrahmen · Gangfolge und Mengen · Quoten und Ausschlüsse.
     Jedes Feld optional — das Gerüst wächst, zwingt nicht. fa-pass: nur Tokens + x-fa-Bausteine;
     Raster brechen auf Laptop-Breite um (vorher starres 12er-Raster). --}}
@php
    $vokabular = $this->framePlanningVokabular();
    $regelChip = 'inline-flex items-center gap-1 h-7 pl-2.5 pr-1 rounded-full bg-[var(--fa-neutral-soft)] text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]';
    $regelWeg = 'inline-flex items-center justify-center w-5 h-5 rounded-full text-[var(--fa-crit)] hover:bg-[var(--fa-crit-soft)]';
    $slotTypLabel = fn (string $t) => ['titel' => 'Titel', 'titel_preis' => 'Titel mit Preis', 'freitext' => 'Freitext', 'leerzeile' => 'Leerzeile'][$t] ?? ucfirst(str_replace('_', ' ', $t));
@endphp

<div class="flex flex-col gap-3" data-planning-frame-board="{{ $frameOwnerType }}">
    @if($frameGespeichert)
        <x-fa::notice tone="ok" data-frame-gespeichert>Gespeichert. Daran misst sich jetzt der Soll-Ist-Abgleich und jede KI-Erstellung.</x-fa::notice>
    @endif
    @if($frameFehler)
        <x-fa::notice tone="crit" data-frame-fehler>{{ $frameFehler }}</x-fa::notice>
    @endif

    {{-- ── Preisrahmen (netto, je Person) ── --}}
    <x-fa::section title="Preisrahmen" icon="heroicon-o-banknotes" description="Netto, je Person.">
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <x-fa::field label="Zielpreis je Person" for="frame-ziel">
                <x-fa::input id="frame-ziel" numeric type="number" step="0.01" wire:model="frameHead.target_price_pp" placeholder="z. B. 42,50" />
            </x-fa::field>
            <x-fa::field label="Preisspanne von" for="frame-min">
                <x-fa::input id="frame-min" numeric type="number" step="0.01" wire:model="frameHead.price_min_pp" />
            </x-fa::field>
            <x-fa::field label="Preisspanne bis" for="frame-max">
                <x-fa::input id="frame-max" numeric type="number" step="0.01" wire:model="frameHead.price_max_pp" />
            </x-fa::field>
        </div>
        <x-fa::field label="Notiz zum Rahmen" for="frame-notiz">
            <textarea id="frame-notiz" wire:model="frameHead.note"
                      x-data
                      x-effect="$wire.frameHead; $el.style.height='auto'; $el.style.height=$el.scrollHeight+'px'"
                      @input="$el.style.height='auto'; $el.style.height=$el.scrollHeight+'px'"
                      class="fa-control py-2 text-[length:var(--fa-text-md)] leading-relaxed resize-none overflow-hidden min-h-[4.5rem]"
                      placeholder="z. B. Ankerpreis, Preisstufen (Basis, Hochwertig, Premium), Ziel für den Wareneinsatz, Budget des Kunden"></textarea>
        </x-fa::field>
        {{-- hideSave: Concepter sichert den Rahmen über EIN Speichern (konzeptSpeichern); andere Orte zeigen den Knopf weiter. --}}
        @unless($hideSave ?? false)
            <div><x-fa::button variant="primary" icon="heroicon-m-check" wire:click="frameKopfSpeichern" data-frame-kopf-speichern>Rahmen speichern</x-fa::button></div>
        @endunless
    </x-fa::section>

    {{-- ── Gangfolge und Mengen ── --}}
    <x-fa::section title="Gangfolge und Mengen" icon="heroicon-o-queue-list" description="Gänge oder Stationen in der gewünschten Reihenfolge.">
        @forelse($frameSlots as $i => $slot)
            <div wire:key="frame-slot-{{ $slot['id'] }}" class="flex flex-col gap-2 rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] bg-[var(--fa-ground)] px-3 py-2.5">
                <div class="grid grid-cols-2 md:grid-cols-6 xl:grid-cols-12 gap-2 items-end">
                    <x-fa::field label="Gang oder Station" class="col-span-2 md:col-span-2 xl:col-span-3">
                        <x-fa::input size="sm" wire:model="frameSlots.{{ $i }}.label" aria-label="Gang oder Station" />
                    </x-fa::field>
                    <x-fa::field label="Art" class="col-span-1 md:col-span-2 xl:col-span-2">
                        <x-fa::select size="sm" wire:model="frameSlots.{{ $i }}.slot_type" aria-label="Art">
                            <option value="">Keine Vorgabe</option>
                            @foreach($vokabular['slot_types'] as $t)<option value="{{ $t }}">{{ $slotTypLabel($t) }}</option>@endforeach
                        </x-fa::select>
                    </x-fa::field>
                    <x-fa::field label="Gerichte" class="col-span-1 md:col-span-2 xl:col-span-1">
                        <x-fa::input size="sm" numeric type="number" wire:model="frameSlots.{{ $i }}.target_count" aria-label="Anzahl Gerichte" title="Soll: so viele Gerichte" />
                    </x-fa::field>
                    <x-fa::field label="Ankerpreis" class="col-span-1 md:col-span-2 xl:col-span-2">
                        <x-fa::input size="sm" numeric type="number" step="0.01" wire:model="frameSlots.{{ $i }}.price_anchor" aria-label="Ankerpreis" />
                    </x-fa::field>
                    <x-fa::field label="Preisspanne" class="col-span-1 md:col-span-2 xl:col-span-2">
                        <div class="flex gap-1">
                            <x-fa::input size="sm" numeric type="number" step="0.01" wire:model="frameSlots.{{ $i }}.price_min" aria-label="Preis von" placeholder="von" />
                            <x-fa::input size="sm" numeric type="number" step="0.01" wire:model="frameSlots.{{ $i }}.price_max" aria-label="Preis bis" placeholder="bis" />
                        </div>
                    </x-fa::field>
                    <div class="col-span-2 md:col-span-2 xl:col-span-2 flex items-center gap-1.5 h-7">
                        <label class="inline-flex items-center gap-1.5 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)] mr-auto">
                            <input type="checkbox" wire:model="frameSlots.{{ $i }}.is_pflicht" class="w-4 h-4 accent-[var(--fa-accent)]" /> Pflicht
                        </label>
                        <x-fa::icon-button icon="heroicon-m-check" label="Gang speichern" size="sm" wire:click="frameSlotSpeichern({{ $i }})" />
                        <x-fa::icon-button icon="heroicon-m-trash" label="Gang löschen" size="sm" tone="danger" wire:click="frameSlotLoeschen({{ $slot['id'] }})" wire:confirm="Gang samt seinen Regeln löschen?" />
                    </div>
                </div>
                @if(($slot['rules'] ?? []) !== [])
                    <div class="flex flex-wrap gap-1.5">
                        @foreach($slot['rules'] as $r)
                            <span wire:key="frame-slot-rule-{{ $r['id'] }}" class="{{ $regelChip }}">
                                {{ $this->frameRegelLabel($r) }}
                                <button type="button" wire:click="frameRegelLoeschen({{ $r['id'] }})" class="{{ $regelWeg }}" title="Regel löschen" aria-label="Regel löschen">@svg('heroicon-m-x-mark', 'w-3.5 h-3.5')</button>
                            </span>
                        @endforeach
                    </div>
                @endif
            </div>
        @empty
            <x-fa::empty compact icon="heroicon-o-queue-list" title="Noch keine Gänge">Unten den ersten Gang oder die erste Station anlegen.</x-fa::empty>
        @endforelse

        <div class="grid grid-cols-2 md:grid-cols-12 gap-2 items-end pt-3 border-t border-[var(--fa-line)]">
            <x-fa::field label="Neuer Gang" class="col-span-2 md:col-span-5">
                <x-fa::input size="sm" wire:model="frameNeuSlot.label" placeholder="z. B. Vorspeisen" aria-label="Neuer Gang" />
            </x-fa::field>
            <x-fa::field label="Art" class="col-span-1 md:col-span-3">
                <x-fa::select size="sm" wire:model="frameNeuSlot.slot_type" aria-label="Art">
                    <option value="">Keine Vorgabe</option>
                    @foreach($vokabular['slot_types'] as $t)<option value="{{ $t }}">{{ $slotTypLabel($t) }}</option>@endforeach
                </x-fa::select>
            </x-fa::field>
            <x-fa::field label="Gerichte" class="col-span-1 md:col-span-2">
                <x-fa::input size="sm" numeric type="number" wire:model="frameNeuSlot.target_count" placeholder="Anzahl" aria-label="Anzahl Gerichte" />
            </x-fa::field>
            <div class="col-span-2 md:col-span-2">
                <x-fa::button size="sm" icon="heroicon-m-plus" wire:click="frameSlotHinzu" class="w-full" data-frame-slot-hinzu>Gang hinzufügen</x-fa::button>
            </div>
        </div>
    </x-fa::section>

    {{-- ── Quoten und Kundenvorgaben (Regeln) ── --}}
    <x-fa::section title="Quoten und Kundenvorgaben" icon="heroicon-o-adjustments-horizontal" description="Ernährungsformen, Saison, Ausschlüsse und Allergen-Linie. Eine Regel gilt fürs ganze Gerüst oder für einen Gang.">
        @if($frameRules !== [])
            <div class="flex flex-wrap gap-1.5">
                @foreach($frameRules as $r)
                    <span wire:key="frame-rule-{{ $r['id'] }}" class="{{ $regelChip }}">
                        {{ $this->frameRegelLabel($r) }}
                        <button type="button" wire:click="frameRegelLoeschen({{ $r['id'] }})" class="{{ $regelWeg }}" title="Regel löschen" aria-label="Regel löschen">@svg('heroicon-m-x-mark', 'w-3.5 h-3.5')</button>
                    </span>
                @endforeach
            </div>
        @else
            <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Noch keine Regeln. Unten hinzufügen.</p>
        @endif

        <div class="grid grid-cols-2 md:grid-cols-6 xl:grid-cols-12 gap-2 items-end pt-3 border-t border-[var(--fa-line)]">
            <x-fa::field label="Art der Regel" class="col-span-2 md:col-span-2 xl:col-span-3">
                <x-fa::select size="sm" wire:model.live="frameNeuRule.rule_type" aria-label="Art der Regel">
                    @foreach($vokabular['rule_types'] as $key => $lbl)<option value="{{ $key }}">{{ $lbl }}</option>@endforeach
                </x-fa::select>
            </x-fa::field>
            <x-fa::field label="Gilt für" class="col-span-2 md:col-span-2 xl:col-span-2">
                <x-fa::select size="sm" wire:model="frameNeuRule.slot_id" aria-label="Gilt für">
                    <option value="">ganzes Gerüst</option>
                    @foreach($frameSlots as $slot)<option value="{{ $slot['id'] }}">{{ $slot['label'] }}</option>@endforeach
                </x-fa::select>
            </x-fa::field>

            @if($frameNeuRule['rule_type'] === 'diet_quota')
                <x-fa::field label="Ernährungsform" class="col-span-2 md:col-span-2 xl:col-span-2">
                    <x-fa::select size="sm" wire:model="frameNeuRule.ref_key" aria-label="Ernährungsform">
                        <option value="">Bitte wählen</option>
                        @foreach($vokabular['diet_forms'] as $d)<option value="{{ $d }}">{{ $d }}</option>@endforeach
                    </x-fa::select>
                </x-fa::field>
                <x-fa::field label="Bedingung" class="col-span-1 md:col-span-2 xl:col-span-1">
                    <x-fa::select size="sm" wire:model="frameNeuRule.operator" aria-label="Bedingung">
                        <option value="min">mindestens</option><option value="max">höchstens</option><option value="exact">genau</option>
                    </x-fa::select>
                </x-fa::field>
                <x-fa::field label="Wert" class="col-span-1 md:col-span-2 xl:col-span-1">
                    <x-fa::input size="sm" numeric type="number" step="0.01" wire:model="frameNeuRule.value_num" aria-label="Wert" />
                </x-fa::field>
                <x-fa::field label="Einheit" class="col-span-1 md:col-span-2 xl:col-span-1">
                    <x-fa::select size="sm" wire:model="frameNeuRule.unit" aria-label="Einheit">
                        <option value="count">Stück</option><option value="percent">%</option>
                    </x-fa::select>
                </x-fa::field>
            @elseif($frameNeuRule['rule_type'] === 'season_coverage')
                <x-fa::field label="Saison" class="col-span-2 md:col-span-2 xl:col-span-4">
                    <x-fa::select size="sm" wire:model="frameNeuRule.ref_id" aria-label="Saison">
                        <option value="">Bitte wählen</option>
                        @foreach($vokabular['seasons'] as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach
                    </x-fa::select>
                </x-fa::field>
            @elseif($frameNeuRule['rule_type'] === 'nogo_allergen')
                <x-fa::field label="Allergen" class="col-span-2 md:col-span-2 xl:col-span-3">
                    <x-fa::select size="sm" wire:model="frameNeuRule.ref_key" aria-label="Allergen">
                        <option value="">Bitte wählen</option>
                        @foreach($vokabular['allergens'] as $a)<option value="{{ $a }}">{{ $a }}</option>@endforeach
                    </x-fa::select>
                </x-fa::field>
                <x-fa::field label="Härte" class="col-span-2 md:col-span-2 xl:col-span-2">
                    <x-fa::select size="sm" wire:model="frameNeuRule.severity" aria-label="Härte">
                        <option value="hart">ausgeschlossen</option><option value="weich">möglichst vermeiden</option>
                    </x-fa::select>
                </x-fa::field>
            @else
                <x-fa::field :label="$frameNeuRule['rule_type'] === 'allergen_line' ? 'Linie' : 'Zutat oder Begriff'" class="col-span-2 md:col-span-2 xl:col-span-3">
                    <x-fa::input size="sm" wire:model="frameNeuRule.value_text" placeholder="{{ $frameNeuRule['rule_type'] === 'allergen_line' ? 'z. B. durchgängig glutenfreie Linie' : 'z. B. Innereien' }}" aria-label="{{ $frameNeuRule['rule_type'] === 'allergen_line' ? 'Linie' : 'Zutat oder Begriff' }}" />
                </x-fa::field>
                @if($frameNeuRule['rule_type'] === 'nogo_ingredient')
                    <x-fa::field label="Härte" class="col-span-2 md:col-span-2 xl:col-span-2">
                        <x-fa::select size="sm" wire:model="frameNeuRule.severity" aria-label="Härte">
                            <option value="hart">ausgeschlossen</option><option value="weich">möglichst vermeiden</option>
                        </x-fa::select>
                    </x-fa::field>
                @endif
            @endif

            <div class="col-span-2 md:col-span-2 xl:col-span-2">
                <x-fa::button size="sm" icon="heroicon-m-plus" wire:click="frameRegelHinzu" class="w-full" data-frame-regel-hinzu>Regel hinzufügen</x-fa::button>
            </div>
        </div>
    </x-fa::section>
</div>
