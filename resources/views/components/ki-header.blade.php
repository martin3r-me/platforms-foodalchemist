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
        Manuell       → manual_<field>()   (Besitzer schaltet Edit-Modus frei; Edit setzt quelle='manual')

    Quelle-Zustände (GL-07 §4.1): null = unbefüllt · 'ki' (+ Konfidenz %) · 'manual' · 'auto'.
    begruendung wird als title-Tooltip am Quelle-Badge gereicht (Review-Hilfe).
--}}
@props([
    'label',
    'field',
    'quelle' => null,
    'confidence' => null,
    'begruendung' => null,
    'hasProposal' => false,
])

@php
    $badge = match ($quelle) {
        'ki' => ['KI', 'bg-violet-500/10 text-violet-600 dark:text-violet-400'],
        'auto' => ['Auto', 'bg-sky-500/10 text-sky-600 dark:text-sky-400'],
        'manual' => ['Manuell', 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400'],
        default => ['unbefüllt', 'bg-black/5 dark:bg-white/10 text-gray-500 dark:text-gray-400'],
    };
    $ghostBtn = 'inline-flex items-center gap-1 px-2 py-1 text-xs font-medium text-gray-600 dark:text-gray-300 bg-white/60 dark:bg-white/5 border border-black/5 dark:border-white/10 rounded-md hover:bg-white/80 dark:hover:bg-white/10 transition-all duration-150';
@endphp

<div {{ $attributes->merge(['class' => 'space-y-2']) }} data-ki-header="{{ $field }}" data-quelle="{{ $quelle ?? 'leer' }}">
    <div class="flex items-center justify-between gap-3">
        <div class="flex items-baseline gap-2 min-w-0">
            <span class="text-xs font-medium uppercase tracking-wider text-gray-400">{{ $label }}</span>
            <span class="inline-flex px-2 py-0.5 rounded-full text-xs {{ $badge[1] }}"
                  @if($begruendung) title="{{ $begruendung }}" @endif>{{ $badge[0] }}</span>
            @if($quelle === 'ki' && $confidence !== null)
                <span class="text-xs text-gray-400" data-ki-confidence>{{ round($confidence * 100) }}%</span>
            @endif
        </div>
        <div class="flex items-center gap-1.5 shrink-0">
            @if($hasProposal)
                <button type="button" wire:click="accept_{{ $field }}"
                        class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-medium text-white bg-gradient-to-r from-violet-500 to-indigo-500 rounded-md shadow-sm shadow-violet-500/25 hover:shadow-md transition-all duration-150">
                    Übernehmen
                </button>
            @endif
            @if($quelle !== null)
                <button type="button" wire:click="clear_{{ $field }}" class="{{ $ghostBtn }}" title="Wert und Lineage zurücksetzen (GL-07 clear)">
                    Reset
                </button>
            @endif
            <button type="button" wire:click="manual_{{ $field }}" class="{{ $ghostBtn }}" title="Manuell pflegen (setzt Quelle auf manual)">
                Manuell
            </button>
            <button type="button" wire:click="ai_{{ $field }}" class="{{ $ghostBtn }} text-violet-600 dark:text-violet-400" title="KI-Vorschlag anfordern (persistiert nichts)">
                ✨ Autopilot
            </button>
        </div>
    </div>
    @if(trim($slot ?? ''))
        <div data-ki-value>{{ $slot }}</div>
    @endif
</div>
