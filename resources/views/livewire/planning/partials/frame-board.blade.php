{{-- R4.1 Planungs-Gerüst-Board (Trait ManagesPlanningFrame) — die messbare Soll-Ebene
     neben dem Freitext-Canvas: Preisarchitektur · Dramaturgie/Mengengerüst · Quoten & Politik.
     Jedes Feld optional — das Gerüst wächst, zwingt nicht. --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))
@php($vokabular = $this->framePlanningVokabular())

<div class="space-y-3" data-planning-frame-board="{{ $frameOwnerType }}">
    @if($frameGespeichert)
        <div class="rounded-lg bg-emerald-500/10 border border-emerald-500/30 px-3 py-1.5 text-[11px] text-emerald-700 dark:text-emerald-300" data-frame-gespeichert>
            ✓ Gespeichert — Messlatte für Coverage (R4.2) und KI-Konzepte (R6).
        </div>
    @endif
    @if($frameFehler)
        <div class="rounded-lg bg-rose-500/10 border border-rose-500/30 px-3 py-1.5 text-[11px] text-rose-700 dark:text-rose-300" data-frame-fehler>{{ $frameFehler }}</div>
    @endif

    {{-- ── Preisarchitektur (p. P.) ── --}}
    <div class="relative overflow-hidden {{ $card }}">
        <div class="{{ $cardAccent }}"></div>
        <div class="px-4 py-3 space-y-2.5">
            <p class="text-[11px] uppercase tracking-wider text-gray-400">Preisarchitektur (netto, pro Person)</p>
            <div class="grid grid-cols-3 gap-2">
                <div>
                    <label class="block {{ $label }} mb-1">Zielpreis p. P.</label>
                    <input type="number" step="0.01" wire:model="frameHead.target_price_pp" placeholder="z. B. 42,50" class="{{ $input }}" />
                </div>
                <div>
                    <label class="block {{ $label }} mb-1">Spanne von</label>
                    <input type="number" step="0.01" wire:model="frameHead.price_min_pp" class="{{ $input }}" />
                </div>
                <div>
                    <label class="block {{ $label }} mb-1">Spanne bis</label>
                    <input type="number" step="0.01" wire:model="frameHead.price_max_pp" class="{{ $input }}" />
                </div>
            </div>
            <div>
                <label class="block {{ $label }} mb-1">Notiz zum Rahmen</label>
                <textarea wire:model="frameHead.note" rows="2" class="{{ $input }}" placeholder="z. B. Anker-Logik, Budget-Kontext"></textarea>
            </div>
            <button type="button" wire:click="frameKopfSpeichern" class="{{ $btnPrimary }}" data-frame-kopf-speichern>Rahmen speichern</button>
        </div>
    </div>

    {{-- ── Dramaturgie + Mengengerüst (Slots) ── --}}
    <div class="relative overflow-hidden {{ $card }}">
        <div class="{{ $cardAccent }}"></div>
        <div class="px-4 py-3 space-y-2.5">
            <p class="text-[11px] uppercase tracking-wider text-gray-400">Dramaturgie & Mengengerüst — Gänge / Stationen in Soll-Reihenfolge</p>

            @forelse($frameSlots as $i => $slot)
                <div wire:key="frame-slot-{{ $slot['id'] }}" class="rounded-lg border border-black/5 dark:border-white/10 px-2.5 py-2 space-y-1.5">
                    <div class="grid grid-cols-12 gap-2 items-end">
                        <div class="col-span-3">
                            <label class="block {{ $label }} mb-1">Slot</label>
                            <input type="text" wire:model="frameSlots.{{ $i }}.label" class="{{ $input }}" />
                        </div>
                        <div class="col-span-2">
                            <label class="block {{ $label }} mb-1">Typ</label>
                            <select wire:model="frameSlots.{{ $i }}.slot_type" class="{{ $input }}">
                                <option value="">—</option>
                                @foreach($vokabular['slot_types'] as $t)<option value="{{ $t }}">{{ ucfirst($t) }}</option>@endforeach
                            </select>
                        </div>
                        <div class="col-span-1">
                            <label class="block {{ $label }} mb-1" title="Soll: n Gerichte">n</label>
                            <input type="number" wire:model="frameSlots.{{ $i }}.target_count" class="{{ $input }}" />
                        </div>
                        <div class="col-span-2">
                            <label class="block {{ $label }} mb-1">Anker €</label>
                            <input type="number" step="0.01" wire:model="frameSlots.{{ $i }}.price_anchor" class="{{ $input }}" />
                        </div>
                        <div class="col-span-2">
                            <label class="block {{ $label }} mb-1">Spanne €</label>
                            <div class="flex gap-1">
                                <input type="number" step="0.01" wire:model="frameSlots.{{ $i }}.price_min" class="{{ $input }}" />
                                <input type="number" step="0.01" wire:model="frameSlots.{{ $i }}.price_max" class="{{ $input }}" />
                            </div>
                        </div>
                        <div class="col-span-2 flex items-center gap-2 pb-1.5">
                            <label class="inline-flex items-center gap-1 text-[11px] text-gray-500">
                                <input type="checkbox" wire:model="frameSlots.{{ $i }}.is_pflicht" /> Pflicht
                            </label>
                            <button type="button" wire:click="frameSlotSpeichern({{ $i }})" class="{{ $btnGhostXs }} text-violet-600 dark:text-violet-400" title="Slot speichern">✓</button>
                            <button type="button" wire:click="frameSlotLoeschen({{ $slot['id'] }})" wire:confirm="Slot samt Regeln löschen?" class="{{ $btnGhostXs }} text-rose-500" title="Slot löschen">✕</button>
                        </div>
                    </div>
                    @if(($slot['rules'] ?? []) !== [])
                        <div class="flex flex-wrap gap-1.5">
                            @foreach($slot['rules'] as $r)
                                <span wire:key="frame-slot-rule-{{ $r['id'] }}" class="inline-flex items-center gap-1 rounded-full bg-black/[0.04] dark:bg-white/[0.06] px-2 py-0.5 text-[11px] text-gray-600 dark:text-gray-300">
                                    {{ $this->frameRegelLabel($r) }}
                                    <button type="button" wire:click="frameRegelLoeschen({{ $r['id'] }})" class="text-rose-500">✕</button>
                                </span>
                            @endforeach
                        </div>
                    @endif
                </div>
            @empty
                <p class="text-[11px] text-gray-400">Noch keine Slots — unten den ersten Gang / die erste Station anlegen.</p>
            @endforelse

            <div class="grid grid-cols-12 gap-2 items-end border-t border-black/5 dark:border-white/10 pt-2">
                <div class="col-span-4">
                    <input type="text" wire:model="frameNeuSlot.label" placeholder="Neuer Slot (z. B. Vorspeisen)" class="{{ $input }}" />
                </div>
                <div class="col-span-3">
                    <select wire:model="frameNeuSlot.slot_type" class="{{ $input }}">
                        <option value="">— Typ —</option>
                        @foreach($vokabular['slot_types'] as $t)<option value="{{ $t }}">{{ ucfirst($t) }}</option>@endforeach
                    </select>
                </div>
                <div class="col-span-2">
                    <input type="number" wire:model="frameNeuSlot.target_count" placeholder="n Gerichte" class="{{ $input }}" />
                </div>
                <div class="col-span-3">
                    <button type="button" wire:click="frameSlotHinzu" class="{{ $btnGhostXs }} text-violet-600 dark:text-violet-400" data-frame-slot-hinzu>+ Slot</button>
                </div>
            </div>
        </div>
    </div>

    {{-- ── Quoten & Kunden-Politik (Regeln) ── --}}
    <div class="relative overflow-hidden {{ $card }}">
        <div class="{{ $cardAccent }}"></div>
        <div class="px-4 py-3 space-y-2.5">
            <p class="text-[11px] uppercase tracking-wider text-gray-400">Quoten & Kunden-Politik — Diät · Saison · No-Gos · Allergen-Linie</p>

            @if($frameRules !== [])
                <div class="flex flex-wrap gap-1.5">
                    @foreach($frameRules as $r)
                        <span wire:key="frame-rule-{{ $r['id'] }}" class="inline-flex items-center gap-1 rounded-full bg-black/[0.04] dark:bg-white/[0.06] px-2 py-0.5 text-[11px] text-gray-600 dark:text-gray-300">
                            {{ $this->frameRegelLabel($r) }}
                            <button type="button" wire:click="frameRegelLoeschen({{ $r['id'] }})" class="text-rose-500">✕</button>
                        </span>
                    @endforeach
                </div>
            @else
                <p class="text-[11px] text-gray-400">Noch keine Regeln — unten hinzufügen. Regeln gelten fürs ganze Gerüst oder (per Slot-Wahl) für einen Gang.</p>
            @endif

            <div class="grid grid-cols-12 gap-2 items-end border-t border-black/5 dark:border-white/10 pt-2">
                <div class="col-span-3">
                    <label class="block {{ $label }} mb-1">Regel-Typ</label>
                    <select wire:model.live="frameNeuRule.rule_type" class="{{ $input }}">
                        @foreach($vokabular['rule_types'] as $key => $lbl)<option value="{{ $key }}">{{ $lbl }}</option>@endforeach
                    </select>
                </div>
                <div class="col-span-2">
                    <label class="block {{ $label }} mb-1">Gilt für</label>
                    <select wire:model="frameNeuRule.slot_id" class="{{ $input }}">
                        <option value="">ganzes Gerüst</option>
                        @foreach($frameSlots as $slot)<option value="{{ $slot['id'] }}">{{ $slot['label'] }}</option>@endforeach
                    </select>
                </div>

                @if($frameNeuRule['rule_type'] === 'diet_quota')
                    <div class="col-span-2">
                        <label class="block {{ $label }} mb-1">Diätform</label>
                        <select wire:model="frameNeuRule.ref_key" class="{{ $input }}">
                            <option value="">—</option>
                            @foreach($vokabular['diet_forms'] as $d)<option value="{{ $d }}">{{ $d }}</option>@endforeach
                        </select>
                    </div>
                    <div class="col-span-1">
                        <label class="block {{ $label }} mb-1">Op</label>
                        <select wire:model="frameNeuRule.operator" class="{{ $input }}">
                            <option value="min">mind.</option><option value="max">max.</option><option value="exact">genau</option>
                        </select>
                    </div>
                    <div class="col-span-1">
                        <label class="block {{ $label }} mb-1">Wert</label>
                        <input type="number" step="0.01" wire:model="frameNeuRule.value_num" class="{{ $input }}" />
                    </div>
                    <div class="col-span-1">
                        <label class="block {{ $label }} mb-1">Einheit</label>
                        <select wire:model="frameNeuRule.unit" class="{{ $input }}">
                            <option value="count">Stück</option><option value="percent">%</option>
                        </select>
                    </div>
                @elseif($frameNeuRule['rule_type'] === 'season_coverage')
                    <div class="col-span-4">
                        <label class="block {{ $label }} mb-1">Saison</label>
                        <select wire:model="frameNeuRule.ref_id" class="{{ $input }}">
                            <option value="">—</option>
                            @foreach($vokabular['seasons'] as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach
                        </select>
                    </div>
                @elseif($frameNeuRule['rule_type'] === 'nogo_allergen')
                    <div class="col-span-3">
                        <label class="block {{ $label }} mb-1">Allergen</label>
                        <select wire:model="frameNeuRule.ref_key" class="{{ $input }}">
                            <option value="">—</option>
                            @foreach($vokabular['allergens'] as $a)<option value="{{ $a }}">{{ $a }}</option>@endforeach
                        </select>
                    </div>
                    <div class="col-span-2">
                        <label class="block {{ $label }} mb-1">Härte</label>
                        <select wire:model="frameNeuRule.severity" class="{{ $input }}">
                            <option value="hart">hart</option><option value="weich">weich</option>
                        </select>
                    </div>
                @else
                    <div class="col-span-3">
                        <label class="block {{ $label }} mb-1">{{ $frameNeuRule['rule_type'] === 'allergen_line' ? 'Linie' : 'Zutat / Begriff' }}</label>
                        <input type="text" wire:model="frameNeuRule.value_text" placeholder="{{ $frameNeuRule['rule_type'] === 'allergen_line' ? 'z. B. durchgängig glutenfreie Linie' : 'z. B. Innereien' }}" class="{{ $input }}" />
                    </div>
                    @if($frameNeuRule['rule_type'] === 'nogo_ingredient')
                        <div class="col-span-2">
                            <label class="block {{ $label }} mb-1">Härte</label>
                            <select wire:model="frameNeuRule.severity" class="{{ $input }}">
                                <option value="hart">hart</option><option value="weich">weich</option>
                            </select>
                        </div>
                    @endif
                @endif

                <div class="col-span-2">
                    <button type="button" wire:click="frameRegelHinzu" class="{{ $btnGhostXs }} text-violet-600 dark:text-violet-400" data-frame-regel-hinzu>+ Regel</button>
                </div>
            </div>
        </div>
    </div>
</div>
