{{-- Spec 59: Karte „Abwechslung der Woche“ in der rechten Spalte: Vorgaben des Plans (ist/mind./höchstens + Status)
     über den Ist-Zahlen (Diät je Gericht, Hauptgruppen). Erwartet: $ab (SpeiseplanService::wochenAbwechslung),
     $mahlzeiten und die Seitenklassen des Editors ($karte, $etikett, $leise). Rechnet NICHTS.
     Hervorheben: jeder Eintrag ist ein Knopf und setzt den Alpine-Zustand `markiert` (Eintrag-Ids) im
     Eltern-Scope des Editors; die Zellen im Wochen-Raster reagieren per :class (Akzent-Ring). Zweiter Klick
     hebt auf. Rein clientseitig, kein Server-Roundtrip. --}}
@php
    $dm = $ab['diaet'];
    $vorgabenStatus = ['ok' => ['ok', 'erfüllt'], 'zu_wenig' => ['warn', 'zu wenig'], 'zu_viel' => ['warn', 'zu viel']];
    // Gleiche Tonlage wie die Kostform-Kürzel in den Zellen: pflanzlich grün, Fisch blau, Fleisch neutral.
    $diaetAnzeige = [
        'vegan' => ['Vegan', 'ok'],
        'vegetarisch' => ['Vegetarisch', 'ok'],
        'fleisch' => ['Fleisch', 'neutral'],
        'fisch' => ['Fisch', 'info'],
    ];
    $markierKnopf = 'rounded-full focus-visible:outline-2 focus-visible:outline-[var(--fa-accent)]';
@endphp
<div class="{{ $karte }}" data-sp-abwechslung>
    <div class="flex items-center justify-between gap-2">
        <span class="{{ $etikett }}">Abwechslung der Woche</span>
        <x-fa::button size="sm" variant="ghost" icon="heroicon-m-x-mark" x-show="markiert !== null" x-cloak
            x-on:click="markiert = null; markierKey = null" data-sp-markierung-aufheben>Aufheben</x-fa::button>
    </div>

    @if(! empty($ab['vorgaben']))
        <div class="flex flex-col gap-0.5 pb-2 border-b border-[var(--fa-line)]" data-sp-abwechslung-vorgaben>
            <span class="{{ $leise }}">Vorgaben</span>
            @foreach($ab['vorgaben'] as $v)
                @php
                    $key = 'v:' . $v['chip_id'] . ':' . ($v['mahlzeit'] ?? 'alle');
                    $st = $vorgabenStatus[$v['status']] ?? ['neutral', $v['status']];
                    $soll = trim(($v['min'] !== null ? 'mind. ' . $v['min'] : '') . ($v['min'] !== null && $v['max'] !== null ? ', ' : '') . ($v['max'] !== null ? 'höchstens ' . $v['max'] : ''));
                @endphp
                <button type="button"
                        class="w-full flex items-center justify-between gap-2 -mx-1 px-1 py-1 rounded-[var(--fa-radius-control)] text-left text-[length:var(--fa-text-sm)] hover:bg-[var(--fa-hover)]"
                        x-on:click="markierKey === @js($key) ? (markiert = null, markierKey = null) : (markiert = @js($v['eintrag_ids']), markierKey = @js($key))"
                        x-bind:class="markierKey === @js($key) ? 'bg-[var(--fa-accent-soft)] ring-1 ring-[var(--fa-accent)]' : ''"
                        x-bind:aria-pressed="markierKey === @js($key)"
                        title="Hebt die zählenden Gerichte im Kalender hervor"
                        data-sp-vorgabe-status="{{ $v['status'] }}">
                    <span class="min-w-0 flex flex-col">
                        <span class="truncate font-medium text-[var(--fa-ink)]">{{ $v['label'] }}@if($v['mahlzeit'] !== null)<span class="font-normal text-[var(--fa-ink-3)]"> · {{ $mahlzeiten[$v['mahlzeit']] ?? $v['mahlzeit'] }}</span>@endif</span>
                        <span class="tabular-nums text-[var(--fa-ink-3)]">ist {{ $v['ist'] }}@if($soll !== ''), {{ $soll }}@endif</span>
                    </span>
                    <x-fa::badge :tone="$st[0]" class="shrink-0">{{ $st[1] }}</x-fa::badge>
                </button>
            @endforeach
        </div>
    @else
        <div>
            <x-fa::button size="sm" variant="ghost" icon="heroicon-m-plus" data-sp-vorgaben-festlegen
                x-on:click="document.querySelector('[data-sp-tab=stammdaten]')?.click(); setTimeout(() => document.querySelector('[data-sp-vorgaben]')?.scrollIntoView({ block: 'start', behavior: 'smooth' }), 60)">Vorgaben festlegen</x-fa::button>
        </div>
    @endif

    <div class="flex flex-wrap gap-1" data-sp-abwechslung-diaet>
        @foreach($diaetAnzeige as $dk => [$dl, $dton])
            @php($key = 'd:' . $dk)
            <button type="button" class="{{ $markierKnopf }}"
                    x-on:click="markierKey === @js($key) ? (markiert = null, markierKey = null) : (markiert = @js($ab['diaet_eintraege'][$dk] ?? []), markierKey = @js($key))"
                    x-bind:class="markierKey === @js($key) ? 'ring-2 ring-[var(--fa-accent)]' : ''"
                    x-bind:aria-pressed="markierKey === @js($key)">
                <x-fa::badge :tone="$dton">{{ $dl }} {{ $dm[$dk] ?? 0 }}</x-fa::badge>
            </button>
        @endforeach
        @if(($dm['ohne_angabe'] ?? 0) > 0)
            @php($key = 'd:ohne_angabe')
            <button type="button" class="{{ $markierKnopf }}" title="Gerichte ohne Diät-Angabe, am Gericht pflegen"
                    x-on:click="markierKey === @js($key) ? (markiert = null, markierKey = null) : (markiert = @js($ab['diaet_eintraege']['ohne_angabe'] ?? []), markierKey = @js($key))"
                    x-bind:class="markierKey === @js($key) ? 'ring-2 ring-[var(--fa-accent)]' : ''"
                    x-bind:aria-pressed="markierKey === @js($key)"
                    data-sp-abwechslung-ohne-angabe>
                <x-fa::badge tone="warn" icon="heroicon-m-question-mark-circle">ohne Angabe {{ $dm['ohne_angabe'] }}</x-fa::badge>
            </button>
        @endif
    </div>
    @if(! empty($ab['warengruppen']))
        <div class="flex flex-wrap gap-1 pt-2 border-t border-[var(--fa-line)]" data-sp-abwechslung-warengruppen>
            @foreach($ab['warengruppen'] as $w)
                @php($key = 'w:' . ($w['id'] ?? $w['name']))
                <button type="button" class="{{ $markierKnopf }}"
                        x-on:click="markierKey === @js($key) ? (markiert = null, markierKey = null) : (markiert = @js($ab['wg_eintraege'][$w['id'] ?? 0] ?? []), markierKey = @js($key))"
                        x-bind:class="markierKey === @js($key) ? 'ring-2 ring-[var(--fa-accent)]' : ''"
                        x-bind:aria-pressed="markierKey === @js($key)">
                    <x-fa::badge>{{ $w['name'] }} ×{{ $w['count'] }}</x-fa::badge>
                </button>
            @endforeach
        </div>
    @endif
    @if($ab['hinweis'])<x-fa::signal tone="warn">{{ $ab['hinweis'] }}</x-fa::signal>@endif
    <p class="{{ $leise }}">Klick auf einen Eintrag hebt die Gerichte im Kalender hervor.</p>
</div>
