{{-- Spec 59: Karte „Abwechslung · Woche“ in der Kennzahlen-Rail — Plan-Vorgaben (ist/mind./höchstens + Status)
     über den Ist-Chips (Diät je Gericht, Warengruppen). Erwartet: $ab (SpeiseplanService::wochenAbwechslung),
     $mahlzeiten. Rechnet NICHTS.
     Hervorheben: jeder Chip ist ein Knopf und setzt den Alpine-Zustand `markiert` (Eintrag-Ids) im
     Eltern-Scope des Editors; die Zellen der Wochen-Matrix reagieren per :class. Zweiter Klick hebt auf.
     Rein clientseitig — kein Server-Roundtrip. --}}
@php($dm = $ab['diaet'])
@php($vorgabenStatus = ['ok' => ['success', 'ok'], 'zu_wenig' => ['warning', 'zu wenig'], 'zu_viel' => ['warning', 'zu viel']])
@php($diaetAnzeige = [
    'vegan' => ['Vegan', 'success'],
    'vegetarisch' => ['Vegetarisch', 'info'],
    'fleisch' => ['Fleisch', 'secondary'],
    'fisch' => ['Fisch', 'secondary'],
])
<div class="rounded-xl border border-white/10 bg-white/[0.04] p-3 space-y-1.5" data-sp-abwechslung>
    <div class="flex items-center justify-between gap-2">
        <span class="{{ $label }}">Abwechslung · Woche</span>
        <button type="button" x-show="markiert !== null" x-cloak x-on:click="markiert = null; markierKey = null"
                class="text-[10px] text-gray-400 hover:text-gray-200 underline">Markierung aufheben</button>
    </div>

    @if(! empty($ab['vorgaben']))
        <div class="space-y-1 pb-1.5 border-b border-white/10" data-sp-abwechslung-vorgaben>
            <div class="text-[10px] uppercase tracking-wider text-gray-500">Vorgaben</div>
            @foreach($ab['vorgaben'] as $v)
                @php($key = 'v:' . $v['chip_id'] . ':' . ($v['mahlzeit'] ?? 'alle'))
                @php($st = $vorgabenStatus[$v['status']] ?? ['secondary', $v['status']])
                <button type="button" class="w-full flex items-center justify-between gap-2 text-left text-[11px] rounded-md px-1 py-0.5 hover:bg-white/[0.06]"
                        x-on:click="markierKey === @js($key) ? (markiert = null, markierKey = null) : (markiert = @js($v['eintrag_ids']), markierKey = @js($key))"
                        x-bind:class="markierKey === @js($key) ? 'bg-violet-500/15 ring-1 ring-violet-400/40' : ''"
                        title="Klick hebt die zählenden Gerichte in der Wochen-Matrix hervor"
                        data-sp-vorgabe-status="{{ $v['status'] }}">
                    <span class="min-w-0 truncate text-gray-200">{{ $v['label'] }}@if($v['mahlzeit'] !== null)<span class="text-gray-500"> · {{ $mahlzeiten[$v['mahlzeit']] ?? $v['mahlzeit'] }}</span>@endif</span>
                    <span class="flex items-center gap-1.5 shrink-0">
                        <span class="tabular-nums text-gray-400">ist {{ $v['ist'] }}@if($v['min'] !== null) · mind. {{ $v['min'] }}@endif @if($v['max'] !== null) · höchstens {{ $v['max'] }}@endif</span>
                        <span class="{{ $pill }} {{ $variantPill[$st[0]] }}">{{ $st[1] }}</span>
                    </span>
                </button>
            @endforeach
        </div>
    @else
        <button type="button" x-on:click="document.querySelector('[data-sp-tab=stammdaten]')?.click()"
                class="text-[10px] text-violet-300 hover:text-violet-200 underline" data-sp-vorgaben-festlegen>Vorgaben festlegen</button>
    @endif

    <div class="flex flex-wrap gap-1 text-[11px]" data-sp-abwechslung-diaet>
        @foreach($diaetAnzeige as $dk => [$dl, $dton])
            @php($key = 'd:' . $dk)
            <button type="button" class="{{ $pill }} {{ $variantPill[$dton] }}"
                    x-on:click="markierKey === @js($key) ? (markiert = null, markierKey = null) : (markiert = @js($ab['diaet_eintraege'][$dk] ?? []), markierKey = @js($key))"
                    x-bind:class="markierKey === @js($key) ? 'ring-1 ring-violet-400/60' : ''">{{ $dl }} {{ $dm[$dk] ?? 0 }}</button>
        @endforeach
        @if(($dm['ohne_angabe'] ?? 0) > 0)
            @php($key = 'd:ohne_angabe')
            <button type="button" class="{{ $pill }} {{ $variantPill['warning'] }}" title="Gerichte ohne Diät-Kennzeichnung — am Gericht pflegen"
                    x-on:click="markierKey === @js($key) ? (markiert = null, markierKey = null) : (markiert = @js($ab['diaet_eintraege']['ohne_angabe'] ?? []), markierKey = @js($key))"
                    x-bind:class="markierKey === @js($key) ? 'ring-1 ring-violet-400/60' : ''"
                    data-sp-abwechslung-ohne-angabe>ohne Angabe {{ $dm['ohne_angabe'] }}</button>
        @endif
    </div>
    @if(! empty($ab['warengruppen']))
        <div class="flex flex-wrap gap-1 pt-1.5 border-t border-white/10">
            @foreach($ab['warengruppen'] as $w)
                @php($key = 'w:' . ($w['id'] ?? $w['name']))
                <button type="button" class="{{ $pill }} {{ $variantPill['secondary'] }}"
                        x-on:click="markierKey === @js($key) ? (markiert = null, markierKey = null) : (markiert = @js($ab['wg_eintraege'][$w['id'] ?? 0] ?? []), markierKey = @js($key))"
                        x-bind:class="markierKey === @js($key) ? 'ring-1 ring-violet-400/60' : ''">{{ $w['name'] }} ×{{ $w['count'] }}</button>
            @endforeach
        </div>
    @endif
    @if($ab['hinweis'])<p class="text-[10px] text-amber-300/80">{{ $ab['hinweis'] }}</p>@endif
</div>
