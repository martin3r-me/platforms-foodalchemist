{{-- Stufe 2c — Dokumente, die Lieferanten über ihren Link eingereicht haben: erst nach deiner Entscheidung gehen sie an den Artikel. --}}
<div data-lieferanten-dokumente-root>
@if($zeilen->isNotEmpty() || $meldung || $fehler)
    <x-fa::section title="Dokumente vom Lieferanten" icon="heroicon-o-document-arrow-up" :meta="$zeilen->count()" data-lieferanten-dokumente>
        @if($meldung)<x-fa::notice tone="ok">{{ $meldung }}</x-fa::notice>@endif
        @if($fehler)<x-fa::notice tone="crit">{{ $fehler }}</x-fa::notice>@endif
        <table class="fa-table fa-table--compact">
            <thead><tr><th>Datei</th><th>Lieferant</th><th>Artikel</th><th>Eingegangen</th><th></th></tr></thead>
            <tbody>
                @foreach($zeilen as $z)
                    <tr wire:key="ld-{{ $z->id }}" data-lieferanten-dokument="{{ $z->id }}">
                        <td class="font-medium">{{ $z->daten['name'] ?? '' }} <x-fa::badge tone="info" data-lauf-vom-lieferanten>vom Lieferanten</x-fa::badge></td>
                        <td>{{ $z->supplier_name }}</td>
                        <td>{{ $z->article_number }}@if($z->designation) · {{ $z->designation }}@endif @if($z->status === \Platform\FoodAlchemist\Models\FoodAlchemistPriceImportLine::STATUS_OHNE_TREFFER)<x-fa::badge tone="warn">kein Artikel</x-fa::badge>@endif</td>
                        <td class="tabular-nums">{{ $z->created_at?->format('d.m.Y H:i') }}</td>
                        <td class="text-right">
                            @if($z->status === \Platform\FoodAlchemist\Models\FoodAlchemistPriceImportLine::STATUS_TREFFER)
                                <x-fa::button size="sm" variant="primary" wire:click="entscheiden({{ $z->id }}, 'uebernehmen')" data-dokument-uebernehmen>Übernehmen</x-fa::button>
                            @endif
                            <x-fa::button size="sm" variant="ghost" wire:click="entscheiden({{ $z->id }}, 'verwerfen')" data-dokument-verwerfen>Verwerfen</x-fa::button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-fa::section>
@endif
</div>
