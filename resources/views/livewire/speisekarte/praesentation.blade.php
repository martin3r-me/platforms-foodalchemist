{{-- Speisekarten-Präsentation (Web, Kundensicht) — Stufe A schlank; Branding folgt in Stufe C. --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<x-ui-page>
    <x-slot:navbar>
        <x-ui-page-navbar :title="$karte->name" icon="heroicon-o-clipboard-document-list" />
    </x-slot:navbar>

    @php($brand = ($branding['color'] ?? '#6d28d9') ?: '#6d28d9')
    <x-ui-page-container padding="px-6 pb-6" spacing="space-y-6">
        <div class="max-w-2xl mx-auto {{ $card }} p-8">
            <div class="{{ $cardAccent }}"></div>
            @if($branding['logo'] ?? null)
                <div class="text-center mb-4"><img src="{{ $branding['logo'] }}" alt="" class="inline-block max-h-16" /></div>
            @endif
            <h1 class="text-2xl font-semibold text-center text-gray-900 mb-1">{{ $karte->name }}</h1>
            <div class="mx-auto h-1 w-16 rounded mb-6" style="background: {{ $brand }}"></div>
            @if($branding['cover'] ?? null)
                <div class="mb-6"><img src="{{ $branding['cover'] }}" alt="" class="w-full rounded-lg" /></div>
            @endif

            @foreach($karte->sections->whereNull('parent_id') as $rubrik)
                @include('foodalchemist::livewire.speisekarte.partials.praesentation-rubrik', ['rubrik' => $rubrik])
            @endforeach
        </div>
    </x-ui-page-container>
</x-ui-page>
