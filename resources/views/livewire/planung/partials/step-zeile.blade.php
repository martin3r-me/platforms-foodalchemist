{{-- Ergebnis-Karte der Planung: EIN Schritt der Kaskade (Concept, Gericht oder Basisrezept; Kind via $indent).
     Kopf: Art, lesbarer Name, Status-Chip, EINE Hauptaktion (Freigeben bzw. Jetzt erzeugen), KI-Assistent,
     Weitere Aktionen. Darunter: Kalkulation, Bauplan/Menü-Aufbau, Fotos, Hinweise, Zutaten, verwendetes Wissen
     (context_snapshot, je Step persistiert) und Regelwerk-Hinweise.
     Erwartet: $st, $stepLabel, $stepColor (Ton), $refRoute, $indent. Laptop: alles bricht um, nichts wird gekürzt. --}}
@php
    $indent = $indent ?? false;
    $snap = is_array($st->context_snapshot) ? $st->context_snapshot : [];
    // Verwendetes Wissen — ZWEI Kanäle, getrennt persistiert (RecipeGenerationContextService::build):
    // `kanon_files` = verbindlich vorgegeben (Prompt-Key), `knowledge_files` = nachgeschlagen (Suche).
    // Alte Snapshots (vor 2026-09-06) haben nur `knowledge_files` → die verbindliche Gruppe bleibt leer.
    $wissenFiles = (array) ($snap['knowledge_files'] ?? []);
    $kanonFiles = (array) ($snap['kanon_files'] ?? []);
    $wissenGesamt = count($kanonFiles) + count($wissenFiles);
    // "slug@vN" / "graph:anker" / Alt-Pfad "pairings/tomate.md" → lesbarer Chip. Bewusst NICHT
    // `beforeLast('.')`: Slugs wie `workflow.basisrezept_erstellungs_dossier` enthalten Punkte.
    $wissenChip = fn (string $e): string => (string) preg_replace(
        ['/^graph:/', '/\.md$/', '#^.*/#'], '', explode('@', $e, 2)[0]
    );
    // B3 (2026-08-20): Hardstop-Zeile aus dem »Nur Bestand«-Fanout (kein DB-Treffer). status ist
    // technisch `skipped`, darf aber NICHT als „übernommen" gelesen werden — eigener Warnhinweis.
    $hardstop = (is_array($st->deferred) && is_array($st->deferred['hardstop'] ?? null)) ? $st->deferred['hardstop'] : null;
    // 2026-09-07: Reifegrad einer ÜBERNAHME. Nur ein produktionsreifes Bestands-Rezept ist auch fertig.
    $reuse = (is_array($st->deferred) && is_array($st->deferred['reuse'] ?? null)) ? $st->deferred['reuse'] : null;
    $reuseUnreif = $st->status === 'skipped' && $reuse !== null && ! ($reuse['reif'] ?? false);

    $kindName = ['concept' => 'Concept', 'gericht' => 'Gericht', 'rezept' => 'Basisrezept'][$st->kind] ?? ucfirst((string) $st->kind);
    $kindIcon = ['concept' => 'heroicon-o-squares-2x2', 'gericht' => 'heroicon-o-cake', 'rezept' => 'heroicon-o-beaker'][$st->kind] ?? 'heroicon-o-document';
    $name = $st->label ?: $kindName;
    $laeuftGerade = in_array($st->status, ['queued', 'running'], true);
    $statusTon = $reuseUnreif ? 'warn' : ($stepColor[$st->status] ?? 'neutral');
    $statusText = $reuseUnreif ? 'Bestand unfertig' : ($stepLabel[$st->status] ?? ucfirst((string) $st->status));
    $genMs = $st->status === 'done' ? ($snap['timings']['generator_ms'] ?? null) : null;
    $kommentarIstOffen = in_array($st->id, $kommentarOffen ?? [], true);
    $enr = (is_array($st->deferred) && is_array($st->deferred['enrich'] ?? null)) ? $st->deferred['enrich'] : null;
    $enrStatus = $enr['status'] ?? null;

    // Aktionen dieser Karte (reine Anzeige-Logik, gleiche Bedingungen wie bisher).
    $kannAnsehen = $st->ref_id && in_array($st->status, ['done', 'freigegeben', 'skipped'], true);
    $kannFeedback = in_array($st->status, ['done', 'failed'], true) && in_array($st->kind, ['rezept', 'gericht', 'concept'], true);
    $istIdee = $st->status === 'geplant' && $st->kind === 'gericht' && ! empty($snap['dish_idea_id']);
    $kannKonf = $st->ref_type === 'recipe' && $st->ref_id && in_array($st->status, ['done', 'freigegeben', 'skipped'], true);
    $kannRueckmeldung = $st->ref_id && in_array($st->kind, ['rezept', 'gericht'], true) && in_array($st->status, ['done', 'freigegeben'], true);
    $kiAnzahl = ($kannFeedback ? 2 : 0) + ($kannKonf ? 1 : 0);
    $hatWeitere = $kannRueckmeldung || $st->status === 'done' || $st->status === 'geplant';

    $menuePunkt = 'flex w-full items-center gap-2 px-3 py-2 text-left text-[length:var(--fa-text-md)] text-[var(--fa-ink)] hover:bg-[var(--fa-hover)] disabled:opacity-50';
    $menueRot = 'flex w-full items-center gap-2 px-3 py-2 text-left text-[length:var(--fa-text-md)] text-[var(--fa-crit)] hover:bg-[var(--fa-crit-soft)]';
    $textKlein = 'text-[length:var(--fa-text-sm)]';
    $lesbar = fn (?string $w): string => $w === null || $w === '' ? '' : ucfirst(str_replace('_', ' ', $w));
    $statusRand = match (true) {
        $st->status === 'done' => 'border-[var(--fa-warn)]',
        $st->status === 'failed' => 'border-[var(--fa-crit)]',
        default => 'border-[var(--fa-line)]',
    };
@endphp
<div wire:key="step-{{ $st->id }}" x-data="{ busy: null }"
     class="rounded-[var(--fa-radius-surface)] border border-l-4 {{ $statusRand }} bg-[var(--fa-surface)] p-3 flex flex-col gap-2.5 min-w-0"
     data-step-karte="{{ $st->id }}" data-step-status="{{ $st->status }}" @if($indent) data-step-unter @endif>

    {{-- Kopf: Art + Name + Status links, Aktionen rechts (bricht auf schmalen Bildschirmen unter den Namen). --}}
    <div class="flex flex-wrap items-start justify-between gap-x-3 gap-y-2 min-w-0">
        <div class="min-w-0 flex-1 basis-[16rem] flex flex-col gap-1">
            <span class="inline-flex items-center gap-1.5 {{ $textKlein }} text-[var(--fa-ink-3)]">
                @svg($indent ? 'heroicon-m-arrow-turn-down-right' : $kindIcon, 'w-3.5 h-3.5 shrink-0') {{ $kindName }}
            </span>
            <h5 class="text-[length:var(--fa-text-base)] font-semibold leading-snug text-[var(--fa-ink)] break-words">{{ $name }}</h5>
            <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                @if($hardstop)
                    <x-fa::signal tone="warn"
                        title="Die Datenbank hat dafür kein passendes Grundprodukt oder Basisrezept. Zutat am Gericht per Auswahl binden oder den Kreativ-Modus wechseln.">Kein Bestand gefunden, bitte wählen</x-fa::signal>
                @elseif($laeuftGerade)
                    {{-- Spec 53 / Paket C: DB-Wahrheit statt nur „läuft" — die Phase (falls schon gesetzt)
                         ODER „eingereiht" (Job noch nicht angelaufen). Bewusst KEIN x-fa::badge: das Etikett
                         bricht nicht um (whitespace-nowrap) und liefe bei langen Phasen-Texten auf dem Laptop
                         aus der Karte. --}}
                    <span class="inline-flex items-start gap-1 {{ $textKlein }} font-medium text-[var(--fa-info)] break-words min-w-0">
                        @svg('heroicon-o-arrow-path', 'w-3.5 h-3.5 shrink-0 mt-0.5 animate-spin')
                        <span class="min-w-0">{{ $st->phase ?: 'Eingereiht, wartet auf die Hintergrund-Erstellung' }}</span>
                    </span>
                @else
                    <x-fa::badge :tone="$statusTon" data-step-status-chip>{{ $statusText }}</x-fa::badge>
                    @if(is_numeric($genMs))
                        {{-- Paket A liefert generator_ms in context_snapshot.timings — nur anzeigen, wenn da. --}}
                        <span class="{{ $textKlein }} text-[var(--fa-ink-3)] tabular-nums">erstellt in {{ (int) round($genMs / 1000) }} s</span>
                    @endif
                    @if($reuseUnreif)
                        {{-- Was genau fehlt, steht in der Karte — nicht nur „unfertig". --}}
                        <span class="{{ $textKlein }} text-[var(--fa-warn)]" title="Bestands-Rezept ist nicht produktionsreif: {{ implode(' · ', (array) ($reuse['luecken'] ?? [])) }}">{{ ($reuse['luecken'] ?? []) === [] ? 'Nicht produktionsreif' : 'Es fehlt: ' . implode(', ', (array) $reuse['luecken']) }}</span>
                    @endif
                @endif

                {{-- `skipped` mit aufgenommen (2026-09-07): eine unreife eigene Übernahme wird bei der
                     Freigabe mitangereichert und muss ihren Zustand zeigen können. --}}
                @if(in_array($st->status, ['freigegeben', 'skipped'], true) && in_array($st->kind, ['rezept', 'gericht'], true))
                    @if($enrStatus === 'done')
                        {{-- Tiefe ehrlich zeigen (2026-09-07): ein Pass mit complete_coverage=false lässt
                             Schritte, Sensorik, Zeiten, Equipment, Posten und Pairings leer. --}}
                        @if(($enr['tief'] ?? true) === false)
                            <x-fa::signal tone="info" title="Kernfelder gefüllt ({{ $enr['at'] ?? '' }}), ohne Schritte, Sensorik, Zeiten, Equipment und Pairings (Leitplanke „Voll anreichern“ war aus).">Leicht angereichert</x-fa::signal>
                            <x-foodalchemist::ki-action action="neuAnreichern({{ $st->id }}, true)" variant="ai" icon="heroicon-o-sparkles" label="Voll anreichern"
                                busy="Wird angereichert …" flash="Anreicherung eingereiht"
                                title="Jetzt in voller Tiefe anreichern: Schritte, Sensorik, Arbeits- und Rüstzeit, Equipment, Posten, geerdete Pairings." />
                        @else
                            <x-fa::signal tone="ok" title="Voll angereichert {{ $enr['at'] ?? '' }}">Angereichert</x-fa::signal>
                        @endif
                    @elseif(in_array($enrStatus, ['queued', 'running'], true))
                        <span class="inline-flex items-center gap-1 {{ $textKlein }} font-medium text-[var(--fa-info)]">@svg('heroicon-o-arrow-path', 'w-3.5 h-3.5 animate-spin') Wird angereichert …</span>
                    @elseif($enrStatus === 'failed')
                        <x-foodalchemist::ki-action action="neuAnreichern({{ $st->id }})" :error="$enr['error'] ?? null" icon="heroicon-o-arrow-path"
                            retry="Anreicherung fehlgeschlagen, erneut anreichern" busy="Wird erneut angereichert …"
                            flash="Anreicherung eingereiht" class="{{ $textKlein }}" />
                    @elseif($reuseUnreif)
                        {{-- Kein Auto-Lauf: das übernommene Rezept ist FREMD oder schon freigegeben — daran hängen
                             möglicherweise Gerichte, Foodbooks und Speisepläne. Die Entscheidung gehört dem Menschen. --}}
                        <x-foodalchemist::ki-action action="neuAnreichern({{ $st->id }})" variant="ai" icon="heroicon-o-sparkles" label="Bestand anreichern"
                            busy="Wird angereichert …" flash="Anreicherung eingereiht"
                            title="Bestands-Rezept{{ ($reuse['eigen'] ?? false) ? '' : ' eines anderen Teams' }}{{ ($reuse['status'] ?? '') === 'approved' ? ', bereits freigegeben' : '' }}. Anreicherung bewusst anstoßen, vorhandene Inhalte können dabei neu erzeugt werden." />
                    @endif
                    {{-- Etappe 7 — Bild-Status: nur wenn KI-Fotos angefordert waren UND die Anreicherung durch ist
                         (die Fotos laufen im selben Job danach). deferred.bilder trägt done|failed + n; fehlt es
                         (Alt-Läufe), greift die Foto-Zählung. --}}
                    @if(!empty($bilderAngefordert) && $enrStatus === 'done')
                        @php
                            $bld = (is_array($st->deferred) && is_array($st->deferred['bilder'] ?? null)) ? $st->deferred['bilder'] : null;
                            $bldStatus = $bld['status'] ?? null;
                            $fotoN = (int) (($fotoCounts ?? [])[$st->ref_id] ?? 0);
                        @endphp
                        @if(in_array($bldStatus, ['queued', 'running'], true))
                            <span class="inline-flex items-center gap-1 {{ $textKlein }} font-medium text-[var(--fa-info)]" data-bild-status="{{ $st->id }}">@svg('heroicon-o-arrow-path', 'w-3.5 h-3.5 animate-spin') erzeugt Fotos …</span>
                        @elseif($bldStatus === 'failed')
                            {{-- Etappe 7 Teil 2b: NUR die KI-Fotos neu anstoßen (ohne Voll-Anreicherung). --}}
                            <x-foodalchemist::ki-action action="bilderNeu({{ $st->id }})"
                                :error="$bld['error'] ?? 'Bild-Erzeugung fehlgeschlagen'"
                                retry="Fotos fehlgeschlagen{{ $fotoN > 0 ? ' (' . $fotoN . ' ok)' : '' }}, neu erzeugen"
                                icon="heroicon-o-photo" busy="Fotos werden neu erzeugt …" flash="Neu erzeugen eingereiht"
                                data-bild-status="{{ $st->id }}" class="{{ $textKlein }}" />
                        @elseif($fotoN > 0)
                            <x-fa::signal tone="ok" icon="heroicon-m-photo" data-bild-status="{{ $st->id }}" title="{{ $fotoN }} KI-Foto(s) erzeugt">{{ $fotoN }} {{ $fotoN === 1 ? 'Foto' : 'Fotos' }}</x-fa::signal>
                        @else
                            <x-fa::signal tone="warn" icon="heroicon-m-photo" data-bild-status="{{ $st->id }}" title="KI-Fotos angefordert, aber keine erzeugt">keine Fotos erzeugt</x-fa::signal>
                        @endif
                    @endif
                @endif
            </div>
        </div>

        {{-- Aktionen: EINE Hauptaktion, KI-Assistent, Weitere Aktionen. --}}
        @if(! $laeuftGerade && ($kannAnsehen || $kiAnzahl > 0 || $hatWeitere || $st->status === 'geplant'))
            <div class="flex flex-wrap items-center justify-end gap-1.5 shrink-0">
                {{-- `skipped` = übernommenes Bestands-Rezept: ansehen ja, bearbeiten/freigeben nein. --}}
                @if($kannAnsehen)
                    @if($st->kind === 'rezept')
                        <x-fa::button size="sm" icon="heroicon-o-eye" wire:click="$dispatch('recipe-modal.oeffnen', { id: {{ (int) $st->ref_id }} })">Ansehen</x-fa::button>
                    @elseif($st->kind === 'gericht')
                        <x-fa::button size="sm" icon="heroicon-o-eye" wire:click="$dispatch('vk-modal.oeffnen', { id: {{ (int) $st->ref_id }} })">Ansehen</x-fa::button>
                    @elseif($st->kind === 'concept')
                        {{-- Vollen Conceptor inline öffnen (KPIs/Score/Aufbau/Kalkulation/Geschirr) statt Listen-Seite. --}}
                        <x-fa::button size="sm" icon="heroicon-o-arrow-top-right-on-square" wire:click="$dispatch('concepter-editor.oeffnen', { type: 'concepts', id: {{ (int) $st->ref_id }} })">Concept öffnen</x-fa::button>
                    @elseif(isset($refRoute[$st->kind]))
                        <x-fa::button size="sm" icon="heroicon-o-arrow-top-right-on-square" :href="route($refRoute[$st->kind])">Öffnen</x-fa::button>
                    @endif
                @endif

                @if($kiAnzahl >= 2)
                    <div class="relative" x-data="faMenu()" x-on:keydown.escape="offen = false" x-on:click.outside="offen = false">
                        <x-fa::button size="sm" variant="ai" icon="heroicon-m-sparkles" icon-right="heroicon-m-chevron-down"
                            x-on:click="toggle($event)" aria-haspopup="menu" x-bind:aria-expanded="offen">KI-Assistent</x-fa::button>
                        <div x-bind:class="{ hidden: ! offen }" x-bind:style="pos" role="menu" class="hidden w-72 fa-surface shadow-lg py-1">
                            @if($kannFeedback)
                                {{-- A2: Feedback zu genau dieser Position → dann gezielt neu generieren (nur diese Position). --}}
                                <button type="button" role="menuitem" wire:click="toggleKommentar({{ $st->id }})" x-on:click="offen = false" class="{{ $menuePunkt }}"
                                        title="Rückmeldung geben und nur dieses Ergebnis gezielt neu erzeugen">
                                    @svg('heroicon-o-pencil-square', 'w-4 h-4 text-[var(--fa-ink-3)]') {{ $kommentarIstOffen ? 'Rückmeldung schließen' : 'Mit Rückmeldung überarbeiten' }}
                                </button>
                                <div class="px-3 py-1.5">
                                    <x-foodalchemist::ki-action action="neuGenerieren({{ $st->id }})" variant="ai" icon="heroicon-o-arrow-path"
                                        label="Neu erzeugen" title="Neu erzeugen, der aktuelle Stand wird verworfen" busy="Wird neu erzeugt …" flash="Neu erzeugen eingereiht" class="w-full justify-start" />
                                </div>
                            @endif
                            @if($kannKonf)
                                <div class="px-3 py-1.5">
                                    <x-foodalchemist::ki-action action="konformitaetPruefen({{ (int) $st->ref_id }})" variant="ai" icon="heroicon-o-clipboard-document-check" label="Gegen Regelwerk prüfen"
                                        busy="Wird geprüft …" flash="Prüfung eingereiht" title="Konformität gegen die Regelwerke prüfen" class="w-full justify-start" />
                                </div>
                            @endif
                        </div>
                    </div>
                @elseif($kannKonf)
                    <x-foodalchemist::ki-action action="konformitaetPruefen({{ (int) $st->ref_id }})" variant="ai" icon="heroicon-o-clipboard-document-check" label="Gegen Regelwerk prüfen"
                        busy="Wird geprüft …" flash="Prüfung eingereiht" title="Konformität gegen die Regelwerke prüfen" />
                @endif

                @if($hatWeitere)
                    <div class="relative" x-data="faMenu()" x-on:keydown.escape="offen = false" x-on:click.outside="offen = false">
                        <x-fa::icon-button icon="heroicon-m-ellipsis-horizontal" label="Weitere Aktionen" size="sm" x-on:click="toggle($event)" aria-haspopup="menu" x-bind:aria-expanded="offen" />
                        <div x-bind:class="{ hidden: ! offen }" x-bind:style="pos" role="menu" class="hidden w-72 fa-surface shadow-lg py-1">
                            @if($kannRueckmeldung)
                                {{-- E4 (Spec 40) — Rückkopplung: Lücke im Einkauf melden (Nordstern „Lücke ist Signal")
                                     + noch nicht gepinnte Grundprodukte als Favoriten vorschlagen (Pinnen bleibt beim Menschen). --}}
                                <button type="button" role="menuitem" wire:click="sourcingLueckenMelden({{ $st->id }})" x-on:click="offen = false"
                                        wire:loading.attr="disabled" wire:target="sourcingLueckenMelden" class="{{ $menuePunkt }}">
                                    @svg('heroicon-o-exclamation-triangle', 'w-4 h-4 text-[var(--fa-ink-3)]') Fehlende Lieferantenartikel melden
                                </button>
                                <button type="button" role="menuitem" wire:click="favoritVorschlaegeLaden({{ $st->id }})" x-on:click="offen = false"
                                        wire:loading.attr="disabled" wire:target="favoritVorschlaegeLaden" class="{{ $menuePunkt }}">
                                    @svg('heroicon-o-bookmark', 'w-4 h-4 text-[var(--fa-ink-3)]') Favoriten vorschlagen
                                </button>
                            @endif
                            @if($st->status === 'done')
                                @if($kannRueckmeldung)<div class="my-1 border-t border-[var(--fa-line)]"></div>@endif
                                <button type="button" role="menuitem" wire:click="verwirf({{ $st->id }})" x-on:click="offen = false"
                                        wire:confirm="Dieses Ergebnis wirklich verwerfen?" class="{{ $menueRot }}" data-step-verwerfen>
                                    @svg('heroicon-o-trash', 'w-4 h-4') Ergebnis verwerfen
                                </button>
                            @elseif($st->status === 'geplant')
                                {{-- Etappe 1, Teil 2: geplante Sub-Rezepte einzeln verwerfen — VOR der Freigabe der Stufe darüber. --}}
                                <button type="button" role="menuitem" wire:click="verwirfGeplant({{ $st->id }})" x-on:click="offen = false" class="{{ $menueRot }}" data-step-verwerfen>
                                    @svg('heroicon-o-trash', 'w-4 h-4') Nicht benötigt, verwerfen
                                </button>
                            @endif
                        </div>
                    </div>
                @endif

                {{-- Hauptaktion der Karte --}}
                @if($st->status === 'done')
                    <x-foodalchemist::ki-action action="gibFrei({{ $st->id }})" variant="primary" icon="heroicon-o-check"
                        label="Freigeben" busy="Wird freigegeben …" flash="Freigegeben" data-step-freigeben />
                @elseif($st->status === 'geplant')
                    @if($istIdee)
                        <x-fa::button size="sm" variant="ghost" icon="heroicon-o-pencil-square" wire:click="toggleKommentar({{ $st->id }})"
                            title="Vorschlag mit Rückmeldung überarbeiten">{{ $kommentarIstOffen ? 'Rückmeldung schließen' : 'Überarbeiten' }}</x-fa::button>
                        <x-foodalchemist::ki-action action="erzeugeGeplant({{ $st->id }})" variant="primary" icon="heroicon-o-bolt"
                            label="Vorschlag annehmen" title="Vorschlag annehmen und das Gericht zur Prüfung erzeugen" busy="Wird erzeugt …" flash="Erzeugung eingereiht"
                            />
                    @else
                        <x-foodalchemist::ki-action action="erzeugeGeplant({{ $st->id }})" variant="primary" icon="heroicon-o-bolt"
                            label="Jetzt erzeugen" title="Jetzt erzeugen, ohne auf die Freigabe der Stufe darüber zu warten" busy="Wird erzeugt …" flash="Erzeugung eingereiht"
                            />
                    @endif
                @endif
            </div>
        @endif
    </div>

    {{-- A2 (Rückmeldung je Position): nur dieses eine Ergebnis wird danach neu gebaut, die Nachbarn bleiben. --}}
    @if($kommentarIstOffen && in_array($st->status, ['geplant', 'done', 'failed'], true))
        <div class="flex flex-col gap-2 rounded-[var(--fa-radius-control)] bg-[var(--fa-ground)] p-2.5" data-speise-kommentar="{{ $st->id }}" wire:key="kommentar-{{ $st->id }}">
            <x-fa::textarea wire:model="speiseKommentar.{{ $st->id }}" rows="2" aria-label="Rückmeldung zu diesem Ergebnis"
                placeholder="Was soll sich ändern? Zum Beispiel: vegetarisch statt Rind, leichter mit weniger Sahne, mehr Säure" />
            <div class="flex flex-wrap items-center justify-end gap-2">
                <x-fa::button size="sm" variant="ghost" wire:click="toggleKommentar({{ $st->id }})">Abbrechen</x-fa::button>
                <x-foodalchemist::ki-action
                    action="{{ $st->status === 'geplant' ? 'vorschlagUeberarbeiten' : 'neuGenerieren' }}({{ $st->id }})"
                    variant="ai" icon="heroicon-o-arrow-path"
                    label="{{ $st->status === 'geplant' ? 'Vorschlag überarbeiten' : 'Mit Rückmeldung neu erzeugen' }}"
                    busy="Wird eingereiht …" flash="Eingereiht" />
            </div>
        </div>
    @endif

    @if($istIdee)
        <div class="flex flex-col gap-1.5 rounded-[var(--fa-radius-control)] bg-[var(--fa-ground)] p-2.5" data-gericht-bauplan="{{ $st->id }}">
            @if(!empty($snap['beschreibung']))<p class="text-[length:var(--fa-text-md)] text-[var(--fa-ink)]">{{ $snap['beschreibung'] }}</p>@endif
            @if(!empty($snap['komponenten']))
                <p class="{{ $textKlein }} font-medium text-[var(--fa-ink-2)]">Geplante Komponenten</p>
                <ul class="flex flex-col gap-1">
                    @foreach($snap['komponenten'] as $komponente)
                        <li class="{{ $textKlein }} text-[var(--fa-ink-2)]"><span class="font-medium text-[var(--fa-ink)]">{{ $komponente['name'] ?? 'Komponente' }}</span>@if(!empty($komponente['funktion'])) · {{ $lesbar($komponente['funktion']) }}@endif @if(!empty($komponente['herstellung'])) · {{ $komponente['herstellung'] }}@endif</li>
                    @endforeach
                </ul>
            @endif
            <p class="{{ $textKlein }} text-[var(--fa-ink-3)]">Noch kein Gericht angelegt. „Vorschlag annehmen“ erzeugt es zur Prüfung.</p>
        </div>
    @endif

    {{-- Etappe 6: EK/VK/Marge je Ergebnis — schon vor der Freigabe sichtbar (SalesRecipeService::cockpit,
         gebündelt in Index::render). Nur Rezept/Gericht; Concept trägt keine Rezept-Marge.
         Ampel färbt Marge %/Wareneinsatz % (ok = auf/unter Ziel · warn = drüber · crit = >50 % drüber). --}}
    @php $kalk = ($kalkulation ?? [])[$st->ref_id] ?? null; @endphp
    @if($kalk !== null && in_array($st->kind, ['rezept', 'gericht'], true))
        @php
            $ampelTon = ['gruen' => 'ok', 'gelb' => 'warn', 'rot' => 'crit'][$kalk['ampel'] ?? 'unbekannt'] ?? null;
            $euro = fn ($v) => $v !== null ? number_format((float) $v, 2, ',', '.') . ' €' : 'fehlt';
            $kalkItems = [
                ['label' => 'EK gesamt', 'value' => $euro($kalk['ek_total']), 'tone' => $kalk['ek_total'] === null ? 'crit' : null],
                ['label' => 'VK netto', 'value' => $euro($kalk['vk_netto']), 'tone' => $kalk['vk_netto'] === null ? 'crit' : null],
                ['label' => 'Marge', 'value' => $kalk['marge_pct'] !== null ? number_format((float) $kalk['marge_pct'], 1, ',', '.') . ' %' : 'fehlt', 'tone' => $kalk['marge_pct'] !== null ? $ampelTon : null],
            ];
            if ($kalk['we_pct'] !== null) {
                $kalkItems[] = ['label' => 'Wareneinsatz', 'value' => number_format((float) $kalk['we_pct'], 1, ',', '.') . ' %', 'tone' => $ampelTon];
            }
        @endphp
        <div class="flex flex-col gap-1.5 min-w-0" data-kalkulation="{{ $st->id }}">
            {{-- Das Wichtige zuerst: was an der Kalkulation fehlt. --}}
            @if($kalk['formel_fehlt'] || $kalk['vk_netto'] === null || !empty($kalk['ek_teil_unbepreist']))
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                    @if($kalk['formel_fehlt'])
                        <x-fa::signal tone="crit" title="Keine Aufschlagsklasse oder Formel hinterlegt, VK und Marge sind nicht berechenbar">Formel fehlt, VK nicht berechenbar</x-fa::signal>
                    @elseif($kalk['vk_netto'] === null)
                        <x-fa::signal tone="crit">VK noch nicht bepreist</x-fa::signal>
                    @endif
                    {{-- Etappe 6: unvollständige Bepreisung ehrlich markieren (Lücken = 0 €) → EK/Marge zu günstig.
                         Kanonische Wahrheit: DataQualityService »teil-unbepreist«. Eigener @if: darf NEBEN einer gesunden Marge stehen. --}}
                    @if(!empty($kalk['ek_teil_unbepreist']))
                        <x-fa::signal tone="warn" title="Nur {{ $kalk['ek_n_priced'] }} von {{ $kalk['ek_n_total'] }} Zutaten bepreist, EK und Marge sind zu günstig gerechnet">EK teil-unbepreist ({{ $kalk['ek_n_priced'] }} von {{ $kalk['ek_n_total'] }} Zutaten)</x-fa::signal>
                    @endif
                </div>
            @endif
            <x-fa::kpis :items="$kalkItems" />
        </div>
    @endif

    {{-- Concept: die geplanten Menü-Positionen direkt zeigen (WELCHE Speisen der Plan vorsieht). Die Gerichte
         selbst entstehen mit der Stufen-Freigabe als eigene Gericht-Karten. Eine Query, nur für Concepts. --}}
    @if($st->ref_id && $st->kind === 'concept' && in_array($st->status, ['done', 'freigegeben'], true))
        @php $speisen = $this->conceptSpeisen((int) $st->ref_id); @endphp
        @if($speisen !== [])
            <div class="flex flex-col gap-1.5 rounded-[var(--fa-radius-control)] bg-[var(--fa-ground)] p-2.5" data-concept-speisen="{{ $st->id }}">
                <p class="{{ $textKlein }} font-medium text-[var(--fa-ink-2)]">Menü-Aufbau mit {{ count($speisen) === 1 ? '1 Position' : count($speisen) . ' Positionen' }}. Die Gerichte entstehen mit der Freigabe.</p>
                <ol class="flex flex-col gap-1">
                    @foreach($speisen as $sp)
                        <li class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5 text-[length:var(--fa-text-md)] text-[var(--fa-ink)]">
                            <span class="w-5 shrink-0 text-right {{ $textKlein }} text-[var(--fa-ink-3)] tabular-nums">{{ $loop->iteration }}.</span>
                            <span class="min-w-0 break-words">{{ $sp['titel'] !== '' ? $sp['titel'] : ($sp['rolle'] !== '' ? $lesbar($sp['rolle']) : 'Position') }}</span>
                            @if($sp['titel'] !== '' && $sp['rolle'] !== '')<span class="{{ $textKlein }} text-[var(--fa-ink-3)]">{{ $lesbar($sp['rolle']) }}</span>@endif
                            @if($sp['pflicht'])<x-fa::badge tone="accent">Pflicht</x-fa::badge>@endif
                        </li>
                    @endforeach
                </ol>
            </div>
        @endif
    @endif

    {{-- Etappe 7 — Kosten-Transparenz: die KI-Fotos werden je Aufruf protokolliert (foodalchemist_ai_call_log).
         Hier die Zahl der kostenpflichtigen Bild-Erzeugungen + das Modell — KEIN EUR-Betrag (keine Preisquelle). --}}
    @php $bild = ($bildCalls ?? [])[$st->ref_id] ?? null; @endphp
    @if($bild !== null && ($bild['n'] ?? 0) > 0 && in_array($st->kind, ['rezept', 'gericht'], true))
        <p class="inline-flex flex-wrap items-center gap-1 {{ $textKlein }} text-[var(--fa-ink-3)]" data-bild-calls="{{ $st->id }}"
           title="{{ $bild['n'] }} kostenpflichtige KI-Bild-Erzeugung(en){{ ($bild['model'] ?? '') !== '' ? ' · Modell ' . $bild['model'] : '' }}">
            @svg('heroicon-o-photo', 'w-3.5 h-3.5 shrink-0')
            {{ (int) $bild['n'] === 1 ? '1 kostenpflichtiges KI-Bild' : $bild['n'] . ' kostenpflichtige KI-Bilder' }}{{ ($bild['model'] ?? '') !== '' ? ' · ' . $bild['model'] : '' }}
        </p>
    @endif

    {{-- Etappe 7 Teil 2 — eigenes Foto hochladen: die Alternative ohne KI. Für freigegebene Rezepte/Gerichte.
         Kein KI-Aufruf → das Foto überlebt eine spätere KI-Neuerzeugung (loescheKiFotos). „Als Titelbild" = Hero
         (max. 1), sonst Foto im Pool. Teil 3b: vorhandenes Team-Foto wiederverwenden (Kopie, kein KI-Aufruf). --}}
    @if($st->ref_id && $st->status === 'freigegeben' && in_array($st->kind, ['rezept', 'gericht'], true))
        <div class="flex flex-wrap items-center gap-2" data-foto-upload="{{ $st->id }}">
            <label class="fa-btn inline-flex items-center gap-1.5 h-7 px-2.5 rounded-[var(--fa-radius-control)] border border-[var(--fa-line-strong)] bg-[var(--fa-surface)] {{ $textKlein }} font-medium text-[var(--fa-ink)] hover:bg-[var(--fa-hover)] cursor-pointer focus-within:outline focus-within:outline-2 focus-within:outline-[var(--fa-accent)]">
                @svg('heroicon-o-arrow-up-tray', 'w-3.5 h-3.5 shrink-0')
                {{ !empty($fotoUploads[$st->id]) ? 'Anderes Foto wählen' : 'Eigenes Foto hochladen' }}
                <input type="file" accept="image/*" wire:model="fotoUploads.{{ $st->id }}" class="sr-only" />
            </label>
            <span wire:loading wire:target="fotoUploads.{{ $st->id }}" class="{{ $textKlein }} text-[var(--fa-ink-3)]"><span class="inline-flex items-center gap-1">@svg('heroicon-o-arrow-path', 'w-3.5 h-3.5 animate-spin') Wird geladen …</span></span>
            @if(!empty($fotoUploads[$st->id]))
                <x-fa::signal tone="info" icon="heroicon-m-photo">Foto gewählt</x-fa::signal>
                <x-fa::button size="sm" wire:click="fotoHochladen({{ $st->id }}, false)" title="Als weiteres Foto übernehmen (kein KI-Aufruf)">Als Foto übernehmen</x-fa::button>
                <x-fa::button size="sm" wire:click="fotoHochladen({{ $st->id }}, true)" title="Als Titelbild übernehmen (ersetzt das bisherige Titelbild)">Als Titelbild übernehmen</x-fa::button>
            @endif
            @if(($fotoPickerStep ?? null) === $st->id)
                <x-fa::button size="sm" variant="ghost" icon="heroicon-m-x-mark" wire:click="fotoPickerSchliessen">Fotoauswahl schließen</x-fa::button>
            @else
                <x-fa::button size="sm" variant="ghost" icon="heroicon-o-photo" wire:click="fotoPickerOeffnen({{ $st->id }})" title="Vorhandenes Team-Foto wiederverwenden (kein KI-Aufruf)">Vorhandenes Foto wählen</x-fa::button>
            @endif
            @error("fotoUploads.{$st->id}")<x-fa::signal tone="crit">{{ $message }}</x-fa::signal>@enderror
        </div>
        @if(($fotoPickerStep ?? null) === $st->id)
            <div class="rounded-[var(--fa-radius-control)] bg-[var(--fa-ground)] p-2.5" data-foto-picker="{{ $st->id }}">
                @if(empty($fotoPickerKandidaten))
                    <x-fa::empty compact icon="heroicon-o-photo" title="Keine Fotos im Team">Es gibt noch keine Fotos aus anderen Rezepten, die sich wiederverwenden lassen.</x-fa::empty>
                @else
                    <div class="grid grid-cols-[repeat(auto-fill,minmax(8rem,1fr))] gap-2">
                        @foreach($fotoPickerKandidaten as $kand)
                            <div class="flex flex-col gap-1.5 rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] bg-[var(--fa-surface)] p-1.5 min-w-0" data-foto-kandidat="{{ $kand['id'] }}">
                                <img src="{{ $kand['url'] }}" alt="{{ $kand['caption'] ?: $kand['rezept'] }}" class="h-20 w-full rounded object-cover bg-[var(--fa-neutral-soft)]" loading="lazy" />
                                <p class="truncate {{ $textKlein }} text-[var(--fa-ink-2)]" title="{{ $kand['rezept'] }}{{ $kand['caption'] !== '' ? ' · ' . $kand['caption'] : '' }}">{{ $kand['rezept'] ?: 'Ohne Rezept' }}</p>
                                <div class="flex flex-wrap gap-1">
                                    <x-fa::button size="sm" variant="ghost" class="flex-1" wire:click="fotoUebernehmen({{ $st->id }}, {{ $kand['id'] }}, false)" title="Als weiteres Foto übernehmen (Kopie, kein KI-Aufruf)">Foto</x-fa::button>
                                    <x-fa::button size="sm" class="flex-1" wire:click="fotoUebernehmen({{ $st->id }}, {{ $kand['id'] }}, true)" title="Als Titelbild übernehmen (Kopie)">Titelbild</x-fa::button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif
    @endif

    @if($st->status === 'failed' && $st->error)
        <x-fa::signal tone="crit">{{ \Illuminate\Support\Str::limit($st->error, 160) }}</x-fa::signal>
    @endif
    @php $fanoutErr = is_array($st->deferred) ? ($st->deferred['fanout_error'] ?? null) : null; @endphp
    @if($fanoutErr)
        {{-- #124: Fan-out crashte, aber das Concept ist freigegeben — Teil-Problem (warn), nicht rot. --}}
        <x-fa::signal tone="warn">Die Gerichte konnten nicht automatisch erfunden werden: {{ \Illuminate\Support\Str::limit($fanoutErr, 140) }}. Das Concept selbst ist freigegeben.</x-fa::signal>
    @endif
    @php $attachErr = is_array($st->deferred) ? ($st->deferred['attach_error'] ?? null) : null; @endphp
    @if($attachErr)
        {{-- E-P0 (Spec 40): Einhängen ins Ausgabe-Kapitel/die Rubrik schlug fehl — das Konzept ist erzeugt,
             hängt aber nicht am Dokument. Teil-Problem, mit Nachhol-Aktion. --}}
        <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5">
            <x-fa::signal tone="warn">Nicht ins Ausgabe-Kapitel bzw. die Rubrik eingehängt: {{ \Illuminate\Support\Str::limit($attachErr, 120) }}. Das Konzept ist erzeugt.</x-fa::signal>
            <x-fa::button size="sm" icon="heroicon-o-link" wire:click="haengeKonzeptNach({{ $st->id }})"
                wire:loading.attr="disabled" wire:target="haengeKonzeptNach">Nachträglich einhängen</x-fa::button>
        </div>
    @endif
    @if($st->status === 'geplant')
        <p class="{{ $textKlein }} text-[var(--fa-ink-3)]">Entsteht, sobald die Stufe darüber freigegeben ist.</p>
    @endif

    {{-- A: Zutaten direkt prüfen — sehen WAS angelegt wurde, tauschen/entfernen/ergänzen VOR der Freigabe.
         On-Demand gemountet (toggleZutaten), nutzt den IngredientEditor (:eingebettet), nur Rezept/Gericht. --}}
    @if($st->ref_id && in_array($st->kind, ['rezept', 'gericht'], true) && in_array($st->status, ['done', 'freigegeben'], true))
        @php $zOffen = in_array($st->id, $zutatenOffen ?? [], true); @endphp
        <div class="flex flex-col gap-2 min-w-0">
            <div>
                <x-fa::button size="sm" variant="ghost" :icon="$zOffen ? 'heroicon-m-chevron-down' : 'heroicon-m-chevron-right'" wire:click="toggleZutaten({{ $st->id }})"
                    aria-expanded="{{ $zOffen ? 'true' : 'false' }}">{{ $zOffen ? 'Zutaten schließen' : 'Zutaten prüfen und ändern' }}</x-fa::button>
            </div>
            @if($zOffen)
                <div class="flex flex-col gap-2 rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] bg-[var(--fa-ground)] p-2.5 min-w-0 overflow-x-auto" wire:key="zutaten-wrap-{{ $st->id }}">
                    <p class="{{ $textKlein }} text-[var(--fa-ink-2)]">Zutaten tauschen, entfernen oder ergänzen. Danach speichern und freigeben.</p>
                    <livewire:foodalchemist.recipes.ingredient-editor :recipe-id="(int) $st->ref_id" :eingebettet="true" wire:key="worker-zutaten-{{ $st->id }}-{{ (int) $st->ref_id }}" />
                    {{-- #1b: Zutaten-Speichern je Karte. Das Cockpit stößt den Save adressiert an GENAU diesen
                         Editor an (MVP-046) — andere offene Karten bleiben unangetastet. Der Editor meldet selbst
                         und rechnet GL-02 neu. --}}
                    <div class="flex justify-end" x-data>
                        <x-fa::button size="sm" variant="primary" icon="heroicon-m-check"
                            x-on:click="$dispatch('zutaten-speichern', { recipeId: {{ (int) $st->ref_id }} })"
                            data-step-zutaten-speichern>Zutaten speichern</x-fa::button>
                    </div>
                </div>
            @endif
        </div>
        @php $fv = $favoritVorschlaege[$st->id] ?? null; @endphp
        @if(is_array($fv))
            <div class="flex flex-col gap-1.5">
                @if($fv === [])
                    <p class="{{ $textKlein }} text-[var(--fa-ink-3)]">Alle Grundprodukte dieses Ergebnisses sind bereits Favorit.</p>
                @else
                    <p class="{{ $textKlein }} font-medium text-[var(--fa-ink-2)]">Als Favorit merken:</p>
                    <div class="flex flex-wrap gap-1.5">
                        @foreach($fv as $k)
                            <x-fa::button size="sm" icon="heroicon-m-plus" wire:click="favoritPinnen({{ (int) $k['id'] }})">{{ $k['name'] }}</x-fa::button>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif
    @endif

    @if($wissenGesamt > 0)
        {{-- Komplett, ohne Kappung: der Chip ist die einzige Stelle, an der man je Ergebnis nachprüfen
             kann, was WIRKLICH im Prompt stand — eine „+N"-Abkürzung nimmt genau diese Kontrolle weg. --}}
        <details class="group" data-wissen-chip>
            <summary class="inline-flex flex-wrap items-center gap-x-1.5 {{ $textKlein }} text-[var(--fa-ink-2)] cursor-pointer hover:text-[var(--fa-ink)] select-none">
                @svg('heroicon-m-chevron-right', 'w-3.5 h-3.5 shrink-0 transition-transform group-open:rotate-90')
                <span class="font-medium">Verwendetes Wissen ({{ $wissenGesamt }})</span>
                <span class="text-[var(--fa-ink-3)]">{{ count($kanonFiles) }} verbindlich vorgegeben · {{ count($wissenFiles) }} nachgeschlagen</span>
            </summary>
            <div class="mt-2 flex flex-col gap-2">
                @if($kanonFiles !== [])
                    <div>
                        <p class="{{ $textKlein }} font-medium text-[var(--fa-ink-2)]">Verbindlich vorgegeben</p>
                        <div class="mt-1 flex flex-wrap gap-1">
                            @foreach($kanonFiles as $f)
                                <x-fa::badge tone="accent" title="{{ $f }}">{{ $wissenChip((string) $f) }}</x-fa::badge>
                            @endforeach
                        </div>
                    </div>
                @endif
                @if($wissenFiles !== [])
                    <div>
                        <p class="{{ $textKlein }} font-medium text-[var(--fa-ink-2)]">Nachgeschlagen</p>
                        <div class="mt-1 flex flex-wrap gap-1">
                            @foreach($wissenFiles as $f)
                                <x-fa::badge title="{{ $f }}">{{ $wissenChip((string) $f) }}</x-fa::badge>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        </details>
    @endif

    {{-- Schicht 3: Konformitäts-Hinweise (§-genau gegen die Regelwerke, verbindlich = rot / Empfehlung = gelb).
         Die Neu-Prüfung sitzt im KI-Assistenten der Karte. --}}
    @php
        $konf = ($st->ref_type === 'recipe' && $st->ref_id) ? (($konformitaet ?? [])[(int) $st->ref_id] ?? []) : [];
        $konfHart = collect($konf)->contains(fn ($k) => ($k['schweregrad'] ?? '') === 'hart');
    @endphp
    @if($kannKonf && $konf !== [])
        <details class="group">
            <summary class="inline-flex flex-wrap items-center gap-x-1.5 {{ $textKlein }} font-medium cursor-pointer select-none {{ $konfHart ? 'text-[var(--fa-crit)]' : 'text-[var(--fa-warn)]' }}">
                @svg('heroicon-m-exclamation-triangle', 'w-3.5 h-3.5 shrink-0')
                Konformität: {{ count($konf) === 1 ? '1 Hinweis' : count($konf) . ' Hinweise' }}{{ $konfHart ? ', davon verbindlich' : '' }}
            </summary>
            <ul class="mt-1.5 flex flex-col gap-1">
                @foreach($konf as $k)
                    <li class="{{ $textKlein }} text-[var(--fa-ink-2)] leading-snug break-words">
                        <span class="font-medium {{ ($k['schweregrad'] ?? '') === 'hart' ? 'text-[var(--fa-crit)]' : 'text-[var(--fa-warn)]' }}">{{ $k['paragraph'] ?: '§ ohne Angabe' }}</span>
                        {{ $k['reason'] }}@if(($k['feld'] ?? '') !== '') · <span class="text-[var(--fa-ink-3)]">{{ $lesbar($k['feld']) }}</span>@endif @if(($k['vorschlag'] ?? '') !== '')<span class="text-[var(--fa-ok)]">Vorschlag: {{ $k['vorschlag'] }}</span>@endif
                    </li>
                @endforeach
            </ul>
        </details>
    @endif
    {{-- Slice 4c: Konformität der Zutaten-Grundprodukte (v. a. die im Lauf neu angelegten, vorläufigen). --}}
    @php $gpKonf = ($st->ref_type === 'recipe' && $st->ref_id) ? (($gpKonformitaet ?? [])[(int) $st->ref_id] ?? []) : []; @endphp
    @if($gpKonf !== [])
        @php $gpKonfHart = collect($gpKonf)->contains(fn ($k) => ($k['schweregrad'] ?? '') === 'hart'); @endphp
        <details class="group">
            <summary class="inline-flex flex-wrap items-center gap-x-1.5 {{ $textKlein }} font-medium cursor-pointer select-none {{ $gpKonfHart ? 'text-[var(--fa-crit)]' : 'text-[var(--fa-warn)]' }}">
                @svg('heroicon-m-exclamation-triangle', 'w-3.5 h-3.5 shrink-0')
                GP-Konformität: {{ count($gpKonf) === 1 ? '1 Hinweis' : count($gpKonf) . ' Hinweise' }} zu Grundprodukten
            </summary>
            <ul class="mt-1.5 flex flex-col gap-1">
                @foreach($gpKonf as $k)
                    <li class="{{ $textKlein }} text-[var(--fa-ink-2)] leading-snug break-words">
                        <span class="font-medium text-[var(--fa-ink)]">{{ $k['gp'] }}</span>:
                        <span class="font-medium {{ ($k['schweregrad'] ?? '') === 'hart' ? 'text-[var(--fa-crit)]' : 'text-[var(--fa-warn)]' }}">{{ $k['paragraph'] ?: '§ ohne Angabe' }}</span>
                        {{ $k['reason'] }}@if(($k['feld'] ?? '') !== '') · <span class="text-[var(--fa-ink-3)]">{{ $lesbar($k['feld']) }}</span>@endif
                    </li>
                @endforeach
            </ul>
        </details>
    @endif
</div>
