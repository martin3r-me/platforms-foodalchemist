{{-- Spec 57 · Paket 1: ein Eintrag in der Wochen-Matrix „auf einen Blick“.
     Erwartet: $e (Eintrag-Model), $k (Kennzahlen aus SpeiseplanService::zellenKennzahlen oder null),
     $farbe (Linienfarbe oder null), $dichte (kompakt|detail), $sp (Plan).
     Rechnet NICHTS — alle Zahlen kommen fertig aus dem Service. --}}
@php($k = is_array($k ?? null) ? $k : [])
@php($titel = $k['titel'] ?? $e->inhaltName())
@php($st = $k['status'] ?? 'unbekannt')
@php($wes = $k['wes'] ?? null)
@php($band = $k['band'] ?? ['min' => null, 'max' => 0, 'quelle' => 'team'])
@php($paxStandard = $k['pax'] ?? $sp->default_pax)
@php($statusFarbe = ['ok' => 'bg-emerald-400', 'unter' => 'bg-sky-400', 'ueber' => 'bg-amber-400', 'weit_ueber' => 'bg-rose-400', 'unbekannt' => 'bg-gray-500'])
@php($statusText = ['ok' => 'text-emerald-300', 'unter' => 'text-sky-300', 'ueber' => 'text-amber-300', 'weit_ueber' => 'text-rose-300', 'unbekannt' => 'text-gray-400'])
@php($statusLabel = ['ok' => 'im Zielband', 'unter' => 'unter dem Zielband', 'ueber' => 'über dem Zielband', 'weit_ueber' => 'weit über dem Zielband', 'unbekannt' => 'ohne VK — nicht berechenbar'])
@php($diaetChip = [
    'vegan' => ['Vg', 'vegan', 'bg-emerald-500/20 text-emerald-200 border-emerald-400/30'],
    'vegetarisch' => ['Vt', 'vegetarisch', 'bg-lime-500/15 text-lime-200 border-lime-400/30'],
    'schwein' => ['Sw', 'Schwein', 'bg-rose-500/15 text-rose-200 border-rose-400/30'],
    'rind' => ['Rd', 'Rind', 'bg-orange-500/15 text-orange-200 border-orange-400/30'],
    'fisch' => ['Fi', 'Fisch', 'bg-sky-500/15 text-sky-200 border-sky-400/30'],
    'fleisch' => ['Fl', 'Fleisch (Tierart nicht gepflegt)', 'bg-amber-500/15 text-amber-200 border-amber-400/30'],
])
@php($bandText = ($band['min'] !== null ? number_format((float) $band['min'], 0) . '–' : 'bis ') . number_format((float) $band['max'], 0) . ' %' . (($band['quelle'] ?? '') === 'team' ? ', Team-Ziel' : ''))
@php($wesText = $wes !== null ? number_format((float) $wes, 0, ',', '.') . ' %' : '—')
<div wire:key="e-{{ $e->id }}" class="group rounded-lg border border-white/10 px-2 py-1.5 text-left text-gray-100"
     style="background: {{ $farbe ? $farbe . '26' : 'rgba(255,255,255,0.06)' }}" data-sp-zelle="{{ $e->id }}">
    <div class="flex items-start gap-1">
        <div class="flex-1 min-w-0">
            <div class="text-[12px] font-medium leading-snug break-words">{{ $titel }}</div>
            @if(! empty($k['untertitel']))
                <div class="text-[10.5px] text-gray-400 leading-snug line-clamp-2">{{ $k['untertitel'] }}</div>
            @endif
        </div>
        <button type="button" wire:click="eintragRaus({{ $e->id }})" wire:confirm="Eintrag entfernen?"
                class="opacity-60 group-hover:opacity-100 focus:opacity-100 text-gray-300 hover:text-red-400 shrink-0 text-[11px] leading-none px-0.5"
                aria-label="{{ $titel }} entfernen">✕</button>
    </div>

    @if(($k['diaet'] ?? []) !== [] || ($k['codes'] ?? []) !== [])
        <div class="flex flex-wrap items-center gap-1 mt-1">
            @foreach($k['diaet'] ?? [] as $d)
                @if(isset($diaetChip[$d]))
                    <span class="inline-flex items-center px-1 rounded border text-[9.5px] leading-[14px] {{ $diaetChip[$d][2] }}" title="{{ $diaetChip[$d][1] }}">{{ $diaetChip[$d][0] }}</span>
                @endif
            @endforeach
            @if(($k['codes'] ?? []) !== [])
                <span class="text-[9.5px] text-gray-400 tracking-wide" title="LMIV-Kennzeichnung (Allergene A–N, Zusatzstoffe 1–18, * = Spuren)">{{ implode(' ', $k['codes']) }}</span>
            @endif
        </div>
    @endif

    <div class="flex items-center justify-between gap-1 mt-1 text-[10.5px] tabular-nums">
        <span class="text-gray-300" title="VK netto{{ ($k['linienpreis'] ?? false) ? ' (Linienpreis)' : '' }}">{{ number_format((float) ($k['vk'] ?? 0), 2, ',', '.') }} €<span class="text-violet-300">{{ ($k['linienpreis'] ?? false) ? '*' : '' }}</span></span>
        <span class="inline-flex items-center gap-1 {{ $statusText[$st] ?? 'text-gray-400' }}" title="Wareneinsatz {{ $wesText }} · {{ $statusLabel[$st] ?? '' }} ({{ $bandText }})">
            <span class="w-1.5 h-1.5 rounded-full {{ $statusFarbe[$st] ?? 'bg-gray-500' }}"></span>{{ $wesText }}
        </span>
        <input type="number" min="0" value="{{ $e->pax }}" placeholder="{{ $paxStandard }}"
               wire:change="setPax({{ $e->id }}, $event.target.value)"
               title="Essen/Portionen (leer = Standard der Linie bzw. des Plans: {{ $paxStandard }})"
               aria-label="Essen für {{ $titel }}"
               class="w-12 text-right text-[10px] px-1 py-0 rounded bg-white/10 border border-white/15 text-gray-100 shrink-0" />
    </div>

    @if($dichte === 'detail' && $k !== [])
        <ul class="mt-1 pt-1 border-t border-white/10 space-y-0.5" data-sp-komponenten>
            @forelse($k['komponenten'] ?? [] as $komp)
                <li class="flex justify-between gap-2 text-[10px] text-gray-400"><span class="truncate">{{ $komp['name'] }}</span><span class="tabular-nums shrink-0">{{ $komp['menge'] ?? '' }}</span></li>
            @empty
                <li class="text-[10px] text-gray-500">Keine Komponenten gepflegt.</li>
            @endforelse
            @if(($k['kcal'] ?? null) !== null || ($k['portion_g'] ?? null) !== null)
                <li class="flex justify-between text-[10px] text-gray-300 pt-0.5">
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
