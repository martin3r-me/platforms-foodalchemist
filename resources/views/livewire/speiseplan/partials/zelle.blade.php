{{-- Spec 57 · Paket 1: ein Eintrag im Wochenraster „auf einen Blick" — fa-pass (2026-10-05), nur Tokens.
     Erwartet: $e (Eintrag-Model), $k (Kennzahlen aus SpeiseplanService::zellenKennzahlen oder null),
     $farbe (Linienfarbe oder null), $dichte (kompakt|detail), $sp (Plan), $detailId.
     Rechnet NICHTS — alle Zahlen kommen fertig aus dem Service.
     Lesereihenfolge: Name → Kennzeichnung → Preis · Wareneinsatz · Essen. Die Linienfarbe sitzt als
     Streifen links (lesbar hell und dunkel), ein fehlender Preis wird als Signal gezeigt, nicht als 0,00 €. --}}
@php
    $k = is_array($k ?? null) ? $k : [];
    $titel = $k['titel'] ?? $e->inhaltName();
    $st = $k['status'] ?? 'unbekannt';
    $wes = $k['wes'] ?? null;
    $band = $k['band'] ?? ['min' => null, 'max' => 0, 'quelle' => 'team'];
    $paxStandard = $k['pax'] ?? $sp->default_pax;
    $vk = (float) ($k['vk'] ?? 0);
    $statusFarbe = ['ok' => 'bg-[var(--fa-ok)]', 'unter' => 'bg-[var(--fa-info)]', 'ueber' => 'bg-[var(--fa-warn)]', 'weit_ueber' => 'bg-[var(--fa-crit)]', 'unbekannt' => 'bg-[var(--fa-ink-3)]'];
    $statusText = ['ok' => 'text-[var(--fa-ok)]', 'unter' => 'text-[var(--fa-info)]', 'ueber' => 'text-[var(--fa-warn)]', 'weit_ueber' => 'text-[var(--fa-crit)]', 'unbekannt' => 'text-[var(--fa-ink-3)]'];
    $statusLabel = ['ok' => 'im Zielband', 'unter' => 'unter dem Zielband', 'ueber' => 'über dem Zielband', 'weit_ueber' => 'weit über dem Zielband', 'unbekannt' => 'ohne Verkaufspreis nicht berechenbar'];
    // Kostform-Kürzel: pflanzlich grün, Fisch blau, Fleischarten neutral (keine Alarmfarbe für Tierarten).
    $diaetChip = [
        'vegan' => ['Vg', 'vegan', 'bg-[var(--fa-ok-soft)] text-[var(--fa-ok)]'],
        'vegetarisch' => ['Vt', 'vegetarisch', 'bg-[var(--fa-ok-soft)] text-[var(--fa-ok)]'],
        'schwein' => ['Sw', 'Schwein', 'bg-[var(--fa-neutral-soft)] text-[var(--fa-ink-2)]'],
        'rind' => ['Rd', 'Rind', 'bg-[var(--fa-neutral-soft)] text-[var(--fa-ink-2)]'],
        'fisch' => ['Fi', 'Fisch', 'bg-[var(--fa-info-soft)] text-[var(--fa-info)]'],
        'fleisch' => ['Fl', 'Fleisch (Tierart nicht gepflegt)', 'bg-[var(--fa-neutral-soft)] text-[var(--fa-ink-2)]'],
    ];
    $bandText = ($band['min'] !== null ? number_format((float) $band['min'], 0) . ' bis ' : 'bis ') . number_format((float) $band['max'], 0) . ' %' . (($band['quelle'] ?? '') === 'team' ? ', Ziel des Betriebs' : '');
    $wesText = $wes !== null ? number_format((float) $wes, 0, ',', '.') . ' %' : '–';
    $offen = ($detailId ?? null) === $e->id;
@endphp
{{-- Spec 57 · Paket 5: ziehbar (Drop-Ziel = Zelle im Raster, Alpine `dragId`), Titel öffnet das Detail
     (Ersetzen/Verschieben/Kopieren) — auch per Tastatur.
     Spec 59: `markiert` (Eintrag-Ids aus der Abwechslungs-Karte) hebt per Akzent-Ring hervor, der Rest tritt zurück. --}}
<div wire:key="e-{{ $e->id }}" class="group rounded-[var(--fa-radius-control)] border border-l-[3px] bg-[var(--fa-surface)] px-2 py-1.5 text-left text-[var(--fa-ink)] {{ $offen ? 'border-[var(--fa-accent)] ring-1 ring-[var(--fa-accent)]' : 'border-[var(--fa-line)]' }}"
     @if($farbe && ! $offen) style="border-left-color: {{ $farbe }}" @endif data-sp-zelle="{{ $e->id }}"
     draggable="true" x-on:dragstart="dragId = {{ $e->id }}" x-on:dragend="dragId = null"
     x-bind:class="(dragId === {{ $e->id }} ? 'opacity-40 ' : '') + (typeof markiert !== 'undefined' && markiert !== null ? (markiert.includes({{ $e->id }}) ? 'ring-2 ring-[var(--fa-accent)]' : 'opacity-40') : '')">
    <div class="flex items-start gap-1">
        <button type="button" wire:click="eintragOeffnen({{ $e->id }})" class="flex-1 min-w-0 text-left rounded-sm hover:text-[var(--fa-accent)]" aria-label="{{ $titel }}: Details, ersetzen, verschieben, kopieren">
            <span class="block text-[length:var(--fa-text-md)] font-medium leading-snug break-words">{{ $titel }}</span>
            @if(! empty($k['untertitel']))
                <span class="block text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)] leading-snug line-clamp-2">{{ $k['untertitel'] }}</span>
            @endif
        </button>
        <span class="shrink-0 flex items-center">
            <span class="cursor-move select-none text-[var(--fa-ink-3)] opacity-0 group-hover:opacity-100" aria-hidden="true" title="Ziehen zum Verschieben">@svg('heroicon-m-bars-2', 'w-3.5 h-3.5')</span>
            <button type="button" wire:click="eintragRaus({{ $e->id }})" wire:confirm="Eintrag entfernen?"
                    class="inline-flex items-center justify-center w-6 h-6 rounded-[var(--fa-radius-control)] text-[var(--fa-ink-3)] opacity-60 group-hover:opacity-100 focus:opacity-100 hover:text-[var(--fa-crit)] hover:bg-[var(--fa-crit-soft)]"
                    aria-label="{{ $titel }} entfernen" title="Eintrag entfernen">@svg('heroicon-m-x-mark', 'w-3.5 h-3.5')</button>
        </span>
    </div>

    @if(($k['diaet'] ?? []) !== [] || ($k['codes'] ?? []) !== [])
        <div class="flex flex-wrap items-center gap-1 mt-1">
            @foreach($k['diaet'] ?? [] as $d)
                @if(isset($diaetChip[$d]))
                    <span class="inline-flex items-center h-[18px] px-1.5 rounded text-[length:var(--fa-text-sm)] font-medium leading-none {{ $diaetChip[$d][2] }}" title="{{ $diaetChip[$d][1] }}">{{ $diaetChip[$d][0] }}</span>
                @endif
            @endforeach
            @if(($k['codes'] ?? []) !== [])
                <span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)] tracking-wide" title="Kennzeichnung: Allergene A bis N, Zusatzstoffe 1 bis 18, Stern = Spuren">{{ implode(' ', $k['codes']) }}</span>
            @endif
        </div>
    @endif

    <div class="flex flex-wrap items-center justify-between gap-x-2 gap-y-1 mt-1.5 text-[length:var(--fa-text-sm)] tabular-nums">
        @if($vk > 0)
            <span class="text-[var(--fa-ink)]" title="Verkaufspreis netto{{ ($k['linienpreis'] ?? false) ? ' (Linienpreis)' : '' }}">{{ number_format($vk, 2, ',', '.') }} €<span class="text-[var(--fa-accent)]">{{ ($k['linienpreis'] ?? false) ? '*' : '' }}</span></span>
        @else
            <x-fa::signal tone="crit" title="Kein Verkaufspreis hinterlegt">Preis fehlt</x-fa::signal>
        @endif
        <span class="inline-flex items-center gap-1 font-medium {{ $statusText[$st] ?? 'text-[var(--fa-ink-3)]' }}" title="Wareneinsatz {{ $wesText }} · {{ $statusLabel[$st] ?? '' }} ({{ $bandText }})">
            <span class="w-2 h-2 rounded-full {{ $statusFarbe[$st] ?? 'bg-[var(--fa-ink-3)]' }}"></span>{{ $wesText }}
        </span>
        <label class="inline-flex items-center gap-1 text-[var(--fa-ink-3)]" title="Essen bzw. Portionen (leer = Standard der Linie bzw. des Plans: {{ $paxStandard }})">
            @svg('heroicon-m-user', 'w-3.5 h-3.5 shrink-0')
            <input type="number" min="0" value="{{ $e->pax }}" placeholder="{{ $paxStandard }}"
                   wire:change="setPax({{ $e->id }}, $event.target.value)"
                   aria-label="Essen für {{ $titel }}"
                   class="fa-control h-6 w-14 px-1.5 text-right tabular-nums text-[length:var(--fa-text-sm)]" />
        </label>
    </div>

    @if($dichte === 'detail' && $k !== [])
        <ul class="mt-1.5 pt-1.5 border-t border-[var(--fa-line)] flex flex-col gap-0.5" data-sp-komponenten>
            @forelse($k['komponenten'] ?? [] as $komp)
                <li class="flex justify-between gap-2 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]"><span class="min-w-0 break-words">{{ $komp['name'] }}</span><span class="tabular-nums shrink-0">{{ $komp['menge'] ?? '' }}</span></li>
            @empty
                <li class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Keine Komponenten gepflegt.</li>
            @endforelse
            @if(($k['kcal'] ?? null) !== null || ($k['portion_g'] ?? null) !== null)
                <li class="flex justify-between gap-2 pt-0.5 text-[length:var(--fa-text-sm)] text-[var(--fa-ink)]">
                    <span>Portion</span>
                    <span class="tabular-nums">{{ implode(' · ', array_filter([
                        ($k['portion_g'] ?? null) !== null ? number_format((float) $k['portion_g'], 0, ',', '.') . ' g' : null,
                        ($k['kcal'] ?? null) !== null ? number_format((float) $k['kcal'], 0, ',', '.') . ' kcal' : null,
                    ])) }}</span>
                </li>
            @endif
        </ul>
    @endif
</div>
