@php use Platform\FoodAlchemist\Support\TrendVokabular as V; @endphp
{{-- Spec 93 A · Eingang: systematisch erfasste Artikel als Prüfliste — getrennt von der Pinnwand (Inspiration). --}}
<div class="flex flex-col gap-3" data-trend-eingang>
    @if($meldung)<x-fa::notice tone="ok" data-eingang-meldung>{{ $meldung }}</x-fa::notice>@endif
    @if($fehler)<x-fa::notice tone="crit" data-eingang-fehler>{{ $fehler }}</x-fa::notice>@endif

    <div class="flex flex-wrap items-center gap-1.5">
        @foreach(['neu' => 'Offen', 'verworfen' => 'Verworfen', 'alle' => 'Alle'] as $wert => $label)
            <button type="button" wire:click="$set('filter', @js($wert))" wire:key="ef-{{ $wert }}"
                    class="fa-chip {{ $filter === $wert ? 'bg-[var(--fa-accent-soft)] text-[var(--fa-accent)]' : '' }}">
                <span>{{ $label }}@if($wert === 'verworfen' && ($zaehler['verworfen'] ?? 0) > 0) · {{ $zaehler['verworfen'] }}@endif</span>
            </button>
        @endforeach
        <x-fa::input size="sm" wire:model.live.debounce.400ms="suche" placeholder="Im Eingang suchen…" class="ml-auto w-56" aria-label="Im Eingang suchen" />
    </div>

    @if($eintraege->isEmpty())
        <div class="fa-surface">
            <x-fa::empty icon="heroicon-o-inbox" title="Der Eingang ist leer">
                Hier landen Artikel aus den Quellen (Reiter „Quellen“) und was per MCP eingereicht wird. Die Pinnwand bleibt davon unberührt.
            </x-fa::empty>
        </div>
    @else
        <div class="fa-surface divide-y divide-[var(--fa-line)]" data-eingang-liste>
            @foreach($eintraege as $e)
                @php $eigen = (int) $e->team_id === $teamId; @endphp
                <article class="p-3 flex flex-col gap-1.5 min-w-0" wire:key="eg-{{ $e->id }}" data-eingang-eintrag="{{ $e->id }}">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <a href="{{ $e->url }}" target="_blank" rel="noopener nofollow" class="font-medium hover:underline break-words">{{ $e->titel }}</a>
                            <div class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] flex flex-wrap gap-x-2">
                                <span>{{ $e->quelle?->name ?? V::label(V::EINGEREICHT_VON, $e->eingereicht_von) }}</span>
                                @if($e->veroeffentlicht_am)<span>{{ $e->veroeffentlicht_am->format('d.m.Y') }}</span>@endif
                                @if($e->thema)<span>Thema: {{ $e->thema }}</span>@endif
                            </div>
                        </div>
                        <x-fa::badge :tone="$e->status === 'verworfen' ? 'neutral' : 'accent'">{{ V::label(V::EINGANG_STATUS, $e->status) }}</x-fa::badge>
                    </div>
                    @if($e->zusammenfassung || $e->teaser)
                        <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] line-clamp-3">{{ $e->zusammenfassung ?? $e->teaser }}</p>
                    @endif
                    @if($darfKuratieren && $eigen)
                        <div class="flex flex-wrap items-center gap-1.5">
                            @if($e->status === 'verworfen')
                                <x-fa::button size="sm" variant="ghost" icon="heroicon-m-arrow-uturn-left" wire:click="entscheiden({{ $e->id }}, 'wiederherstellen')">Wiederherstellen</x-fa::button>
                            @else
                                <x-fa::button size="sm" variant="ghost" icon="heroicon-m-x-mark" wire:click="entscheiden({{ $e->id }}, 'verwerfen')" data-eingang-verwerfen>Verwerfen</x-fa::button>
                            @endif
                            @if($e->inspiration_id === null)
                                <x-fa::button size="sm" variant="ghost" icon="heroicon-m-light-bulb" wire:click="entscheiden({{ $e->id }}, 'pinnwand')" data-eingang-pinnwand>Auf die Pinnwand</x-fa::button>
                            @else
                                <span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">liegt auf der Pinnwand</span>
                            @endif
                            <x-fa::input size="sm" wire:model="thema.{{ $e->id }}" placeholder="Thema ändern…" class="w-44" aria-label="Thema für {{ $e->titel }}" />
                            <x-fa::button size="sm" variant="ghost" wire:click="entscheiden({{ $e->id }}, 'thema')">Setzen</x-fa::button>
                        </div>
                    @endif
                </article>
            @endforeach
        </div>
    @endif
</div>
