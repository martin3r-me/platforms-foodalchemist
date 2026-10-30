{{-- x-fa::detail-kopf — Kopf jedes Detail-Panels (Anatomie Detail-Panels, DESIGN.md).
     Name + Untertitel, Zustands-Chips, GENAU EINE Hauptaktion (Slot „aktion"), alles Weitere im Menü „Weitere Aktionen"
     (Slot „menue", Einträge mit <x-fa::menu-item>; Löschen als letzter Eintrag mit danger).
     Slots: badges · aktion · menue · default (Zusatzzeile unter dem Titel, z. B. „Für Gäste: …"). --}}
@props(['title', 'subtitle' => null])
<header {{ $attributes->merge(['class' => 'flex flex-col gap-3']) }} data-fa-detail-kopf>
    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
            <h2 class="text-[length:var(--fa-text-lg)] font-semibold leading-snug text-[var(--fa-ink)] break-words">{{ $title }}</h2>
            @if($subtitle)<p class="mt-0.5 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">{{ $subtitle }}</p>@endif
            {{ $slot }}
        </div>
        @isset($menue)
            <div class="relative shrink-0" {{ \Platform\FoodAlchemist\Support\HandyLesemodus::attr('kopf.menue') }} x-data="faMenu()" x-on:keydown.escape="offen = false" x-on:click.outside="offen = false">
                <x-fa::icon-button size="sm" icon="heroicon-m-ellipsis-horizontal" label="Weitere Aktionen" x-on:click="toggle($event)" aria-haspopup="menu" x-bind:aria-expanded="offen" />
                <div class="hidden w-60 fa-surface shadow-lg py-1" x-bind:class="{ hidden: ! offen }" x-bind:style="pos" role="menu" x-on:click="offen = false">
                    {{ $menue }}
                </div>
            </div>
        @endisset
    </div>
    @isset($badges)
        <div class="flex flex-wrap items-center gap-1.5">{{ $badges }}</div>
    @endisset
    @isset($aktion)
        <div class="flex flex-wrap items-center gap-2" {{ \Platform\FoodAlchemist\Support\HandyLesemodus::attr('kopf.aktion') }}>{{ $aktion }}</div>
    @endisset
    @if(isset($aktion) || isset($menue))
        {{-- Handy-Lesemodus (#1003): nur am Handy in Lese-Bereichen sichtbar, dort sind die Aktionen oben aus --}}
        <p class="items-center gap-1.5 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]" data-fa-handy-hinweis>
            @svg('heroicon-m-computer-desktop', 'w-4 h-4 shrink-0') Am Computer bearbeiten
            {{-- Notausgang: Desktop-Ansicht auf diesem Gerät (Support\HandyLesemodus::JS_MODUS liest fa-ansicht) --}}
            <button type="button" class="ml-auto underline underline-offset-2" x-on:click="window.faAnsicht('desktop')" data-fa-desktop-ansicht>Desktop-Ansicht</button>
        </p>
    @endif
</header>
