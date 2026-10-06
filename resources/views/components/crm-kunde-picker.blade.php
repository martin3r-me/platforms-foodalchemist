@props([
    'ausgabe',
    'crmVerfuegbar' => false,
    'firmen' => collect(),
    'kontakte' => collect(),
])
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<div class="space-y-2 pt-1 border-t border-[var(--fa-line)]" data-crm-kunde-picker>
    <span class="{{ $label }}">Kunde (CRM)</span>
    @if(! $crmVerfuegbar)
        <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">CRM-Modul nicht verfügbar — diese Ausgabe bleibt ohne Kunde.</p>
    @else
        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-[var(--fa-ink-2)]">
            <div>Firma: <span class="font-medium text-[var(--fa-ink)]">{{ $ausgabe?->crmCompany?->display_name ?? '—' }}</span></div>
            <div>Kontakt: <span class="font-medium text-[var(--fa-ink)]">{{ $ausgabe?->crmContact?->display_name ?? '—' }}</span></div>
            @if(($ausgabe?->crm_company_id ?? null) || ($ausgabe?->crm_contact_id ?? null))
                <button type="button" wire:click="loeseKunde" class="{{ $btnGhostXs }}">Verknüpfung lösen</button>
            @endif
        </div>
        <div class="grid md:grid-cols-2 gap-2">
            <div>
                <input type="search" wire:model.live.debounce.300ms="firmaSuche" placeholder="Firma suchen ..." class="{{ $input }}" data-crm-firma-suche />
                @if($firmen->isNotEmpty())
                    <div class="mt-1 max-h-36 overflow-auto rounded-[var(--fa-radius-surface)] border border-[var(--fa-line-strong)] bg-[var(--fa-surface)] shadow-sm">
                        @foreach($firmen as $f)
                            <button type="button" wire:key="crm-fi-{{ $f->id }}" wire:click="verknuepfeFirma({{ $f->id }})" class="w-full text-left px-2 py-1 rounded-[var(--fa-radius-surface)] text-xs hover:bg-[var(--fa-hover)]">{{ $f->display_name }}</button>
                        @endforeach
                    </div>
                @endif
            </div>
            <div>
                <input type="search" wire:model.live.debounce.300ms="kontaktSuche" placeholder="Kontakt suchen ..." class="{{ $input }}" data-crm-kontakt-suche />
                @if($kontakte->isNotEmpty())
                    <div class="mt-1 max-h-36 overflow-auto rounded-[var(--fa-radius-surface)] border border-[var(--fa-line-strong)] bg-[var(--fa-surface)] shadow-sm">
                        @foreach($kontakte as $k)
                            <button type="button" wire:key="crm-ko-{{ $k->id }}" wire:click="verknuepfeKontakt({{ $k->id }})" class="w-full text-left px-2 py-1 rounded-[var(--fa-radius-surface)] text-xs hover:bg-[var(--fa-hover)]">{{ $k->display_name }}</button>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
