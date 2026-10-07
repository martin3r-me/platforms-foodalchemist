{{-- Spec-42-Vollzug S3a — Kapitel-Steuerung in der Leitstelle: Kapitel-Liste, je Kapitel die Ziele
     (aufklappbar) + Erstellen (gezielter Teil-Lauf). Blade-Regeln beachtet: heroicons inline,
     keine Direktiven-Tokens in Kommentaren, wire:key je Zeile/Chip.
     fa-pass: Bausteine (Tokens, hell + Werkbank), Kapitel als klar abgegrenzte Zeilen mit Aufklapp-Pfeil,
     Ziele in beschrifteten Feldern; „erben" heißt jetzt „Wie im Foodbook". Bindings und Marker unverändert. --}}
@php
    extract(\Platform\FoodAlchemist\Support\Ui::maps());
@endphp
<div data-planung-kapitel-rail>
    @if(! $fb)
        <x-fa::empty compact icon="heroicon-o-book-open" title="Noch kein Foodbook gewählt" data-kapitel-kein-owner>Erst ein Foodbook wählen oder aus einem Brief erstellen.</x-fa::empty>
    @elseif(count($kapitel) === 0)
        <x-fa::empty compact icon="heroicon-o-list-bullet" title="Noch keine Kapitel" data-kapitel-leer>Die Kapitel entstehen mit „Gerüst planen" aus dem Brief oben.</x-fa::empty>
    @else
        @if($meldung)<x-fa::notice tone="crit" class="mb-2" data-kapitel-meldung>{{ $meldung }}</x-fa::notice>@endif
        <div class="flex flex-col gap-1.5" data-kapitel-liste>
            @foreach($kapitel as $k)
                @php
                    $laeuft = isset($laeuftMap[$k['id']]);
                    $offen = $offenId === $k['id'];
                @endphp
                <div wire:key="fb-kap-{{ $k['id'] }}" class="rounded-[var(--fa-radius-control)] border {{ $offen ? 'border-[var(--fa-accent-line)] bg-[var(--fa-surface)]' : 'border-[var(--fa-line)] bg-[var(--fa-surface)]' }}" data-kapitel="{{ $k['id'] }}" style="margin-left: {{ min((int) $k['depth'], 3) * 16 }}px">
                    <div class="flex items-center gap-2 px-2 py-1.5">
                        <button type="button" wire:click="oeffne({{ $k['id'] }})" aria-expanded="{{ $offen ? 'true' : 'false' }}"
                                class="flex-1 min-w-0 flex items-center gap-1.5 text-left text-[length:var(--fa-text-md)] font-medium text-[var(--fa-ink)] hover:text-[var(--fa-accent)]" data-kapitel-toggle="{{ $k['id'] }}">
                            @svg('heroicon-m-chevron-right', 'w-4 h-4 shrink-0 text-[var(--fa-ink-3)] transition-transform ' . ($offen ? 'rotate-90' : ''))
                            <span class="min-w-0 break-words">{{ $k['title'] ?: 'Kapitel ohne Titel' }}</span>
                        </button>
                        @if($laeuft)<x-fa::badge tone="warn">läuft</x-fa::badge>@endif
                        <x-foodalchemist::ki-action action="kapitelErzeugen({{ $k['id'] }})" target="kapitelErzeugen" variant="ai"
                                icon="heroicon-o-sparkles" label="Kapitel erstellen" title="Dieses Kapitel mit KI füllen"
                                class="shrink-0" data-kapitel-erzeugen="{{ $k['id'] }}" />
                    </div>

                    @if($offen)
                        <div class="border-t border-[var(--fa-line)] px-3 py-3 flex flex-col gap-3" data-kapitel-ziele="{{ $k['id'] }}">
                            {{-- Zielgruppen-Chips (Stempel; Kapitel schlägt Foodbook-Vorgabe) --}}
                            <div class="flex flex-col gap-1.5">
                                <span class="text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]">Zielgruppen</span>
                                <div class="flex flex-wrap gap-1.5" data-kapitel-zielgruppen>
                                    @forelse($zielgruppenVokab as $z)
                                        @php $zAn = in_array($z->id, $zielgruppenIds, true); @endphp
                                        <button type="button" wire:click="zielgruppeToggle({{ $z->id }})" wire:key="kzg-{{ $k['id'] }}-{{ $z->id }}" aria-pressed="{{ $zAn ? 'true' : 'false' }}"
                                                class="inline-flex items-center gap-1 h-7 px-2.5 rounded-full border text-[length:var(--fa-text-sm)] transition-colors {{ $zAn ? 'border-[var(--fa-accent-line)] bg-[var(--fa-accent-soft)] text-[var(--fa-accent)] font-medium' : 'border-[var(--fa-line-strong)] bg-[var(--fa-surface)] text-[var(--fa-ink-2)] hover:bg-[var(--fa-hover)]' }}"
                                                data-an="{{ $zAn ? '1' : '0' }}">@if($zAn)@svg('heroicon-m-check', 'w-3.5 h-3.5')@endif{{ $z->name }}</button>
                                    @empty
                                        <span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Noch keine Zielgruppen angelegt. Pflegen in den Einstellungen.</span>
                                    @endforelse
                                </div>
                            </div>

                            {{-- Ziele je Kapitel --}}
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3" data-kapitel-m3>
                                <x-fa::field label="Niveau">
                                    <x-fa::select wire:model="ziel.niveau" placeholder="Wie im Foodbook" :options="$niveauLabels" />
                                </x-fa::field>
                                <x-fa::field label="Preisangabe">
                                    <x-fa::select wire:model="ziel.pricing_mode" placeholder="Keine Vorgabe">
                                        @foreach($pricingModes as $pm)<option value="{{ $pm }}">{{ ['paket' => 'Paketpreis', 'einzel' => 'Einzelpreise', 'gemischt' => 'Paket- und Einzelpreise'][$pm] ?? ucfirst($pm) }}</option>@endforeach
                                    </x-fa::select>
                                </x-fa::field>
                                <x-fa::field label="Einsatzmoment">
                                    <x-fa::select wire:model="ziel.service_moment_id" placeholder="Wie im Foodbook">
                                        @foreach($einsatzmomente as $e)<option value="{{ $e->id }}">{{ $e->name }}</option>@endforeach
                                    </x-fa::select>
                                </x-fa::field>
                                <x-fa::field label="Servierform">
                                    <x-fa::select wire:model="ziel.serving_form_id" placeholder="Wie im Foodbook">
                                        @foreach($servierformen as $s)<option value="{{ $s->id }}">{{ $s->label }}</option>@endforeach
                                    </x-fa::select>
                                </x-fa::field>
                                <x-fa::field label="Anzahl Positionen">
                                    <x-fa::input type="number" min="0" step="1" numeric wire:model="ziel.target_count" placeholder="Keine Vorgabe" />
                                </x-fa::field>
                                <x-fa::field label="Wareneinsatz-Ziel in %">
                                    <x-fa::input type="number" min="0" step="0.1" numeric wire:model="ziel.target_food_cost_pct" placeholder="Keine Vorgabe" />
                                </x-fa::field>
                                <x-fa::field label="Richtpreis in €">
                                    <x-fa::input type="number" min="0" step="0.01" numeric wire:model="ziel.price_anchor" placeholder="Keine Vorgabe" />
                                </x-fa::field>
                                <div class="grid grid-cols-2 gap-2">
                                    <x-fa::field label="Preis ab €"><x-fa::input type="number" min="0" step="0.01" numeric wire:model="ziel.price_min" placeholder="offen" /></x-fa::field>
                                    <x-fa::field label="Preis bis €"><x-fa::input type="number" min="0" step="0.01" numeric wire:model="ziel.price_max" placeholder="offen" /></x-fa::field>
                                </div>
                            </div>
                            <div class="flex justify-end">
                                <x-fa::button icon="heroicon-m-check" wire:click="zieleSpeichern" data-kapitel-ziele-speichern>Ziele speichern</x-fa::button>
                            </div>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
