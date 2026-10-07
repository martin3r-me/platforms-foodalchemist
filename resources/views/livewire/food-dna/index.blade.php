{{-- #389/Canvas: Food-DNA-Seite. Team-Canvas über die zentrale Mechanik (Board-Partial).
     fa-pass 2026-10-05: Seitenkopf und Bausteine <x-fa::…>; Anordnung unverändert
     (Erklärung, Board, Verweis auf das Küchen-Profil). --}}
<x-ui-page>
    <x-slot:navbar>
        <x-foodalchemist::shell.page-navbar title="Food DNA" icon="heroicon-o-finger-print" />
    </x-slot:navbar>

    <x-ui-page-container padding="px-6 py-6" spacing="space-y-4">
        <div class="max-w-3xl flex flex-col gap-4">
            <x-fa::page-header title="Food DNA" />
            <p class="text-[length:var(--fa-text-md)] text-[var(--fa-ink-2)] max-w-[70ch]">
                Der Markenkern der Küche deines Teams. Die KI stellt diese DNA allen Texten und Vorschlägen voran
                (Rezept, Wording, Komposition, Angebot) als verbindlichen Rahmen für Stil und Geschmack.
                Kunden-DNA und Foodbook ergänzen sie, sie ersetzen sie nicht.
            </p>

            @include('foodalchemist::livewire.canvas.partials.board')

            <x-fa::section title="Küchen-Profil" icon="heroicon-o-building-storefront" description="Wird hier nur angezeigt, gepflegt wird es in den Einstellungen.">
                <x-slot:actions>
                    <x-fa::button size="sm" variant="ghost" icon="heroicon-o-cog-6-tooth" :href="route('foodalchemist.einstellungen', ['sektion' => 'kueche'])">In Einstellungen ändern</x-fa::button>
                </x-slot:actions>
                @if($kuechenTypLabel !== null)
                    <p class="text-[length:var(--fa-text-base)] font-medium text-[var(--fa-ink)]">{{ $kuechenTypLabel }}</p>
                @else
                    <x-fa::signal tone="warn">Noch kein Küchen-Profil gewählt</x-fa::signal>
                @endif
            </x-fa::section>
        </div>
    </x-ui-page-container>
    {{-- Spec 53/F Stufe 2: Sprachbefehl-Mount auf Seitenebene (Modal + optionales schwebendes Element). --}}
</x-ui-page>
