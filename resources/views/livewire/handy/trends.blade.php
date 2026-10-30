{{-- #1003 Teil 8 · Trendradar am Handy (Concerns\HandyTrendsLesen): Reiter Trends (Karten nach Ebene oder Kategorie) |
     Pinnwand (Fundstücke mit Bild, „Fundstück ablegen“ bedienbar) → Trend lesbar über die ganze Breite. Keine Einordnung,
     keine Belege anhängen, kein Status, kein Messen. Das Radar bleibt am Computer (bei 375 px nicht eindeutig antippbar). --}}
@php
    use Platform\FoodAlchemist\Support\TrendVokabular as V;

    $leise = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]';
    $statusTon = ['gesichtet' => 'warn', 'geprueft' => 'info', 'auf_radar' => 'accent', 'in_umsetzung' => 'ok', 'archiviert' => 'neutral', 'verworfen' => 'neutral'];
    $konfTon = ['hoch' => 'ok', 'mittel' => 'info', 'niedrig' => 'warn'];
    $liste = 'flex flex-col rounded-[var(--fa-radius-surface)] border border-[var(--fa-line)] bg-[var(--fa-surface)] divide-y divide-[var(--fa-line)]';
@endphp
<x-ui-page>
    <x-slot:navbar>
        <x-foodalchemist::shell.page-navbar title="Trendradar" icon="heroicon-o-signal" />
    </x-slot:navbar>

    <div class="flex-1 min-w-0 h-full overflow-y-auto overflow-x-hidden px-4 py-4 space-y-3" data-fa-handy-trends>
        @if($gewaehlt !== null || $trendFehlt)
            <button type="button" wire:click="deselect" class="-ml-1 inline-flex items-center gap-1 min-h-11 pr-3 text-[length:var(--fa-text-md)] font-medium text-[var(--fa-accent)]" data-fa-handy-zurueck>
                @svg('heroicon-m-chevron-left', 'w-5 h-5') Zurück
            </button>
        @endif

        @if($gewaehlt !== null)
            @php $kf = $gewaehlt->wirksameKonfidenz(); @endphp
            <article class="space-y-4 min-w-0 wrap-anywhere" data-fa-handy-trend="{{ $gewaehlt->id }}">
                <div>
                    <h1 class="text-[length:var(--fa-text-2xl)] font-semibold leading-tight text-[var(--fa-ink)]">{{ $gewaehlt->name }}</h1>
                    <p class="{{ $leise }}">{{ $gewaehlt->kategorie ? V::KATEGORIEN[$gewaehlt->kategorie] . ($gewaehlt->food_cluster ? ' · ' . V::FOOD_CLUSTER[$gewaehlt->food_cluster] : '') : 'Noch nicht eingeordnet' }}</p>
                    <div class="mt-1.5 flex flex-wrap gap-1.5">
                        @if($gewaehlt->typ)<x-fa::badge :tone="in_array($gewaehlt->typ, ['hype'], true) ? 'warn' : 'accent'">{{ V::TYPEN[$gewaehlt->typ] }}</x-fa::badge>@endif
                        <x-fa::badge :tone="$statusTon[$gewaehlt->status] ?? 'neutral'">{{ V::STATUS[$gewaehlt->status] ?? $gewaehlt->status }}</x-fa::badge>
                        @if($gewaehlt->befragung_bestaetigt)<x-fa::badge tone="ok" icon="heroicon-m-check-badge">Befragung bestätigt</x-fa::badge>@endif
                    </div>
                </div>

                <dl class="grid grid-cols-2 rounded-[var(--fa-radius-surface)] border border-[var(--fa-line)] bg-[var(--fa-surface)] divide-x divide-[var(--fa-line)]">
                    <div class="flex flex-col gap-0.5 px-3 py-2.5 min-w-0">
                        <dt class="{{ $leise }}">Ebene</dt>
                        <dd class="font-semibold text-[var(--fa-ink)]">{{ $gewaehlt->ebene ? V::EBENEN[$gewaehlt->ebene] : 'nicht eingeordnet' }}</dd>
                    </div>
                    <div class="flex flex-col gap-0.5 px-3 py-2.5 min-w-0">
                        <dt class="{{ $leise }}">Konfidenz</dt>
                        <dd><x-fa::badge :tone="$konfTon[$kf] ?? 'neutral'">{{ V::KONFIDENZ[$kf] ?? $kf }}</x-fa::badge></dd>
                    </div>
                </dl>

                @if($gewaehlt->definition)
                    <section class="space-y-1">
                        <h2 class="text-[length:var(--fa-text-md)] font-semibold text-[var(--fa-ink)]">Definition</h2>
                        <p class="leading-relaxed text-[var(--fa-ink)]">{{ $gewaehlt->definition }}</p>
                    </section>
                @endif
                @if(($bewertung['begruendung'] ?? '') !== '' || $gewaehlt->befragung_anteil !== null)
                    <p class="{{ $leise }}">{{ $bewertung['begruendung'] ?? '' }}@if($gewaehlt->befragung_anteil !== null) Befragung: {{ number_format($gewaehlt->befragung_anteil, 0, ',', '.') }} % der Nennungen.@endif</p>
                @endif
                @if($gewaehlt->historische_einordnung || $gewaehlt->gartner_phase || $gewaehlt->einordnung_begruendung)
                    <section class="space-y-1">
                        <h2 class="text-[length:var(--fa-text-md)] font-semibold text-[var(--fa-ink)]">Einordnung</h2>
                        @if($gewaehlt->historische_einordnung)<p class="text-[var(--fa-ink)]">{{ $gewaehlt->historische_einordnung }}</p>@endif
                        @if($gewaehlt->gartner_phase)<p class="{{ $leise }}">Gartner Hype Cycle: {{ V::GARTNER_PHASEN[$gewaehlt->gartner_phase] }}</p>@endif
                        @if($gewaehlt->einordnung_begruendung)<p class="{{ $leise }}">{{ $gewaehlt->einordnung_begruendung }}@if(in_array($gewaehlt->einordnung_quelle, ['ki'], true)) (KI-Vorschlag)@endif</p>@endif
                    </section>
                @endif

                <section class="space-y-2" data-fa-handy-trend-belege>
                    <h2 class="text-[length:var(--fa-text-md)] font-semibold text-[var(--fa-ink)]">Belege <span class="font-normal {{ $leise }}">{{ $belege->count() }}</span></h2>
                    <ul class="{{ $liste }}">
                        @forelse($belege as $eintrag)
                            @php $b = $eintrag['b']; @endphp
                            <li class="px-4 py-3 space-y-1" wire:key="hb-{{ $b->id }}">
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <x-fa::badge>{{ V::QUELLEN[$b->quelle] ?? $b->quelle }}</x-fa::badge>
                                    @if($b->beobachtet_am)<span class="{{ $leise }}">{{ $b->beobachtet_am->format('d.m.Y') }}</span>@endif
                                </div>
                                @if($b->titel)<p class="text-[var(--fa-ink)]">{{ $b->titel }}</p>@endif
                                @if($b->notiz)<p class="{{ $leise }}">{{ $b->notiz }}</p>@endif
                                <div class="flex flex-wrap gap-x-4">
                                    @if($b->url)<a href="{{ $b->url }}" target="_blank" rel="noopener" class="inline-flex items-center min-h-11 text-[var(--fa-accent)] underline">Link öffnen</a>@endif
                                    @if($eintrag['url'])<a href="{{ $eintrag['url'] }}" target="_blank" rel="noopener" class="inline-flex items-center min-h-11 text-[var(--fa-accent)] underline">{{ $b->datei_name ?: 'Datei öffnen' }}</a>@endif
                                </div>
                            </li>
                        @empty
                            <li class="px-4 py-3 {{ $leise }}">Noch kein Beleg.</li>
                        @endforelse
                    </ul>
                </section>

                @if($messungen->isNotEmpty())
                    <section class="space-y-1">
                        <h2 class="text-[length:var(--fa-text-md)] font-semibold text-[var(--fa-ink)]">Google Trends</h2>
                        @foreach($messungen as $m)
                            <p class="{{ $leise }}" wire:key="hm-{{ $m->id }}"><span class="text-[var(--fa-ink)]">„{{ $m->suchbegriff }}“</span> · {{ ucfirst((string) $m->richtung ?: 'keine Daten') }} · Ø {{ $m->durchschnitt ?? '–' }} · {{ $m->created_at?->format('d.m.Y') }}</p>
                        @endforeach
                    </section>
                @endif
            </article>
        @elseif($trendFehlt)
            <x-fa::empty compact icon="heroicon-o-signal" title="Trend nicht verfügbar">Er ist für diesen Mandanten nicht sichtbar oder wurde gelöscht.</x-fa::empty>
        @else
            <h1 class="text-[length:var(--fa-text-2xl)] font-semibold text-[var(--fa-ink)]">Trendradar</h1>
            <div role="tablist" aria-label="Ansicht" class="flex p-0.5 gap-0.5 rounded-[var(--fa-radius-control)] bg-[var(--fa-neutral-soft)]" data-fa-handy-reiter>
                @foreach(['trends' => 'Trends', 'pinnwand' => 'Pinnwand'] as $rk => $rt)
                    <button type="button" role="tab" wire:click="$set('handyReiter', '{{ $rk }}')" aria-selected="{{ $reiter === $rk ? 'true' : 'false' }}"
                            class="flex-1 min-h-11 rounded-[5px] text-[length:var(--fa-text-md)] font-medium {{ $reiter === $rk ? 'bg-[var(--fa-surface)] text-[var(--fa-ink)] shadow-sm' : 'text-[var(--fa-ink-2)]' }}">{{ $rt }}</button>
                @endforeach
            </div>
            <input type="search" wire:model.live.debounce.300ms="suche" placeholder="{{ $reiter === 'pinnwand' ? 'Fundstück suchen' : 'Trend suchen' }}" aria-label="Suchen"
                   class="fa-control h-12 text-[length:var(--fa-text-base)]" data-fa-handy-suche>

            @if($reiter === 'pinnwand')
                @include('foodalchemist::livewire.handy.trend-pinnwand')
            @else
                <div class="flex gap-2">
                    <select wire:model.live="handyGruppe" aria-label="Gruppieren" class="fa-select h-11 flex-1 min-w-0" data-fa-handy-gruppe>
                        <option value="ebene">Nach Ebene</option>
                        <option value="kategorie">Nach Kategorie</option>
                    </select>
                    <select wire:model.live="statusFilter" aria-label="Status" class="fa-select h-11 flex-1 min-w-0" data-fa-handy-status>
                        <option value="">Alle Status</option>
                        @foreach(V::STATUS as $wert => $label)<option value="{{ $wert }}">{{ $label }}</option>@endforeach
                    </select>
                </div>
                @forelse($gruppen as $gk => $g)
                    <section class="space-y-1.5" wire:key="hg-{{ $gruppe }}-{{ $gk }}" data-fa-handy-trend-gruppe="{{ $gk }}">
                        <h2 class="text-[length:var(--fa-text-md)] font-semibold text-[var(--fa-ink-2)]">{{ $g['label'] }} <span class="font-normal {{ $leise }}">{{ $g['trends']->count() }}</span></h2>
                        <ul class="{{ $liste }}">
                            @foreach($g['trends'] as $t)
                                <li wire:key="ht-{{ $t->id }}">
                                    <button type="button" wire:click="select({{ $t->id }})" class="flex w-full items-center gap-3 px-4 py-3 min-h-[60px] text-left" data-fa-handy-karte="{{ $t->id }}">
                                        <span class="min-w-0 flex-1">
                                            <span class="block font-medium leading-snug text-[var(--fa-ink)] wrap-anywhere">{{ $t->name }}</span>
                                            <span class="block truncate {{ $leise }}">{{ implode(' · ', array_filter([
                                                $gruppe === 'ebene' ? ($t->kategorie ? V::KATEGORIEN[$t->kategorie] : null) : ($t->ebene ? V::EBENEN[$t->ebene] : null),
                                                V::STATUS[$t->status] ?? $t->status,
                                            ])) }}</span>
                                        </span>
                                        @if($t->typ)<x-fa::badge :tone="in_array($t->typ, ['hype'], true) ? 'warn' : 'accent'">{{ V::TYPEN[$t->typ] }}</x-fa::badge>@endif
                                        @svg('heroicon-m-chevron-right', 'w-5 h-5 shrink-0 text-[var(--fa-ink-3)]')
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @empty
                    <x-fa::empty compact icon="heroicon-o-magnifying-glass" title="Keine Trends">Andere Suche oder Status versuchen.</x-fa::empty>
                @endforelse
            @endif
        @endif
    </div>
</x-ui-page>
