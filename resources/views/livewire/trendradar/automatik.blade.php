@php use Platform\FoodAlchemist\Support\TrendVokabular as V; @endphp
{{-- Spec 93 C · Was die Automatik tun würde: Kennzahlen, Entscheidung, Aktion, Begründung — reine Rechnung. --}}
<div class="flex flex-col gap-3" data-trend-automatik>
    @if($meldung)<x-fa::notice tone="ok" data-automatik-meldung>{{ $meldung }}</x-fa::notice>@endif
    @if($fehler)<x-fa::notice tone="crit" data-automatik-fehler>{{ $fehler }}</x-fa::notice>@endif

    @if($einstellungen)
        <x-fa::notice :tone="$einstellungen['schatten'] || $einstellungen['stufe'] === 'aus' ? 'info' : 'warn'" data-automatik-stand>
            Stufe: {{ $stufen[$einstellungen['stufe']] }}.
            @if($einstellungen['schatten']) Schatten-Modus an: die Automatik rechnet, schreibt aber keine Trends. @endif
            Schwellen und Stufe stehen in den Einstellungen → Trendradar (Startwerte sind Annahmen, nicht belegt).
        </x-fa::notice>
    @endif

    <div class="flex items-center gap-2">
        <span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">
            {{ count($plan['liste'] ?? []) }} Themen · {{ $plan['neue_trends'] ?? 0 }} neue Trends geplant
            @if(($plan['gestaut'] ?? 0) > 0) · {{ $plan['gestaut'] }} warten (Grenze je Lauf) @endif
        </span>
        @if($darfKuratieren)
            <x-fa::button size="sm" variant="primary" icon="heroicon-m-play" wire:click="laufen" class="ml-auto" data-automatik-lauf>Lauf jetzt</x-fa::button>
        @endif
    </div>

    @if(empty($plan['liste']))
        <div class="fa-surface">
            <x-fa::empty icon="heroicon-o-cpu-chip" title="Noch keine Themen">
                Themen entstehen, wenn Artikel im Eingang erschlossen und gebündelt sind.
            </x-fa::empty>
        </div>
    @else
        <div class="fa-surface divide-y divide-[var(--fa-line)]" data-automatik-liste>
            @foreach($plan['liste'] as $z)
                @php $k = $z['kennzahlen']; $e = $z['einordnung']; @endphp
                <article class="p-3 flex flex-col gap-1 min-w-0" wire:key="au-{{ $k['thema_id'] }}" data-automatik-thema="{{ $k['thema_id'] }}">
                    <div class="flex items-start justify-between gap-3">
                        <strong class="min-w-0 break-words">{{ $k['thema'] }}</strong>
                        <div class="flex gap-1.5 shrink-0">
                            @if($e['typ'])<x-fa::badge tone="accent">{{ V::label(V::TYPEN, $e['typ']) }} · {{ V::label(V::EBENEN, $e['ebene']) }}</x-fa::badge>
                            @elseif($e['kandidat'])<x-fa::badge>Kandidat, Typ offen</x-fa::badge>@endif
                            @if($e['abgeklungen'])<x-fa::badge tone="warn">abgeklungen</x-fa::badge>@endif
                            <x-fa::badge data-automatik-aktion="{{ $z['aktion'] }}">{{ $z['aktion'] }}</x-fa::badge>
                        </div>
                    </div>
                    <div class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] flex flex-wrap gap-x-3">
                        <span>{{ $k['sichtungen'] }} Sichtungen @if($k['davon_pinnwand'] > 0)(davon {{ $k['davon_pinnwand'] }} Pinnwand)@endif</span>
                        <span>{{ $k['sichtungen_30t'] }} in 30 Tagen</span>
                        <span>{{ $k['quellen_fenster'] }} Quellen im Zeitraum</span>
                        <span>{{ count($k['quellenarten']) }} Quellenarten</span>
                        <span>{{ $k['monate'] }} Monate</span>
                        <span>Bedürfnis {{ number_format($k['anteil_beduerfnis'] * 100, 0) }} %</span>
                    </div>
                    <p class="text-[length:var(--fa-text-sm)]">{{ $z['hinweis'] }}</p>
                    <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">{{ implode(' ', $e['begruendung']) }}</p>
                </article>
            @endforeach
        </div>
    @endif
</div>
