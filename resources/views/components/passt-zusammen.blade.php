{{-- Spec 58 · Paket 6 — „Passt das zusammen?": Harmonie (Foodpairing-Sterne) und Kontrast (Geschmack/Textur)
     eines Gerichts als Sätze. Daten: PairingAnalyseService::analyseRezept(). Wichtiges zuerst (sehr gut, Brücke,
     Spannung, Lücke), Rauschen („passt" = 2★) und „kein Bezug" eingeklappt. --}}
@props(['analyse'])
@php
    $paare = collect($analyse['harmonie']['paare'] ?? []);
    $z = $analyse['harmonie']['zusammenhalt'] ?? [];
    $stark = $paare->whereIn('stufe', ['sehr_gut', 'bruecke']);
    $schwach = $paare->whereIn('stufe', ['passt', 'kein_bezug']);
    $offen = $paare->where('stufe', 'unbekannt');
    $kontrast = collect($analyse['kontrast'] ?? []);
    $luecken = collect($analyse['luecken'] ?? []);
    $stufeTon = ['gut' => 'text-emerald-700 bg-emerald-50', 'mittel' => 'text-amber-700 bg-amber-50', 'schwach' => 'text-rose-700 bg-rose-50'];
@endphp
<div class="flex flex-col gap-3 text-sm" data-passt-zusammen>
    <div class="flex flex-wrap items-center gap-2">
        @if(($z['wert'] ?? null) !== null)
            <span class="rounded px-2 py-0.5 text-xs font-medium {{ $stufeTon[$z['stufe']] ?? 'text-gray-700 bg-gray-100' }}" data-zusammenhalt>Harmonie {{ $z['stufe'] }}</span>
        @endif
        <p class="text-gray-600">{{ $analyse['zusammenfassung'] ?? '' }}</p>
    </div>

    @if($stark->isNotEmpty())
        <div>
            <p class="text-xs font-medium text-gray-500 mb-1">Harmonie (geteilte Aromen, Foodpairing 3★)</p>
            <ul class="flex flex-col gap-1" data-harmonie-stark>
                @foreach($stark as $p)
                    <li class="flex items-start gap-2">
                        <span class="mt-1.5 inline-block w-2 h-2 shrink-0 rounded-full {{ $p['stufe'] === 'sehr_gut' ? 'bg-emerald-500' : 'bg-sky-400' }}"></span>
                        <span class="text-gray-800">{{ $p['satz'] }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if($kontrast->isNotEmpty())
        <div>
            <p class="text-xs font-medium text-gray-500 mb-1">Kontrast (Spannung durch Gegensätze)</p>
            <ul class="flex flex-col gap-1" data-kontrast>
                @foreach($kontrast as $k)
                    <li class="flex items-start gap-2">
                        <span class="mt-1.5 inline-block w-2 h-2 shrink-0 rotate-45 {{ $k['art'] === 'textur' ? 'bg-violet-400' : 'bg-orange-400' }}"></span>
                        <span class="text-gray-800">{{ $k['satz'] }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if($luecken->isNotEmpty())
        <ul class="flex flex-col gap-1" data-luecken>
            @foreach($luecken as $l)
                <li class="rounded bg-amber-50 px-2 py-1 text-amber-800">{{ $l['text'] }}</li>
            @endforeach
        </ul>
    @endif

    @if($schwach->isNotEmpty() || $offen->isNotEmpty())
        <details class="text-gray-500">
            <summary class="cursor-pointer text-xs">Weitere Paare ({{ $schwach->count() + $offen->count() }}): nur „passt" (2★), ohne Bezug oder ohne Zuordnung</summary>
            <ul class="mt-1 flex flex-col gap-0.5" data-harmonie-weitere>
                @foreach($schwach->concat($offen) as $p)
                    <li>{{ $p['satz'] }}</li>
                @endforeach
            </ul>
        </details>
    @endif
</div>
