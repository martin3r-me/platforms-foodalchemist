{{-- x-fa::kpis — Kennzahl-Leiste: EINE Fläche, Zellen durch Linien getrennt.
     items: [['label' => 'EK je kg', 'value' => '4,07 €', 'primary' => true, 'tone' => null|'ok'|'warn'|'crit', 'hint' => '…', 'kpi' => 'marker']]
     primary = die eine große Zahl der Ansicht (höchstens eine). tone färbt nur Zustände. --}}
@props(['items' => []])
<dl {{ $attributes->merge(['class' => 'fa-kpis']) }} data-fa-kpis>
    @foreach($items as $item)
        @php($ton = $item['tone'] ?? null)
        <div class="fa-kpi" @if(!empty($item['kpi'])) data-kpi="{{ $item['kpi'] }}" @endif @if(!empty($item['title'])) title="{{ $item['title'] }}" @endif>
            <dt class="text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)] truncate">{{ $item['label'] ?? '' }}</dt>
            <dd class="tabular-nums truncate {{ !empty($item['primary']) ? 'text-[length:var(--fa-text-2xl)] font-semibold tracking-tight text-[var(--fa-accent)] leading-tight' : 'text-[length:var(--fa-text-lg)] font-semibold leading-snug' }} {{ ['ok' => 'text-[var(--fa-ok)]', 'warn' => 'text-[var(--fa-warn)]', 'crit' => 'text-[var(--fa-crit)]'][$ton] ?? (empty($item['primary']) ? 'text-[var(--fa-ink)]' : '') }}">
                {{ $item['value'] ?? '–' }}@if(!empty($item['hint']))<span class="ml-1 text-[length:var(--fa-text-sm)] font-normal text-[var(--fa-warn)]" @if(!empty($item['hint_title'])) title="{{ $item['hint_title'] }}" @endif>{{ $item['hint'] }}</span>@endif
            </dd>
        </div>
    @endforeach
</dl>
