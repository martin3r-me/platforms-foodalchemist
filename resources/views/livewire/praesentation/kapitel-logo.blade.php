{{-- Optionales Logo je Kapitel (Präsentation 2026-11-13). Eingebettet im Foodbook-, Angebots- und Speisekarten-Editor. --}}
<div data-kapitel-logo="{{ $art }}">
    <x-fa::field label="Logo im Kapitel" optional hint="Klein im Kapitel, zusätzlich zu den Kapitelbildern. Position und Sichtbarkeit stellst du im Präsentations-Design ein.">
        <div class="flex flex-wrap items-center gap-3">
            @if($logoUrl)
                <img src="{{ $logoUrl }}" alt="" class="h-12 w-20 object-contain rounded-[var(--fa-radius-control)] border border-[var(--fa-line)]">
                <x-fa::button variant="danger" size="sm" icon="heroicon-m-trash" wire:click="entfernen" data-kapitel-logo-remove>Logo entfernen</x-fa::button>
            @endif
            <input type="file" wire:model="logoUpload" accept="image/*" class="block text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]" aria-label="Kapitel-Logo hochladen" data-kapitel-logo-upload>
            <span wire:loading wire:target="logoUpload" class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Wird hochgeladen …</span>
        </div>
        @if($fehler)<span class="text-[length:var(--fa-text-sm)] text-[var(--fa-crit)]" role="alert">{{ $fehler }}</span>@endif
        @error('logoUpload')<span class="text-[length:var(--fa-text-sm)] text-[var(--fa-crit)]" role="alert">{{ $message }}</span>@enderror
    </x-fa::field>
</div>
