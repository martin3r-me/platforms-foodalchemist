{{-- Spec 80 Teil D — Fortschritt als Baum · Gericht-Cluster · Rezept (abgenommen am Mockup 09.10.).
     Links die Gliederung wie in der Ausgabe angelegt, Mitte je Gericht ein Cluster mit seinen Basisrezepten als
     Zeilen, rechts der gewählte Eintrag: bewährte Ergebniskarte (alle Aktionen) + Rezeptansicht. Erwartet $lauf. --}}
@php
    $daten = $this->fortschrittDaten($lauf);
    $leiste = $this->fortschrittStufenleiste($lauf->steps);
    $stepLabel = ['geplant' => 'Geplant', 'queued' => 'Wartet', 'running' => 'In Arbeit', 'done' => 'Bereit zur Prüfung', 'freigegeben' => 'Freigegeben', 'verworfen' => 'Verworfen', 'failed' => 'Fehler', 'skipped' => 'Aus dem Bestand'];
    $stepColor = ['geplant' => 'neutral', 'queued' => 'info', 'running' => 'info', 'done' => 'warn', 'freigegeben' => 'ok', 'verworfen' => 'neutral', 'failed' => 'crit', 'skipped' => 'ok'];
    $refRoute = ['gericht' => 'foodalchemist.verkauf.index', 'rezept' => 'foodalchemist.recipes.index', 'concept' => 'foodalchemist.concepts.index'];
    // Zeilen-Chip: Herkunft/Zustand in einem Wort (D6).
    $chip = function ($st) {
        $enr = is_array($st->deferred['enrich'] ?? null) ? $st->deferred['enrich']['status'] ?? null : null;
        return match (true) {
            $st->status === 'skipped' => ['ok', 'aus Bestand'],
            $st->status === 'failed' => ['crit', 'Fehler'],
            $st->status === 'geplant' => ['neutral', 'geplant'],
            in_array($st->status, ['queued', 'running'], true) => ['info', 'läuft'],
            $st->status === 'freigegeben' && in_array($enr, ['queued', 'running'], true) => ['info', 'wird angereichert'],
            $st->status === 'freigegeben' => ['ok', 'freigegeben'],
            $st->status === 'verworfen' => ['neutral', 'verworfen'],
            default => ['warn', 'neu gebaut'],
        };
    };
    $punkt = fn ($st) => match (true) {
        $st->status === 'failed' => 'bg-[var(--fa-crit)]',
        in_array($st->status, ['done', 'geplant'], true) => 'bg-[var(--fa-warn)]',
        in_array($st->status, ['queued', 'running'], true) => 'bg-[var(--fa-info)] motion-safe:animate-pulse',
        $st->status === 'freigegeben' => 'bg-[var(--fa-ok)]',
        default => 'bg-[var(--fa-line-strong)]',
    };
    $kuerzel = function ($st) {
        if ($st->kind === 'gericht' && preg_match('/^\[([A-ZÄÖÜ]{2,4})\]/u', (string) $st->label, $m) === 1) {
            return $m[1];
        }
        return ['gericht' => 'GER', 'rezept' => 'BR', 'concept' => 'KON'][$st->kind] ?? '–';
    };
    $euro = fn ($v) => $v !== null ? number_format((float) $v, 2, ',', '.') . ' €' : '–';
    $ek = fn ($st) => $st->ref_id !== null ? (($kalkulation ?? [])[$st->ref_id]['ek_total'] ?? null) : null;
    $auswahl = $fortschrittAuswahl !== null ? $lauf->steps->firstWhere('id', $fortschrittAuswahl) : null;
    $auswahl ??= $daten['cluster'][0]['kopf'] ?? null;
    $rezept = $this->fortschrittRezept($auswahl);
    $laufFailedGenerierbar = $lauf->steps->where('status', 'failed')->whereIn('kind', ['rezept', 'gericht', 'concept'])->count();
    $offen = $lauf->steps->where('status', 'done')->count();
    $hatBaum = ! empty($daten['baum']['kinder']);
    $filterLabel = ['alle' => 'Alle', 'pruefen' => 'Zu prüfen', 'fehler' => 'Fehler', 'bestand' => 'Aus Bestand'];
    $klein = 'text-[length:var(--fa-text-sm)]';
@endphp
<x-foodalchemist::modal-section icon="heroicon-o-queue-list" title="Fortschritt">
    <div class="flex flex-col gap-3 min-w-0" data-fortschritt-neu>
        @if($lauf->brief)
            <p class="{{ $klein }} text-[var(--fa-ink-2)] line-clamp-2 break-words"><span class="font-medium text-[var(--fa-ink)]">Auftrag:</span> {{ \Illuminate\Support\Str::limit($lauf->brief, 200) }}</p>
        @endif

        {{-- Kopf: Stufenleiste · Filter · Sammelaktionen --}}
        <div class="flex flex-wrap items-center justify-between gap-3 rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] bg-[var(--fa-surface)] px-3 py-2">
            <div class="flex flex-wrap items-center gap-1.5" aria-label="Stufen">
                @foreach($leiste as $i => $l)
                    @if($i > 0)<span class="text-[var(--fa-ink-3)]" aria-hidden="true">→</span>@endif
                    <span class="inline-flex items-center gap-2 rounded-[var(--fa-radius-control)] bg-[var(--fa-ground)] px-2.5 py-1 {{ $klein }}">
                        {{ $l['label'] }} <b class="tabular-nums">{{ $l['fertig'] }}/{{ $l['gesamt'] }}</b>
                        <span class="h-1 w-12 overflow-hidden rounded-full bg-[var(--fa-line)]"><span class="block h-full bg-[var(--fa-accent)]" style="width: {{ $l['gesamt'] > 0 ? round($l['fertig'] / $l['gesamt'] * 100) : 0 }}%"></span></span>
                    </span>
                @endforeach
            </div>
            <div class="flex flex-wrap items-center gap-1.5" role="group" aria-label="Filter">
                @foreach($filterLabel as $f => $text)
                    <button type="button" wire:click="setzeFortschrittFilter('{{ $f }}')" aria-pressed="{{ $fortschrittFilter === $f ? 'true' : 'false' }}"
                        class="rounded-full border px-3 py-1 {{ $klein }} {{ $fortschrittFilter === $f ? 'border-[var(--fa-accent-line)] bg-[var(--fa-accent-soft)] text-[var(--fa-accent)]' : 'border-[var(--fa-line)] text-[var(--fa-ink-2)] hover:bg-[var(--fa-hover)]' }}"
                        data-fortschritt-filter="{{ $f }}">{{ $text }}</button>
                @endforeach
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @if($offen > 0)
                <x-foodalchemist::ki-action action="alleNeuenAnreichern()" target="alleNeuenAnreichern" icon="heroicon-o-sparkles" variant="ai"
                    label="Alle neu gebauten anreichern und freigeben ({{ $offen }})" busy="Wird angereichert …" flash="Anreicherung gestartet" data-planung-alle-anreichern />
            @endif
            @if($laufFailedGenerierbar > 0)
                <x-foodalchemist::ki-action action="laufWiederAufnehmen()" target="laufWiederAufnehmen" icon="heroicon-o-arrow-path" variant="ghostXs"
                    label="Gescheiterte Schritte fortsetzen ({{ $laufFailedGenerierbar }})" busy="Wird fortgesetzt …" flash="Fortsetzung eingereiht" data-planung-resume-btn />
            @endif
            <button type="button" wire:click="fortschrittAnsichtUmschalten" class="ml-auto {{ $klein }} text-[var(--fa-ink-3)] underline-offset-2 hover:underline" data-fortschritt-klassisch>Klassische Ansicht</button>
        </div>

        <div class="grid grid-cols-1 gap-4 {{ $hatBaum ? 'xl:grid-cols-[minmax(200px,0.55fr)_minmax(0,1.1fr)_minmax(0,1fr)] lg:grid-cols-[minmax(0,1.1fr)_minmax(0,1fr)]' : 'lg:grid-cols-[minmax(0,1.1fr)_minmax(0,1fr)]' }}">
            {{-- Baum (D2) --}}
            @if($hatBaum)
                <nav class="self-start rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] bg-[var(--fa-surface)] p-2 lg:col-span-2 xl:col-span-1 xl:sticky xl:top-3" aria-label="Gliederung" data-fortschritt-baum>
                    <button type="button" wire:click="waehleKnoten(null)"
                        class="mb-1 flex w-full items-center justify-between rounded-[var(--fa-radius-control)] px-2 py-1.5 text-left text-[length:var(--fa-text-md)] {{ $fortschrittKnoten === null ? 'bg-[var(--fa-accent-soft)] text-[var(--fa-accent)]' : 'hover:bg-[var(--fa-hover)]' }}">
                        Alle Ergebnisse
                    </button>
                    @include('foodalchemist::livewire.planung.partials.fortschritt.knoten', ['knoten' => $daten['baum']['kinder'], 'tiefe' => 0])
                </nav>
            @endif

            {{-- Cluster (D3) --}}
            <div class="flex min-w-0 flex-col gap-3" data-fortschritt-cluster>
                @if($daten['pfad'] !== [])
                    <p class="{{ $klein }} text-[var(--fa-ink-3)]">{!! collect($daten['pfad'])->map(fn ($p, $i) => $i === count($daten['pfad']) - 1 ? '<b class="font-medium text-[var(--fa-ink)]">' . e($p) . '</b>' : e($p))->implode(' › ') !!}</p>
                @endif
                @forelse($daten['cluster'] as $c)
                    @php $kopf = $c['kopf']; @endphp
                    <div class="overflow-hidden rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] bg-[var(--fa-surface)]" wire:key="fc-{{ $kopf->id }}">
                        <button type="button" wire:click="waehleSchritt({{ $kopf->id }})"
                            class="grid w-full grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-2.5 px-3 py-2.5 text-left {{ $auswahl?->id === $kopf->id ? 'bg-[var(--fa-accent-soft)]' : 'hover:bg-[var(--fa-hover)]' }}"
                            data-fortschritt-kopf="{{ $kopf->id }}">
                            <span class="rounded border border-[var(--fa-line)] bg-[var(--fa-ground)] px-1.5 font-mono text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]">{{ $kuerzel($kopf) }}</span>
                            <span class="truncate font-medium text-[var(--fa-ink)]" title="{{ $kopf->label }}">{{ $kopf->label }}</span>
                            <span class="flex items-center gap-2 {{ $klein }} tabular-nums text-[var(--fa-ink-3)]">
                                @if($ek($kopf) !== null)EK {{ $euro($ek($kopf)) }}@endif
                                @php [$ton, $txt] = $chip($kopf); @endphp
                                <x-fa::badge :tone="$ton">{{ $txt }}</x-fa::badge>
                            </span>
                        </button>
                        @foreach($c['zeilen'] as $z)
                            @php [$ton, $txt] = $chip($z); @endphp
                            <button type="button" wire:click="waehleSchritt({{ $z->id }})" wire:key="fz-{{ $z->id }}"
                                class="grid w-full grid-cols-[14px_minmax(0,1fr)_auto_4.5rem_10px] items-center gap-2.5 border-t border-[var(--fa-line)] py-2 pl-6 pr-3 text-left {{ $klein }} {{ $auswahl?->id === $z->id ? 'bg-[var(--fa-accent-soft)]' : 'hover:bg-[var(--fa-hover)]' }}"
                                data-fortschritt-zeile="{{ $z->id }}">
                                <span class="font-mono text-[var(--fa-line-strong)]" aria-hidden="true">└</span>
                                <span class="truncate text-[var(--fa-ink)]" title="{{ $z->label }}">{{ $z->label }}</span>
                                <x-fa::badge :tone="$ton">{{ $txt }}</x-fa::badge>
                                <span class="text-right tabular-nums text-[var(--fa-ink-2)]">{{ $euro($ek($z)) }}</span>
                                <span class="h-2 w-2 rounded-full {{ $punkt($z) }}" aria-hidden="true"></span>
                            </button>
                        @endforeach
                        <div class="flex flex-wrap items-center justify-between gap-2 border-t border-[var(--fa-line)] bg-[var(--fa-ground)] px-3 py-2 {{ $klein }} text-[var(--fa-ink-3)]">
                            <span>{{ count($c['zeilen']) }} {{ count($c['zeilen']) === 1 ? 'Basisrezept' : 'Basisrezepte' }} · {{ $c['offen'] }} offen{{ $c['fehler'] > 0 ? ' · ' . $c['fehler'] . ' Fehler' : '' }}</span>
                            @if($c['offen'] > 0)
                                <x-foodalchemist::ki-action action="clusterFreigeben({{ $kopf->id }})" target="clusterFreigeben" icon="heroicon-o-check" variant="ghostXs"
                                    label="{{ $kopf->kind === 'gericht' ? 'Gericht + Basisrezepte' : 'Rezept + Unterrezepte' }} anreichern und freigeben" busy="Wird angereichert …" flash="Anreicherung gestartet" />
                            @endif
                        </div>
                    </div>
                @empty
                    <x-fa::empty icon="heroicon-o-funnel" title="Nichts in dieser Auswahl">Anderen Filter oder „Alle Ergebnisse“ wählen.</x-fa::empty>
                @endforelse
            </div>

            {{-- Rechts: gewählter Eintrag (D4) --}}
            <aside class="flex min-w-0 flex-col gap-3 self-start lg:sticky lg:top-3 lg:max-h-[calc(100vh-1.5rem)] lg:overflow-y-auto" data-fortschritt-detail>
                @if($auswahl !== null)
                    @include('foodalchemist::livewire.planung.partials.step-zeile', ['st' => $auswahl, 'stepLabel' => $stepLabel, 'stepColor' => $stepColor, 'refRoute' => $refRoute, 'indent' => '', 'kalkulation' => $kalkulation ?? [], 'bildCalls' => $bildCalls ?? [], 'bilderAngefordert' => $bilderAngefordert ?? false, 'fotoCounts' => $fotoCounts ?? [], 'fotoPickerStep' => $fotoPickerStep ?? null, 'fotoPickerKandidaten' => $fotoPickerKandidaten ?? [], 'konformitaet' => $konformitaet ?? [], 'gpKonformitaet' => $gpKonformitaet ?? []])
                    @if($rezept !== null)
                        @include('foodalchemist::livewire.planung.partials.fortschritt.rezept', ['rezept' => $rezept])
                    @endif
                @else
                    <p class="{{ $klein }} text-[var(--fa-ink-3)]">Einen Eintrag links wählen.</p>
                @endif
            </aside>
        </div>
    </div>
</x-foodalchemist::modal-section>
