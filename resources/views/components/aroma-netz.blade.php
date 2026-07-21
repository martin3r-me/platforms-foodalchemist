{{--
    FA Aroma-Netz — kompakter Inline-Graph fürs Detail-Panel: Quell-Gericht zentral,
    Kern-/Pairing-Anker im Ring, Aroma-Brücken dazwischen (Hover = Brücken des Ankers).
    Server-gerechnetes SVG (kein JS-Graph), Daten aus PairingService::aromaNetz.
    Der volle Graph (verwandte Rezepte + Vorschläge) bleibt im „Netz öffnen"-Overlay.
--}}
@props(['recipeId'])
@php
    $team = \Illuminate\Support\Facades\Auth::user()?->currentTeamRelation;
    $netz = ($team !== null && $recipeId !== null)
        ? app(\Platform\FoodAlchemist\Services\PairingService::class)->aromaNetz($team, $recipeId, 0)
        : ['zentrum' => null, 'anker' => [], 'kanten' => []];
    $cx = 180; $cy = 115; $R = 86;
    $anker = array_values($netz['anker']);
    $n = max(1, count($anker));
    $pos = [];
    foreach ($anker as $i => $a) {
        $w = 2 * M_PI * $i / $n - M_PI / 2;
        $anker[$i]['x'] = round($cx + $R * cos($w), 1);
        $anker[$i]['y'] = round($cy + $R * sin($w), 1);
        $pos[$a['id']] = ['x' => $anker[$i]['x'], 'y' => $anker[$i]['y']];
    }
@endphp

@if($netz['zentrum'] === null || count($anker) < 2)
    <p class="text-[13px] text-gray-500">Zu wenige Anker fürs Netz — mind. 2 Kern-Anker verknüpfen.</p>
@else
<div x-data="{ hov: null }">
    <svg viewBox="0 0 360 230" class="w-full rounded-xl bg-black/[0.02]" data-vk-netz-inline>
        @foreach($anker as $a)
            <line x1="{{ $cx }}" y1="{{ $cy }}" x2="{{ $a['x'] }}" y2="{{ $a['y'] }}" stroke="#9ca3af" stroke-width="0.7" opacity="0.12" />
        @endforeach
        @foreach($netz['kanten'] as $k)
            @if(isset($pos[$k['a']], $pos[$k['b']]))
                <line x1="{{ $pos[$k['a']]['x'] }}" y1="{{ $pos[$k['a']]['y'] }}" x2="{{ $pos[$k['b']]['x'] }}" y2="{{ $pos[$k['b']]['y'] }}"
                      :opacity="(hov === null || hov === {{ $k['a'] }} || hov === {{ $k['b'] }}) ? 0.8 : 0.08"
                      @switch($k['type'])
                          @case('klassisch')
                          @case('erprobt') stroke="#d6409f" stroke-width="1.8" @break
                          @case('aroma') stroke="#d6409f" stroke-width="1.2" stroke-dasharray="2 4" @break
                          @case('modern') stroke="#7c3aed" stroke-width="1.2" stroke-dasharray="1 3" @break
                          @case('kontrast') stroke="#06b6d4" stroke-width="1.3" stroke-dasharray="2 4" @break
                          @default stroke="#9ca3af" stroke-width="1" stroke-dasharray="5 4"
                      @endswitch
                      style="transition: opacity .15s" />
            @endif
        @endforeach
        @foreach($anker as $a)
            <g @mouseenter="hov = {{ $a['id'] }}" @mouseleave="hov = null" class="cursor-default">
                <circle cx="{{ $a['x'] }}" cy="{{ $a['y'] }}" r="{{ $a['kern'] ? 8 : 6 }}"
                        fill="#f9a8d4" stroke="{{ $a['kern'] ? '#be185d' : '#ec4899' }}" stroke-width="{{ $a['kern'] ? 2 : 1 }}"
                        :opacity="(hov === null || hov === {{ $a['id'] }}) ? 1 : 0.4" style="transition: opacity .15s">
                    <title>{{ $a['display_de'] }}{{ $a['kern'] ? ' (Kern-Anker)' : '' }}</title>
                </circle>
                <text x="{{ $a['x'] }}" y="{{ $a['y'] + ($a['y'] > $cy ? 16 : -10) }}" text-anchor="middle" font-size="9.5"
                      class="fill-gray-600 {{ $a['kern'] ? 'font-semibold' : '' }}"
                      style="paint-order: stroke; stroke: rgba(255,255,255,.85); stroke-width: 2.5px;">{{ $a['kern'] ? '★ ' : '' }}{{ $a['slug'] }}</text>
            </g>
        @endforeach
        <circle cx="{{ $cx }}" cy="{{ $cy }}" r="22" fill="#fdba74" stroke="#ea580c" stroke-width="2.5" data-vk-netz-zentrum>
            <title>{{ $netz['zentrum']['name'] }}</title>
        </circle>
    </svg>
</div>
@endif
