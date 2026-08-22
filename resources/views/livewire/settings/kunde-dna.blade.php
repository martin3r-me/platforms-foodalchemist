{{-- Kunde-DNA als Einstellungen-Sektion (Ebene 2 der DNA-Kette) — Firma wählen → geteiltes Canvas-Board --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))
<div>
    <div class="relative overflow-hidden {{ $card }} p-5 space-y-3">
        <div class="{{ $cardAccent }}"></div>
        <p class="{{ $label }} mb-1">Kunde-DNA — Marken-/Kunden-Identität. Ebene 2 der DNA-Kette (Team → Kunde → Foodbook): fließt als stehende Referenz in jede KI-Generierung für diesen Kunden.</p>

        @if(! $crmVerfuegbar)
            <p class="text-xs text-amber-600" data-kunde-dna-kein-crm>CRM ist nicht verfügbar — Kunden-DNA benötigt die CRM-Anbindung.</p>
        @else
            {{-- Firma-Auswahl --}}
            @if($companyId === null)
                <div class="space-y-2" data-kunde-dna-picker>
                    <label class="{{ $label }}">Kunde wählen</label>
                    <input type="search" wire:model.live.debounce.300ms="firmaSuche" placeholder="Firma suchen …" class="{{ $input }} max-w-md" data-kunde-dna-suche>
                    @if($firmen->isNotEmpty())
                        <div class="border border-gray-200 rounded-lg divide-y divide-gray-100 max-w-md">
                            @foreach($firmen as $f)
                                <button type="button" wire:key="kdna-fi-{{ $f->id }}" wire:click="firmaWaehlen({{ $f->id }}, @js($f->display_name))"
                                        class="w-full text-left px-3 py-2 text-sm hover:bg-violet-500/10">{{ $f->display_name }}</button>
                            @endforeach
                        </div>
                    @elseif(trim($firmaSuche) !== '')
                        <p class="text-[11px] text-gray-500">Keine Firma gefunden.</p>
                    @endif
                </div>
            @else
                <div class="flex items-center gap-2" data-kunde-dna-gewaehlt>
                    <span class="{{ $pill }} {{ $variantPill['primary'] }}">{{ $companyName }}</span>
                    <button type="button" wire:click="firmaLoesen" class="{{ $btnGhostXs ?? 'text-xs text-gray-500 hover:text-gray-700' }}">andere Firma</button>
                </div>
            @endif
        @endif

        {{-- Canvas-Board erst nach Firmen-Wahl (canvasInit ist dann gelaufen) --}}
        @if($companyId !== null && $crmVerfuegbar)
            <div class="pt-2 border-t border-black/5">
                @include('foodalchemist::livewire.canvas.partials.board')
            </div>
        @endif
    </div>
</div>
