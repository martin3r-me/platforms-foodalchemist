{{-- Speisekarten-Präsentation (Web, Kundensicht) — Stufe A schlank; Branding folgt in Stufe C. --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<x-ui-page>
    <x-slot:navbar>
        <x-ui-page-navbar :title="$karte->name" icon="heroicon-o-clipboard-document-list" />
    </x-slot:navbar>

    <x-ui-page-container padding="px-6 pb-6" spacing="space-y-6">
        <div class="max-w-2xl mx-auto {{ $card }} p-8">
            <div class="{{ $cardAccent }}"></div>
            <h1 class="text-2xl font-semibold text-center text-gray-900 mb-6">{{ $karte->name }}</h1>

            @foreach($karte->sections->whereNull('parent_id') as $rubrik)
                @include('foodalchemist::livewire.speisekarte.partials.praesentation-rubrik', ['rubrik' => $rubrik])
            @endforeach
        </div>
    </x-ui-page-container>
</x-ui-page>
