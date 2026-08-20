{{-- Speisekarte-Leitstelle-Rail: abgeleitete Fertigstellungs-Checkliste (read-only)
     + Werkstrang M Phase E: Soll/Ist-Coverage gegen das Planungs-Gerüst (nur wenn eines existiert). --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))
@php($ampel = ['erledigt' => 'bg-emerald-500', 'teil' => 'bg-amber-500', 'offen' => 'bg-gray-300'])
@php($ampelText = ['erledigt' => 'text-emerald-600', 'teil' => 'text-amber-600', 'offen' => 'text-gray-400'])

<div class="space-y-3">
    <div class="relative overflow-hidden {{ $card }} p-4">
        <div class="{{ $cardAccent }}"></div>
        <div class="flex items-center gap-2 mb-3">
            <span class="font-semibold text-gray-900 text-sm">Was fehlt der Karte noch?</span>
            @if($stand['bereit'])
                <span class="{{ $pill }} {{ $variantPill['success'] }}">bereit</span>
            @else
                <span class="{{ $pill }} {{ $variantPill['warning'] }}">in Arbeit</span>
            @endif
        </div>
        <div class="space-y-2">
            @foreach($stand['punkte'] as $punkt)
                <div wire:key="sk-ls-{{ $punkt['key'] }}" class="flex items-center gap-2">
                    <span class="h-2.5 w-2.5 rounded-full {{ $ampel[$punkt['status']] ?? 'bg-gray-300' }}"></span>
                    <span class="flex-1 text-xs text-gray-700">{{ $punkt['label'] }}</span>
                    <span class="text-[10px] {{ $ampelText[$punkt['status']] ?? 'text-gray-400' }}">{{ $punkt['hinweis'] }}</span>
                </div>
            @endforeach
        </div>
    </div>

    {{-- Werkstrang M Phase E: Coverage-Panel NUR bei vorhandenem Planungs-Gerüst (kein Frame-Zwang). --}}
    @if($coverage['hat_geruest'] ?? false)
        @include('foodalchemist::livewire.planning.partials.coverage-panel', ['coverage' => $coverage])
    @endif
</div>
