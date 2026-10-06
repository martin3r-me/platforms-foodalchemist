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
            <div class="relative shrink-0" x-data="faMenu()" x-on:keydown.escape="offen = false" x-on:click.outside="offen = false">
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
        <div class="flex flex-wrap items-center gap-2">{{ $aktion }}</div>
    @endisset
</header>
