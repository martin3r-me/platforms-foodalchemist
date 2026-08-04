@props([
    'ausgabe',
    'crmVerfuegbar' => false,
    'firmen' => collect(),
    'kontakte' => collect(),
])
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<div class="space-y-2 pt-1 border-t border-black/5" data-crm-kunde-picker>
    <span class="{{ $label }}">Kunde (CRM)</span>
    @if(! $crmVerfuegbar)
        <p class="text-[11px] text-gray-500">CRM-Modul nicht verfügbar — diese Ausgabe bleibt ohne Kunde.</p>
    @else
        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-gray-600">
            <div>Firma: <span class="font-medium text-gray-900">{{ $ausgabe?->crmCompany?->display_name ?? '—' }}</span></div>
            <div>Kontakt: <span class="font-medium text-gray-900">{{ $ausgabe?->crmContact?->display_name ?? '—' }}</span></div>
            @if(($ausgabe?->crm_company_id ?? null) || ($ausgabe?->crm_contact_id ?? null))
                <button type="button" wire:click="loeseKunde" class="{{ $btnGhostXs }}">Verknüpfung lösen</button>
            @endif
        </div>
        <div class="grid md:grid-cols-2 gap-2">
            <div>
                <input type="search" wire:model.live.debounce.300ms="firmaSuche" placeholder="Firma suchen ..." class="{{ $input }}" data-crm-firma-suche />
                @if($firmen->isNotEmpty())
                    <div class="mt-1 max-h-36 overflow-auto rounded-lg border border-black/10 bg-white shadow-sm">
                        @foreach($firmen as $f)
                            <button type="button" wire:key="crm-fi-{{ $f->id }}" wire:click="verknuepfeFirma({{ $f->id }})" class="w-full text-left px-2 py-1 rounded-lg text-xs hover:bg-violet-500/10">{{ $f->display_name }}</button>
                        @endforeach
                    </div>
                @endif
            </div>
            <div>
                <input type="search" wire:model.live.debounce.300ms="kontaktSuche" placeholder="Kontakt suchen ..." class="{{ $input }}" data-crm-kontakt-suche />
                @if($kontakte->isNotEmpty())
                    <div class="mt-1 max-h-36 overflow-auto rounded-lg border border-black/10 bg-white shadow-sm">
                        @foreach($kontakte as $k)
                            <button type="button" wire:key="crm-ko-{{ $k->id }}" wire:click="verknuepfeKontakt({{ $k->id }})" class="w-full text-left px-2 py-1 rounded-lg text-xs hover:bg-violet-500/10">{{ $k->display_name }}</button>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
