{{-- Tagesplanung + Küchenmonitor — fa-pass (2026-10-05), Bausteine <x-fa::…> und --fa-*-Tokens.

     Häufigste Aufgaben:
       Tagesplanung (Küchenleitung): Tageshorizont lesen, Engpässe je Posten erkennen, einen Tag öffnen.
       Küchenmonitor (Koch am Pass): Aufgabe antippen, Anleitung lesen, abhaken.

     Grundanordnung wie vorher (Seitenleiste links Zeitraum/Posten, rechts Tagesdetail, Mitte Dashboard;
     Editor mit drei Spalten; Monitor mit Kopf + Posten-Spalten). Neu ist die Passform auf kleinen
     Bildschirmen (Laptop): Raster füllen nach verfügbarer Breite statt nach festen Bildschirm-Stufen,
     Posten-Spalten liegen nebeneinander und scrollen seitlich, statt sich in halbe Höhen zu teilen,
     und der Monitor-Kopf wird erst auf hohen Bildschirmen groß.

     Der Küchenmonitor läuft im Kiosk-Rahmen und schaltet über data-fa-theme="dark" die Werkbank-Tokens.
     Dort gelten Fernsicht-Größen (Tailwind-Skala), Farben trotzdem nur über Tokens.
     Alle wire:-Bindungen, wire:keys, Event-Namen und data-Marker unverändert. --}}
@php
    $istWall = $display === 'wall';

    $kg = fn ($wert) => rtrim(rtrim(number_format((float) $wert, 3, ',', '.'), '0'), ',');

    // Auslastungs-Stufe → Zustandston (ein Ort für alle Ansichten).
    $stufeTon = fn (?string $stufe) => match ($stufe) {
        'ueberlast' => 'crit',
        'eng' => 'warn',
        'ok' => 'ok',
        'ohne_kapazitaet' => 'info',
        default => 'neutral',
    };
    $stufeText = fn (?string $stufe) => match ($stufe) {
        'ueberlast' => 'Überlast',
        'eng' => 'Eng',
        'ok' => 'Im Plan',
        'ohne_kapazitaet' => 'Ohne Kapazität',
        default => 'Frei',
    };
    $tonFlaeche = [
        'crit' => 'bg-[var(--fa-crit-soft)] text-[var(--fa-crit)]',
        'warn' => 'bg-[var(--fa-warn-soft)] text-[var(--fa-warn)]',
        'ok' => 'bg-[var(--fa-ok-soft)] text-[var(--fa-ok)]',
        'info' => 'bg-[var(--fa-info-soft)] text-[var(--fa-info)]',
        'neutral' => 'bg-[var(--fa-neutral-soft)] text-[var(--fa-ink-3)]',
    ];
    $tonBalken = [
        'crit' => 'bg-[var(--fa-crit)]',
        'warn' => 'bg-[var(--fa-warn)]',
        'ok' => 'bg-[var(--fa-ok)]',
        'info' => 'bg-[var(--fa-info)]',
        'neutral' => 'bg-[var(--fa-ink-3)]',
    ];
    $tonRand = [
        'crit' => 'border-[var(--fa-crit)]',
        'warn' => 'border-[var(--fa-warn)]',
        'ok' => 'border-[var(--fa-ok)]',
        'info' => 'border-[var(--fa-info)]',
        'neutral' => 'border-[var(--fa-line)]',
    ];

    // Seitenlokale Klassen (nur Tokens).
    $eyebrow = 'text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-3)]';
    $chip = 'inline-flex items-center h-7 px-2.5 rounded-full border text-[length:var(--fa-text-md)] transition-colors duration-150';
    $chipAn = 'bg-[var(--fa-accent-soft)] border-[var(--fa-accent)] text-[var(--fa-accent)] font-medium';
    $chipAus = 'bg-[var(--fa-surface)] border-[var(--fa-line-strong)] text-[var(--fa-ink-2)] hover:text-[var(--fa-ink)] hover:border-[var(--fa-ink-3)]';
    $menuePunkt = 'flex w-full items-center gap-2 px-3 py-2 text-left text-[length:var(--fa-text-md)] text-[var(--fa-ink)] hover:bg-[var(--fa-hover)]';

    // Küchenmonitor (Fernsicht, Touch): große Ziele, kräftige Zustandsfarben.
    $wKnopf = 'inline-flex h-12 items-center justify-center gap-2 rounded-xl border border-[var(--fa-line-strong)] bg-[var(--fa-surface)] px-4 text-base font-semibold text-[var(--fa-ink)] transition-colors hover:bg-[var(--fa-hover)]';
    $wKnopfIcon = 'inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-xl border border-[var(--fa-line-strong)] bg-[var(--fa-surface)] text-[var(--fa-ink)] transition-colors hover:bg-[var(--fa-hover)]';
    $wSegment = 'rounded-lg px-4 py-2 text-base font-semibold transition-colors';
    $wSegmentAn = 'bg-[var(--fa-accent)] text-[var(--fa-on-accent)]';
    $wSegmentAus = 'text-[var(--fa-ink-2)] hover:text-[var(--fa-ink)]';
    $wEyebrow = 'text-sm font-semibold text-[var(--fa-ink-3)]';
    $wKarte = 'rounded-2xl border border-[var(--fa-line)] bg-[var(--fa-surface)]';
    $wFlaeche = 'rounded-xl bg-[var(--fa-ground)]';
    $wSicher = 'inline-flex items-center rounded-full px-2.5 py-1 text-sm font-semibold';
    $wHakenOffen = 'border-dashed border-[var(--fa-accent)] bg-[var(--fa-accent-soft)] text-[var(--fa-accent)] hover:bg-[var(--fa-accent-soft-hover)]';
    $wHakenLaeuft = 'border-[var(--fa-line-strong)] bg-[var(--fa-ground)] text-[var(--fa-ink)] hover:border-[var(--fa-accent)]';
    $wHakenFertig = 'border-[var(--fa-ok)] bg-[var(--fa-ok)] text-[var(--fa-ground)]';
@endphp

<div class="{{ $istWall ? '' : 'h-full min-h-0' }}">
@if($istWall)
    @php
        $wallTag = \Illuminate\Support\Carbon::parse($von)->toDateString();
        $wallZeilen = $zeilenNachTag->get($wallTag, collect());
        $wallBuckets = collect($auslastung[$wallTag] ?? []);
        $offen = $wallZeilen->reject(fn ($z) => in_array($z->line_status, ['done', 'skipped'], true))->count();
        $fertig = $wallZeilen->filter(fn ($z) => $z->line_status === 'done')->count();
        $krit = $wallBuckets->where('stufe', 'ueberlast')->count();
        $gesamtMin = (int) $wallZeilen->sum('arbeitszeit_min');
    @endphp

    <div class="h-screen w-screen overflow-hidden bg-[var(--fa-ground)] text-[var(--fa-ink)]" data-fa-theme="dark"
         data-tagesplan-wall data-tagesplan-wall-kiosk wire:poll.30s>
        <div class="flex h-full min-h-0 flex-col">
            {{-- Kopf: kompakt auf Laptop-Höhe, groß erst ab ca. 900 px Bildschirmhöhe --}}
            <header class="shrink-0 border-b border-[var(--fa-line)] bg-[var(--fa-surface)] px-4 py-3 [@media(min-height:56rem)]:px-5 [@media(min-height:56rem)]:py-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex min-w-0 items-center gap-3">
                        <a href="{{ route('foodalchemist.produktion.tagesplan', ['von' => $von, 'bis' => $bis, 'tage' => $tage]) }}"
                           onclick="try { if (document.fullscreenElement) document.exitFullscreen(); } catch (_) {}"
                           class="{{ $wKnopf }} shrink-0"
                           aria-label="Zurück zur Tagesplanung" data-tagesplan-wall-zurueck>
                            @svg('heroicon-m-chevron-left', 'w-5 h-5')<span>Zurück</span>
                        </a>
                        <div class="min-w-0">
                            <p class="{{ $wEyebrow }}">Küchenmonitor, aktualisiert sich alle 30 Sekunden</p>
                            <h1 class="truncate text-2xl font-bold tracking-tight md:text-3xl [@media(min-height:56rem)]:md:text-5xl">
                                {{ \Illuminate\Support\Carbon::parse($wallTag)->locale('de')->isoFormat('dddd, D. MMMM') }}
                            </h1>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center justify-end gap-2" data-tagesplan-steuerung>
                        <button type="button" wire:click="verschiebe(-1)" class="{{ $wKnopfIcon }}" aria-label="Vorheriger Tag" title="Vorheriger Tag">@svg('heroicon-m-chevron-left', 'w-6 h-6')</button>
                        <button type="button" wire:click="heute" class="{{ $wKnopf }}" data-tagesplan-heute>Heute</button>
                        <button type="button" wire:click="verschiebe(1)" class="{{ $wKnopfIcon }}" aria-label="Nächster Tag" title="Nächster Tag">@svg('heroicon-m-chevron-right', 'w-6 h-6')</button>
                        <button type="button"
                                x-data
                                x-on:click="(async () => { const root = document.documentElement; if (!root.requestFullscreen) return; try { document.fullscreenElement ? await document.exitFullscreen() : await root.requestFullscreen(); } catch (_) {} })()"
                                title="Browser-Vollbild umschalten"
                                class="inline-flex h-12 items-center gap-2 rounded-xl bg-[var(--fa-accent)] px-5 text-base font-semibold text-[var(--fa-on-accent)] transition-colors hover:bg-[var(--fa-accent-hover)]"
                                data-tagesplan-wall-fullscreen>@svg('heroicon-m-arrows-pointing-out', 'w-5 h-5') Vollbild</button>
                    </div>
                </div>

                {{-- Kennzahlen als eine Zeile: auf dem Laptop eine Reihe, nicht zwei Kachelreihen --}}
                <div class="mt-3 flex flex-wrap gap-2">
                    <div class="flex items-baseline gap-2 rounded-xl bg-[var(--fa-ground)] px-4 py-2">
                        <span class="text-2xl font-bold tabular-nums [@media(min-height:56rem)]:text-3xl">{{ $offen }}</span><span class="text-base text-[var(--fa-ink-2)]">offen</span>
                    </div>
                    <div class="flex items-baseline gap-2 rounded-xl {{ $tonFlaeche['ok'] }} px-4 py-2">
                        <span class="text-2xl font-bold tabular-nums [@media(min-height:56rem)]:text-3xl">{{ $fertig }}</span><span class="text-base">erledigt</span>
                    </div>
                    <div class="flex items-baseline gap-2 rounded-xl {{ $krit > 0 ? $tonFlaeche['crit'] : 'bg-[var(--fa-ground)]' }} px-4 py-2">
                        <span class="text-2xl font-bold tabular-nums [@media(min-height:56rem)]:text-3xl">{{ $krit }}</span><span class="text-base {{ $krit > 0 ? '' : 'text-[var(--fa-ink-2)]' }}">Posten über Last</span>
                    </div>
                    <div class="flex items-baseline gap-2 rounded-xl bg-[var(--fa-ground)] px-4 py-2">
                        <span class="text-2xl font-bold tabular-nums [@media(min-height:56rem)]:text-3xl">{{ $gesamtMin }}</span><span class="text-base text-[var(--fa-ink-2)]">Minuten Arbeit</span>
                    </div>
                    @foreach(collect($readiness)->take(2) as $f)
                        <div class="flex items-baseline gap-2 rounded-xl {{ $f['level'] === 'blocker' ? $tonFlaeche['crit'] : $tonFlaeche['warn'] }} px-4 py-2" data-tagesplan-readiness>
                            <span class="text-2xl font-bold tabular-nums [@media(min-height:56rem)]:text-3xl">{{ $f['count'] }}</span>
                            <span class="text-base">{{ $f['label'] }}</span>
                        </div>
                    @endforeach
                </div>

                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <div class="inline-flex rounded-xl border border-[var(--fa-line)] bg-[var(--fa-ground)] p-1" role="group" aria-label="Ansicht" data-tagesplan-wall-ansicht>
                        <button type="button" wire:click="wallAnsichtSetzen('lanes')" aria-pressed="{{ $wallAnsicht === 'lanes' ? 'true' : 'false' }}" class="{{ $wSegment }} {{ $wallAnsicht === 'lanes' ? $wSegmentAn : $wSegmentAus }}">Nach Posten</button>
                        <button type="button" wire:click="wallAnsichtSetzen('mise')" aria-pressed="{{ $wallAnsicht === 'mise' ? 'true' : 'false' }}" class="{{ $wSegment }} {{ $wallAnsicht === 'mise' ? $wSegmentAn : $wSegmentAus }}" data-tagesplan-wall-mise>Mise en Place</button>
                    </div>
                    @if($wallAnsicht === 'lanes')
                        @php
                            $wallFilterGruppen = $wallPostenGruppen->flatten(1);
                            $wallFilterAlle = $wallFilterGruppen->count();
                            $wallFilterGerichte = $wallFilterGruppen->filter(fn ($gruppe) => (bool) ($gruppe->hat_gericht ?? false))->count();
                            $wallFilterBasis = $wallFilterAlle - $wallFilterGerichte;
                        @endphp
                        <div class="inline-flex rounded-xl border border-[var(--fa-line)] bg-[var(--fa-ground)] p-1" role="group" aria-label="Was zeigen" data-tagesplan-wall-gruppenfilter>
                            <button type="button" wire:click="wallGruppenFilterSetzen('alle')" aria-pressed="{{ $wallGruppenFilter === 'alle' ? 'true' : 'false' }}" class="{{ $wSegment }} {{ $wallGruppenFilter === 'alle' ? $wSegmentAn : $wSegmentAus }}" data-tagesplan-wall-filter-alle>Alle <span class="ml-1 text-sm tabular-nums opacity-75">{{ $wallFilterAlle }}</span></button>
                            <button type="button" wire:click="wallGruppenFilterSetzen('gerichte')" aria-pressed="{{ $wallGruppenFilter === 'gerichte' ? 'true' : 'false' }}" class="{{ $wSegment }} {{ $wallGruppenFilter === 'gerichte' ? $wSegmentAn : $wSegmentAus }}" data-tagesplan-wall-filter-gerichte>Gerichte <span class="ml-1 text-sm tabular-nums opacity-75">{{ $wallFilterGerichte }}</span></button>
                            <button type="button" wire:click="wallGruppenFilterSetzen('basis')" aria-pressed="{{ $wallGruppenFilter === 'basis' ? 'true' : 'false' }}" class="{{ $wSegment }} {{ $wallGruppenFilter === 'basis' ? $wSegmentAn : $wSegmentAus }}" data-tagesplan-wall-filter-basis>Basisrezepte <span class="ml-1 text-sm tabular-nums opacity-75">{{ $wallFilterBasis }}</span></button>
                        </div>
                    @endif
                    @if($postenFilter !== null)
                        <button type="button" wire:click="postenWaehlen({{ $postenFilter }})"
                                class="inline-flex h-10 items-center gap-2 rounded-full border border-[var(--fa-accent)] bg-[var(--fa-accent-soft)] px-4 text-sm font-semibold text-[var(--fa-accent)]"
                                data-tagesplan-wall-station-reset>@svg('heroicon-m-x-mark', 'w-4 h-4') Alle Posten zeigen</button>
                    @endif
                    @foreach($wallBuckets as $b)
                        @php
                            $ton = $stufeTon($b['stufe'] ?? null);
                        @endphp
                        <button type="button" wire:click="postenWaehlen({{ $b['station_id'] === null ? 'null' : (int) $b['station_id'] }})"
                                aria-pressed="{{ $postenFilter === $b['station_id'] ? 'true' : 'false' }}"
                                class="inline-flex h-10 items-center gap-2 rounded-full border px-4 text-sm text-[var(--fa-ink)] {{ $postenFilter === $b['station_id'] ? 'border-[var(--fa-accent)] bg-[var(--fa-accent-soft)]' : 'border-[var(--fa-line)] bg-[var(--fa-ground)]' }}"
                                title="{{ $stufeText($b['stufe'] ?? null) }}"
                                data-tagesplan-ampeln data-tagesplan-wall-station-filter>
                            <span class="h-2.5 w-2.5 shrink-0 rounded-full {{ $tonBalken[$ton] }}"></span>{{ $b['station'] }}
                            <span class="tabular-nums text-[var(--fa-ink-3)]">{{ $b['geplant_min'] }}@if($b['kapazitaet_min'] !== null) / {{ $b['kapazitaet_min'] }}@endif min</span>
                        </button>
                    @endforeach
                </div>
            </header>

            <main class="min-h-0 flex-1 overflow-hidden p-3 [@media(min-height:56rem)]:p-4">
                @if($fehler)<x-fa::notice tone="crit" class="mb-3" data-tagesplan-fehler>{{ $fehler }}</x-fa::notice>@endif

                @if($wallAnsicht === 'mise')
                    <section class="h-full min-h-0 overflow-y-auto rounded-2xl border border-[var(--fa-line)] bg-[var(--fa-surface)] p-3" data-tagesplan-mise>
                        {{-- Füllt nach Breite: auf dem Laptop zwei bis drei Karten je Reihe, am großen Monitor mehr --}}
                        <div class="grid grid-cols-[repeat(auto-fill,minmax(min(100%,20rem),1fr))] gap-3">
                            @forelse($miseEnPlace as $m)
                                @php
                                    $miseFertig = $m->gesamt > 0 && $m->erledigt === $m->gesamt;
                                @endphp
                                <article class="flex gap-3 rounded-2xl border border-[var(--fa-line)] bg-[var(--fa-ground)] p-4 text-left transition-colors hover:border-[var(--fa-accent)] {{ $miseFertig ? 'opacity-60' : '' }}" data-tagesplan-mise-karte>
                                    <button type="button" wire:click="abhakenMise({{ $m->erste_line_id }})"
                                            class="grid h-16 w-16 shrink-0 place-items-center rounded-2xl border-2 {{ $miseFertig ? $wHakenFertig : $wHakenOffen }}"
                                            title="{{ $miseFertig ? 'Mise en Place zurücknehmen' : 'Mise en Place als erledigt abhaken' }}"
                                            aria-label="{{ $miseFertig ? 'Mise en Place zurücknehmen' : 'Mise en Place als erledigt abhaken' }}"
                                            data-tagesplan-mise-abhaken>@if($miseFertig)@svg('heroicon-m-check', 'w-9 h-9')@endif</button>
                                    <button type="button" wire:click="{{ $m->ist_gericht ? 'oeffneGericht(' . \Illuminate\Support\Js::from($m->gericht_key) . ')' : 'oeffneAnleitung(' . (int) $m->erste_line_id . ')' }}" class="min-w-0 flex-1 text-left" data-tagesplan-mise-anleitung @if($m->ist_gericht) data-tagesplan-mise-gericht @endif>
                                        <div class="flex items-start justify-between gap-2">
                                            <p class="break-words text-xl font-bold leading-tight {{ $miseFertig ? 'line-through' : '' }}">{{ $m->name }}</p>
                                            @if($m->ist_gericht)
                                                <span class="{{ $wSicher }} shrink-0 bg-[var(--fa-accent-soft)] text-[var(--fa-accent)]">Gericht</span>
                                            @elseif($m->ist_basisrezept)
                                                <span class="{{ $wSicher }} shrink-0 bg-[var(--fa-neutral-soft)] text-[var(--fa-ink-2)]">Basisrezept</span>
                                            @endif
                                        </div>
                                        <p class="mt-2 text-base text-[var(--fa-ink-2)] tabular-nums">{{ $m->anzahl }}× · {{ $m->erledigt }} von {{ $m->gesamt }} erledigt · {{ $m->minuten }} min</p>
                                        <p class="mt-1 break-words text-sm text-[var(--fa-ink-3)]">für {{ $m->auftraege->implode(', ') }}</p>
                                        @if($m->stationen->isNotEmpty())<p class="mt-1 text-sm text-[var(--fa-ink-3)]">Posten: {{ $m->stationen->implode(', ') }}</p>@endif
                                        @if(collect($m->sicherheit['allergene'] ?? [])->isNotEmpty() || collect($m->sicherheit['warnungen'] ?? [])->isNotEmpty() || collect($m->sicherheit['diaet'] ?? [])->isNotEmpty())
                                            <div class="mt-3 flex flex-wrap gap-1.5" data-tagesplan-wall-sicherheit>
                                                @foreach(collect($m->sicherheit['warnungen'] ?? [])->take(3) as $warnung)
                                                    <span class="{{ $wSicher }} {{ $tonFlaeche['warn'] }}" data-tagesplan-wall-warnung>{{ $warnung }}</span>
                                                @endforeach
                                                @foreach(collect($m->sicherheit['allergene'] ?? [])->take(4) as $a)
                                                    <span class="{{ $wSicher }} {{ $tonFlaeche['crit'] }}" data-tagesplan-wall-allergen>{{ $a['label'] }}</span>
                                                @endforeach
                                                @foreach(collect($m->sicherheit['diaet'] ?? [])->take(3) as $d)
                                                    <span class="{{ $wSicher }} {{ $tonFlaeche['ok'] }}" data-tagesplan-wall-diaet>{{ $d }}</span>
                                                @endforeach
                                            </div>
                                        @endif
                                    </button>
                                </article>
                            @empty
                                <div class="col-span-full grid min-h-[50vh] place-items-center text-center" data-tagesplan-leer>
                                    <div>
                                        @svg('heroicon-o-check-badge', 'mx-auto w-14 h-14 text-[var(--fa-ink-3)]')
                                        <p class="mt-3 text-3xl font-bold">Heute steht nichts an.</p>
                                        <p class="mt-2 text-lg text-[var(--fa-ink-3)]">Der Küchenmonitor aktualisiert sich automatisch.</p>
                                    </div>
                                </div>
                            @endforelse
                        </div>
                    </section>
                @else
                    @php
                        $nachPosten = $wallPostenGruppen
                            ->map(fn ($gruppen) => $gruppen
                                ->filter(fn ($gruppe) => $wallGruppenFilter === 'alle'
                                    || ($wallGruppenFilter === 'gerichte' && (bool) ($gruppe->hat_gericht ?? false))
                                    || ($wallGruppenFilter === 'basis' && ! (bool) ($gruppe->hat_gericht ?? false)))
                                ->values())
                            ->filter(fn ($gruppen) => $gruppen->isNotEmpty());
                        $sichtbareBuckets = $wallBuckets->filter(fn ($b) => ($nachPosten[$b['station_id'] === null ? '_none' : (int) $b['station_id']] ?? collect())->isNotEmpty())->values();
                        $einzelLane = $sichtbareBuckets->count() <= 1;
                    @endphp
                    @if($wallZeilen->isEmpty() || $sichtbareBuckets->isEmpty())
                        <div class="grid h-full place-items-center rounded-2xl border border-[var(--fa-line)] bg-[var(--fa-surface)] text-center" data-tagesplan-leer>
                            <div>
                                @svg('heroicon-o-check-badge', 'mx-auto w-14 h-14 text-[var(--fa-ink-3)]')
                                <p class="mt-3 text-3xl font-bold md:text-4xl">{{ $wallGruppenFilter === 'gerichte' ? 'Keine Gerichte an diesem Tag.' : ($wallGruppenFilter === 'basis' ? 'Keine Basisrezepte ohne Gericht.' : 'Heute steht nichts an.') }}</p>
                                <p class="mt-2 text-lg text-[var(--fa-ink-3)]">Der Küchenmonitor aktualisiert sich automatisch.</p>
                            </div>
                        </div>
                    @else
                        {{-- Posten-Spalten liegen IMMER nebeneinander und nutzen die volle Höhe.
                             Passen nicht alle auf den Bildschirm (Laptop), scrollt die Reihe seitlich —
                             früher brachen sie in eine zweite Reihe um und teilten sich die Höhe. --}}
                        <div class="{{ $einzelLane ? 'h-full min-h-0' : 'grid h-full min-h-0 grid-flow-col auto-cols-[minmax(min(100%,20rem),1fr)] gap-3 overflow-x-auto overscroll-x-contain pb-1 [@media(min-height:56rem)]:gap-4' }}" data-tagesplan-lanes @if($einzelLane) data-tagesplan-wall-single-lane @endif>
                            @foreach($sichtbareBuckets as $b)
                                @php
                                    $schluessel = $b['station_id'] === null ? '_none' : (int) $b['station_id'];
                                    $laneGruppen = $nachPosten[$schluessel] ?? collect();
                                    $laneZeilenAnzahl = $laneGruppen->sum(fn ($gruppe) => $gruppe->gesamt);
                                    $laneGruppenLabel = $wallGruppenFilter === 'gerichte'
                                        ? ($laneGruppen->count() === 1 ? 'Gericht' : 'Gerichte')
                                        : ($laneGruppen->count() === 1 ? 'Arbeitsblock' : 'Arbeitsblöcke');
                                    $laneTon = $stufeTon($b['stufe'] ?? null);
                                @endphp
                                <section class="flex h-full min-h-0 flex-col overflow-hidden rounded-2xl border border-t-4 border-[var(--fa-line)] {{ $tonRand[$laneTon] }} bg-[var(--fa-surface)]" data-tagesplan-lane="{{ $schluessel }}">
                                    <div class="shrink-0 border-b border-[var(--fa-line)] px-4 py-3">
                                        <div class="flex items-start justify-between gap-3">
                                            <div class="min-w-0">
                                                <h2 class="break-words text-2xl font-bold leading-tight">{{ $b['station'] }}</h2>
                                                <p class="mt-0.5 text-sm text-[var(--fa-ink-3)] tabular-nums">{{ $laneGruppen->count() }} {{ $laneGruppenLabel }} · {{ $laneZeilenAnzahl }} {{ $laneZeilenAnzahl === 1 ? 'Aufgabe' : 'Aufgaben' }} · {{ $b['geplant_min'] }}@if($b['kapazitaet_min'] !== null) von {{ $b['kapazitaet_min'] }}@endif min</p>
                                            </div>
                                            <span class="{{ $wSicher }} shrink-0 {{ $tonFlaeche[$laneTon] }}">{{ $stufeText($b['stufe'] ?? null) }}</span>
                                        </div>
                                    </div>
                                    <div class="min-h-0 flex-1 overflow-y-auto p-3">
                                        <div class="{{ $einzelLane ? 'grid auto-rows-max grid-cols-[repeat(auto-fill,minmax(min(100%,22rem),1fr))] gap-3' : 'space-y-3' }}" data-tagesplan-wall-lane-jobs>
                                        @foreach($laneGruppen as $gruppe)
                                            @php
                                                $gruppeFertig = $gruppe->gesamt > 0 && $gruppe->erledigt === $gruppe->gesamt;
                                            @endphp
                                            <article class="overflow-hidden rounded-2xl border border-[var(--fa-line)] bg-[var(--fa-ground)] {{ $gruppeFertig ? 'opacity-60' : '' }}" data-tagesplan-wall-gericht-gruppe>
                                                <div class="px-4 py-3 {{ $gruppe->hat_gericht ? '' : 'border-b border-[var(--fa-line)]' }}" data-tagesplan-wall-gericht>
                                                    @if($gruppe->hat_gericht)
                                                        <button type="button" wire:click="oeffneGericht(@js($gruppe->key))"
                                                                class="flex w-full items-start justify-between gap-3 text-left"
                                                                data-tagesplan-wall-gericht-open>
                                                            <div class="min-w-0">
                                                                <p class="{{ $wEyebrow }}">Gericht</p>
                                                                <h3 class="mt-0.5 whitespace-normal break-words text-xl font-bold leading-tight [@media(min-height:56rem)]:text-2xl">{{ $gruppe->gericht }}</h3>
                                                                <p class="mt-1.5 text-sm text-[var(--fa-ink-3)]">{{ $gruppe->auftrag }} · für {{ \Illuminate\Support\Carbon::parse($gruppe->liefertag)->format('d.m.') }}</p>
                                                            </div>
                                                            <div class="flex shrink-0 flex-col items-end gap-2">
                                                                <p class="text-xl font-bold tabular-nums">{{ $gruppe->erledigt }}/{{ $gruppe->gesamt }}</p>
                                                                <span class="inline-flex items-center gap-1 rounded-full bg-[var(--fa-accent-soft)] px-2.5 py-1 text-sm font-semibold text-[var(--fa-accent)]">Öffnen @svg('heroicon-m-chevron-right', 'w-4 h-4')</span>
                                                            </div>
                                                        </button>
                                                    @else
                                                        <div class="flex w-full items-start justify-between gap-3">
                                                            <div class="min-w-0">
                                                                <p class="{{ $wEyebrow }}">Arbeitsblock</p>
                                                                <h3 class="mt-0.5 whitespace-normal break-words text-xl font-bold leading-tight [@media(min-height:56rem)]:text-2xl">{{ $gruppe->gericht }}</h3>
                                                                <p class="mt-1.5 text-sm text-[var(--fa-ink-3)]">{{ $gruppe->auftrag }} · für {{ \Illuminate\Support\Carbon::parse($gruppe->liefertag)->format('d.m.') }}</p>
                                                            </div>
                                                            <div class="shrink-0 text-right">
                                                                <p class="text-xl font-bold tabular-nums">{{ $gruppe->erledigt }}/{{ $gruppe->gesamt }}</p>
                                                                <p class="text-sm text-[var(--fa-ink-3)]">erledigt</p>
                                                            </div>
                                                        </div>
                                                    @endif
                                                    @if(collect($gruppe->sicherheit['allergene'] ?? [])->isNotEmpty() || collect($gruppe->sicherheit['warnungen'] ?? [])->isNotEmpty() || collect($gruppe->sicherheit['diaet'] ?? [])->isNotEmpty())
                                                        <div class="mt-3 flex flex-wrap gap-1.5" data-tagesplan-wall-sicherheit>
                                                            @foreach(collect($gruppe->sicherheit['warnungen'] ?? [])->take(3) as $warnung)
                                                                <span class="{{ $wSicher }} {{ $tonFlaeche['warn'] }}" data-tagesplan-wall-warnung>{{ $warnung }}</span>
                                                            @endforeach
                                                            @foreach(collect($gruppe->sicherheit['allergene'] ?? [])->take(4) as $a)
                                                                <span class="{{ $wSicher }} {{ $tonFlaeche['crit'] }}" data-tagesplan-wall-allergen>{{ $a['label'] }}</span>
                                                            @endforeach
                                                            @foreach(collect($gruppe->sicherheit['diaet'] ?? [])->take(3) as $d)
                                                                <span class="{{ $wSicher }} {{ $tonFlaeche['ok'] }}" data-tagesplan-wall-diaet>{{ $d }}</span>
                                                            @endforeach
                                                        </div>
                                                    @endif
                                                </div>
                                                @if(! $gruppe->hat_gericht)
                                                <div class="space-y-2 p-3" data-tagesplan-wall-rezepte>
                                                    @foreach($gruppe->zeilen as $z)
                                                        @php
                                                            $erledigt = $z->line_status === 'done';
                                                            $laeuft = $z->auftrag_status === 'in_progress';
                                                            $rezeptTitel = $z->rezept_label ?: $z->name;
                                                            $hakenText = $erledigt ? 'Haken zurücknehmen' : ($laeuft ? 'Als erledigt abhaken' : 'Auftrag starten und als erledigt abhaken');
                                                        @endphp
                                                        <div class="flex gap-3 rounded-xl border border-[var(--fa-line)] bg-[var(--fa-surface)] p-3 {{ $erledigt ? 'opacity-55' : '' }}" wire:key="wk-{{ $z->id }}" data-tagesplan-zeile="{{ $z->id }}" data-tagesplan-wall-rezept>
                                                            <button type="button" wire:click="abhaken({{ $z->id }})"
                                                                    class="grid h-16 w-16 shrink-0 place-items-center rounded-2xl border-2 {{ $erledigt ? $wHakenFertig : ($laeuft ? $wHakenLaeuft : $wHakenOffen) }}"
                                                                    title="{{ $hakenText }}" aria-label="{{ $hakenText }}"
                                                                    data-tagesplan-abhaken>@if($erledigt)@svg('heroicon-m-check', 'w-9 h-9')@endif</button>
                                                            <button type="button" wire:click="oeffneAnleitung({{ $z->id }})" class="min-w-0 flex-1 text-left" data-tagesplan-wall-karte>
                                                                <div class="flex items-start justify-between gap-2">
                                                                    <div class="min-w-0">
                                                                        <p class="{{ $wEyebrow }}">{{ $z->is_basisrezept ? 'Basisrezept' : 'Rezept' }}</p>
                                                                        <p class="mt-0.5 whitespace-normal break-words text-xl font-bold leading-tight {{ $erledigt ? 'line-through' : '' }}">{{ $rezeptTitel }}</p>
                                                                    </div>
                                                                    @svg('heroicon-m-chevron-right', 'mt-1 w-5 h-5 shrink-0 text-[var(--fa-ink-3)]')
                                                                </div>
                                                                <p class="mt-1.5 text-base text-[var(--fa-ink-2)] tabular-nums">
                                                                    @if($z->gesamt_kg !== null){{ $kg($z->gesamt_kg) }} kg · @endif{{ $z->arbeitszeit_min !== null ? $z->arbeitszeit_min . ' min' : 'keine Zeit hinterlegt' }}
                                                                </p>
                                                                @if(collect($z->sicherheit['allergene'] ?? [])->isNotEmpty() || collect($z->sicherheit['warnungen'] ?? [])->isNotEmpty() || collect($z->sicherheit['diaet'] ?? [])->isNotEmpty())
                                                                    <div class="mt-2 flex flex-wrap gap-1.5" data-tagesplan-wall-sicherheit>
                                                                        @foreach(collect($z->sicherheit['warnungen'] ?? [])->take(3) as $warnung)
                                                                            <span class="{{ $wSicher }} {{ $tonFlaeche['warn'] }}" data-tagesplan-wall-warnung>{{ $warnung }}</span>
                                                                        @endforeach
                                                                        @foreach(collect($z->sicherheit['allergene'] ?? [])->take(4) as $a)
                                                                            <span class="{{ $wSicher }} {{ $tonFlaeche['crit'] }}" data-tagesplan-wall-allergen>{{ $a['label'] }}</span>
                                                                        @endforeach
                                                                        @foreach(collect($z->sicherheit['diaet'] ?? [])->take(3) as $d)
                                                                            <span class="{{ $wSicher }} {{ $tonFlaeche['ok'] }}" data-tagesplan-wall-diaet>{{ $d }}</span>
                                                                        @endforeach
                                                                    </div>
                                                                @endif
                                                                @if(!$laeuft && !$erledigt)<p class="mt-2 inline-flex items-center gap-1 text-sm font-semibold text-[var(--fa-accent)]">@svg('heroicon-m-play', 'w-4 h-4') Startet beim Abhaken</p>@endif
                                                                @if($z->blocked_reason)<p class="mt-2 rounded-lg {{ $tonFlaeche['crit'] }} px-2.5 py-1.5 text-sm font-medium">Blockiert: {{ $z->blocked_reason }}</p>@endif
                                                            </button>
                                                        </div>
                                                    @endforeach
                                                </div>
                                                @endif
                                            </article>
                                        @endforeach
                                        </div>
                                    </div>
                                </section>
                            @endforeach
                        </div>
                    @endif
                @endif
            </main>
        </div>

        <x-foodalchemist::modal name="wall-gericht" fullscreen dark-canvas title="Gericht" :title-name="$wallGericht->gericht ?? null" :close-via="'gerichtSchliessen'">
            <x-slot:actions>
                <button type="button" x-data @click="$wire.gerichtSchliessen(); close()"
                        class="{{ $wKnopf }}"
                        data-tagesplan-wall-gericht-zurueck>
                    @svg('heroicon-m-chevron-left', 'w-5 h-5') Zurück zum Monitor
                </button>
            </x-slot:actions>
            @if($wallGericht)
                <div class="space-y-4" data-tagesplan-wall-gericht-detail>
                    <header class="{{ $wKarte }} px-4 py-3">
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div class="min-w-0">
                                <p class="{{ $wEyebrow }}">{{ $wallGericht->auftrag }} · für {{ \Illuminate\Support\Carbon::parse($wallGericht->liefertag)->format('d.m.') }}</p>
                                <h2 class="mt-1 whitespace-normal break-words text-2xl font-bold leading-tight md:text-3xl">{{ $wallGericht->gericht }}</h2>
                            </div>
                            <div class="flex gap-2">
                                <div class="{{ $wFlaeche }} px-4 py-2 text-center">
                                    <p class="text-2xl font-bold tabular-nums">{{ $wallGericht->erledigt }}/{{ $wallGericht->gesamt }}</p>
                                    <p class="text-sm text-[var(--fa-ink-3)]">erledigt</p>
                                </div>
                                <div class="{{ $wFlaeche }} px-4 py-2 text-center">
                                    <p class="text-2xl font-bold tabular-nums">{{ $wallGericht->minuten }}</p>
                                    <p class="text-sm text-[var(--fa-ink-3)]">Minuten</p>
                                </div>
                            </div>
                        </div>
                        @if(collect($wallGericht->sicherheit['allergene'] ?? [])->isNotEmpty() || collect($wallGericht->sicherheit['warnungen'] ?? [])->isNotEmpty() || collect($wallGericht->sicherheit['diaet'] ?? [])->isNotEmpty())
                            <div class="mt-3 flex flex-wrap gap-1.5" data-tagesplan-wall-sicherheit>
                                @foreach(collect($wallGericht->sicherheit['warnungen'] ?? [])->take(4) as $warnung)
                                    <span class="{{ $wSicher }} {{ $tonFlaeche['warn'] }}" data-tagesplan-wall-warnung>{{ $warnung }}</span>
                                @endforeach
                                @foreach(collect($wallGericht->sicherheit['allergene'] ?? [])->take(6) as $a)
                                    <span class="{{ $wSicher }} {{ $tonFlaeche['crit'] }}" data-tagesplan-wall-allergen>{{ $a['label'] }}</span>
                                @endforeach
                                @foreach(collect($wallGericht->sicherheit['diaet'] ?? [])->take(4) as $d)
                                    <span class="{{ $wSicher }} {{ $tonFlaeche['ok'] }}" data-tagesplan-wall-diaet>{{ $d }}</span>
                                @endforeach
                            </div>
                        @endif
                    </header>

                    {{-- §3.2 Regeneration am Pass: das Programm je Komponente aus dem eingefrorenen
                         Auftrag. Bis 2026-09-04 stand hier nichts — die Kachel unten las die
                         Regenerations-Skalare der Standard-Darreichung, die kein Schreibpfad füllt. --}}
                    @if(collect($wallGericht->regeneration ?? [])->isNotEmpty())
                        <section class="{{ $wKarte }} p-4" data-tagesplan-wall-gericht-regeneration>
                            <h3 class="flex items-center gap-2 text-lg font-bold">@svg('heroicon-o-fire', 'w-5 h-5 text-[var(--fa-ink-3)]') Regeneration</h3>
                            <div class="mt-3 grid grid-cols-[repeat(auto-fill,minmax(min(100%,16rem),1fr))] gap-2">
                                @foreach($wallGericht->regeneration as $reg)
                                    <div class="{{ $wFlaeche }} px-3 py-2">
                                        <p class="text-base font-semibold">{{ $reg['komponente'] ?? 'Komponente' }}</p>
                                        <p class="mt-0.5 text-sm text-[var(--fa-ink-2)] tabular-nums">
                                            {{ collect([
                                                $reg['geraet'] ?? null,
                                                ($reg['temp_c'] ?? null) !== null ? $reg['temp_c'] . ' °C' : null,
                                                ($reg['duration_min'] ?? null) !== null ? $reg['duration_min'] . ' min' : null,
                                                ($reg['core_temp_c'] ?? null) !== null ? 'Kerntemperatur ' . $reg['core_temp_c'] . ' °C' : null,
                                            ])->filter()->implode(' · ') }}
                                        </p>
                                        @if($reg['note'] ?? null)
                                            <p class="mt-0.5 text-sm text-[var(--fa-ink-3)]">{{ $reg['note'] }}</p>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </section>
                    @endif

                    @if($wallGericht->anrichten || collect($wallGericht->darreichung ?? [])->isNotEmpty())
                        <section class="grid grid-cols-1 gap-3 xl:grid-cols-[minmax(0,1.4fr)_minmax(16rem,0.6fr)]" data-tagesplan-wall-gericht-service>
                            @if($wallGericht->anrichten)
                                <article class="{{ $wKarte }} p-4" data-tagesplan-wall-gericht-anrichten>
                                    <h3 class="flex items-center gap-2 text-lg font-bold">@svg('heroicon-o-hand-raised', 'w-5 h-5 text-[var(--fa-ink-3)]') Anrichten</h3>
                                    @if(collect($wallGericht->anrichten_schritte ?? [])->isNotEmpty())
                                        <ol class="mt-3 space-y-3">
                                            @foreach($wallGericht->anrichten_schritte as $s)
                                                <li class="flex gap-3">
                                                    <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-[var(--fa-ground)] text-base font-bold tabular-nums text-[var(--fa-ink-2)]">{{ $s['nr'] ?? $loop->iteration }}</span>
                                                    <div class="min-w-0">
                                                        <p class="text-lg font-semibold leading-snug">{{ $s['text'] ?? '' }}</p>
                                                        @if(collect($s['fotos'] ?? [])->isNotEmpty())
                                                            <div class="mt-2 flex flex-wrap gap-2">
                                                                @foreach($s['fotos'] as $foto)
                                                                    @if($foto['url'] ?? null)
                                                                        <img src="{{ $foto['url'] }}" alt="{{ $foto['caption'] ?? 'Anrichten' }}"
                                                                             class="h-24 w-32 rounded-lg object-cover" />
                                                                    @endif
                                                                @endforeach
                                                            </div>
                                                        @endif
                                                    </div>
                                                </li>
                                            @endforeach
                                        </ol>
                                    @else
                                        <p class="mt-2 whitespace-pre-line text-lg font-semibold leading-snug">{{ $wallGericht->anrichten }}</p>
                                    @endif
                                </article>
                            @endif
                            {{-- Spec 51: auch wenn es kein Geschirr gibt. Eine Basisrezept-Gruppe hat
                                 keine Servierform, aber sehr wohl einen Abfuell-Bedarf — an der
                                 alten Bedingung waere er wortlos verschwunden. --}}
                            @if(collect($wallGericht->darreichung ?? [])->isNotEmpty() || collect($wallGericht->behaelter ?? [])->isNotEmpty())
                                <article class="{{ $wKarte }} p-4" data-tagesplan-wall-gericht-geschirr>
                                    <h3 class="flex items-center gap-2 text-lg font-bold">@svg('heroicon-o-archive-box', 'w-5 h-5 text-[var(--fa-ink-3)]') Geschirr und Ausgabe</h3>
                                    <div class="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2 xl:grid-cols-1">
                                        {{-- Spec 51: was gepackt werden muss, steht oben — es ist die
                                             Handlung. Aggregiert ueber ALLE Zeilen der Gruppe, nicht
                                             nur die erste (Abfuellen haengt an den Produktionszeilen,
                                             Regenerieren an der Gericht-Zeile). --}}
                                        @foreach($wallGericht->behaelter ?? [] as $info)
                                            <div class="rounded-xl border border-[var(--fa-accent-line)] bg-[var(--fa-accent-soft)] px-3 py-2" data-wall-behaelter>
                                                <p class="text-sm font-semibold text-[var(--fa-accent)]">{{ $info['label'] }}</p>
                                                <p class="mt-0.5 text-base font-semibold">{{ $info['wert'] }}</p>
                                            </div>
                                        @endforeach
                                        @foreach($wallGericht->darreichung as $info)
                                            <div class="{{ $wFlaeche }} px-3 py-2">
                                                <p class="text-sm font-semibold text-[var(--fa-ink-3)]">{{ $info['label'] }}</p>
                                                <p class="mt-0.5 text-base font-semibold">{{ $info['wert'] }}</p>
                                            </div>
                                        @endforeach
                                    </div>
                                </article>
                            @endif
                        </section>
                    @endif

                    <section class="{{ $wKarte }} p-4" data-tagesplan-wall-gericht-uebersicht>
                        <div class="flex flex-wrap items-end justify-between gap-3">
                            <div>
                                <p class="{{ $wEyebrow }}">Rezeptübersicht</p>
                                <h3 class="mt-0.5 text-xl font-bold">Was für dieses Gericht erledigt werden muss</h3>
                            </div>
                            <p class="text-base font-semibold text-[var(--fa-ink-2)] tabular-nums">{{ $wallGericht->erledigt }} von {{ $wallGericht->gesamt }} erledigt</p>
                        </div>
                        <div class="mt-3 divide-y divide-[var(--fa-line)] rounded-xl border border-[var(--fa-line)] bg-[var(--fa-ground)]" data-tagesplan-wall-gericht-uebersicht-liste>
                            @foreach($wallGericht->rezept_uebersicht as $eintrag)
                                <div class="flex items-center gap-3 px-3 py-2.5 {{ $eintrag['erledigt'] ? 'opacity-60' : '' }}"
                                     data-tagesplan-wall-gericht-uebersicht-zeile>
                                    <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg border {{ $eintrag['erledigt'] ? $wHakenFertig : 'border-[var(--fa-line-strong)] bg-[var(--fa-surface)]' }}" aria-label="{{ $eintrag['erledigt'] ? 'erledigt' : 'offen' }}">@if($eintrag['erledigt'])@svg('heroicon-m-check', 'w-5 h-5')@endif</span>
                                    <div class="min-w-0 flex-1">
                                        <p class="whitespace-normal break-words text-base font-semibold leading-snug {{ $eintrag['erledigt'] ? 'line-through' : '' }}">{{ $eintrag['name'] }}</p>
                                        <p class="mt-0.5 text-sm font-medium {{ $eintrag['typ'] === 'Basisrezept' ? 'text-[var(--fa-ok)]' : 'text-[var(--fa-ink-3)]' }}">{{ $eintrag['typ'] }}</p>
                                    </div>
                                    <p class="shrink-0 text-right text-sm font-semibold text-[var(--fa-ink-2)] tabular-nums">{{ collect([$eintrag['menge'], $eintrag['zeit']])->filter()->implode(' · ') }}</p>
                                </div>
                            @endforeach
                        </div>
                    </section>

                    <section class="min-h-0" data-tagesplan-wall-gericht-arbeitslane>
                        <div class="mb-3 flex flex-wrap items-end justify-between gap-3">
                            <div>
                                <p class="{{ $wEyebrow }}">Abarbeiten</p>
                                <h3 class="mt-0.5 text-xl font-bold">Rezepte abhaken</h3>
                            </div>
                            <p class="text-sm text-[var(--fa-ink-3)]">Karte antippen öffnet die Anleitung, der Haken erledigt direkt.</p>
                        </div>
                    {{-- Kein eigener Scrollkasten mehr: auf dem Laptop blieben davon nur ein paar Zentimeter.
                         Der Editor-Körper scrollt als Ganzes. --}}
                    <div class="grid grid-cols-[repeat(auto-fill,minmax(min(100%,20rem),1fr))] gap-3" data-tagesplan-wall-gericht-rezepte>
                        @foreach($wallGericht->zeilen as $z)
                            @php
                                $erledigt = $z->line_status === 'done';
                                $laeuft = $z->auftrag_status === 'in_progress';
                                $rezeptTitel = $z->rezept_label ?: $z->name;
                                $hakenText = $erledigt ? 'Haken zurücknehmen' : ($laeuft ? 'Als erledigt abhaken' : 'Auftrag starten und als erledigt abhaken');
                            @endphp
                            <article class="flex gap-3 {{ $wKarte }} p-4 text-left transition-colors hover:border-[var(--fa-accent)] {{ $erledigt ? 'opacity-60' : '' }}" data-tagesplan-wall-gericht-detail-card>
                                <button type="button" wire:click="abhaken({{ $z->id }})"
                                        class="grid h-16 w-16 shrink-0 place-items-center rounded-2xl border-2 {{ $erledigt ? $wHakenFertig : ($laeuft ? $wHakenLaeuft : $wHakenOffen) }}"
                                        title="{{ $hakenText }}" aria-label="{{ $hakenText }}"
                                        data-tagesplan-wall-gericht-abhaken>@if($erledigt)@svg('heroicon-m-check', 'w-9 h-9')@endif</button>
                                <button type="button" wire:click="oeffneAnleitung({{ $z->id }})" class="min-w-0 flex-1 text-left" data-tagesplan-wall-gericht-anleitung>
                                    <div class="flex items-start justify-between gap-2">
                                        <div class="min-w-0">
                                            <p class="{{ $wEyebrow }}">{{ $z->is_basisrezept ? 'Basisrezept' : 'Rezept' }}</p>
                                            <p class="mt-0.5 whitespace-normal break-words text-xl font-bold leading-tight {{ $erledigt ? 'line-through' : '' }}" data-tagesplan-wall-gericht-detail-card-title>{{ $rezeptTitel }}</p>
                                        </div>
                                        @svg('heroicon-m-chevron-right', 'mt-1 w-5 h-5 shrink-0 text-[var(--fa-ink-3)]')
                                    </div>
                                    <p class="mt-2 text-base text-[var(--fa-ink-2)] tabular-nums">
                                        @if($z->gesamt_kg !== null){{ $kg($z->gesamt_kg) }} kg · @endif{{ $z->arbeitszeit_min !== null ? $z->arbeitszeit_min . ' min' : 'keine Zeit hinterlegt' }}
                                    </p>
                                    <p class="mt-1 text-sm text-[var(--fa-ink-3)]">für {{ $z->auftrag }}</p>
                                    @if(collect($z->sicherheit['allergene'] ?? [])->isNotEmpty() || collect($z->sicherheit['warnungen'] ?? [])->isNotEmpty() || collect($z->sicherheit['diaet'] ?? [])->isNotEmpty())
                                        <div class="mt-3 flex flex-wrap gap-1.5" data-tagesplan-wall-sicherheit>
                                            @foreach(collect($z->sicherheit['warnungen'] ?? [])->take(3) as $warnung)
                                                <span class="{{ $wSicher }} {{ $tonFlaeche['warn'] }}" data-tagesplan-wall-warnung>{{ $warnung }}</span>
                                            @endforeach
                                            @foreach(collect($z->sicherheit['allergene'] ?? [])->take(4) as $a)
                                                <span class="{{ $wSicher }} {{ $tonFlaeche['crit'] }}" data-tagesplan-wall-allergen>{{ $a['label'] }}</span>
                                            @endforeach
                                            @foreach(collect($z->sicherheit['diaet'] ?? [])->take(3) as $d)
                                                <span class="{{ $wSicher }} {{ $tonFlaeche['ok'] }}" data-tagesplan-wall-diaet>{{ $d }}</span>
                                            @endforeach
                                        </div>
                                    @endif
                                </button>
                            </article>
                        @endforeach
                    </div>
                    </section>
                </div>
            @else
                <div class="grid min-h-[60vh] place-items-center text-center" data-tagesplan-wall-gericht-leer>
                    <div>
                        @svg('heroicon-o-question-mark-circle', 'mx-auto w-14 h-14 text-[var(--fa-ink-3)]')
                        <p class="mt-3 text-3xl font-bold">Gericht nicht gefunden.</p>
                        <p class="mt-2 text-lg text-[var(--fa-ink-3)]">Der Arbeitsblock gehört nicht mehr zum gezeigten Tag.</p>
                    </div>
                </div>
            @endif
        </x-foodalchemist::modal>

        <x-foodalchemist::modal name="wall-anleitung" fullscreen dark-canvas title="Anleitung" :title-name="$anleitung['name'] ?? null" :close-via="'anleitungSchliessen'">
            @if($anleitung && (collect($anleitung['sicherheit']['allergene'] ?? [])->isNotEmpty() || collect($anleitung['sicherheit']['warnungen'] ?? [])->isNotEmpty() || collect($anleitung['sicherheit']['diaet'] ?? [])->isNotEmpty()))
                <x-slot:titleExtra>
                    <span class="flex min-w-0 flex-wrap items-center gap-1.5" data-tagesplan-wall-sicherheitsblock>
                        @foreach(collect($anleitung['sicherheit']['warnungen'] ?? [])->take(2) as $warnung)
                            <span class="{{ $wSicher }} {{ $tonFlaeche['warn'] }}" data-tagesplan-wall-warnung>{{ $warnung }}</span>
                        @endforeach
                        @foreach(collect($anleitung['sicherheit']['allergene'] ?? [])->take(5) as $a)
                            <span class="{{ $wSicher }} {{ $tonFlaeche['crit'] }}" data-tagesplan-wall-allergen>{{ $a['label'] }}{{ ($a['wert'] ?? '') === 'spuren' ? ' · Spuren' : '' }}</span>
                        @endforeach
                        @foreach(collect($anleitung['sicherheit']['diaet'] ?? [])->take(3) as $d)
                            <span class="{{ $wSicher }} {{ $tonFlaeche['ok'] }}" data-tagesplan-wall-diaet>{{ $d }}</span>
                        @endforeach
                    </span>
                </x-slot:titleExtra>
            @endif
            <x-slot:actions>
                <button type="button" x-data @click="$wire.anleitungSchliessen(); close()"
                        class="{{ $wKnopf }}"
                        data-tagesplan-wall-anleitung-zurueck>
                    @svg('heroicon-m-chevron-left', 'w-5 h-5') Zurück zum Monitor
                </button>
            </x-slot:actions>
            @if($anleitung)
                @php
                    $wallStepKeys = array_keys($anleitung['arbeitsschritte'] ?? []);
                    $wallErledigteSteps = collect($anleitung['step_erledigt'] ?? [])->map(fn ($i) => (int) $i)->intersect($wallStepKeys);
                    $wallAlleStepsErledigt = $wallStepKeys !== [] && $wallErledigteSteps->count() === count($wallStepKeys);
                    $wallLineErledigt = ($anleitung['line_status'] ?? null) === 'done';
                    $wallLineLaeuft = ($anleitung['line_status'] ?? null) === 'in_progress';
                    $wallGesamtKg = $anleitung['gesamt_kg'] ?? null;
                    $wallGesamtKgText = $wallGesamtKg !== null ? $kg($wallGesamtKg) . ' kg' : null;
                    $wallArbeitszeit = $anleitung['arbeitszeit_min'] ?? null;
                    $wallStandzeit = $anleitung['standzeit_min'] ?? null;
                    $wallDurchlaufzeit = $anleitung['durchlaufzeit_min'] ?? $wallArbeitszeit;
                    $wallStartedAt = $anleitung['started_at'] ?? null;
                    $fbAchsen = [
                        'score' => 'Wie lief es?',
                        'machbarkeit' => 'Machbarkeit',
                        'aufwand' => 'Aufwand',
                        'geschmack' => 'Geschmack',
                    ];
                    $fbGruende = (array) config('foodalchemist.feedback_gruende', []);
                    $fbGewaehlt = (array) ($feedbackForm['gruende'] ?? []);
                @endphp
                {{-- Linke Spalte scrollt auf niedrigen Bildschirmen selbst — sonst lag das Feedback unten
                     außer Reichweite, solange die Schritte rechts länger waren. --}}
                <div class="grid gap-3 xl:grid-cols-[minmax(17rem,22rem)_minmax(0,1fr)] 2xl:grid-cols-[minmax(18rem,24rem)_minmax(0,1fr)]" data-tagesplan-wall-anleitung>
                    <aside class="space-y-3 xl:sticky xl:top-0 xl:max-h-[calc(100dvh-9rem)] xl:self-start xl:overflow-y-auto xl:overscroll-contain xl:pr-1">
                        <div class="{{ $wKarte }} px-3 py-2">
                            <p class="{{ $wEyebrow }}">Auftrag</p>
                            <p class="mt-0.5 text-base">für {{ $anleitung['auftrag'] }}</p>
                        </div>

                        <section class="{{ $wKarte }} px-3 py-3" data-tagesplan-wall-timer>
                            <div class="grid grid-cols-2 gap-2">
                                @if($wallGesamtKgText !== null)
                                    <div data-tagesplan-wall-gesamtmenge>
                                        <p class="{{ $wEyebrow }}">Gesamtmenge</p>
                                        <p class="mt-0.5 text-lg font-semibold tabular-nums">{{ $wallGesamtKgText }}</p>
                                    </div>
                                @endif
                                <div>
                                    <p class="{{ $wEyebrow }}">Zeit</p>
                                    @if($wallArbeitszeit !== null || $wallStandzeit !== null)
                                        <p class="mt-0.5 text-lg font-semibold tabular-nums">{{ $wallDurchlaufzeit }} min<span class="text-sm font-normal text-[var(--fa-ink-3)]"> gesamt</span></p>
                                        <p class="text-sm tabular-nums text-[var(--fa-ink-3)]">{{ (int) ($wallArbeitszeit ?? 0) }} min aktiv @if($wallStandzeit)· {{ $wallStandzeit }} min Garzeit @endif</p>
                                    @else
                                        <p class="mt-0.5"><x-fa::badge tone="warn">Keine Zeit hinterlegt</x-fa::badge></p>
                                    @endif
                                </div>
                            </div>
                            @if($wallLineLaeuft && $wallStartedAt !== null)
                                <div class="mt-3 rounded-xl {{ $tonFlaeche['info'] }} px-3 py-2"
                                     x-data="{ started: Date.parse(@js($wallStartedAt)), total: {{ (int) ($wallDurchlaufzeit ?? 0) }}, now: Date.now(), tick: null, init(){ this.tick = setInterval(() => this.now = Date.now(), 1000) }, elapsed(){ return Math.max(0, Math.floor((this.now - this.started) / 60000)) }, remaining(){ return this.total > 0 ? Math.max(0, this.total - this.elapsed()) : null } }"
                                     data-tagesplan-wall-laufzeit>
                                    <p class="text-sm font-semibold">Läuft</p>
                                    <p class="mt-0.5 text-base font-semibold tabular-nums text-[var(--fa-ink)]">
                                        <span x-text="elapsed()"></span> min gelaufen
                                        <template x-if="remaining() !== null"><span> · noch <span x-text="remaining()"></span> min</span></template>
                                    </p>
                                </div>
                            @elseif(! $wallLineErledigt)
                                <button type="button" wire:click="anleitungStarten"
                                        class="mt-3 flex h-12 w-full items-center justify-between rounded-xl bg-[var(--fa-accent)] px-4 text-left text-base font-semibold text-[var(--fa-on-accent)] transition-colors hover:bg-[var(--fa-accent-hover)]"
                                        data-tagesplan-wall-start>
                                    <span>Zubereitung starten</span>
                                    @svg('heroicon-m-play', 'w-6 h-6')
                                </button>
                            @else
                                <div class="mt-3 flex items-center gap-2 rounded-xl {{ $tonFlaeche['ok'] }} px-3 py-2 text-base font-semibold">@svg('heroicon-m-check-circle', 'w-5 h-5') Erledigt</div>
                            @endif
                            @if($wallStepKeys !== [])
                                <button type="button" wire:click="anleitungAlleStepsUmschalten"
                                        class="mt-2 flex h-12 w-full items-center justify-between rounded-xl border px-4 text-left text-base font-semibold transition-colors {{ $wallAlleStepsErledigt || $wallLineErledigt ? 'border-transparent ' . $tonFlaeche['ok'] : 'border-[var(--fa-line-strong)] bg-[var(--fa-surface)] text-[var(--fa-ink)] hover:bg-[var(--fa-hover)]' }}"
                                        data-tagesplan-wall-anleitung-alle-steps>
                                    <span>{{ $wallAlleStepsErledigt || $wallLineErledigt ? 'Alle Schritte erledigt' : 'Alle Schritte abhaken' }}</span>
                                    @svg($wallAlleStepsErledigt || $wallLineErledigt ? 'heroicon-m-check-circle' : 'heroicon-o-check-circle', 'w-6 h-6')
                                </button>
                                <p class="mt-1.5 text-sm text-[var(--fa-ink-3)] tabular-nums" data-tagesplan-wall-step-fortschritt>{{ $wallErledigteSteps->count() }} von {{ count($wallStepKeys) }} Schritten erledigt</p>
                            @endif
                            {{-- Spec 76: Etikett direkt aus der Küche — vorbelegt, ohne Formular --}}
                            @if(! empty($anleitung['etikett']))
                                <a href="{{ $anleitung['etikett']['url'] }}" target="_blank"
                                   class="mt-2 flex h-12 w-full items-center justify-between rounded-xl border border-[var(--fa-line-strong)] bg-[var(--fa-surface)] px-4 text-left text-base font-semibold text-[var(--fa-ink)] transition-colors hover:bg-[var(--fa-hover)]"
                                   data-tagesplan-wall-etikett>
                                    <span>Etikett drucken ({{ $anleitung['etikett']['anzahl'] }}×)</span>
                                    @svg('heroicon-o-tag', 'w-6 h-6')
                                </a>
                            @endif
                        </section>

                        @if(!empty($anleitung['sub_rezepte']))
                            <section class="{{ $wKarte }} px-3 py-3" data-tagesplan-wall-subrezepte>
                                <h3 class="text-lg font-bold">Enthaltene Rezepte</h3>
                                <div class="mt-2 space-y-2">
                                    @foreach($anleitung['sub_rezepte'] as $sub)
                                        @if($sub['line_id'] !== null)
                                            <button type="button" wire:click="oeffneAnleitung({{ $sub['line_id'] }})"
                                                    class="flex w-full items-start gap-3 rounded-xl border border-[var(--fa-line)] bg-[var(--fa-ground)] px-3 py-2 text-left transition-colors hover:border-[var(--fa-accent)]"
                                                    data-tagesplan-wall-subrezept>
                                                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg border {{ $sub['erledigt'] ? $wHakenFertig : 'border-[var(--fa-line-strong)] bg-[var(--fa-surface)] text-[var(--fa-ink-3)]' }}">@svg($sub['erledigt'] ? 'heroicon-m-check' : 'heroicon-m-chevron-right', 'w-5 h-5')</span>
                                                <span class="min-w-0 flex-1">
                                                    <span class="block whitespace-normal break-words text-base font-semibold leading-snug">{{ $sub['name'] }}</span>
                                                    <span class="mt-0.5 block text-sm font-medium text-[var(--fa-ok)]">{{ $sub['typ'] }}</span>
                                                </span>
                                                <span class="shrink-0 text-right text-sm font-semibold text-[var(--fa-ink-3)] tabular-nums">{{ collect([$sub['menge'], $sub['zeit']])->filter()->implode(' · ') }}</span>
                                            </button>
                                        @else
                                            <div class="flex items-start gap-3 rounded-xl border border-dashed border-[var(--fa-line-strong)] px-3 py-2" data-tagesplan-wall-subrezept-fehlt>
                                                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg {{ $tonFlaeche['warn'] }}">@svg('heroicon-m-exclamation-triangle', 'w-5 h-5')</span>
                                                <span class="min-w-0 flex-1">
                                                    <span class="block whitespace-normal break-words text-base font-semibold leading-snug">{{ $sub['name'] }}</span>
                                                    <span class="mt-0.5 block text-sm text-[var(--fa-warn)]">Für diesen Tag ist keine Produktion dafür geplant.</span>
                                                </span>
                                            </div>
                                        @endif
                                    @endforeach
                                </div>
                            </section>
                        @endif

                        @if(!empty($anleitung['zutaten']))
                            <section class="{{ $wKarte }} px-3 py-3">
                                <h3 class="text-lg font-bold">Zutaten</h3>
                                <div class="mt-1.5 divide-y divide-[var(--fa-line)]" data-tagesplan-wall-zutatenliste>
                                    @foreach($anleitung['zutaten'] as $zt)
                                        <div class="grid grid-cols-[minmax(0,1fr)_auto] items-start gap-3 py-2 text-base" data-tagesplan-wall-zutat>
                                            <span class="min-w-0 whitespace-normal leading-snug">{{ $zt['name'] ?? ($zt['bezeichnung'] ?? 'Zutat ohne Namen') }}</span>
                                            <span class="shrink-0 text-right font-semibold tabular-nums">{{ $zt['menge'] ?? ($zt['quantity'] ?? '') }} {{ $zt['einheit'] ?? ($zt['unit'] ?? '') }}</span>
                                        </div>
                                    @endforeach
                                </div>
                            </section>
                        @endif

                        @if(!empty($anleitung['behaelter']))
                            <section class="{{ $wKarte }} px-3 py-3" data-tagesplan-wall-behaelter>
                                <h3 class="text-lg font-bold">Behälter</h3>
                                <div class="mt-1.5 divide-y divide-[var(--fa-line)]">
                                    @foreach($anleitung['behaelter'] as $bh)
                                        <div class="py-2 text-base" data-tagesplan-wall-behaelter-item>
                                            <div class="grid grid-cols-[minmax(0,1fr)_auto] items-start gap-3">
                                                <span class="min-w-0 whitespace-normal leading-snug text-[var(--fa-ink-2)]">{{ $bh['zweck'] }}</span>
                                                @if($bh['wert'])
                                                    <span class="shrink-0 text-right font-semibold tabular-nums">{{ $bh['wert'] }}</span>
                                                @else
                                                    <x-fa::badge tone="warn">fehlt</x-fa::badge>
                                                @endif
                                            </div>
                                            @if($bh['zusatz'])
                                                <p class="mt-0.5 text-sm text-[var(--fa-ink-3)]" data-tagesplan-wall-behaelter-alt>{{ $bh['zusatz'] }}</p>
                                            @endif
                                            @if($bh['hinweis'])
                                                <p class="mt-0.5 text-sm {{ $bh['wert'] ? 'text-[var(--fa-ink-3)]' : 'text-[var(--fa-warn)]' }}">{{ $bh['hinweis'] }}</p>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            </section>
                        @endif

                        @if(!empty($anleitung['equipment']))
                            <section class="{{ $wKarte }} px-3 py-3" data-tagesplan-wall-equipment>
                                <h3 class="text-lg font-bold">Geräte</h3>
                                <div class="mt-1.5 divide-y divide-[var(--fa-line)]">
                                    @foreach($anleitung['equipment'] as $eq)
                                        <div class="py-2 text-base" data-tagesplan-wall-equipment-item>
                                            <div class="flex items-start justify-between gap-3">
                                                <span class="min-w-0 whitespace-normal leading-snug">{{ $eq['name'] ?? 'Gerät' }}</span>
                                                @if($eq['gruppe'] ?? null)<span class="shrink-0 text-right text-sm text-[var(--fa-ink-3)]">{{ $eq['gruppe'] }}</span>@endif
                                            </div>
                                            @if($eq['notiz'] ?? null)<p class="mt-0.5 text-sm text-[var(--fa-ink-3)]">{{ $eq['notiz'] }}</p>@endif
                                        </div>
                                    @endforeach
                                </div>
                            </section>
                        @endif

                        {{-- Am Ende, wo es hingehoert: bewertet wird, was gekocht wurde. Landet ueber
                             FeedbackService in derselben Ablage wie der Feedback-Tab des Rezepts. --}}
                        <section class="{{ $wKarte }} px-3 py-3" data-tagesplan-wall-feedback>
                            <h3 class="text-lg font-bold">Feedback zum Rezept</h3>
                            @if($feedbackGespeichert)
                                <p class="mt-1 inline-flex items-center gap-1 text-sm font-medium text-[var(--fa-ok)]" data-tagesplan-wall-feedback-ok>@svg('heroicon-m-check', 'w-4 h-4') Gespeichert, steht jetzt am Rezept.</p>
                            @else
                                <p class="mt-0.5 text-sm text-[var(--fa-ink-3)]">Die Küche, die es kocht, ist die ehrlichste Quelle.</p>
                            @endif

                            <div class="mt-3 space-y-3">
                                @foreach($fbAchsen as $fbKey => $fbLabel)
                                    <div wire:key="fb-{{ $fbKey }}">
                                        <span class="block text-sm text-[var(--fa-ink-2)]">{{ $fbLabel }}</span>
                                        <div class="mt-1 flex gap-1.5" role="group" aria-label="{{ $fbLabel }}" data-tagesplan-wall-feedback-achse="{{ $fbKey }}">
                                            @for($fbN = 1; $fbN <= 5; $fbN++)
                                                @php
                                                    $fbAktiv = (int) ($feedbackForm[$fbKey] ?? 0) === $fbN;
                                                @endphp
                                                <button type="button"
                                                        wire:click="feedbackSetzen('{{ $fbKey }}', {{ $fbN }})"
                                                        aria-pressed="{{ $fbAktiv ? 'true' : 'false' }}"
                                                        class="h-11 min-w-11 flex-1 rounded-lg border text-base font-bold tabular-nums transition-colors {{ $fbAktiv ? 'border-[var(--fa-accent)] bg-[var(--fa-accent)] text-[var(--fa-on-accent)]' : 'border-[var(--fa-line)] bg-[var(--fa-ground)] text-[var(--fa-ink-2)] hover:text-[var(--fa-ink)]' }}"
                                                        @if($fbAktiv) data-tagesplan-wall-feedback-aktiv @endif>{{ $fbN }}</button>
                                            @endfor
                                        </div>
                                    </div>
                                @endforeach

                                {{-- Antippbare Gruende: der haeufige Fall wird zaehlbar, ohne Tastatur. --}}
                                @if($fbGruende !== [])
                                    <div>
                                        <span class="block text-sm text-[var(--fa-ink-2)]">Was war los? Mehrfachauswahl möglich.</span>
                                        <div class="mt-1 flex flex-wrap gap-1.5" data-tagesplan-wall-feedback-gruende>
                                            @foreach($fbGruende as $fbSlug => $fbText)
                                                @php
                                                    $fbAn = in_array($fbSlug, $fbGewaehlt, true);
                                                @endphp
                                                <button type="button" wire:key="fbg-{{ $fbSlug }}"
                                                        wire:click="feedbackGrundUmschalten('{{ $fbSlug }}')"
                                                        aria-pressed="{{ $fbAn ? 'true' : 'false' }}"
                                                        class="h-10 rounded-lg border px-3 text-sm font-medium transition-colors {{ $fbAn ? 'border-[var(--fa-accent)] bg-[var(--fa-accent-soft)] text-[var(--fa-accent)]' : 'border-[var(--fa-line)] bg-[var(--fa-ground)] text-[var(--fa-ink-2)] hover:text-[var(--fa-ink)]' }}"
                                                        @if($fbAn) data-tagesplan-wall-feedback-grund-aktiv="{{ $fbSlug }}" @endif>{{ $fbText }}</button>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif

                                <div>
                                    <div class="flex items-center justify-between gap-2">
                                        <label for="tp-wall-feedback-kommentar" class="text-sm text-[var(--fa-ink-2)]">Sonst noch etwas?</label>
                                        {{-- Derselbe Diktat-Knopf wie Planungsstelle und Step-Editor: am Pass
                                             bedienen nasse Hände keine Bildschirmtastatur. --}}
                                        @include('foodalchemist::livewire.recipes.partials.diktat-knopf', [
                                            'audio' => 'feedbackAudio',
                                            'marker' => 'wall-feedback',
                                            'label' => 'Sprechen',
                                            'btnAi' => 'inline-flex items-center gap-1.5 h-10 rounded-lg border border-[var(--fa-line)] bg-[var(--fa-ground)] px-3 text-sm font-medium text-[var(--fa-ink-2)] hover:text-[var(--fa-ink)]',
                                        ])
                                    </div>
                                    <textarea id="tp-wall-feedback-kommentar" wire:model="feedbackForm.comment" rows="2"
                                              class="fa-control mt-1 w-full py-2 text-base leading-relaxed"
                                              placeholder="Sprechen oder tippen, was keine der Kacheln trifft …"
                                              data-tagesplan-wall-feedback-kommentar></textarea>
                                </div>

                                <button type="button" wire:click="feedbackSpeichern"
                                        class="h-11 w-full rounded-lg bg-[var(--fa-accent)] text-base font-semibold text-[var(--fa-on-accent)] transition-colors hover:bg-[var(--fa-accent-hover)]"
                                        data-tagesplan-wall-feedback-speichern>Feedback speichern</button>
                            </div>
                        </section>

                        <div class="h-20 shrink-0" aria-hidden="true"></div>{{-- Auslauf: letzter Knopf (Feedback speichern) bleibt über dem Rand erreichbar, auch im Vollbild --}}
                    </aside>

                    <section class="{{ $wKarte }} p-3 md:p-4" data-tagesplan-wall-media>
                        <h3 class="text-lg font-bold">Schritte und Medien</h3>
                        @if(!empty($anleitung['schritte']))
                            <div class="mt-3 space-y-2">
                                @foreach($anleitung['schritte'] as $s)
                                    @php
                                        $stepIndex = (int) $loop->index;
                                        $stepErledigt = in_array($stepIndex, $anleitung['step_erledigt'] ?? [], true) || $wallLineErledigt;
                                        $fotos = collect($s['fotos'] ?? $s['photos'] ?? [])->filter(fn ($f) => ($f['url'] ?? $f['src'] ?? null));
                                        $medien = collect($s['medien'] ?? $s['media'] ?? [])->filter(fn ($m) => ($m['url'] ?? $m['src'] ?? null));
                                    @endphp
                                    <article class="rounded-xl border border-[var(--fa-line)] bg-[var(--fa-ground)] px-3 py-2.5 {{ $stepErledigt ? 'opacity-70' : '' }}" data-tagesplan-wall-schritt>
                                        <div class="flex gap-3">
                                            <button type="button" wire:click="anleitungStepUmschalten({{ $stepIndex }})"
                                                    class="grid h-12 w-12 shrink-0 place-items-center rounded-xl border-2 text-xl font-bold tabular-nums {{ $stepErledigt ? $wHakenFertig : $wHakenOffen }}"
                                                    title="{{ $stepErledigt ? 'Schritt zurücknehmen' : 'Schritt als erledigt abhaken' }}"
                                                    aria-label="{{ $stepErledigt ? 'Schritt zurücknehmen' : 'Schritt als erledigt abhaken' }}"
                                                    data-tagesplan-wall-step-abhaken>@if($stepErledigt)@svg('heroicon-m-check', 'w-7 h-7')@else{{ $s['nr'] ?? $loop->iteration }}@endif</button>
                                            <div class="min-w-0 flex-1">
                                                @if($s['phase'] ?? null)<p class="{{ $wEyebrow }}">{{ $s['phase'] }}</p>@endif
                                                <p class="text-lg leading-snug {{ $stepErledigt ? 'line-through' : '' }}">{{ $s['text'] ?? '' }}</p>
                                            </div>
                                        </div>
                                        @if($fotos->isNotEmpty())
                                            <div class="mt-4 grid grid-cols-[repeat(auto-fill,minmax(min(100%,16rem),1fr))] gap-3" data-tagesplan-wall-bilder>
                                                @foreach($fotos as $f)
                                                    @php
                                                        $src = $f['url'] ?? $f['src'] ?? null;
                                                    @endphp
                                                    <figure class="overflow-hidden rounded-xl border border-[var(--fa-line)] bg-[var(--fa-surface)]">
                                                        <img src="{{ $src }}" alt="{{ $f['caption'] ?? ('Schritt ' . ($s['nr'] ?? $loop->parent->iteration)) }}" class="h-56 w-full object-cover [@media(min-height:56rem)]:h-64" loading="lazy" />
                                                        @if($f['caption'] ?? null)<figcaption class="px-3 py-2 text-sm text-[var(--fa-ink-2)]">{{ $f['caption'] }}</figcaption>@endif
                                                    </figure>
                                                @endforeach
                                            </div>
                                        @endif
                                        @if(($s['video'] ?? null) || ($s['audio'] ?? null) || $medien->isNotEmpty())
                                            <div class="mt-4 space-y-3" data-tagesplan-wall-player>
                                                @if($s['video'] ?? null)<video src="{{ $s['video'] }}" controls playsinline class="max-h-[60vh] w-full rounded-xl bg-[var(--fa-rail)]"></video>@endif
                                                @if($s['audio'] ?? null)<audio src="{{ $s['audio'] }}" controls class="w-full"></audio>@endif
                                                @foreach($medien as $m)
                                                    @php
                                                        $src = $m['url'] ?? $m['src'] ?? null;
                                                        $typ = $m['type'] ?? $m['typ'] ?? '';
                                                    @endphp
                                                    @if(str_contains((string) $typ, 'video'))
                                                        <video src="{{ $src }}" controls playsinline class="max-h-[60vh] w-full rounded-xl bg-[var(--fa-rail)]"></video>
                                                    @elseif(str_contains((string) $typ, 'audio'))
                                                        <audio src="{{ $src }}" controls class="w-full"></audio>
                                                    @elseif($src)
                                                        <a href="{{ $src }}" target="_blank" class="{{ $wKnopf }}">@svg('heroicon-m-arrow-top-right-on-square', 'w-5 h-5') Medium öffnen</a>
                                                    @endif
                                                @endforeach
                                            </div>
                                        @endif
                                    </article>
                                @endforeach
                            </div>
                        @elseif(!empty($anleitung['zubereitung']))
                            @php
                                $fallbackZeilen = collect(preg_split('/\R+/', (string) $anleitung['zubereitung']))
                                    ->map(fn ($line) => trim($line))
                                    ->filter()
                                    ->values();
                                $fallbackStepIndex = -1;
                            @endphp
                            <div class="mt-3 space-y-2" data-tagesplan-wall-fallback-schritte>
                                @foreach($fallbackZeilen as $line)
                                    @php
                                        $istHeading = str_starts_with($line, '##');
                                        $text = trim(preg_replace('/^#+\s*/', '', $line));
                                    @endphp
                                    @if($istHeading)
                                        <h4 class="pt-1 text-lg font-bold text-[var(--fa-ink-2)]">{{ $text }}</h4>
                                    @else
                                        @php
                                            $fallbackStepIndex++;
                                            $stepErledigt = in_array($fallbackStepIndex, $anleitung['step_erledigt'] ?? [], true) || $wallLineErledigt;
                                        @endphp
                                        <div class="flex gap-3 rounded-xl border border-[var(--fa-line)] bg-[var(--fa-ground)] px-3 py-2 text-lg leading-snug {{ $stepErledigt ? 'opacity-70' : '' }}" data-tagesplan-wall-fallback-zeile>
                                            <button type="button" wire:click="anleitungStepUmschalten({{ $fallbackStepIndex }})"
                                                    class="grid h-11 w-11 shrink-0 place-items-center rounded-xl border-2 text-xl font-bold tabular-nums {{ $stepErledigt ? $wHakenFertig : $wHakenOffen }}"
                                                    title="{{ $stepErledigt ? 'Schritt zurücknehmen' : 'Schritt als erledigt abhaken' }}"
                                                    aria-label="{{ $stepErledigt ? 'Schritt zurücknehmen' : 'Schritt als erledigt abhaken' }}"
                                                    data-tagesplan-wall-step-abhaken>@if($stepErledigt)@svg('heroicon-m-check', 'w-7 h-7')@else{{ $fallbackStepIndex + 1 }}@endif</button>
                                            <span class="min-w-0 flex-1 {{ $stepErledigt ? 'line-through' : '' }}">{{ $text }}</span>
                                        </div>
                                    @endif
                                @endforeach
                            </div>
                        @else
                            <div class="mt-4 rounded-xl border border-dashed border-[var(--fa-line-strong)] p-8 text-center" data-tagesplan-wall-anleitung-leer>
                                @svg('heroicon-o-document-text', 'mx-auto w-12 h-12 text-[var(--fa-ink-3)]')
                                <p class="mt-3 text-2xl font-bold">Keine Anleitung hinterlegt.</p>
                                <p class="mt-2 text-base text-[var(--fa-ink-3)]">Für dieses Rezept fehlen noch Zutaten, Schritte, Bilder oder Medien.</p>
                            </div>
                        @endif
                    </section>
                </div>
            @else
                <div class="grid min-h-[60vh] place-items-center text-center" data-tagesplan-wall-anleitung-leer>
                    <div>
                        @svg('heroicon-o-question-mark-circle', 'mx-auto w-14 h-14 text-[var(--fa-ink-3)]')
                        <p class="mt-3 text-3xl font-bold">Keine Anleitung gefunden.</p>
                        <p class="mt-2 text-lg text-[var(--fa-ink-3)]">Die Aufgabe gehört nicht mehr zum gezeigten Tag oder wurde gerade neu eingeplant.</p>
                    </div>
                </div>
            @endif
        </x-foodalchemist::modal>
    </div>
@else
<x-ui-page>
    <x-slot:navbar>
        <x-foodalchemist::shell.page-navbar :title="$istWall ? 'Küchenmonitor' : 'Tagesplanung'" icon="heroicon-o-calendar-days" />
    </x-slot:navbar>

    @php
        $blattUrl = route('foodalchemist.produktion.tagesplan.blatt', array_filter(['von' => $von, 'tage' => $tage, 'posten' => $postenFilter, 'ansicht' => $ansicht]));
    @endphp

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Food Alchemist', 'href' => route('foodalchemist.dashboard'), 'icon' => 'cube'],
            ['label' => 'Produktion', 'href' => route('foodalchemist.produktion.index')],
            ['label' => $istWall ? 'Küchenmonitor' : 'Tagesplanung'],
        ]">
            <div class="flex flex-wrap items-center gap-2">
                <x-fa::button size="sm" variant="secondary" icon="heroicon-o-tv" :href="route('foodalchemist.produktion.wandmonitor', ['von' => $von, 'tage' => 1])"
                              wire:navigate data-tagesplan-wall-toggle>Küchenmonitor öffnen</x-fa::button>
                <div class="relative" x-data="faMenu()" x-on:keydown.escape="offen = false" x-on:click.outside="offen = false">
                    <x-fa::icon-button size="sm" icon="heroicon-m-ellipsis-horizontal" label="Weitere Aktionen" x-on:click="toggle($event)" aria-haspopup="menu" x-bind:aria-expanded="offen" />
                    <div class="hidden w-60 fa-surface shadow-lg py-1" x-bind:class="{ hidden: ! offen }" x-bind:style="pos" role="menu">
                        <a href="{{ $blattUrl }}" target="_blank" role="menuitem" x-on:click="offen = false" class="{{ $menuePunkt }}" data-tagesplan-drucken>
                            @svg('heroicon-o-printer', 'w-4 h-4 text-[var(--fa-ink-3)]') Produktionsblatt drucken
                        </a>
                        <a href="{{ $blattUrl . (str_contains($blattUrl, '?') ? '&' : '?') . 'pdf=1' }}" role="menuitem" x-on:click="offen = false" class="{{ $menuePunkt }}" data-tagesplan-pdf>
                            @svg('heroicon-o-arrow-down-tray', 'w-4 h-4 text-[var(--fa-ink-3)]') Produktionsblatt als PDF
                        </a>
                    </div>
                </div>
                @if(! $istWall)
                    <x-fa::button size="sm" variant="primary" icon="heroicon-o-pencil-square"
                                  :href="route('foodalchemist.produktion.tagesplan.editor', ['von' => $von, 'bis' => $bis, 'tage' => $tage, 'ansicht' => $ansicht])"
                                  wire:navigate x-data x-on:click="$dispatch('modal.open', { name: 'tagesplan-editor' })"
                                  data-tagesplan-editor-link>Tagesplan bearbeiten</x-fa::button>
                @endif
            </div>
        </x-ui-page-actionbar>
    </x-slot>

    @if(! $istWall)
        <x-slot name="sidebar">
            <x-ui-page-sidebar title="Zeitraum und Posten" width="w-72">
                <div class="p-3 space-y-5" data-tagesplanung-sidebar>
                    <div class="space-y-2" data-tagesplan-steuerung>
                        <p class="{{ $eyebrow }}">Zeitraum</p>
                        <div class="flex items-center gap-1">
                            <x-fa::icon-button size="sm" icon="heroicon-m-chevron-left" label="Eine Woche zurück" wire:click="dashboardTagVerschieben(-7)" />
                            <x-fa::button size="sm" variant="secondary" wire:click="dashboardHeute" class="flex-1" data-tagesplan-heute>Heute</x-fa::button>
                            <x-fa::icon-button size="sm" icon="heroicon-m-chevron-right" label="Eine Woche vor" wire:click="dashboardTagVerschieben(7)" />
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <x-fa::field label="Von" for="tp-von">
                                <x-fa::input type="date" id="tp-von" size="sm" wire:model.live="von" data-tagesplan-von />
                            </x-fa::field>
                            <x-fa::field label="Bis" for="tp-bis">
                                <x-fa::input type="date" id="tp-bis" size="sm" wire:model.live="bis" data-tagesplan-bis />
                            </x-fa::field>
                        </div>
                        <div class="flex flex-wrap gap-1" role="group" aria-label="Zeitfenster" data-tagesplanung-dashboard-fenster>
                            @foreach([3 => '3 Tage', 7 => '7 Tage', 14 => '14 Tage', 30 => 'Monat'] as $n => $lbl)
                                <button type="button" wire:click="waehleDashboardFenster({{ $n }})" aria-pressed="{{ $dashboard['fenster'] === $n ? 'true' : 'false' }}"
                                        class="{{ $chip }} {{ $dashboard['fenster'] === $n ? $chipAn : $chipAus }}">{{ $lbl }}</button>
                            @endforeach
                        </div>
                    </div>

                    <div class="space-y-2" data-tagesplan-postenfilter>
                        <p class="{{ $eyebrow }}">Posten</p>
                        <div class="space-y-1">
                            <x-foodalchemist::filter-row wire:click="postenWaehlen(null)" :active="$postenFilter === null">
                                <span class="font-medium">Alle Posten</span>
                            </x-foodalchemist::filter-row>
                            <x-foodalchemist::filter-ast>
                                @foreach($postenListe as $p)
                                    <x-foodalchemist::filter-row level="child" wire:key="tps-{{ $p->id }}"
                                        wire:click="postenWaehlen({{ $p->id }})"
                                        :active="$postenFilter === $p->id">{{ $p->name }}</x-foodalchemist::filter-row>
                                @endforeach
                            </x-foodalchemist::filter-ast>
                        </div>
                    </div>

                    <div class="space-y-1.5">
                        <x-fa::button variant="secondary" icon="heroicon-o-arrows-right-left" wire:click="vorschlagen" class="w-full" data-tagesplan-vorschlagen>
                            Verteilung vorschlagen
                        </x-fa::button>
                        <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Rechnet Vorlauftage so, dass kein Posten über Last läuft. Übernommen wird erst nach Bestätigung.</p>
                    </div>
                </div>
            </x-ui-page-sidebar>
        </x-slot>

        <x-slot name="activity">
            <x-foodalchemist::detail-sidebar title="Tagesdetail" width="w-80" :maxWidth="760"
                                             scope="activity_tagesplan" side="right">
                {{-- Anatomie Detail-Panels (DESIGN.md): Kopf (Tag, Last, „Im Editor öffnen") · Kennzahlen · offene Punkte
                     (Posten über Last) · Inhalt (Posten mit ihren Positionen). Ohne gewählten Tag: Übersicht des Zeitraums. --}}
                <div class="p-4 flex flex-col gap-5 min-h-full bg-[var(--fa-ground)]" data-tagesplanung-activity>
                    @if($selectedDay && $tagDetail)
                        @php
                            $tagPosten = collect($tagDetail['auslastung'])->filter(function ($b) use ($tagDetail) {
                                $schluessel = $b['station_id'] === null ? '_none' : (int) $b['station_id'];
                                return $tagDetail['posten']->get($schluessel, collect())->isNotEmpty();
                            });
                            $tagUeberlast = $tagPosten->filter(fn ($b) => ($b['stufe'] ?? null) === 'ueberlast');
                            $tagEng = $tagPosten->filter(fn ($b) => ($b['stufe'] ?? null) === 'eng');
                            $tagOhneZeit = $tagDetail['zeilen']->filter(fn ($z) => $z->arbeitszeit_min === null)->count();
                            $tagDatum = \Illuminate\Support\Carbon::parse($tagDetail['tag']);
                        @endphp
                        <div class="flex flex-col gap-5" data-tagesplanung-tagdetail>
                            <x-fa::detail-kopf :title="$tagDatum->locale('de')->isoFormat('dddd, DD.MM.')"
                                :subtitle="$tagDetail['zeilen']->count() . ' ' . ($tagDetail['zeilen']->count() === 1 ? 'Position' : 'Positionen') . ' · ' . $tagDetail['minuten'] . ' min geplant'">
                                <x-slot:badges>
                                    @if($tagUeberlast->isNotEmpty())
                                        <x-fa::badge tone="crit">{{ $stufeText('ueberlast') }}</x-fa::badge>
                                    @elseif($tagEng->isNotEmpty())
                                        <x-fa::badge tone="warn">{{ $stufeText('eng') }}</x-fa::badge>
                                    @elseif($tagPosten->contains(fn ($b) => ($b['stufe'] ?? null) === 'ok'))
                                        <x-fa::badge tone="ok">{{ $stufeText('ok') }}</x-fa::badge>
                                    @elseif($tagPosten->isNotEmpty())
                                        <x-fa::badge :tone="$stufeTon($tagPosten->first()['stufe'] ?? null)">{{ $stufeText($tagPosten->first()['stufe'] ?? null) }}</x-fa::badge>
                                    @endif
                                    @if($tagDatum->isToday())<x-fa::badge tone="info">Heute</x-fa::badge>@endif
                                </x-slot:badges>
                                @if($modus === 'dashboard' && ! $istWall)
                                    <x-slot:aktion>
                                        <x-fa::button size="sm" variant="primary" icon="heroicon-m-pencil-square"
                                            :href="route('foodalchemist.produktion.tagesplan.editor', ['von' => $von, 'bis' => $bis, 'tage' => $tage, 'ansicht' => $ansicht, 'tag' => $tagDetail['tag']])"
                                            wire:navigate x-data x-on:click="$dispatch('modal.open', { name: 'tagesplan-editor' })">Im Editor öffnen</x-fa::button>
                                    </x-slot:aktion>
                                @endif
                                <x-slot:menue>
                                    <x-fa::menu-item icon="heroicon-m-squares-2x2" wire:click="waehleTag(null)">Zur Übersicht</x-fa::menu-item>
                                </x-slot:menue>
                            </x-fa::detail-kopf>

                            <x-fa::kpis :items="[
                                ['label' => 'Geplant', 'value' => $tagDetail['minuten'] . ' min', 'primary' => true, 'kpi' => 'tag-minuten'],
                                ['label' => 'Positionen', 'value' => (string) $tagDetail['zeilen']->count(), 'kpi' => 'tag-positionen'],
                                ['label' => 'Posten', 'value' => (string) $tagPosten->count(), 'kpi' => 'tag-posten'],
                            ]" />

                            @if($tagUeberlast->isNotEmpty() || $tagEng->isNotEmpty() || $tagOhneZeit > 0)
                                <div class="flex flex-col gap-1">
                                    @foreach($tagUeberlast as $b)
                                        <x-fa::signal tone="crit">{{ $b['station'] }}: {{ $b['geplant_min'] }}@if($b['kapazitaet_min'] !== null) von {{ $b['kapazitaet_min'] }}@endif min, über Last.</x-fa::signal>
                                    @endforeach
                                    @foreach($tagEng as $b)
                                        <x-fa::signal tone="warn">{{ $b['station'] }}: {{ $b['geplant_min'] }}@if($b['kapazitaet_min'] !== null) von {{ $b['kapazitaet_min'] }}@endif min, eng.</x-fa::signal>
                                    @endforeach
                                    @if($tagOhneZeit > 0)
                                        <x-fa::signal tone="warn">{{ $tagOhneZeit }} {{ $tagOhneZeit === 1 ? 'Position' : 'Positionen' }} ohne Arbeitszeit.</x-fa::signal>
                                    @endif
                                </div>
                            @endif

                            <div class="flex flex-col">
                                @foreach($tagPosten as $b)
                                    @php
                                        $schluessel = $b['station_id'] === null ? '_none' : (int) $b['station_id'];
                                        $postenZeilen = $tagDetail['posten']->get($schluessel, collect());
                                        $ton = $stufeTon($b['stufe'] ?? null);
                                    @endphp
                                    <x-fa::section variant="plain" :title="$b['station']" icon="heroicon-o-users"
                                        :meta="$b['geplant_min'] . ($b['kapazitaet_min'] !== null ? ' von ' . $b['kapazitaet_min'] : '') . ' min'" data-tagesplanung-tagdetail-posten>
                                        <x-slot:actions>
                                            <x-fa::badge :tone="$ton">{{ $stufeText($b['stufe'] ?? null) }}</x-fa::badge>
                                        </x-slot:actions>
                                        <ul class="flex flex-col">
                                            @foreach($postenZeilen as $z)
                                                <li class="py-1.5 border-b border-[var(--fa-line)] last:border-0">
                                                    <p class="text-[length:var(--fa-text-md)] font-medium text-[var(--fa-ink)] break-words">{{ $z->name }}</p>
                                                    <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] break-words">{{ $z->auftrag }} · {{ $z->arbeitszeit_min !== null ? $z->arbeitszeit_min . ' min' : 'keine Zeit' }} · für {{ \Illuminate\Support\Carbon::parse($z->liefertag)->format('d.m.') }}</p>
                                                </li>
                                            @endforeach
                                        </ul>
                                    </x-fa::section>
                                @endforeach
                            </div>
                        </div>
                    @else
                        <div class="flex flex-col">
                            <x-fa::section variant="plain" title="Als Nächstes" icon="heroicon-o-queue-list" data-tagesplanung-dashboard-next>
                                @if($dashboard['naechstes']->isEmpty())
                                    <x-fa::empty compact icon="heroicon-o-check-circle" title="Nichts offen im Zeitraum" />
                                @else
                                    <ul class="flex flex-col">
                                        @foreach($dashboard['naechstes'] as $z)
                                            <li class="py-1.5 border-b border-[var(--fa-line)] last:border-0">
                                                <p class="text-[length:var(--fa-text-md)] font-medium text-[var(--fa-ink)] break-words">{{ $z->name }}</p>
                                                <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] break-words">{{ \Illuminate\Support\Carbon::parse($z->plan_date)->format('d.m.') }} · {{ $z->station ?: 'ohne Posten' }} · {{ $z->auftrag }}</p>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            </x-fa::section>

                            <x-fa::section variant="plain" title="Engpässe" icon="heroicon-o-chart-bar" description="Auslastung je Posten im Zeitraum." data-tagesplanung-dashboard-performance>
                                @if($dashboard['performance']->isEmpty())
                                    <x-fa::empty compact icon="heroicon-o-chart-bar" title="Noch keine Auslastung">Sobald Aufträge eingeplant sind, steht hier die Last je Posten.</x-fa::empty>
                                @else
                                    <div class="flex flex-col gap-2.5">
                                        @foreach($dashboard['performance']->take(8) as $p)
                                            @php
                                                $breite = $p['prozent'] !== null ? min(100, max(4, $p['prozent'])) : 12;
                                                $balkenTon = ($p['kritisch'] ?? 0) > 0 ? 'crit' : (($p['eng'] ?? 0) > 0 ? 'warn' : 'info');
                                            @endphp
                                            <div>
                                                <div class="flex items-center justify-between gap-2 text-[length:var(--fa-text-sm)]">
                                                    <span class="min-w-0 truncate font-medium text-[var(--fa-ink-2)]" title="{{ $p['station'] }}">{{ $p['station'] }}</span>
                                                    <span class="shrink-0 tabular-nums text-[var(--fa-ink-3)]">{{ $p['prozent'] !== null ? $p['prozent'] . ' %' : (int) $p['minuten'] . ' min' }}</span>
                                                </div>
                                                <div class="mt-1 h-2 overflow-hidden rounded-full bg-[var(--fa-neutral-soft)]">
                                                    <div class="h-full rounded-full {{ $tonBalken[$balkenTon] }}" style="width: {{ $breite }}%"></div>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                            </x-fa::section>
                        </div>
                    @endif
                </div>
            </x-foodalchemist::detail-sidebar>
        </x-slot>

        <livewire:foodalchemist.produktion.editor key="produktion-tagesplan--produktion.editor" />

        <x-foodalchemist::modal name="tagesplan-editor" fullscreen dark-canvas title="Tagesplanung"
                                :title-name="$ansicht === 'gericht' ? 'Nach Gerichten' : 'Nach Posten'"
                                :close-via="'editorSchliessen'">
            <x-slot:actions>
                <div class="ml-auto flex flex-wrap items-center gap-2">
                    <div class="relative" x-data="faMenu()" x-on:keydown.escape="offen = false" x-on:click.outside="offen = false">
                        <x-fa::icon-button icon="heroicon-m-ellipsis-horizontal" label="Weitere Aktionen" x-on:click="toggle($event)" aria-haspopup="menu" x-bind:aria-expanded="offen" />
                        <div class="hidden w-60 fa-surface shadow-lg py-1" x-bind:class="{ hidden: ! offen }" x-bind:style="pos" role="menu">
                            <a href="{{ $blattUrl }}" target="_blank" role="menuitem" x-on:click="offen = false" class="{{ $menuePunkt }}" data-tagesplan-drucken>
                                @svg('heroicon-o-printer', 'w-4 h-4 text-[var(--fa-ink-3)]') Produktionsblatt drucken
                            </a>
                            <a href="{{ $blattUrl . (str_contains($blattUrl, '?') ? '&' : '?') . 'pdf=1' }}" role="menuitem" x-on:click="offen = false" class="{{ $menuePunkt }}" data-tagesplan-pdf>
                                @svg('heroicon-o-arrow-down-tray', 'w-4 h-4 text-[var(--fa-ink-3)]') Produktionsblatt als PDF
                            </a>
                        </div>
                    </div>
                    <x-fa::button variant="primary" icon="heroicon-o-arrows-right-left" wire:click="vorschlagen" wire:loading.attr="disabled" wire:target="vorschlagen" data-tagesplan-vorschlagen>Verteilung vorschlagen</x-fa::button>
                </div>
            </x-slot:actions>

            <x-slot:kpiHeader>
                <x-foodalchemist::kpi-tiles marker="tagesplan-editor" :tiles="[
                    ['kpi' => 'offen', 'label' => 'Offen', 'tone' => 'accent', 'value' => (string) $dashboard['kpis']['offen']],
                    ['kpi' => 'zeit', 'label' => 'Arbeitszeit', 'value' => $dashboard['kpis']['minuten'] . ' min'],
                    ['kpi' => 'manntage', 'label' => 'Manntage', 'value' => number_format($dashboard['kpis']['minuten'] / 480, 1, ',', '.'), 'title' => '1 Manntag = 480 Minuten'],
                    ['kpi' => 'ueberlast', 'label' => 'Überlast', 'tone' => $dashboard['kpis']['ueberlast'] > 0 ? 'bad' : 'neutral', 'value' => (string) $dashboard['kpis']['ueberlast'], 'title' => 'Posten-Tage über der Kapazität'],
                    ['kpi' => 'posten', 'label' => 'Posten belegt', 'value' => (string) $dashboard['kpis']['posten']],
                ]" />
            </x-slot:kpiHeader>

            <section data-tagesplan-editor>
                @if($fehler)<x-fa::notice tone="crit" class="mb-4" data-tagesplan-fehler>{{ $fehler }}</x-fa::notice>@endif

                @if($vorschlag !== null)
                    {{-- Vorschlag steht OBEN: er ist die offene Entscheidung, nicht ein Nachtrag unter der Liste. --}}
                    <x-fa::notice tone="info" title="Vorschlag zur Verteilung" class="mb-4" data-tagesplan-vorschlag>
                        {{ $vorschlag['aenderungen'] }} {{ (int) $vorschlag['aenderungen'] === 1 ? 'Änderung' : 'Änderungen' }} am Vorlauf, damit die Posten nicht über Last laufen.
                        <x-slot:actions>
                            <x-fa::button size="sm" variant="ghost" wire:click="vorschlagVerwerfen">Verwerfen</x-fa::button>
                            <x-fa::button size="sm" variant="secondary" icon="heroicon-m-check" wire:click="vorschlagUebernehmen">Vorschlag übernehmen</x-fa::button>
                        </x-slot:actions>
                    </x-fa::notice>
                @endif

                {{-- Drei Spalten wie gehabt. Auf dem Laptop schmalere Seitenspalten; die Mitte scrollt mit dem
                     Editor statt in einem eigenen 70-%-Kasten, die Seitenspalten bleiben dabei stehen. --}}
                <div class="grid grid-cols-1 items-start gap-4 xl:grid-cols-[13rem_minmax(0,1fr)_15rem] 2xl:grid-cols-[16rem_minmax(0,1fr)_20rem]">
                    <aside class="fa-surface space-y-4 p-3 xl:sticky xl:top-0" data-tagesplan-postenfilter>
                        <div class="space-y-2" data-tagesplan-steuerung>
                            <p class="{{ $eyebrow }}">Zeitraum</p>
                            <div class="flex items-center gap-1">
                                <x-fa::icon-button size="sm" icon="heroicon-m-chevron-left" label="Eine Woche zurück" wire:click="verschiebe(-7)" />
                                <x-fa::button size="sm" variant="secondary" wire:click="heute" class="flex-1" data-tagesplan-heute>Heute</x-fa::button>
                                <x-fa::icon-button size="sm" icon="heroicon-m-chevron-right" label="Eine Woche vor" wire:click="verschiebe(7)" />
                            </div>
                            <x-fa::field label="Von" for="tp-ed-von"><x-fa::input type="date" id="tp-ed-von" size="sm" wire:model.live="von" /></x-fa::field>
                            <x-fa::field label="Bis" for="tp-ed-bis"><x-fa::input type="date" id="tp-ed-bis" size="sm" wire:model.live="bis" /></x-fa::field>
                        </div>

                        <div class="space-y-2">
                            <p class="{{ $eyebrow }}">Posten</p>
                            <div class="flex flex-wrap gap-1">
                                @foreach($postenListe as $p)
                                    <button type="button" wire:click="postenWaehlen({{ $p->id }})" wire:key="tpf-modal-{{ $p->id }}" aria-pressed="{{ $postenFilter === $p->id ? 'true' : 'false' }}"
                                            class="{{ $chip }} {{ $postenFilter === $p->id ? $chipAn : $chipAus }}">{{ $p->name }}</button>
                                @endforeach
                            </div>
                            @if($postenFilter !== null)
                                <x-fa::button size="sm" variant="ghost" icon="heroicon-m-x-mark" wire:click="postenWaehlen(null)">Alle Posten zeigen</x-fa::button>
                            @endif
                        </div>
                    </aside>

                    <main class="min-w-0 space-y-4">
                        @forelse($zeilenNachTag as $tag => $zeilen)
                            @php
                                $tagC = \Illuminate\Support\Carbon::parse($tag);
                                $nachPosten = $zeilen->groupBy(fn ($z) => $z->station_id === null ? '_none' : (int) $z->station_id);
                            @endphp
                            <section data-modal-zone="section" class="fa-surface overflow-hidden" data-tagesplan-tag="{{ $tag }}">
                                <div class="flex flex-wrap items-baseline gap-2 border-b border-[var(--fa-line)] px-4 py-3">
                                    <h3 class="text-[length:var(--fa-text-lg)] font-semibold text-[var(--fa-ink)]">{{ $tagC->locale('de')->isoFormat('dd, DD.MM.') }}</h3>
                                    @if($tagC->isToday())<x-fa::badge tone="accent">Heute</x-fa::badge>@endif
                                    <span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] tabular-nums">{{ $zeilen->count() }} {{ $zeilen->count() === 1 ? 'Position' : 'Positionen' }}</span>
                                </div>
                                @foreach($auslastung[$tag] ?? [] as $b)
                                    @php
                                        $schluessel = $b['station_id'] === null ? '_none' : (int) $b['station_id'];
                                        $blockZeilen = $nachPosten[$schluessel] ?? collect();
                                    @endphp
                                    @continue($blockZeilen->isEmpty())
                                    @php
                                        $ton = $stufeTon($b['stufe'] ?? null);
                                    @endphp
                                    <div class="border-b border-[var(--fa-line)] px-4 py-3 last:border-b-0" data-tagesplan-auslastung>
                                        {{-- Kopfzeile je Posten bricht um statt feste Breiten zu erzwingen (Laptop) --}}
                                        <div class="mb-2 flex flex-wrap items-center gap-x-3 gap-y-1.5">
                                            <span class="min-w-0 text-[length:var(--fa-text-md)] font-semibold text-[var(--fa-ink)]">{{ $b['station'] }}</span>
                                            <span class="text-[length:var(--fa-text-sm)] tabular-nums text-[var(--fa-ink-3)]">{{ $b['geplant_min'] }}@if($b['kapazitaet_min'] !== null) von {{ $b['kapazitaet_min'] }}@endif min</span>
                                            <span class="h-1.5 min-w-16 flex-1 overflow-hidden rounded-full bg-[var(--fa-neutral-soft)]">
                                                @if($b['kapazitaet_min'] !== null)
                                                    <span class="block h-full {{ $tonBalken[$ton] }}" style="width: {{ min(100, (int) ($b['prozent'] ?? 0)) }}%"></span>
                                                @endif
                                            </span>
                                            @if($b['stufe'] === 'ueberlast')
                                                <x-fa::badge tone="crit">{{ $b['prozent'] }} % Überlast</x-fa::badge>
                                            @elseif($b['stufe'] === 'eng')
                                                <x-fa::badge tone="warn">{{ $b['prozent'] }} % Eng</x-fa::badge>
                                            @endif
                                            @if($b['ohne_zeit'] > 0)<x-fa::signal tone="warn">{{ $b['ohne_zeit'] }} ohne Zeit</x-fa::signal>@endif
                                        </div>
                                        <div class="overflow-x-auto">
                                            <table class="fa-table fa-table--compact">
                                                <thead>
                                                    <tr>
                                                        <th class="w-px"><span class="sr-only">Erledigt</span></th>
                                                        <th>Rezept</th>
                                                        <th class="num">Ansätze</th>
                                                        <th class="num">Zeit</th>
                                                        <th>Auftrag</th>
                                                        <th class="num" title="Tage Vorlauf vor dem Liefertag">Vorlauf (Tage)</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    @foreach($blockZeilen as $z)
                                                        @php
                                                            $erledigt = $z->line_status === 'done';
                                                            $laeuft = $z->auftrag_status === 'in_progress';
                                                        @endphp
                                                        <tr class="{{ $erledigt ? 'opacity-60' : '' }}" wire:key="tpz-modal-{{ $z->id }}" data-tagesplan-zeile="{{ $z->id }}">
                                                            <td class="w-px">
                                                                @if($laeuft)
                                                                    <button type="button" wire:click="abhaken({{ $z->id }})"
                                                                            class="grid h-6 w-6 place-items-center rounded-[var(--fa-radius-control)] border {{ $erledigt ? 'border-[var(--fa-ok)] bg-[var(--fa-ok)] text-[var(--fa-surface)]' : 'border-[var(--fa-line-strong)] hover:border-[var(--fa-accent)]' }}"
                                                                            title="{{ $erledigt ? 'Haken zurücknehmen' : 'Als erledigt abhaken' }}"
                                                                            aria-label="{{ $erledigt ? 'Haken zurücknehmen' : 'Als erledigt abhaken' }}" data-tagesplan-abhaken>@if($erledigt)@svg('heroicon-m-check', 'w-4 h-4')@endif</button>
                                                                @else
                                                                    <span class="inline-block h-6 w-6 rounded-[var(--fa-radius-control)] border border-dashed border-[var(--fa-line-strong)]" title="Abhaken geht erst, wenn der Auftrag in Arbeit ist."></span>
                                                                @endif
                                                            </td>
                                                            <td class="min-w-48 {{ $erledigt ? 'line-through' : '' }}">
                                                                <span class="font-medium text-[var(--fa-ink)] break-words">{{ $z->name }}</span>@if($z->assignee)<span class="ml-1 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">· {{ $z->assignee }}</span>@endif
                                                            </td>
                                                            <td class="num">{{ rtrim(rtrim(number_format($z->ansaetze_effektiv, 2, ',', '.'), '0'), ',') }}</td>
                                                            <td class="num">@if($z->arbeitszeit_min !== null){{ $z->arbeitszeit_min }} min @else<x-fa::signal tone="warn">fehlt</x-fa::signal>@endif</td>
                                                            <td class="whitespace-nowrap">
                                                                <button type="button" wire:click="$dispatch('produktion-editor.bearbeiten', { id: {{ $z->order_id }} })" class="text-[var(--fa-accent)] hover:underline" title="Auftrag öffnen" data-tagesplan-auftrag>{{ $z->auftrag }}</button>
                                                                <span class="ml-1 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">für {{ \Illuminate\Support\Carbon::parse($z->liefertag)->format('d.m.') }}</span>
                                                            </td>
                                                            <td class="num w-px">
                                                                <x-fa::input size="sm" numeric inputmode="numeric" value="{{ $z->vorlauf_tage }}" wire:change="vorlaufSetzen({{ $z->id }}, $event.target.value)"
                                                                             class="w-16" title="Tage Vorlauf vor dem Liefertag" aria-label="Vorlauf in Tagen für {{ $z->name }}" data-tagesplan-vorlauf />
                                                            </td>
                                                        </tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                @endforeach
                            </section>
                        @empty
                            <div data-modal-zone="section" class="fa-surface" data-tagesplan-leer>
                                <x-fa::empty icon="heroicon-o-calendar" title="In diesem Zeitraum steht nichts an.">
                                    Der Tagesplan zeigt Positionen aus geplanten und laufenden Aufträgen. Zeitraum erweitern oder einen Auftrag einplanen.
                                </x-fa::empty>
                            </div>
                        @endforelse
                    </main>

                    <aside class="fa-surface p-3 xl:sticky xl:top-0" data-tagesplan-next>
                        <p class="{{ $eyebrow }}">Als Nächstes</p>
                        @if($dashboard['naechstes']->isEmpty())
                            <x-fa::empty compact icon="heroicon-o-check-circle" title="Nichts offen" />
                        @else
                            <ul class="mt-2 divide-y divide-[var(--fa-line)]">
                                @foreach($dashboard['naechstes'] as $z)
                                    <li data-modal-zone="section" class="py-2">
                                        <p class="text-[length:var(--fa-text-md)] font-medium text-[var(--fa-ink)] break-words">{{ $z->name }}</p>
                                        <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">{{ \Illuminate\Support\Carbon::parse($z->plan_date)->format('d.m.') }} · {{ $z->station ?: 'ohne Posten' }}</p>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </aside>
                </div>
            </section>
        </x-foodalchemist::modal>

        <x-ui-page-container padding="px-4 pb-6 lg:px-6" spacing="space-y-4">
            @if($fehler)<x-fa::notice tone="crit" data-tagesplan-fehler>{{ $fehler }}</x-fa::notice>@endif

            @if($vorschlag !== null)
                <x-fa::notice tone="info" title="Vorschlag zur Verteilung" data-tagesplan-vorschlag>
                    {{ $vorschlag['aenderungen'] }} {{ (int) $vorschlag['aenderungen'] === 1 ? 'Änderung' : 'Änderungen' }} am Vorlauf, damit die Posten nicht über Last laufen.
                    <x-slot:actions>
                        <x-fa::button size="sm" variant="ghost" wire:click="vorschlagVerwerfen">Verwerfen</x-fa::button>
                        <x-fa::button size="sm" variant="secondary" icon="heroicon-m-check" wire:click="vorschlagUebernehmen">Vorschlag übernehmen</x-fa::button>
                    </x-slot:actions>
                </x-fa::notice>
            @endif

            <section class="space-y-3" data-tagesplanung-dashboard>
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <div class="min-w-0">
                        <p class="{{ $eyebrow }}">Küchenleiter-Dashboard</p>
                        <h1 class="text-[length:var(--fa-text-2xl)] font-semibold tracking-tight text-[var(--fa-ink)]">Auslastung und Tageshorizont</h1>
                        <p class="mt-0.5 text-[length:var(--fa-text-md)] text-[var(--fa-ink-2)] tabular-nums">
                            {{ \Illuminate\Support\Carbon::parse($dashboard['von'])->locale('de')->isoFormat('dd, DD.MM.YYYY') }}
                            bis {{ \Illuminate\Support\Carbon::parse($dashboard['bis'])->locale('de')->isoFormat('dd, DD.MM.YYYY') }}
                        </p>
                    </div>
                </div>

                <x-fa::kpis data-tagesplanung-dashboard-kpis :items="[
                    ['label' => 'Offen', 'value' => number_format($dashboard['kpis']['offen'], 0, ',', '.'), 'primary' => true, 'title' => 'Noch zu produzieren'],
                    ['label' => 'Positionen', 'value' => number_format($dashboard['kpis']['speisen'], 0, ',', '.'), 'title' => 'Alle Positionen im Zeitraum'],
                    ['label' => 'Arbeitszeit', 'value' => number_format($dashboard['kpis']['minuten'], 0, ',', '.') . ' min', 'title' => 'Geplante Minuten'],
                    ['label' => 'Überlast', 'value' => number_format($dashboard['kpis']['ueberlast'], 0, ',', '.'), 'tone' => $dashboard['kpis']['ueberlast'] > 0 ? 'crit' : null, 'title' => 'Posten-Tage über der Kapazität'],
                    ['label' => 'Posten belegt', 'value' => number_format($dashboard['kpis']['posten'], 0, ',', '.')],
                ]" />
            </section>

            <section class="space-y-4">
                    <div class="grid grid-cols-[repeat(auto-fit,minmax(min(100%,24rem),1fr))] gap-4">
                        <x-fa::section title="Planung in Manntagen" icon="heroicon-o-users" meta="1 Manntag = 480 min" data-tagesplanung-dashboard-manntage>
                            <div class="flex min-h-36 items-end gap-1.5 overflow-x-auto pb-1">
                                @foreach($dashboard['manntage'] as $tag)
                                    @php
                                        $hoehe = max(8, (int) round(($tag['wert'] / $dashboard['maxManntage']) * 112));
                                    @endphp
                                    <div class="min-w-9 flex-1 text-center" title="{{ \Illuminate\Support\Carbon::parse($tag['tag'])->locale('de')->isoFormat('dddd, DD.MM.') }}: {{ number_format($tag['wert'], 1, ',', '.') }} Manntage">
                                        <p class="text-[length:var(--fa-text-sm)] font-semibold tabular-nums text-[var(--fa-ink)]">{{ number_format($tag['wert'], 1, ',', '.') }}</p>
                                        <div class="mx-auto mt-1 rounded-t-[var(--fa-radius-control)] bg-[var(--fa-info)]" style="height: {{ $hoehe }}px"></div>
                                        <p class="mt-1 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] tabular-nums">{{ \Illuminate\Support\Carbon::parse($tag['tag'])->format('d.m.') }}</p>
                                    </div>
                                @endforeach
                            </div>
                        </x-fa::section>

                        <x-fa::section title="Produktion" icon="heroicon-o-signal" meta="Posten-Tage im Zeitraum" data-tagesplanung-dashboard-produktion>
                            <div class="grid grid-cols-3 gap-2">
                                @foreach([
                                    ['label' => 'Pünktlich', 'wert' => $dashboard['produktionAmpeln']['puenktlich'], 'ton' => 'ok'],
                                    ['label' => 'Eng', 'wert' => $dashboard['produktionAmpeln']['verspaetet'], 'ton' => 'warn'],
                                    ['label' => 'Kritisch', 'wert' => $dashboard['produktionAmpeln']['kritisch'], 'ton' => 'crit'],
                                ] as $ampel)
                                    <div class="rounded-[var(--fa-radius-surface)] px-3 py-4 text-center {{ $tonFlaeche[$ampel['ton']] }}">
                                        <p class="text-[length:var(--fa-text-2xl)] font-semibold tabular-nums leading-tight">{{ $ampel['wert'] }}</p>
                                        <p class="mt-1 text-[length:var(--fa-text-sm)] font-medium">{{ $ampel['label'] }}</p>
                                    </div>
                                @endforeach
                            </div>
                        </x-fa::section>
                    </div>

                    <x-fa::section title="Tageshorizont" icon="heroicon-o-calendar-days" description="Tag antippen, um rechts die Posten und Positionen zu sehen." data-tagesplanung-dashboard-horizont>
                        {{-- Füllt nach verfügbarer Breite (Seitenleisten offen oder zu, Laptop oder großer Monitor) --}}
                        <div class="grid grid-cols-[repeat(auto-fill,minmax(min(100%,14rem),1fr))] gap-3">
                            @foreach($dashboard['tage'] as $tag)
                                @php
                                    $tagZeilen = $dashboard['zeilenNachTag']->get($tag, collect());
                                    $tagBuckets = collect($dashboard['auslastung'][$tag] ?? []);
                                    $tagUeberlast = $tagBuckets->where('stufe', 'ueberlast')->count();
                                    $tagEng = $tagBuckets->where('stufe', 'eng')->count();
                                    [$tagTon, $tagText] = $tagUeberlast > 0 ? ['crit', 'Überlast'] : ($tagEng > 0 ? ['warn', 'Eng'] : ($tagZeilen->isNotEmpty() ? ['ok', 'Im Plan'] : ['neutral', 'Frei']));
                                    $tagGewaehlt = $selectedDay === $tag;
                                    $tagCarbon = \Illuminate\Support\Carbon::parse($tag)->locale('de');
                                @endphp
                                <article wire:click="waehleTag('{{ $tag }}')" x-data x-on:click="$store.ui?.mSet('activity_tagesplan', 'open', true)"
                                         role="button" tabindex="0" aria-pressed="{{ $tagGewaehlt ? 'true' : 'false' }}"
                                         class="flex cursor-pointer flex-col gap-3 rounded-[var(--fa-radius-surface)] border border-t-4 bg-[var(--fa-surface)] p-3 transition-colors hover:bg-[var(--fa-hover)] {{ $tonRand[$tagTon] }} {{ $tagGewaehlt ? 'ring-2 ring-[var(--fa-accent)]' : 'border-x-[var(--fa-line)] border-b-[var(--fa-line)]' }}"
                                         data-tagesplanung-dashboard-tag="{{ $tag }}" data-tagesplanung-dashboard-tag-klickbar>
                                    <div class="flex items-start justify-between gap-2">
                                        <div>
                                            <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">{{ $tagCarbon->isoFormat('dddd') }}</p>
                                            <h4 class="text-[length:var(--fa-text-2xl)] font-semibold leading-tight tracking-tight text-[var(--fa-ink)] tabular-nums">{{ $tagCarbon->format('d.m.') }}</h4>
                                        </div>
                                        <x-fa::badge :tone="$tagTon">{{ $tagText }}</x-fa::badge>
                                    </div>
                                    <dl class="grid grid-cols-3 gap-1.5 text-center">
                                        <div class="rounded-[var(--fa-radius-control)] bg-[var(--fa-ground)] px-1 py-1.5"><dd class="text-[length:var(--fa-text-lg)] font-semibold tabular-nums text-[var(--fa-ink)]">{{ $tagZeilen->count() }}</dd><dt class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Positionen</dt></div>
                                        <div class="rounded-[var(--fa-radius-control)] bg-[var(--fa-ground)] px-1 py-1.5"><dd class="text-[length:var(--fa-text-lg)] font-semibold tabular-nums text-[var(--fa-ink)]">{{ (int) $tagZeilen->sum('arbeitszeit_min') }}</dd><dt class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Minuten</dt></div>
                                        <div class="rounded-[var(--fa-radius-control)] bg-[var(--fa-ground)] px-1 py-1.5"><dd class="text-[length:var(--fa-text-lg)] font-semibold tabular-nums text-[var(--fa-ink)]">{{ $tagBuckets->count() }}</dd><dt class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Posten</dt></div>
                                    </dl>
                                    <ul class="space-y-0.5">
                                        @foreach($tagZeilen->take(4) as $z)
                                            <li class="truncate text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]" title="{{ $z->name }} · {{ $z->station ?: 'ohne Posten' }}">{{ $z->name }} <span class="text-[var(--fa-ink-3)]">· {{ $z->station ?: 'ohne Posten' }}</span></li>
                                        @endforeach
                                        @if($tagZeilen->count() > 4)
                                            <li class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">und {{ $tagZeilen->count() - 4 }} weitere</li>
                                        @elseif($tagZeilen->isEmpty())
                                            <li class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Keine Produktion geplant.</li>
                                        @endif
                                    </ul>
                                </article>
                            @endforeach
                        </div>
                    </x-fa::section>

                    <x-fa::section title="Auslastung nach Posten" icon="heroicon-o-table-cells" description="Kapazität aus Posten und Besetzung." data-tagesplanung-dashboard-auslastung>
                        @if(empty($dashboard['matrix']))
                            <x-fa::empty icon="heroicon-o-table-cells" title="Noch keine Tagesproduktion geplant">Sobald Aufträge im gewählten Zeitraum eingeplant sind, steht hier die Last je Posten und Tag.</x-fa::empty>
                        @else
                            <div class="-mx-4 overflow-x-auto border-t border-[var(--fa-line)]">
                                <table class="fa-table fa-table--compact">
                                    <thead>
                                        <tr>
                                            <th class="sticky left-0 z-10 bg-[var(--fa-surface)]">Posten</th>
                                            @foreach($dashboard['tage'] as $tag)
                                                <th class="whitespace-nowrap">{{ \Illuminate\Support\Carbon::parse($tag)->locale('de')->isoFormat('dd DD.MM.') }}</th>
                                            @endforeach
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($dashboard['matrix'] as $station => $zellen)
                                            <tr>
                                                <td class="sticky left-0 z-10 whitespace-nowrap bg-[var(--fa-surface)] font-medium">{{ $station }}</td>
                                                @foreach($dashboard['tage'] as $tag)
                                                    @php
                                                        $bucket = $zellen[$tag] ?? null;
                                                        $zellTon = $bucket ? $stufeTon($bucket['stufe'] ?? null) : 'neutral';
                                                    @endphp
                                                    <td class="min-w-28">
                                                        <div class="rounded-[var(--fa-radius-control)] px-2.5 py-1.5 {{ $tonFlaeche[$zellTon] }}" @if($bucket) title="{{ $stufeText($bucket['stufe'] ?? null) }}" @endif>
                                                            @if($bucket)
                                                                <p class="font-semibold tabular-nums whitespace-nowrap">{{ (int) $bucket['geplant_min'] }} min @if($bucket['prozent'] !== null)<span class="font-normal">· {{ $bucket['prozent'] }} %</span>@endif</p>
                                                                <p class="text-[length:var(--fa-text-sm)] opacity-80 whitespace-nowrap">{{ (int) $bucket['zeilen'] }} {{ (int) $bucket['zeilen'] === 1 ? 'Position' : 'Positionen' }}@if($bucket['ohne_zeit']) · {{ (int) $bucket['ohne_zeit'] }} ohne Zeit @endif</p>
                                                            @else
                                                                <p class="font-medium">Frei</p>
                                                            @endif
                                                        </div>
                                                    </td>
                                                @endforeach
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </x-fa::section>
            </section>
        </x-ui-page-container>
    @endif
</x-ui-page>
@endif
{{-- Spec 53/F Stufe 2: Sprachbefehl-Mount auf Seitenebene (echter Root ist das aeussere <div>, nicht das bedingte <x-ui-page> — Include gehoert deshalb hier, nicht neben das andere </x-ui-page>). --}}
</div>
