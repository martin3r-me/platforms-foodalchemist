@php use Platform\FoodAlchemist\Support\TrendVokabular as V; @endphp
{{-- Spec 93 A · Quellen des Trendradars: Feed, Übersicht oder MCP — das System wählt beim Anlegen selbst. --}}
<div class="flex flex-col gap-3" data-trend-quellen>
    @if($meldung)<x-fa::notice tone="ok" data-quellen-meldung>{{ $meldung }}</x-fa::notice>@endif
    @if($fehler)<x-fa::notice tone="crit" data-quellen-fehler>{{ $fehler }}</x-fa::notice>@endif

    @if($darfKuratieren)
        <x-fa::section title="Quelle hinzufügen" description="Feed- oder Seiten-URL. Das System sucht einen Feed, sonst liest es die Übersichtsseite; ist die Seite nicht lesbar, wird die Quelle per MCP gesichtet.">
            <form wire:submit="anlegen" class="flex flex-wrap items-end gap-2" data-quelle-neu>
                <x-fa::field label="URL" class="grow min-w-[16rem]"><x-fa::input wire:model="neu.url" placeholder="https://…" /></x-fa::field>
                <x-fa::field label="Name" optional><x-fa::input wire:model="neu.name" /></x-fa::field>
                <x-fa::field label="Art"><x-fa::select wire:model="neu.art" :options="$arten" /></x-fa::field>
                <x-fa::button type="submit" variant="primary" icon="heroicon-m-plus">Prüfen und anlegen</x-fa::button>
            </form>
        </x-fa::section>
    @endif

    @if($quellen->isEmpty())
        <div class="fa-surface">
            <x-fa::empty icon="heroicon-o-rss" title="Noch keine Quellen">
                Fachpresse und Branchenquellen hier hinterlegen. Neue Quellen stehen auf dem Rhythmus aus den Trendradar-Einstellungen (Standard „aus“).
            </x-fa::empty>
        </div>
    @else
        <div class="fa-surface divide-y divide-[var(--fa-line)]" data-quellen-liste>
            @foreach($quellen as $q)
                @php
                    $eigen = (int) $q->team_id === $teamId;
                    $faellig = $svc->istFaellig($q);
                    $w = $svc->stichworte($q);
                @endphp
                <article class="p-3 flex flex-col gap-1.5 min-w-0" wire:key="tq-{{ $q->id }}" data-quelle="{{ $q->id }}">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <a href="{{ $q->url }}" target="_blank" rel="noopener nofollow" class="font-medium hover:underline break-words">{{ $q->name }}</a>
                            <div class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] flex flex-wrap gap-x-2">
                                <span>{{ V::label(V::QUELLEN, $q->art) }}</span>
                                <span>{{ V::label(V::ABRUF_WEGE, $q->abruf_weg) }} · {{ V::label(V::FEED_TYPEN, $q->feed_typ) }}</span>
                                <span>Rhythmus: {{ V::label(V::RHYTHMEN, $q->rhythmus) }}</span>
                                <span>Letzter Abruf: {{ $q->letzter_abruf_at?->format('d.m.Y H:i') ?? 'noch nie' }}</span>
                                <span>Gefiltert: {{ $q->gefiltert_letzter_abruf }} zuletzt · {{ $q->gefiltert_gesamt }} gesamt</span>
                            </div>
                        </div>
                        <div class="flex gap-1.5 shrink-0">
                            @if(! $q->aktiv)<x-fa::badge>pausiert</x-fa::badge>@elseif($faellig)<x-fa::badge tone="warn">fällig</x-fa::badge>@endif
                        </div>
                    </div>
                    @if($q->letzter_fehler)
                        <x-fa::notice tone="warn" data-quelle-fehler>{{ $q->letzter_fehler }} @if($q->fehler_in_folge > 1)({{ $q->fehler_in_folge }}× in Folge)@endif</x-fa::notice>
                    @endif
                    @if($q->pruef_hinweis)<p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">{{ $q->pruef_hinweis }}</p>@endif
                    <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">
                        Stichworte ein: {{ $w['ein'] === [] ? 'alles' : implode(', ', $w['ein']) }}@if($w['ein_quelle'] === 'team' && $w['ein'] !== []) (Team-Standard)@endif
                        · aus: {{ $w['aus'] === [] ? '–' : implode(', ', $w['aus']) }}@if($w['aus_quelle'] === 'team' && $w['aus'] !== []) (Team-Standard)@endif
                    </p>

                    @if(isset($bearbeiten[$q->id]))
                        <div class="flex flex-wrap items-end gap-2" data-quelle-bearbeiten>
                            <x-fa::field label="Rhythmus"><x-fa::select size="sm" wire:model="bearbeiten.{{ $q->id }}.rhythmus" :options="$rhythmen" /></x-fa::field>
                            <x-fa::field label="Stichworte ein" hint="Beispiel, keine Vorgabe: {{ implode(', ', V::STICHWORT_BEISPIEL_EIN) }}. Leer = Team-Standard." class="grow min-w-[14rem]">
                                <x-fa::input size="sm" wire:model="bearbeiten.{{ $q->id }}.stichworte_ein" />
                            </x-fa::field>
                            <x-fa::field label="Stichworte aus" hint="Beispiel: {{ implode(', ', V::STICHWORT_BEISPIEL_AUS) }}. Phrasen in Anführungszeichen." class="grow min-w-[14rem]">
                                <x-fa::input size="sm" wire:model="bearbeiten.{{ $q->id }}.stichworte_aus" />
                            </x-fa::field>
                            <x-fa::button size="sm" variant="primary" wire:click="speichern({{ $q->id }})">Speichern</x-fa::button>
                        </div>
                    @endif

                    @if(isset($probe[$q->id]))
                        @php $p = $probe[$q->id]; @endphp
                        <div class="text-[length:var(--fa-text-sm)] flex flex-col gap-1" data-quelle-probelauf>
                            @if($p['fehler'])
                                <x-fa::notice tone="warn">{{ $p['fehler'] }}</x-fa::notice>
                            @else
                                <strong>Käme durch ({{ count($p['durch']) }}):</strong>
                                @foreach(array_slice($p['durch'], 0, 20) as $z)<span>· {{ $z['titel'] }}</span>@endforeach
                                <strong>Gefiltert ({{ count($p['gefiltert']) }}):</strong>
                                @foreach(array_slice($p['gefiltert'], 0, 20) as $z)<span class="text-[var(--fa-ink-3)]">· {{ $z['titel'] }} — {{ $z['grund'] }}</span>@endforeach
                            @endif
                        </div>
                    @endif

                    @if($darfKuratieren && $eigen)
                        <div class="flex flex-wrap items-center gap-1.5">
                            <x-fa::button size="sm" variant="ghost" icon="heroicon-m-pencil-square" wire:click="oeffnen({{ $q->id }})">Rhythmus & Stichworte</x-fa::button>
                            <x-fa::button size="sm" variant="ghost" icon="heroicon-m-funnel" wire:click="probelauf({{ $q->id }})" data-quelle-probe-knopf>Was käme durch?</x-fa::button>
                            <x-fa::button size="sm" variant="ghost" icon="heroicon-m-arrow-path" wire:click="neuPruefen({{ $q->id }})">Quelle neu prüfen</x-fa::button>
                            <x-fa::button size="sm" variant="ghost" :icon="$q->aktiv ? 'heroicon-m-pause' : 'heroicon-m-play'" wire:click="umschalten({{ $q->id }})">{{ $q->aktiv ? 'Pausieren' : 'Aktivieren' }}</x-fa::button>
                            <x-fa::button size="sm" variant="ghost" icon="heroicon-m-trash" wire:click="entfernen({{ $q->id }})" wire:confirm="Quelle entfernen? Eingegangene Artikel bleiben.">Entfernen</x-fa::button>
                        </div>
                    @endif
                </article>
            @endforeach
        </div>
    @endif
</div>
