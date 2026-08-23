{{-- Spec-42-Vollzug S3a — Kapitel-Steuerung in der Leitstelle: Kapitel-Liste, je Kapitel M3-Ziele
     (aufklappbar) + Erzeugen (gezielter Kaskaden-Teil-Lauf). Blade-Regeln beachtet: heroicons inline,
     keine Direktiven-Tokens in Kommentaren, wire:key je Zeile/Chip. --}}
@php
    extract(\Platform\FoodAlchemist\Support\Ui::maps());
@endphp
<div data-planung-kapitel-rail>
    @if(! $fb)
        <div class="text-xs text-gray-400 px-1" data-kapitel-kein-owner>Kein Owner-Foodbook — erst „Foodbook aus Brief".</div>
    @elseif(count($kapitel) === 0)
        <div class="text-xs text-gray-400 px-1" data-kapitel-leer>Noch keine Kapitel — erst „Foodbook aus Brief" erzeugen.</div>
    @else
        @if($meldung)<p class="text-[11px] text-rose-500 mb-2" data-kapitel-meldung>{{ $meldung }}</p>@endif
        <div class="space-y-1.5" data-kapitel-liste>
            @foreach($kapitel as $k)
                @php
                    $laeuft = isset($laeuftMap[$k['id']]);
                    $offen = $offenId === $k['id'];
                @endphp
                <div wire:key="fb-kap-{{ $k['id'] }}" class="{{ $card }} p-2" data-kapitel="{{ $k['id'] }}" style="margin-left: {{ min((int) $k['depth'], 3) * 12 }}px">
                    <div class="flex items-center gap-2">
                        <button type="button" wire:click="oeffne({{ $k['id'] }})" class="flex-1 min-w-0 text-left text-xs font-medium text-gray-800 truncate hover:text-violet-700" data-kapitel-toggle="{{ $k['id'] }}">{{ $k['title'] ?: 'Kapitel' }}</button>
                        @if($laeuft)<span class="shrink-0 w-1.5 h-1.5 rounded-full bg-amber-400 animate-pulse" title="läuft"></span>@endif
                        <button type="button" wire:click="kapitelErzeugen({{ $k['id'] }})" wire:loading.attr="disabled" wire:target="kapitelErzeugen" class="{{ $btnGhostXs }} shrink-0" title="Dieses Kapitel über die Kaskade erzeugen" data-kapitel-erzeugen="{{ $k['id'] }}">@svg('heroicon-o-sparkles', 'w-3.5 h-3.5')</button>
                    </div>

                    @if($offen)
                        <div class="mt-2 border-t border-black/5 pt-2 space-y-2" data-kapitel-ziele="{{ $k['id'] }}">
                            {{-- Zielgruppen-Chips (Stempel; Kapitel schlägt Foodbook-Default in der Kaskade) --}}
                            <div class="space-y-1">
                                <span class="{{ $label }}">Zielgruppen</span>
                                <div class="flex flex-wrap gap-1" data-kapitel-zielgruppen>
                                    @forelse($zielgruppenVokab as $z)
                                        <button type="button" wire:click="zielgruppeToggle({{ $z->id }})" wire:key="kzg-{{ $k['id'] }}-{{ $z->id }}"
                                                class="inline-flex px-2 py-0.5 rounded-full text-[11px] border transition-colors {{ in_array($z->id, $zielgruppenIds, true) ? 'bg-violet-500/10 border-violet-500/30 text-violet-700' : 'bg-black/[0.03] border-black/10 text-gray-500 hover:bg-black/[0.06]' }}"
                                                data-an="{{ in_array($z->id, $zielgruppenIds, true) ? '1' : '0' }}">{{ $z->name }}</button>
                                    @empty
                                        <span class="text-[11px] text-gray-400">Kein Zielgruppen-Vokabular — in den Einstellungen pflegen.</span>
                                    @endforelse
                                </div>
                            </div>

                            {{-- Ziele-Editing (M3-Spalten) --}}
                            <div class="grid grid-cols-2 gap-2" data-kapitel-m3>
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
                            <button type="button" wire:click="zieleSpeichern" class="{{ $btnPrimary }} w-full justify-center" data-kapitel-ziele-speichern>Ziele speichern</button>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
