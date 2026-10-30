{{-- Stufe 2d — Stammdatenblatt-Vorschläge der Lieferanten: Vorher/Nachher, erst nach deiner Entscheidung geht etwas in den Bestand. --}}
<div data-lieferanten-stammdatenblatt-root>
@if($zeilen->isNotEmpty() || $meldung || $fehler)
    <x-fa::section title="Stammdatenblatt vom Lieferanten" icon="heroicon-o-building-office" :meta="$zeilen->count()" data-lieferanten-stammdatenblatt>
        @if($meldung)<x-fa::notice tone="ok">{{ $meldung }}</x-fa::notice>@endif
        @if($fehler)<x-fa::notice tone="crit">{{ $fehler }}</x-fa::notice>@endif
        <div class="flex flex-col gap-3">
            @foreach($zeilen as $z)
                <div wire:key="ls-{{ $z->id }}" class="rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] p-3" data-lieferanten-stammdatenblatt-zeile="{{ $z->id }}">
                    <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                        <span class="font-medium">{{ $z->supplier_name }} · {{ $z->designation }} <x-fa::badge tone="info" data-lauf-vom-lieferanten>vom Lieferanten</x-fa::badge> <x-fa::badge tone="{{ $z->aktion === 'neu' ? 'ok' : 'warn' }}">{{ $z->aktion === 'neu' ? 'neu' : 'ändert' }}</x-fa::badge></span>
                        <span>
                            <x-fa::button size="sm" variant="primary" wire:click="entscheiden({{ $z->id }}, 'uebernehmen')" data-blatt-uebernehmen>Übernehmen</x-fa::button>
                            <x-fa::button size="sm" variant="ghost" wire:click="entscheiden({{ $z->id }}, 'verwerfen')" data-blatt-verwerfen>Verwerfen</x-fa::button>
                        </span>
                    </div>
                    <table class="fa-table fa-table--compact">
                        <thead><tr><th>Feld</th><th>Bisher</th><th>Vorschlag</th></tr></thead>
                        <tbody>
                            @foreach(($z->diff ?? []) as $feld => [$alt, $neu])
                                <tr><td>{{ $feldnamen[$feld] ?? $feld }}</td><td>{{ is_array($alt) ? implode(', ', $alt) : ($alt ?? '—') }}</td><td class="font-medium">{{ is_array($neu) ? implode(', ', $neu) : $neu }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endforeach
        </div>
    </x-fa::section>
@endif
</div>
