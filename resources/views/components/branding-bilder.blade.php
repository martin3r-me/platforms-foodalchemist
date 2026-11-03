{{-- x-foodalchemist::branding-bilder — Logo und Titelbild hochladen bzw. entfernen, eine Oberfläche für Foodbook und Konfigurator.
     Erwartet in der Livewire-Komponente: logoUpload, coverUpload (WithFileUploads, Auto-Upload in updated…),
     brandingLogoEntfernen(), brandingCoverEntfernen(). URLs kommen aufgelöst (FoodAlchemistMediaService::url). --}}
@props(['logoUrl' => null, 'coverUrl' => null])
@php
    $dateiFeld = 'block w-full text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)] cursor-pointer file:mr-2 file:h-7 file:px-2.5 file:rounded-[var(--fa-radius-control)] file:border file:border-[var(--fa-line-strong)] file:bg-[var(--fa-surface)] file:text-[var(--fa-ink)] file:text-[length:var(--fa-text-sm)] file:font-medium hover:file:bg-[var(--fa-hover)]';
    $hinweis = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]';
    $fehler = 'text-[length:var(--fa-text-sm)] text-[var(--fa-crit)]';
@endphp
<div {{ $attributes->merge(['class' => 'grid gap-4 md:grid-cols-2']) }}>
    <x-fa::field label="Logo">
        @if($logoUrl)
            <div class="flex items-center gap-2">
                <img src="{{ $logoUrl }}" alt="Logo" class="h-10 max-w-[120px] object-contain rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] bg-[var(--fa-surface)] p-1" />
                <x-fa::button variant="danger" size="sm" icon="heroicon-m-trash" wire:click="brandingLogoEntfernen" data-logo-entfernen>Logo entfernen</x-fa::button>
            </div>
        @endif
        <input type="file" wire:model="logoUpload" accept="image/*" class="{{ $dateiFeld }}" aria-label="Logo hochladen" data-logo-upload />
        <span wire:loading wire:target="logoUpload" class="{{ $hinweis }}">Wird hochgeladen …</span>
        @error('logoUpload')<span class="{{ $fehler }}" role="alert">{{ $message }}</span>@enderror
    </x-fa::field>
    <x-fa::field label="Titelbild">
        @if($coverUrl)
            <div class="flex items-center gap-2">
                <img src="{{ $coverUrl }}" alt="Titelbild" class="h-10 max-w-[120px] object-cover rounded-[var(--fa-radius-control)] border border-[var(--fa-line)]" />
                <x-fa::button variant="danger" size="sm" icon="heroicon-m-trash" wire:click="brandingCoverEntfernen" data-cover-entfernen>Titelbild entfernen</x-fa::button>
            </div>
        @endif
        <input type="file" wire:model="coverUpload" accept="image/*" class="{{ $dateiFeld }}" aria-label="Titelbild hochladen" data-cover-upload />
        <span wire:loading wire:target="coverUpload" class="{{ $hinweis }}">Wird hochgeladen …</span>
        @error('coverUpload')<span class="{{ $fehler }}" role="alert">{{ $message }}</span>@enderror
    </x-fa::field>
</div>
