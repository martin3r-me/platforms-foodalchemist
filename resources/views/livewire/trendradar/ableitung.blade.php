@php use Platform\FoodAlchemist\Support\TrendVokabular as V; $ab = $trend?->ableitung; @endphp
{{-- Spec 93 D · „Was heißt das für uns“ — Kurzfazit, Bedeutung je Sparte, Impulse für die Planung --}}
<div data-trend-ableitung="{{ $trendId }}">
    <x-fa::section variant="plain" title="Was heißt das für uns" icon="heroicon-o-light-bulb">
        @if($meldung)<x-fa::notice tone="ok">{{ $meldung }}</x-fa::notice>@endif
        @if($fehler)<x-fa::notice tone="crit">{{ $fehler }}</x-fa::notice>@endif
        @if($trend?->ableitung_fehler)<x-fa::notice tone="warn" data-ableitung-fehler>Letzter Lauf: {{ $trend->ableitung_fehler }}</x-fa::notice>@endif

        @if($bearbeiten)
            <div class="flex flex-col gap-2">
                <x-fa::field label="Kurzfazit" for="ab-fazit"><x-fa::textarea id="ab-fazit" rows="2" wire:model="kurzfazit" /></x-fa::field>
                <x-fa::field label="Impulse" for="ab-impulse" hint="Eine Zeile je Impuls: „Gericht: Titel — Beschreibung“ oder „Konzept: …“.">
                    <x-fa::textarea id="ab-impulse" rows="5" wire:model="impulse" />
                </x-fa::field>
                <div class="flex gap-1.5">
                    <x-fa::button size="sm" variant="primary" wire:click="speichern">Speichern</x-fa::button>
                    <x-fa::button size="sm" variant="ghost" wire:click="$set('bearbeiten', false)">Abbrechen</x-fa::button>
                </div>
            </div>
        @elseif(is_array($ab) && $ab !== [])
            <div class="flex flex-col gap-2 text-[length:var(--fa-text-md)]">
                @if(! empty($ab['kurzfazit']))<p>{{ $ab['kurzfazit'] }}</p>@endif
                @foreach($ab['bedeutung'] ?? [] as $sparte => $text)
                    <p class="text-[length:var(--fa-text-sm)]"><strong>{{ V::label(V::SPARTEN, $sparte) }}:</strong> {{ $text }}</p>
                @endforeach
                @if(! empty($ab['impulse']))
                    <ul class="flex flex-col gap-1" data-ableitung-impulse>
                        @foreach($ab['impulse'] as $i)
                            <li class="text-[length:var(--fa-text-sm)]"><x-fa::badge>{{ $i['art'] === 'konzept' ? 'Konzept' : 'Gericht' }}</x-fa::badge> {{ $i['titel'] }}@if($i['beschreibung']) — <span class="text-[var(--fa-ink-3)]">{{ $i['beschreibung'] }}</span>@endif</li>
                        @endforeach
                    </ul>
                @endif
                <span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">{{ $trend->ableitung_quelle === 'manuell' ? 'Von Hand bearbeitet' : 'KI-Vorschlag' }} · {{ $trend->ableitung_at?->format('d.m.Y') }}</span>
            </div>
        @else
            <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Noch keine Ableitung. Sie entsteht automatisch, wenn die Automatik einen eigenen Trend prüft, oder per Knopf.</p>
        @endif

        @if($darf && ! $bearbeiten)
            <div class="flex gap-1.5 pt-1">
                @if($trend->ableitung_quelle !== 'manuell')
                    <x-fa::button size="sm" variant="ai" icon="heroicon-m-sparkles" wire:click="erzeugen" data-ableitung-erzeugen>{{ is_array($ab) && $ab !== [] ? 'Neu erzeugen' : 'Ableitung erzeugen' }}</x-fa::button>
                @endif
                <x-fa::button size="sm" variant="ghost" icon="heroicon-m-pencil-square" wire:click="oeffnen">Bearbeiten</x-fa::button>
            </div>
        @endif
    </x-fa::section>
</div>
