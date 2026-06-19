{{-- #389/Canvas: Food-DNA-Seite — Team-Canvas über die zentrale Mechanik (Board-Partial). --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<x-ui-page>
    <x-slot:navbar>
        <x-ui-page-navbar title="Food DNA" icon="heroicon-o-finger-print" />
    </x-slot:navbar>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Food Alchemist', 'href' => route('foodalchemist.dashboard'), 'icon' => 'cube'],
            ['label' => 'Food DNA'],
        ]" />
    </x-slot>

    <x-ui-page-container padding="px-6 pb-6" spacing="space-y-4">
        <div class="max-w-3xl space-y-4">
            <p class="text-[11px] text-gray-400">
                Der „Markenkern Küche" deines Teams. Diese DNA wird <strong>allen KI-Generatoren</strong>
                (Rezept, Wording, Komposition, Angebot) als verbindlicher Stil-/Geschmacks-Rahmen vorangestellt —
                kaskadiert mit Foodbook- und Concept-Canvas.
            </p>

            @include('foodalchemist::livewire.canvas.partials.board')

            <div class="relative overflow-hidden {{ $card }}">
                <div class="px-4 py-3">
                    <p class="text-[11px] uppercase tracking-wider text-gray-400 mb-1">Referenziert (nicht dupliziert)</p>
                    <p class="text-xs text-gray-700 dark:text-gray-200">
                        Küchen-Profil: {{ $kuechenTypLabel ?? '— nicht gesetzt —' }}
                        <a href="{{ route('foodalchemist.einstellungen', ['sektion' => 'kueche']) }}" class="ml-2 text-violet-600 dark:text-violet-400 hover:underline text-[11px]">in Einstellungen ändern</a>
                    </p>
                </div>
            </div>
        </div>
    </x-ui-page-container>
</x-ui-page>
