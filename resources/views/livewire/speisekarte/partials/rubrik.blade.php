{{-- Rekursive Rubrik-Zeile: Titel + Positionen + Gericht-Picker + Unter-Rubriken.
     Erwartet: $rubrik, $depth, $karte, $preise, $pickerRubrikId, $pickerErgebnisse (+ Ui-Maps). --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))
@php($artLabel = ['speisen' => 'Speisen', 'getraenke' => 'Getränke', 'menue' => 'Menü', 'dessert' => 'Dessert', 'sonstiges' => 'Sonstiges'])

<div wire:key="sk-rubrik-{{ $rubrik->id }}" class="mb-3" style="margin-left: {{ $depth * 1.25 }}rem">
    <div class="flex items-center gap-2 border-b border-black/10 pb-1 mb-2">
        <span class="font-semibold text-gray-900 text-sm">{{ $rubrik->consumer_title ?: $rubrik->title }}</span>
        <span class="{{ $pill }} {{ $variantPill['secondary'] }}">{{ $artLabel[$rubrik->art] ?? $rubrik->art }}</span>
        <span class="flex-1"></span>
        <button type="button" wire:click="pickerOeffnen({{ $rubrik->id }})" class="{{ $btnGhostXs }}">+ Position</button>
        <button type="button" wire:click="rubrikLoeschen({{ $rubrik->id }})" wire:confirm="Rubrik „{{ $rubrik->title }}“ löschen?" class="{{ $btnGhostXs }} text-red-600">✕</button>
    </div>

    {{-- Positionen --}}
    @forelse($rubrik->items as $pos)
        <div wire:key="sk-pos-{{ $pos->id }}" class="flex items-center gap-2 py-0.5 text-sm">
            <span class="flex-1 text-gray-800">
                @if($pos->type === 'gericht_ref')
                    {{ $pos->wording ?: ($pos->dish?->name ?? $pos->label ?? '— Gericht —') }}
                @elseif($pos->type === 'menue_ref')
                    {{ $pos->wording ?: ($pos->concept?->name ?? 'Menü') }}
                @elseif($pos->type === 'header')
                    <span class="font-medium uppercase text-[11px] tracking-wide text-gray-500">{{ $pos->label }}</span>
                @else
                    <span class="text-gray-500 italic">{{ $pos->consumer_text ?: $pos->label ?: $pos->type }}</span>
                @endif
            </span>
            @php($p = $preise[$pos->id] ?? null)
            <span class="tabular-nums text-gray-700 w-24 text-right">
                @if($p && $p['vk'] !== null)
                    {{ number_format($p['vk'], 2, ',', '.') }} €
                    @if($p['quelle'] === 'keine')<span class="text-red-500">?</span>@endif
                @else
                    <span class="text-gray-300">—</span>
                @endif
            </span>
            <button type="button" wire:click="positionLoeschen({{ $pos->id }})" class="{{ $btnGhostXs }} text-red-600">✕</button>
        </div>
    @empty
        <div class="text-[11px] text-gray-400 py-1">Keine Positionen.</div>
    @endforelse

    {{-- Gericht-Picker (nur für die geöffnete Rubrik) --}}
    @if($pickerRubrikId === $rubrik->id)
        <div class="mt-2 p-2 rounded-lg bg-black/[0.03]">
            <input type="search" wire:model.live.debounce.300ms="pickerSuche" placeholder="Gericht suchen …" class="{{ $input }} mb-2" autofocus />
            <div class="max-h-56 overflow-auto space-y-0.5">
                @forelse($pickerErgebnisse as $g)
                    <button type="button" wire:key="sk-pick-{{ $rubrik->id }}-{{ $g->id }}"
                        wire:click="positionAusGericht({{ $rubrik->id }}, {{ $g->id }})"
                        class="w-full flex items-center gap-2 px-2 py-1 rounded-md text-xs hover:bg-white/80 text-left">
                        <span class="flex-1 text-gray-800">{{ $g->name }}</span>
                        <span class="tabular-nums text-gray-500">{{ $g->sales_net !== null ? number_format((float) $g->sales_net, 2, ',', '.') . ' €' : '—' }}</span>
                    </button>
                @empty
                    <div class="px-2 py-3 text-[11px] text-gray-500 text-center">Kein Treffer.</div>
                @endforelse
            </div>
        </div>
    @endif

    {{-- Unter-Rubriken (rekursiv) --}}
    @foreach($karte->sections->where('parent_id', $rubrik->id) as $kind)
        @include('foodalchemist::livewire.speisekarte.partials.rubrik', ['rubrik' => $kind, 'depth' => $depth + 1])
    @endforeach
</div>
