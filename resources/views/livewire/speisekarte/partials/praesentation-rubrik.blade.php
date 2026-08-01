{{-- Kundensicht-Rubrik (rekursiv): Titel + Positionen mit Preis, ohne EK. Erwartet: $rubrik, $karte, $preise. --}}
<div class="mb-6">
    <h2 class="text-sm font-semibold uppercase tracking-wide text-violet-700 border-b border-black/10 pb-1 mb-2">
        {{ $rubrik->consumer_title ?: $rubrik->title }}
    </h2>
    @if($rubrik->claim)
        <p class="text-xs text-gray-500 italic mb-2">{{ $rubrik->claim }}</p>
    @endif

    @foreach($rubrik->items->where('visible', true) as $pos)
        @if($pos->type === 'spacer')
            <div class="h-2"></div>
        @elseif($pos->type === 'header')
            <div class="font-medium text-[11px] uppercase tracking-wide text-gray-500 mt-3">{{ $pos->label }}</div>
        @elseif($pos->type === 'text')
            <p class="text-xs text-gray-500 italic">{{ $pos->consumer_text }}</p>
        @else
            @php($p = $preise[$pos->id] ?? null)
            <div class="flex items-baseline gap-2 py-1">
                <span class="text-gray-900">
                    @if($pos->type === 'gericht_ref')
                        {{ $pos->wording ?: ($pos->dish?->name ?? '') }}
                    @else
                        {{ $pos->wording ?: ($pos->concept?->name ?? 'Menü') }}
                    @endif
                </span>
                @if($pos->consumer_text)
                    <span class="text-xs text-gray-400">{{ $pos->consumer_text }}</span>
                @endif
                <span class="flex-1 border-b border-dotted border-gray-300"></span>
                <span class="tabular-nums text-gray-900">
                    @if($p && $p['vk'] !== null){{ number_format($p['vk'], 2, ',', '.') }} €@endif
                </span>
            </div>
        @endif
    @endforeach

    @foreach($karte->sections->where('parent_id', $rubrik->id) as $kind)
        @include('foodalchemist::livewire.speisekarte.partials.praesentation-rubrik', ['rubrik' => $kind])
    @endforeach
</div>
