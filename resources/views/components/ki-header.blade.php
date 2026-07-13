{{--
    M0-09 / P-3: KI-Feld-Header — macht GL-07 sichtbar und bedienbar.

    LABEL — Quelle (ki|manual|unbefüllt) · Konfidenz %   [Übernehmen] [Reset] [Manuell] [✨ Autopilot]
    [Wert / Chips via Default-Slot]

    Event-Vertrag (GL-07-Quadrupel, P-3-Mapping): Die Buttons rufen per wire:click
    Methoden auf der BESITZENDEN Livewire-Komponente — Namensschema:
        ✨ Autopilot  → ai_<field>()       (Propose; persistiert NICHTS, GL-07 I3)
        Übernehmen    → accept_<field>()   (nur sichtbar bei :has-proposal="true";
                                            Override-First-Check macht der Server, GL-07 I2)
        Reset         → clear_<field>()    (Wert + Lineage → NULL; nur bei gesetzter Quelle)
        Manuell       → manual_<field>()   (Besitzer schaltet Edit-Modus frei; Edit setzt source='manual')

    Quelle-Zustände (GL-07 §4.1): null = unbefüllt · 'ki' (+ Konfidenz %) · 'manual' · 'auto'.
    reasoning wird als title-Tooltip am Quelle-Badge gereicht (Review-Hilfe).
--}}
@props([
    'label',
    'field',
    'source' => null,
    'confidence' => null,
    'reasoning' => null,
    'hasProposal' => false,
])

@php
    $ui = \Platform\FoodAlchemist\Support\Ui::maps(); // M0-12: zentrale Maps
    $badge = match ($source) {
        'ki' => ['KI', $ui['variantPill']['primary']],
        'auto' => ['Auto', $ui['variantPill']['info']],
        'manual' => ['Manuell', $ui['variantPill']['success']],
        default => ['unbefüllt', $ui['variantPill']['secondary']],
    };
    $ghostBtn = $ui['btnGhostXs'];
@endphp

<div {{ $attributes->merge(['class' => 'space-y-2']) }} data-ki-header="{{ $field }}" data-source="{{ $source ?? 'leer' }}">
    <div class="flex items-center justify-between gap-3">
        <div class="flex items-baseline gap-2 min-w-0">
            <span class="{{ $ui['label'] }}">{{ $label }}</span>
            <span class="{{ $ui['pill'] }} {{ $badge[1] }}"
                  @if($reasoning) title="{{ $reasoning }}" @endif>{{ $badge[0] }}</span>
            @if($source === 'ki' && $confidence !== null)
                <span class="text-[11px] text-gray-500" data-ki-confidence>{{ round($confidence * 100) }}%</span>
            @endif
        </div>
        <div class="flex items-center gap-1.5 shrink-0">
            @if($hasProposal)
                <button type="button" wire:click="accept_{{ $field }}"
                        class="inline-flex items-center gap-1 px-2.5 py-1 text-[11px] font-medium text-white bg-gradient-to-r from-violet-500 to-indigo-500 rounded-md shadow-sm shadow-violet-500/25 hover:shadow-md transition-all duration-150">
                    Übernehmen
                </button>
            @endif
            @if($source !== null)
                <button type="button" wire:click="clear_{{ $field }}" class="{{ $ghostBtn }}" title="Wert und Lineage zurücksetzen (GL-07 clear)">
                    Reset
                </button>
            @endif
            {{-- „Manuell" entfernt (Dominique 2026-07-01): redundant — Editieren+Speichern setzt source=manual ohnehin. --}}
            <button type="button" wire:click="ai_{{ $field }}" class="{{ $ghostBtn }} text-violet-600" title="KI-Vorschlag anfordern (persistiert nichts)">
                ✨ Autopilot
            </button>
        </div>
    </div>
    @if(trim($slot ?? ''))
        <div data-ki-value>{{ $slot }}</div>
    @endif
</div>
