{{-- Spec 94 · Konfigurator-Editor (Vollbild), aufgebaut wie der Angebots-Editor: Kopf mit Bearbeiten/Speichern und weiteren
     Aktionen, Kennzahlen-Leiste, Reiter. Zahlen kommen nur aus dem KonfiguratorPricingService (matrix). Reiter-Inhalte in
     partials/konfigurator/*. --}}
@php
    $gesperrt = in_array($sperr['modus'] ?? 'aus', ['lesen', 'fremd'], true);
    $euro = fn ($w) => $w !== null && $w !== '' ? number_format((float) $w, 2, ',', '.') . ' €' : '–';
    $leise = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]';
    $menuePunkt = 'flex w-full items-center gap-2 px-3 py-2 text-left text-[length:var(--fa-text-md)] text-[var(--fa-ink)] hover:bg-[var(--fa-hover)]';
@endphp

<x-foodalchemist::modal name="konfigurator-editor" fullscreen dark-canvas title="Konfigurator" :title-name="$k->name ?? null">
    <x-slot:actions>
        @if($k)
            <div class="ml-auto flex flex-wrap items-center gap-2">
                <div class="relative" x-data="faMenu()" x-on:keydown.escape="offen = false" x-on:click.outside="offen = false">
                    <x-fa::icon-button icon="heroicon-m-ellipsis-horizontal" label="Weitere Aktionen" x-on:click="toggle($event)" aria-haspopup="menu" x-bind:aria-expanded="offen" />
                    <div x-bind:class="{ hidden: ! offen }" x-bind:style="pos" role="menu" class="hidden w-72 fa-surface shadow-lg py-1">
                        <a href="{{ route('foodalchemist.angebote.leistung_vorlagen') }}" target="_blank" role="menuitem" class="{{ $menuePunkt }}">@svg('heroicon-o-wrench-screwdriver', 'w-4 h-4 text-[var(--fa-ink-3)]') Leistungs-Vorlagen öffnen</a>
                        <div class="my-1 border-t border-[var(--fa-line)]"></div>
                        <fieldset @disabled($gesperrt) class="contents">
                            <button type="button" role="menuitem" wire:click="loeschen" wire:confirm="Konfigurator „{{ $k->name }}“ löschen? Konzepte, Leistungen und Pakete gehen mit." x-on:click="offen = false"
                                class="flex w-full items-center gap-2 px-3 py-2 text-left text-[length:var(--fa-text-md)] text-[var(--fa-crit)] hover:bg-[var(--fa-crit-soft)]" data-konfigurator-loeschen>@svg('heroicon-o-trash', 'w-4 h-4') Konfigurator löschen</button>
                        </fieldset>
                    </div>
                </div>
                <x-foodalchemist::bearbeiten-leiste :zustand="$sperr">
                    <x-fa::button variant="primary" icon="heroicon-m-check" wire:click="speichern" data-konfigurator-speichern>Speichern</x-fa::button>
                </x-foodalchemist::bearbeiten-leiste>
            </div>
        @endif
    </x-slot:actions>

    @if($k)
        <x-slot:kpiHeader>
            @php $kz = $kennzahlen; @endphp
            <x-foodalchemist::kpi-tiles marker="konfigurator-kpis" :tiles="[
                ['kpi' => 'ab', 'label' => 'All-in ab', 'tone' => 'accent', 'value' => $kz['ab'] !== null ? $euro($kz['ab']) : 'Noch kein Preis',
                 'title' => 'Günstigstes Konzept im größten Paket, pro Person'],
                ['kpi' => 'konzepte', 'label' => 'Konzepte', 'value' => $kz['haupt'] . ($kz['addons'] ? ' + ' . $kz['addons'] . ' Add-ons' : '')],
                ['kpi' => 'pakete', 'label' => 'Pax-Pakete', 'value' => (string) $kz['pakete']],
                ['kpi' => 'db', 'label' => 'Schwächster DB', 'tone' => $kz['schwach'] !== null && $kz['schwach']['db'] < 0 ? 'bad' : null,
                 'value' => $kz['schwach'] !== null ? $euro($kz['schwach']['db']) : '–', 'title' => $kz['schwach']['wo'] ?? null],
                ['kpi' => 'warnungen', 'label' => 'Warnungen', 'tone' => $kz['warnungen'] > 0 ? 'warn' : 'good', 'value' => (string) $kz['warnungen']],
                ['kpi' => 'status', 'label' => 'Status', 'value' => ['entwurf' => 'Entwurf', 'live' => 'Live', 'archiv' => 'Archiv'][$k->status] ?? $k->status],
                ['kpi' => 'link', 'label' => 'Link', 'tone' => $k->presentation_enabled ? 'good' : null, 'value' => $k->presentation_enabled ? 'Veröffentlicht' : 'Nicht veröffentlicht'],
            ]" />
        </x-slot:kpiHeader>
    @endif

    @if($k === null)
        <x-fa::empty icon="heroicon-o-adjustments-horizontal" title="Kein Konfigurator geladen">In der Liste einen Konfigurator wählen.</x-fa::empty>
    @else
        @if($fehler)<x-fa::notice tone="crit" data-konfigurator-fehler>{{ $fehler }}</x-fa::notice>@endif
        @if($hinweis)<x-fa::notice tone="info" data-konfigurator-hinweis>{{ $hinweis }}</x-fa::notice>@endif

        <div wire:key="kfcockpit-{{ $k->id }}" class="space-y-4">
            <x-foodalchemist::editor-tabs marker="konfigurator" wire-key="konfigurator-tabs-{{ $k->id }}" :init="$k->konzepte->isEmpty() ? 'uebersicht' : 'kalkulation'" :gesperrt="$gesperrt"
                :tabs="[
                    'uebersicht' => 'Übersicht',
                    'konzepte' => 'Konzepte',
                    'leistungen' => 'Leistungen',
                    'pakete' => 'Pax-Pakete',
                    'kalkulation' => 'Kalkulation',
                ]">
                <div x-show="tab === 'uebersicht'" x-cloak class="pt-4 flex flex-col gap-4" data-konfigurator-panel="uebersicht">
                    @include('foodalchemist::livewire.angebote.partials.konfigurator.uebersicht')
                </div>
                <div x-show="tab === 'konzepte'" x-cloak class="pt-4 flex flex-col gap-4" data-konfigurator-panel="konzepte">
                    @include('foodalchemist::livewire.angebote.partials.konfigurator.konzepte')
                </div>
                <div x-show="tab === 'leistungen'" x-cloak class="pt-4 flex flex-col gap-4" data-konfigurator-panel="leistungen">
                    @include('foodalchemist::livewire.angebote.partials.konfigurator.leistungen')
                </div>
                <div x-show="tab === 'pakete'" x-cloak class="pt-4 flex flex-col gap-4" data-konfigurator-panel="pakete">
                    @include('foodalchemist::livewire.angebote.partials.konfigurator.pakete')
                </div>
                <div x-show="tab === 'kalkulation'" x-cloak class="pt-4 flex flex-col gap-4" data-konfigurator-panel="kalkulation">
                    @include('foodalchemist::livewire.angebote.partials.konfigurator.kalkulation')
                </div>
            </x-foodalchemist::editor-tabs>
        </div>
    @endif
</x-foodalchemist::modal>
