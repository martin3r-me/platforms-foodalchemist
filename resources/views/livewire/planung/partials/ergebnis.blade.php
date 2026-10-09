{{-- Ergebnis der Planung: Stufen-Abschnitte (Concept · Gerichte · Basisrezepte) mit Fortschritt und
     Stufen-Freigabe. Nur erreichte Stufen erscheinen (progressive Enthüllung). Erwartet $lauf gesetzt.
     Jedes Ergebnis ist eine eigene Karte (step-zeile); Basisrezepte hängen als Baum unter ihrem Gericht.
     Laptop-tauglich: alle Zeilen umbrechen statt zu kürzen, Namen werden nie abgeschnitten. --}}
@php
    // Status je Ergebnis: Text + Ton für x-fa::badge. `done` = Entwurf fertig, wartet auf Prüfung und Freigabe.
    $stepLabel = ['geplant' => 'Geplant', 'queued' => 'Wartet', 'running' => 'In Arbeit', 'done' => 'Bereit zur Prüfung', 'freigegeben' => 'Freigegeben', 'verworfen' => 'Verworfen', 'failed' => 'Fehler', 'skipped' => 'Aus dem Bestand'];
    $stepColor = ['geplant' => 'neutral', 'queued' => 'info', 'running' => 'info', 'done' => 'warn', 'freigegeben' => 'ok', 'verworfen' => 'neutral', 'failed' => 'crit', 'skipped' => 'ok'];
    $refRoute = ['gericht' => 'foodalchemist.verkauf.index', 'rezept' => 'foodalchemist.recipes.index', 'concept' => 'foodalchemist.concepts.index'];
    $laufRunning = $lauf->steps->whereIn('status', ['queued', 'running'])->count();
    $laufDone = $lauf->steps->whereIn('status', ['done', 'freigegeben'])->count();
    $laufFailed = $lauf->steps->where('status', 'failed')->count();
    // Idempotenz/Resume (Etappe 8 Teil 3): gescheiterte GENERIERBARE Steps (rezept|gericht|concept)
    // — nur diese trägt setzeLaufFort wieder auf (GP-/Referenz-Steps haben keinen Generator).
    $laufFailedGenerierbar = $lauf->steps->where('status', 'failed')->whereIn('kind', ['rezept', 'gericht', 'concept'])->count();
    $offeneEntwuerfe = $lauf->steps->where('status', 'done')->count();
    $stufen = $this->stufenAusSteps($lauf->steps);
    $zustandTon = ['läuft' => ['info', 'Läuft'], 'prüfen' => ['warn', 'Zu prüfen'], 'geplant' => ['neutral', 'Geplant'], 'erledigt' => ['ok', 'Erledigt'], 'fehlgeschlagen' => ['crit', 'Fehlgeschlagen']];
    $stufeIcon = ['concept' => 'heroicon-o-squares-2x2', 'gericht' => 'heroicon-o-cake', 'rezept' => 'heroicon-o-beaker'];
    $stepArgs = fn ($s, $indent) => ['st' => $s, 'stepLabel' => $stepLabel, 'stepColor' => $stepColor, 'refRoute' => $refRoute, 'indent' => $indent, 'kalkulation' => $kalkulation ?? [], 'bildCalls' => $bildCalls ?? [], 'bilderAngefordert' => $bilderAngefordert ?? false, 'fotoCounts' => $fotoCounts ?? [], 'fotoPickerStep' => $fotoPickerStep ?? null, 'fotoPickerKandidaten' => $fotoPickerKandidaten ?? [], 'konformitaet' => $konformitaet ?? [], 'gpKonformitaet' => $gpKonformitaet ?? []];
    // Anreicherungs-Bilanz über die freigegebenen Rezept-/Gericht-Steps (deferred.enrich) — damit der
    // Abschluss ehrlich meldet: freigegeben + angereichert, oder Anreicherung läuft/fehlgeschlagen.
    $freigegebenGesamt = $lauf->steps->where('status', 'freigegeben')->count();
    $anrDone = 0; $anrFehler = 0; $anrOffen = 0;
    foreach ($lauf->steps as $s) {
        if ($s->status !== 'freigegeben' || ! in_array($s->kind, ['rezept', 'gericht'], true)) { continue; }
        $enr = (is_array($s->deferred) && is_array($s->deferred['enrich'] ?? null)) ? $s->deferred['enrich'] : [];
        $es = $enr['status'] ?? null;
        if ($es === 'done') { $anrDone++; }
        elseif ($es === 'failed') { $anrFehler++; }
        elseif (in_array($es, ['queued', 'running'], true)) { $anrOffen++; }
    }
    // Terminal-Endzustand: nichts läuft mehr, keine offenen Entwürfe, aber freigegebene Artefakte da.
    $terminal = $laufRunning === 0 && $offeneEntwuerfe === 0 && $freigegebenGesamt > 0;
    // Spec 53 / Paket C: aktuelle Phase des JÜNGSTEN Steps mit gesetzter Phase (nach phase_at).
    $aktuellePhase = $lauf->steps->whereNotNull('phase')->sortByDesc('phase_at')->first()?->phase;

    // Deutsche Mengenwörter statt „Entwurf/Entwürfe".
    $ergebnisse = fn (int $n) => $n === 1 ? '1 Ergebnis' : $n . ' Ergebnisse';
    $schritte = fn (int $n) => $n === 1 ? '1 Schritt' : $n . ' Schritte';

    // Baum je Stufe: Kinder hängen unter ihrem Eltern-Ergebnis derselben Stufe (Ebene +1). Ergebnisse,
    // deren Eltern in einer ANDEREN Stufe stehen (Basisrezept → Gericht), werden unter dem Namen dieses
    // Eltern-Ergebnisses gruppiert. Reihenfolge bleibt die der Kaskade (depth, sort). Reine Anzeige.
    $alleSteps = $lauf->steps->keyBy('id');
    $baumFuer = function ($stufeSteps) use ($alleSteps) {
        $ids = $stufeSteps->pluck('id')->map(fn ($i) => (int) $i)->all();
        $kinder = [];
        foreach ($stufeSteps as $s) {
            $p = (int) ($s->parent_step_id ?? 0);
            $kinder[in_array($p, $ids, true) ? $p : 0][] = $s;
        }
        $gesehen = [];
        $flach = function (int $eltern, int $ebene) use (&$flach, &$kinder, &$gesehen): array {
            $out = [];
            foreach ($kinder[$eltern] ?? [] as $s) {
                if (isset($gesehen[(int) $s->id])) { continue; }
                $gesehen[(int) $s->id] = true;
                $out[] = [$s, $ebene];
                if ($ebene < 3) { $out = array_merge($out, $flach((int) $s->id, $ebene + 1)); }
            }
            return $out;
        };
        // Wurzeln nach ihrem stufenfremden Eltern-Ergebnis gruppieren (Reihenfolge des ersten Auftretens).
        $gruppen = [];
        foreach ($kinder[0] ?? [] as $wurzel) {
            if (isset($gesehen[(int) $wurzel->id])) { continue; }
            $p = (int) ($wurzel->parent_step_id ?? 0);
            $schluessel = $p > 0 && $alleSteps->has($p) ? $p : 0;
            $gruppen[$schluessel] ??= ['eltern' => $schluessel > 0 ? $alleSteps->get($p) : null, 'zeilen' => []];
            $gesehen[(int) $wurzel->id] = true;
            $gruppen[$schluessel]['zeilen'][] = [$wurzel, 0];
            $gruppen[$schluessel]['zeilen'] = array_merge($gruppen[$schluessel]['zeilen'], $flach((int) $wurzel->id, 1));
        }
        // Sicherheitsnetz: nichts darf verschwinden (z. B. tiefer als drei Ebenen).
        foreach ($stufeSteps as $s) {
            if (! isset($gesehen[(int) $s->id])) {
                $gruppen[0] ??= ['eltern' => null, 'zeilen' => []];
                $gruppen[0]['zeilen'][] = [$s, 0];
            }
        }
        return $gruppen;
    };
    $einzug = [0 => '', 1 => 'ml-2 pl-3 border-l-2 border-[var(--fa-line-strong)]', 2 => 'ml-5 pl-3 border-l-2 border-[var(--fa-line-strong)]', 3 => 'ml-8 pl-3 border-l-2 border-[var(--fa-line-strong)]'];
    $kindName = ['concept' => 'Concept', 'gericht' => 'Gericht', 'rezept' => 'Basisrezept'];

    // Gesamtzustand als EINE Zeile (Ton + Symbol + Satz).
    $gesamt = null;
    if ($laufRunning > 0 && ($hinweis ?? null) !== null) {
        $gesamt = ['warn', 'heroicon-o-exclamation-triangle', false, 'Die Hintergrund-Erstellung reagiert nicht. ' . $schritte($laufRunning) . ' warten.'];
    } elseif ($laufRunning > 0) {
        $gesamt = ['info', 'heroicon-o-arrow-path', true, $schritte($laufRunning) . ' in Arbeit' . ($laufDone > 0 ? ', ' . $laufDone . ' fertig' : '') . '.' . ($aktuellePhase ? ' ' . $aktuellePhase : '')];
    } elseif ($offeneEntwuerfe > 0) {
        $gesamt = ['warn', 'heroicon-o-eye', false, ($offeneEntwuerfe === 1 ? '1 Ergebnis wartet' : $offeneEntwuerfe . ' Ergebnisse warten') . ' auf deine Prüfung. Ansehen, dann stufenweise freigeben.'];
    } elseif ($terminal) {
        $gesamt = ['ok', 'heroicon-o-check-badge', false, 'Abgeschlossen: ' . $freigegebenGesamt . ' freigegeben' . ($anrOffen > 0 ? ', Anreicherung läuft …' : ($anrDone > 0 ? ' und angereichert.' : '.'))];
    } elseif ($laufFailed > 0) {
        $gesamt = ['crit', 'heroicon-o-x-circle', false, 'Fehlgeschlagen. Die Gründe stehen am jeweiligen Ergebnis.'];
    }
    $tonFlaeche = ['ok' => 'bg-[var(--fa-ok-soft)] text-[var(--fa-ok)]', 'warn' => 'bg-[var(--fa-warn-soft)] text-[var(--fa-warn)]', 'crit' => 'bg-[var(--fa-crit-soft)] text-[var(--fa-crit)]', 'info' => 'bg-[var(--fa-info-soft)] text-[var(--fa-info)]'];
    $menuePunkt = 'flex w-full items-center gap-2 px-3 py-2 text-left text-[length:var(--fa-text-md)] text-[var(--fa-ink)] hover:bg-[var(--fa-hover)]';
@endphp
<x-foodalchemist::modal-section icon="heroicon-o-queue-list" title="Stufen und Freigabe">
    <div class="flex flex-col gap-3 min-w-0">
        @if($lauf->brief)
            <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)] line-clamp-2 break-words"><span class="font-medium text-[var(--fa-ink)]">Auftrag:</span> {{ \Illuminate\Support\Str::limit($lauf->brief, 200) }}</p>
        @endif

        {{-- Gesamtzustand (aus den Steps abgeleitet, kein globaler Ping). --}}
        @if($gesamt)
            <div class="flex items-start gap-2 rounded-[var(--fa-radius-control)] px-3 py-2 text-[length:var(--fa-text-md)] font-medium {{ $tonFlaeche[$gesamt[0]] }}" role="status">
                @svg($gesamt[1], 'w-4 h-4 shrink-0 mt-0.5' . ($gesamt[2] ? ' animate-spin' : ''))
                <span class="min-w-0 break-words">{{ $gesamt[3] }}</span>
            </div>
        @endif
        @if($terminal && $anrFehler > 0)
            <x-fa::signal tone="crit">{{ $anrFehler === 1 ? '1 Anreicherung ist' : $anrFehler . ' Anreicherungen sind' }} fehlgeschlagen. Am Ergebnis erneut anstoßen.</x-fa::signal>
        @endif

        {{-- Idempotenz/Resume (Etappe 8 Teil 3): gescheiterte Schritte gebündelt fortsetzen. Auch im
             gemischten Zustand sichtbar, deshalb bewusst außerhalb der Status-Kette. --}}
        @if($laufFailedGenerierbar > 0)
            <div class="flex flex-wrap items-center gap-x-3 gap-y-2" data-planung-resume>
                <x-fa::signal tone="crit">{{ $laufFailedGenerierbar === 1 ? '1 Schritt ist' : $laufFailedGenerierbar . ' Schritte sind' }} gescheitert.</x-fa::signal>
                <x-foodalchemist::ki-action action="laufWiederAufnehmen()" target="laufWiederAufnehmen" icon="heroicon-o-arrow-path" variant="ghostXs"
                    label="Gescheiterte Schritte fortsetzen" busy="Wird fortgesetzt …" flash="Fortsetzung eingereiht"
                    data-planung-resume-btn />
            </div>
        @endif

        @if($laufRunning > 0 && $freigegebenGesamt > 0)
            <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Eine Stufe ist freigegeben, die nächste entsteht gerade. Jede Stufe wird für sich freigegeben.</p>
        @endif

        {{-- Stufen-Abschnitte: je Ebene Kopf mit Fortschritt, die Ergebnis-Karten als Baum, unten die Stufen-Freigabe. --}}
        <div class="flex flex-col gap-3">
            @forelse($stufen as $stufe)
                @php
                    $stufeSteps = $lauf->steps->where('kind', $stufe['kind'])->sortBy([['depth', 'asc'], ['sort', 'asc']]);
                    $gruppen = $baumFuer($stufeSteps);
                    [$zTon, $zText] = $zustandTon[$stufe['zustand']] ?? ['neutral', ucfirst((string) $stufe['zustand'])];
                    $teile = [$stufe['fertig'] . ' von ' . $stufe['total'] . ' fertig'];
                    if ($stufe['freigegeben'] > 0) { $teile[] = $stufe['freigegeben'] . ' freigegeben'; }
                    if (($stufe['geplant'] ?? 0) > 0) { $teile[] = $stufe['geplant'] . ' geplant'; }
                    if (($stufe['uebernommen'] ?? 0) > 0) { $teile[] = $stufe['uebernommen'] . ' aus dem Bestand'; }
                    $geplantN = (int) ($stufe['geplant'] ?? 0);
                @endphp
                <div wire:key="stufe-{{ $stufe['kind'] }}" x-data="{ busy: null }" class="rounded-[var(--fa-radius-surface)] border border-[var(--fa-line)] bg-[var(--fa-ground)] p-3 flex flex-col gap-3 min-w-0" data-planung-stufe="{{ $stufe['kind'] }}">
                    <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1.5">
                        <h4 class="flex items-center gap-2 text-[length:var(--fa-text-base)] font-semibold text-[var(--fa-ink)]">
                            @svg($stufeIcon[$stufe['kind']] ?? 'heroicon-o-queue-list', 'w-4 h-4 shrink-0 text-[var(--fa-ink-3)]')
                            {{ $stufe['label'] }}
                            <x-fa::badge :tone="$zTon">{{ $zText }}</x-fa::badge>
                        </h4>
                        <span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)] tabular-nums">{{ implode(' · ', $teile) }}</span>
                    </div>
                    @if($geplantN > 0)
                        {{-- Gericht = Basisrezepte: die Sub-Rezepte stehen schon als eigene Stufe, erzeugt
                             werden sie mit der Freigabe der Stufe darüber (gestufte Kaskade). --}}
                        <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">{{ $geplantN === 1 ? '1 Basisrezept ist' : $geplantN . ' Basisrezepte sind' }} geplant. Sie entstehen, sobald die Stufe darüber freigegeben ist.</p>
                    @endif

                    <div class="flex flex-col gap-2 min-w-0">
                        @foreach($gruppen as $gruppenSchluessel => $gruppe)
                            @if($gruppe['eltern'])
                                {{-- Baum: Basisrezepte unter ihrem Gericht, mit Führungslinie. --}}
                                <div class="flex flex-col gap-2 min-w-0" wire:key="stufe-{{ $stufe['kind'] }}-zu-{{ $gruppenSchluessel }}" data-planung-baum-eltern="{{ $gruppenSchluessel }}">
                                    <p class="flex flex-wrap items-center gap-1.5 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]">
                                        @svg('heroicon-m-arrow-turn-down-right', 'w-4 h-4 shrink-0 text-[var(--fa-ink-3)]')
                                        Für {{ $kindName[$gruppe['eltern']->kind] ?? 'Ergebnis' }}
                                        <span class="font-semibold text-[var(--fa-ink)] break-words">{{ $gruppe['eltern']->label ?: ($kindName[$gruppe['eltern']->kind] ?? ucfirst((string) $gruppe['eltern']->kind)) }}</span>
                                    </p>
                                    <div class="ml-2 pl-3 border-l-2 border-[var(--fa-line-strong)] flex flex-col gap-2 min-w-0">
                                        @foreach($gruppe['zeilen'] as $zeile)
                                            <div class="{{ $einzug[min((int) $zeile[1], 3)] }} min-w-0">
                                                @include('foodalchemist::livewire.planung.partials.step-zeile', $stepArgs($zeile[0], (int) $zeile[0]->depth > 0))
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @else
                                @foreach($gruppe['zeilen'] as $zeile)
                                    <div class="{{ $einzug[min((int) $zeile[1], 3)] }} min-w-0">
                                        @include('foodalchemist::livewire.planung.partials.step-zeile', $stepArgs($zeile[0], (int) $zeile[0]->depth > 0))
                                    </div>
                                @endforeach
                            @endif
                        @endforeach
                    </div>

                    @if($stufe['kind'] === 'rezept')
                        {{-- Manuell ein Basisrezept ergänzen (T2): den geplanten Sub-Step legt der Motor an,
                             „Jetzt erzeugen" (je Karte) generiert ihn. Für Sub-Rezepte, die die KI nicht erkannt hat. --}}
                        <div class="pt-3 border-t border-[var(--fa-line)] flex flex-wrap items-center gap-2" data-planung-sub-ergaenzen>
                            <x-fa::input size="sm" wire:model="neuerSubName" wire:keydown.enter="ergaenzeSubRezept"
                                placeholder="Fehlendes Basisrezept, z. B. Schweinejus" aria-label="Fehlendes Basisrezept" class="flex-1 min-w-[12rem]" />
                            <x-foodalchemist::ki-action action="ergaenzeSubRezept()" target="ergaenzeSubRezept" icon="heroicon-o-plus" variant="ghostXs" label="Basisrezept ergänzen"
                                busy="Wird ergänzt …" flash="Ergänzt" class="shrink-0" />
                        </div>
                    @endif
                    @if($stufe['zustand'] === 'prüfen')
                        <div class="pt-3 border-t border-[var(--fa-line)] flex flex-wrap items-center justify-end gap-x-3 gap-y-2">
                            <p class="flex-1 min-w-[12rem] text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Gibt alle geprüften Ergebnisse dieser Stufe frei. Danach entsteht die nächste Stufe.</p>
                            <x-foodalchemist::ki-action action="gibStufeFrei('{{ $stufe['kind'] }}')" icon="heroicon-o-check-badge" variant="primary"
                                label="Ganze Stufe freigeben" busy="Wird freigegeben …" flash="Stufe freigegeben" />
                        </div>
                    @endif
                </div>
            @empty
                <div class="fa-surface">
                    <x-fa::empty compact icon="heroicon-o-queue-list" title="Noch keine Schritte">Sobald die Erstellung startet, erscheinen hier die Ergebnisse Stufe für Stufe.</x-fa::empty>
                </div>
            @endforelse
        </div>

        {{-- Sammel-Freigabe über alle Stufen (Ausweg, wenn nicht stufenweise geprüft wird). --}}
        @if($offeneEntwuerfe > 0)
            <div x-data="{ busy: null }" class="flex flex-wrap items-center justify-between gap-x-3 gap-y-2 pt-3 border-t border-[var(--fa-line)]" data-planung-sammel-freigabe>
                <span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]">{{ $ergebnisse($offeneEntwuerfe) }} noch offen</span>
                <div class="flex flex-wrap items-center gap-2">
                    <div class="relative" x-data="faMenu()" x-on:keydown.escape="offen = false" x-on:click.outside="offen = false">
                        <x-fa::icon-button icon="heroicon-m-ellipsis-horizontal" label="Weitere Aktionen" size="sm" x-on:click="toggle($event)" aria-haspopup="menu" x-bind:aria-expanded="offen" />
                        <div x-bind:class="{ hidden: ! offen }" x-bind:style="pos" role="menu" class="hidden w-64 fa-surface shadow-lg py-1">
                            <button type="button" role="menuitem" wire:click="alleVerwerfen" x-on:click="offen = false"
                                    wire:confirm="Alle offenen Ergebnisse dieses Laufs wirklich verwerfen?"
                                    class="flex w-full items-center gap-2 px-3 py-2 text-left text-[length:var(--fa-text-md)] text-[var(--fa-crit)] hover:bg-[var(--fa-crit-soft)]" data-planung-alle-verwerfen>
                                @svg('heroicon-o-trash', 'w-4 h-4') Alle offenen verwerfen
                            </button>
                        </div>
                    </div>
                    {{-- Spec 80 H3: erst prüfen, dann anreichern — die Freigabe reichert an, grün wird es danach.
                         Entwürfe mit offenem harten Regelwerk-Befund bleiben stehen. --}}
                    <x-foodalchemist::ki-action action="alleNeuenAnreichern()" target="alleNeuenAnreichern" icon="heroicon-o-sparkles" variant="ghostXs"
                        label="Alle neu gebauten anreichern und freigeben" busy="Wird angereichert …" flash="Anreicherung gestartet" data-planung-alle-anreichern />
                </div>
            </div>
        @endif
    </div>
</x-foodalchemist::modal-section>
