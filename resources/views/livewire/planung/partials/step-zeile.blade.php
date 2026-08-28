{{-- Live-Cascade-Cockpit: eine Kaskaden-Step-Zeile (root ODER Fan-out-Kind via $indent).
     Zeigt Status, In-Context-Ansicht des Entwurfs (kein Wegspringen), Freigabe/Verwerfen und
     — zur Kontrolle (#1a) — welches Wissen der Generator für DIESEN Schritt genutzt hat
     (context_snapshot, je Step persistiert). Erwartet: $st, $stepLabel, $stepColor, $refRoute, $indent. --}}
@php
    $indent = $indent ?? false;
    $snap = is_array($st->context_snapshot) ? $st->context_snapshot : [];
    $wissenFiles = (array) ($snap['knowledge_files'] ?? []);
    // B3 (2026-08-20): Hardstop-Zeile aus dem »Nur Bestand«-Fanout (kein DB-Treffer). status ist
    // technisch `skipped`, darf aber NICHT als „übernommen" gelesen werden — eigener Warnhinweis.
    $hardstop = (is_array($st->deferred) && is_array($st->deferred['hardstop'] ?? null)) ? $st->deferred['hardstop'] : null;
@endphp
<div wire:key="step-{{ $st->id }}" class="{{ $indent ? 'ml-1 pl-3 border-l border-white/10' : '' }}">
    <div class="flex items-center justify-between gap-3 text-xs">
        <span class="truncate text-gray-200">{{ $indent ? '↳ ' : '' }}{{ $st->label ?: ucfirst($st->kind) }}</span>
        <span class="shrink-0 flex items-center gap-2">
            @if($hardstop)
                <span class="text-amber-300" title="Die Datenbank hat dafür kein passendes Grundprodukt/Basisrezept. Zutat am Gericht per Picker binden oder den Kreativ-Modus wechseln.">⚠ kein Bestand — wählen</span>
            @else
                <span class="{{ $stepColor[$st->status] ?? 'text-gray-400' }}">{{ $stepLabel[$st->status] ?? $st->status }}</span>
            @endif
            {{-- `skipped` = übernommenes Bestands-Rezept (Reuse): ansehen ja, bearbeiten/freigeben nein
                 (es ist fremdes, lebendes Artefakt — kein Draft dieses Laufs). --}}
            @if($st->ref_id && in_array($st->status, ['done', 'freigegeben', 'skipped'], true))
                @if($st->kind === 'rezept')
                    <button type="button" wire:click="$dispatch('recipe-modal.oeffnen', { id: {{ (int) $st->ref_id }} })" class="text-violet-300 hover:text-violet-200 underline">ansehen</button>
                @elseif($st->kind === 'gericht')
                    <button type="button" wire:click="$dispatch('vk-modal.oeffnen', { id: {{ (int) $st->ref_id }} })" class="text-violet-300 hover:text-violet-200 underline">ansehen</button>
                @elseif($st->kind === 'concept')
                    {{-- Vollen Conceptor inline öffnen (KPIs/Score/Aufbau/Kalkulation/Geschirr) statt Listen-Seite. --}}
                    <button type="button" wire:click="$dispatch('concepter-editor.oeffnen', { type: 'concepts', id: {{ (int) $st->ref_id }} })" class="text-violet-300 hover:text-violet-200 underline">öffnen</button>
                @elseif(isset($refRoute[$st->kind]))
                    <a href="{{ route($refRoute[$st->kind]) }}" class="text-violet-300 hover:text-violet-200 underline">öffnen</a>
                @endif
            @endif
            @php
                $enr = (is_array($st->deferred) && is_array($st->deferred['enrich'] ?? null)) ? $st->deferred['enrich'] : null;
                $enrStatus = $enr['status'] ?? null;
            @endphp
            @if($st->status === 'freigegeben' && in_array($st->kind, ['rezept', 'gericht'], true))
                @if($enrStatus === 'done')
                    <span class="text-[10px] text-emerald-400/80" title="angereichert {{ $enr['at'] ?? '' }}">angereichert ✓</span>
                @elseif(in_array($enrStatus, ['queued', 'running'], true))
                    <span class="text-[10px] text-amber-300/80 inline-flex items-center gap-1">@svg('heroicon-o-arrow-path', 'w-3 h-3 animate-spin') reichert an …</span>
                @elseif($enrStatus === 'failed')
                    <button wire:click="neuAnreichern({{ $st->id }})" class="text-[10px] text-rose-300 hover:text-rose-200 underline" title="{{ $enr['error'] ?? '' }}">Anreicherung fehlgeschlagen — neu anreichern</button>
                @endif
                {{-- Etappe 7 — Bild-Status: erzeugt / fehlgeschlagen / angefordert-aber-leer, analog
                     zum Anreicherungs-Badge. Nur wenn KI-Fotos angefordert waren ($bilderAngefordert,
                     run-level) UND die Anreicherung durch ist (enrich=done — die Fotos laufen im
                     selben Job DANACH). Teil 2 (Fehler-Persistenz): der EnrichRecipeJob hält das
                     Bild-Ergebnis jetzt explizit in deferred.bilder fest (status done|failed + n) →
                     ein echter »fehlgeschlagen«-Zustand statt des stummen 0-Foto-Fallbacks. Fehlt
                     deferred.bilder (Alt-Läufe/kein Job-Rücklauf), greift die Teil-1-Foto-Zähl-Logik. --}}
                @if(!empty($bilderAngefordert) && $enrStatus === 'done')
                    @php
                        $bld = (is_array($st->deferred) && is_array($st->deferred['bilder'] ?? null)) ? $st->deferred['bilder'] : null;
                        $bldStatus = $bld['status'] ?? null;
                        $fotoN = (int) (($fotoCounts ?? [])[$st->ref_id] ?? 0);
                    @endphp
                    @if(in_array($bldStatus, ['queued', 'running'], true))
                        {{-- Etappe 7 Teil 2b: „neu erzeugen" läuft (reBilder → EnrichRecipeJob nurBilder) — das
                             Polling hält an (anreicherungOffen watcht deferred.bilder), das Badge kippt live. --}}
                        <span class="text-[10px] text-amber-300/80 inline-flex items-center gap-1" data-bild-status="{{ $st->id }}">@svg('heroicon-o-arrow-path', 'w-3 h-3 animate-spin') erzeugt Fotos …</span>
                    @elseif($bldStatus === 'failed')
                        {{-- Etappe 7 Teil 2b: „neu erzeugen" — NUR die KI-Fotos re-triggern (ohne Voll-Anreicherung),
                             analog zum „neu anreichern" am enrich=failed-Badge. --}}
                        <button wire:click="bilderNeu({{ $st->id }})" class="text-[10px] text-rose-300 hover:text-rose-200 underline inline-flex items-center gap-1" data-bild-status="{{ $st->id }}" title="{{ $bld['error'] ?? 'Bild-Erzeugung fehlgeschlagen' }}">@svg('heroicon-o-photo', 'w-3 h-3') Fotos fehlgeschlagen{{ $fotoN > 0 ? ' (' . $fotoN . ' ok)' : '' }} — neu erzeugen</button>
                    @elseif($fotoN > 0)
                        <span class="text-[10px] text-emerald-400/80 inline-flex items-center gap-1" data-bild-status="{{ $st->id }}" title="{{ $fotoN }} KI-Foto(s) erzeugt">@svg('heroicon-o-photo', 'w-3 h-3') {{ $fotoN }} Foto{{ $fotoN === 1 ? '' : 's' }} ✓</span>
                    @else
                        <span class="text-[10px] text-amber-300/80 inline-flex items-center gap-1" data-bild-status="{{ $st->id }}" title="KI-Fotos angefordert, aber keine erzeugt">@svg('heroicon-o-photo', 'w-3 h-3') keine Fotos erzeugt</span>
                    @endif
                @endif
            @endif
            @if(in_array($st->status, ['done', 'failed'], true) && in_array($st->kind, ['rezept', 'gericht', 'concept'], true))
                {{-- A2: Feedback zu genau dieser Position → dann gezielt neu generieren (nur diese Position). --}}
                <button wire:click="toggleKommentar({{ $st->id }})" class="{{ in_array($st->id, $kommentarOffen ?? [], true) ? 'text-emerald-300' : 'text-gray-400 hover:text-gray-200' }}" title="Feedback geben & gezielt neu generieren">@svg('heroicon-o-pencil-square', 'w-4 h-4')</button>
                <button wire:click="neuGenerieren({{ $st->id }})" class="text-amber-300 hover:text-amber-200" title="Neu generieren (verwirft den aktuellen Entwurf)">@svg('heroicon-o-arrow-path', 'w-4 h-4')</button>
            @endif
            @if($st->status === 'done')
                <button wire:click="gibFrei({{ $st->id }})" class="text-emerald-300 hover:text-emerald-200" title="Freigeben">@svg('heroicon-o-check', 'w-4 h-4')</button>
                <button wire:click="verwirf({{ $st->id }})" class="text-rose-300 hover:text-rose-200" title="Verwerfen">@svg('heroicon-o-trash', 'w-4 h-4')</button>
            @endif
            {{-- Etappe 1, Teil 2: geplante Sub-Rezepte einzeln bedienen — jetzt erzeugen (vorziehen)
                 oder verwerfen — VOR der Freigabe der Stufe darüber. --}}
            @if($st->status === 'geplant')
                <button wire:click="erzeugeGeplant({{ $st->id }})" class="text-emerald-300 hover:text-emerald-200" title="Jetzt erzeugen (vorziehen)">@svg('heroicon-o-bolt', 'w-4 h-4')</button>
                <button wire:click="verwirfGeplant({{ $st->id }})" class="text-rose-300 hover:text-rose-200" title="Brauche ich nicht (verwerfen)">@svg('heroicon-o-trash', 'w-4 h-4')</button>
            @endif
        </span>
    </div>
    {{-- A2 (per-Speise-Feedback): Kommentar zu GENAU dieser Position, dann gezielt neu generieren —
         nur dieser eine Entwurf wird nach dem Feedback neu gebaut, die Nachbar-Positionen bleiben. --}}
    @if(in_array($st->id, $kommentarOffen ?? [], true) && in_array($st->status, ['done', 'failed'], true))
        <div class="mt-1 pl-1" data-speise-kommentar="{{ $st->id }}" wire:key="kommentar-{{ $st->id }}">
            <textarea wire:model="speiseKommentar.{{ $st->id }}" rows="2"
                      placeholder="Was an dieser Position ändern? (z. B. „vegetarisch statt Rind", „leichter, weniger Sahne", „mehr Säure") …"
                      class="w-full text-[11px] bg-white/5 border border-white/10 rounded px-2 py-1 text-gray-200 placeholder-gray-500"></textarea>
            <div class="mt-1 flex items-center gap-3">
                <button wire:click="neuGenerieren({{ $st->id }})"
                        class="text-[11px] text-emerald-300 hover:text-emerald-200 inline-flex items-center gap-1">
                    @svg('heroicon-o-arrow-path', 'w-3.5 h-3.5') mit Feedback neu generieren
                </button>
                <button wire:click="toggleKommentar({{ $st->id }})" class="text-[11px] text-gray-500 hover:text-gray-300">abbrechen</button>
            </div>
        </div>
    @endif
    {{-- Etappe 6: EK/VK/Marge je Stufe — schon am Draft sichtbar (Kalkulation aus SalesRecipeService::cockpit,
         gebündelt in Index::render). Nur Rezept/Gericht-Steps; Concept trägt keine Rezept-Marge.
         Ampel färbt Marge %/Wareneinsatz % (grün=auf/unter Ziel · gelb=drüber · rot=>50% drüber · grau=unbekannt). --}}
    @php $kalk = ($kalkulation ?? [])[$st->ref_id] ?? null; @endphp
    @if($kalk !== null && in_array($st->kind, ['rezept', 'gericht'], true))
        @php
            $ampelTon = ['gruen' => 'text-emerald-300', 'gelb' => 'text-amber-300', 'rot' => 'text-rose-300', 'unbekannt' => 'text-gray-400'][$kalk['ampel'] ?? 'unbekannt'] ?? 'text-gray-400';
        @endphp
        <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-[10px] pl-1" data-kalkulation="{{ $st->id }}">
            <span class="text-gray-500">EK <span class="text-gray-200">{{ $kalk['ek_total'] !== null ? number_format($kalk['ek_total'], 2, ',', '.') . ' €' : '—' }}</span></span>
            <span class="text-gray-500">VK <span class="text-gray-200">{{ $kalk['vk_netto'] !== null ? number_format($kalk['vk_netto'], 2, ',', '.') . ' €' : '—' }}</span></span>
            <span class="text-gray-500">Marge <span class="{{ $ampelTon }}">{{ $kalk['marge_pct'] !== null ? number_format($kalk['marge_pct'], 1, ',', '.') . ' %' : '—' }}</span></span>
            @if($kalk['we_pct'] !== null)
                <span class="text-gray-500">WE <span class="{{ $ampelTon }}">{{ number_format($kalk['we_pct'], 1, ',', '.') }} %</span></span>
            @endif
            {{-- Etappe 6: unvollständige Bepreisung ehrlich markieren — EK ist da, trägt aber nicht alle
                 Zutaten (Lücken = 0 €) → EK/Marge zu günstig. Kanonische Wahrheit: DataQualityService
                 »teil-unbepreist«. Eigener @if (kein elseif): darf NEBEN einer gesunden Marge stehen. --}}
            @if(!empty($kalk['ek_teil_unbepreist']))
                <span class="text-amber-400/70" title="Nur {{ $kalk['ek_n_priced'] }} von {{ $kalk['ek_n_total'] }} Zutaten bepreist — EK/Marge sind zu günstig gerechnet">EK teil-unbepreist</span>
            @endif
            @if($kalk['formel_fehlt'])
                <span class="text-amber-400/70" title="Keine Aufschlagsklasse/Formel hinterlegt — VK/Marge nicht berechenbar">Formel fehlt</span>
            @elseif($kalk['vk_netto'] === null)
                <span class="text-gray-500">noch nicht bepreist</span>
            @endif
        </div>
    @endif
    {{-- Concept-Step: die geplanten Menü-Positionen inline zeigen (WELCHE Speisen der Plan vorsieht) —
         statt nur „öffnen". Die Gerichte selbst entstehen mit der Stufen-Freigabe (Fan-out) als eigene
         Gericht-Steps. Genau das, was vorher nur im Conceptor sichtbar war (#2 „ich sehe nicht welche
         Speisen er vorschlägt"). Eine Query, nur für Concept-Steps. --}}
    @if($st->ref_id && $st->kind === 'concept' && in_array($st->status, ['done', 'freigegeben'], true))
        @php $speisen = $this->conceptSpeisen((int) $st->ref_id); @endphp
        @if($speisen !== [])
            <div class="mt-1 pl-1" data-concept-speisen="{{ $st->id }}">
                <p class="text-[10px] text-gray-500 mb-0.5">Menü-Aufbau — {{ count($speisen) }} Position(en); die Gerichte kommen mit der Freigabe:</p>
                <ol class="space-y-0.5">
                    @foreach($speisen as $sp)
                        <li class="flex items-center gap-2 text-[11px] text-gray-300">
                            <span class="text-gray-600 tabular-nums">{{ $loop->iteration }}.</span>
                            <span>{{ $sp['titel'] !== '' ? $sp['titel'] : ($sp['rolle'] !== '' ? $sp['rolle'] : 'Position') }}</span>
                            @if($sp['titel'] !== '' && $sp['rolle'] !== '')<span class="text-gray-500">· {{ $sp['rolle'] }}</span>@endif
                            @if($sp['pflicht'])<span class="text-[10px] text-emerald-400/70 uppercase tracking-wide">Pflicht</span>@endif
                        </li>
                    @endforeach
                </ol>
            </div>
        @endif
    @endif
    {{-- Etappe 7 — Kosten-Transparenz je Call: die KI-Fotos werden bei der Anreicherung je Call
         protokolliert (foodalchemist_ai_call_log). Hier die Zahl der kostenpflichtigen Bild-Calls
         + das Modell am Draft zeigen — KEIN EUR-Betrag (keine Preisquelle → wäre Erfindung). --}}
    @php $bild = ($bildCalls ?? [])[$st->ref_id] ?? null; @endphp
    @if($bild !== null && ($bild['n'] ?? 0) > 0 && in_array($st->kind, ['rezept', 'gericht'], true))
        <div class="mt-0.5 text-[10px] pl-1" data-bild-calls="{{ $st->id }}">
            <span class="text-gray-500 inline-flex items-center gap-1"
                  title="{{ $bild['n'] }} kostenpflichtige KI-Bild-Generierung(en){{ ($bild['model'] ?? '') !== '' ? ' · Modell ' . $bild['model'] : '' }}">
                @svg('heroicon-o-photo', 'w-3 h-3')
                {{ $bild['n'] }} KI-Bild-Call{{ (int) $bild['n'] === 1 ? '' : 's' }}{{ ($bild['model'] ?? '') !== '' ? ' · ' . $bild['model'] : '' }}
            </span>
        </div>
    @endif
    {{-- Etappe 7 Teil 2 — manueller Foto-Upload: die NICHT-KI-Alternative zur Bild-Erzeugung, neben
         „neu erzeugen". Für freigegebene Rezept-/Gericht-Drafts (hat ein reales Rezept). Kein KI-Call
         → das Foto überlebt einen späteren KI-Re-Trigger (loescheKiFotos). „als Ergebnis" = Hero (max. 1),
         sonst Pool-Foto. Immer verfügbar (auch ohne angeforderte KI-Fotos = der eigentliche Alternativ-Fall). --}}
    @if($st->ref_id && $st->status === 'freigegeben' && in_array($st->kind, ['rezept', 'gericht'], true))
        <div class="mt-0.5 flex flex-wrap items-center gap-2 text-[10px] pl-1" data-foto-upload="{{ $st->id }}">
            <input type="file" accept="image/*" wire:model="fotoUploads.{{ $st->id }}"
                   class="text-[10px] text-gray-400 file:mr-2 file:rounded file:border-0 file:bg-white/10 file:px-2 file:py-0.5 file:text-[10px] file:text-gray-200" />
            <span wire:loading wire:target="fotoUploads.{{ $st->id }}" class="text-gray-500 inline-flex items-center gap-1">@svg('heroicon-o-arrow-path', 'w-3 h-3 animate-spin') lädt …</span>
            @if(!empty($fotoUploads[$st->id]))
                <button wire:click="fotoHochladen({{ $st->id }}, false)" class="text-gray-300 hover:text-gray-100 underline" title="Als Pool-Foto übernehmen (kein KI-Call)">als Foto</button>
                <button wire:click="fotoHochladen({{ $st->id }}, true)" class="text-emerald-300 hover:text-emerald-200 underline" title="Als Ergebnis-/Hero-Foto übernehmen (ersetzt das bisherige Ergebnis-Bild)">als Ergebnis</button>
            @endif
            {{-- Etappe 7 Teil 3b — Foto-Wiederverwendung: ein vorhandenes Team-Foto (aus einem anderen
                 Rezept) COPY-ON-REUSE auf diesen Draft übernehmen, statt neu hochzuladen. Kein KI-Call. --}}
            @if(($fotoPickerStep ?? null) === $st->id)
                <button wire:click="fotoPickerSchliessen" class="text-gray-400 hover:text-gray-200 underline">Picker schliessen</button>
            @else
                <button wire:click="fotoPickerOeffnen({{ $st->id }})" class="text-gray-300 hover:text-gray-100 underline" title="Vorhandenes Team-Foto wiederverwenden (kein KI-Call)">wiederverwenden</button>
            @endif
            @error("fotoUploads.{$st->id}")<span class="text-rose-400/80">{{ $message }}</span>@enderror
        </div>
        @if(($fotoPickerStep ?? null) === $st->id)
            <div class="mt-1 pl-1" data-foto-picker="{{ $st->id }}">
                @if(empty($fotoPickerKandidaten))
                    <p class="text-[10px] text-gray-500">Keine wiederverwendbaren Fotos im Team.</p>
                @else
                    <div class="flex flex-wrap gap-1.5">
                        @foreach($fotoPickerKandidaten as $kand)
                            <div class="w-20 rounded border border-white/10 bg-white/[0.03] p-1" data-foto-kandidat="{{ $kand['id'] }}">
                                <img src="{{ $kand['url'] }}" alt="{{ $kand['caption'] ?: $kand['rezept'] }}" class="h-12 w-full rounded object-cover bg-black/20" loading="lazy" />
                                <p class="mt-0.5 truncate text-[9px] text-gray-400" title="{{ $kand['rezept'] }}{{ $kand['caption'] !== '' ? ' — ' . $kand['caption'] : '' }}">{{ $kand['rezept'] ?: '—' }}</p>
                                <div class="mt-0.5 flex items-center justify-between text-[9px]">
                                    <button wire:click="fotoUebernehmen({{ $st->id }}, {{ $kand['id'] }}, false)" class="text-gray-300 hover:text-gray-100 underline" title="Als Pool-Foto übernehmen (Kopie, kein KI-Call)">Foto</button>
                                    <button wire:click="fotoUebernehmen({{ $st->id }}, {{ $kand['id'] }}, true)" class="text-emerald-300 hover:text-emerald-200 underline" title="Als Ergebnis-/Hero-Foto übernehmen (Kopie)">Ergebnis</button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif
    @endif
    @if($st->status === 'failed' && $st->error)
        <p class="text-[10px] text-rose-400/80 pl-1">{{ \Illuminate\Support\Str::limit($st->error, 160) }}</p>
    @endif
    @php $fanoutErr = is_array($st->deferred) ? ($st->deferred['fanout_error'] ?? null) : null; @endphp
    @if($fanoutErr)
        {{-- #124: Fan-out crashte, aber das Concept ist freigegeben — amber (Teil-Problem), nicht rot. --}}
        <p class="text-[10px] text-amber-400/80 pl-1">Auto-Gericht-Erfindung fehlgeschlagen: {{ \Illuminate\Support\Str::limit($fanoutErr, 140) }} — das Concept selbst ist freigegeben.</p>
    @endif
    @php $attachErr = is_array($st->deferred) ? ($st->deferred['attach_error'] ?? null) : null; @endphp
    @if($attachErr)
        {{-- E-P0 (Spec 40): Attach ans Ausgabe-Kapitel/die Rubrik schlug fehl — das Konzept ist erzeugt, hängt
             aber nicht am Dokument. Amber (Teil-Problem, kein Datenverlust mehr), mit Nachhol-Aktion. --}}
        <div class="pl-1 mt-0.5">
            <p class="text-[10px] text-amber-400/80">Nicht ans Ausgabe-Kapitel/die Rubrik gehängt: {{ \Illuminate\Support\Str::limit($attachErr, 120) }} — das Konzept ist erzeugt.</p>
            <button type="button" wire:click="haengeKonzeptNach({{ $st->id }})"
                    wire:loading.attr="disabled" wire:target="haengeKonzeptNach"
                    class="mt-0.5 text-[10px] text-amber-300/90 hover:text-amber-200 inline-flex items-center gap-1 select-none disabled:opacity-50">
                @svg('heroicon-o-link', 'w-3 h-3') nachträglich einhängen
            </button>
        </div>
    @endif
    @if($st->status === 'geplant')
        <p class="text-[10px] text-violet-300/60 pl-1">wird erzeugt, sobald die Stufe darüber freigegeben ist</p>
    @endif
    {{-- A: voll-inline Zutaten-Review — sehen WAS angelegt wurde + tauschen/entfernen/ergänzen VOR der Freigabe.
         On-Demand gemountet (toggleZutaten), reused IngredientEditor (:eingebettet), nur für Rezept/Gericht-Drafts. --}}
    @if($st->ref_id && in_array($st->kind, ['rezept', 'gericht'], true) && in_array($st->status, ['done', 'freigegeben'], true))
        @php $zOffen = in_array($st->id, $zutatenOffen ?? [], true); @endphp
        <div class="mt-1">
            <button type="button" wire:click="toggleZutaten({{ $st->id }})"
                    class="text-[10px] text-emerald-300/80 hover:text-emerald-200 inline-flex items-center gap-1 select-none">
                @svg($zOffen ? 'heroicon-o-chevron-down' : 'heroicon-o-adjustments-horizontal', 'w-3 h-3')
                {{ $zOffen ? 'Zutaten schließen' : 'Zutaten prüfen & ändern' }}
            </button>
            @if($zOffen)
                <div class="mt-2 rounded-lg bg-white/[0.03] p-2" wire:key="zutaten-wrap-{{ $st->id }}">
                    <p class="text-[10px] text-gray-400 mb-1.5">Entwurf — tauschen / entfernen / ergänzen, dann freigeben:</p>
                    <livewire:foodalchemist.recipes.ingredient-editor :recipe-id="(int) $st->ref_id" :eingebettet="true" wire:key="worker-zutaten-{{ $st->id }}-{{ (int) $st->ref_id }}" />
                    {{-- #1b: Per-Step-Zutaten-Save. Der frühere geteilte Inline-Knopf (zutaten-kern)
                         ist entfallen; hier stößt das Cockpit den Save adressiert an GENAU diese
                         Stufe an (MVP-046) — trifft nur den eigenen Editor, andere offene Stufen
                         bleiben unangetastet. Der Editor toastet selbst + rechnet GL-02 neu. --}}
                    <div class="mt-2 flex justify-end" x-data>
                        <button type="button"
                                @click="$dispatch('zutaten-speichern', { recipeId: {{ (int) $st->ref_id }} })"
                                class="text-[10px] px-2.5 py-1 rounded-md bg-emerald-500/80 hover:bg-emerald-400 text-white font-medium"
                                data-step-zutaten-speichern>Zutaten speichern</button>
                    </div>
                </div>
            @endif
        </div>
        {{-- E4 (Spec 40) — Rückkopplung aus dem Cockpit: Sourcing-Lücke melden (Nordstern „Lücke ist Signal")
             + noch nicht gepinnte GPs als Favoriten-Kandidaten vorschlagen (Pinnen bleibt mensch-gated). --}}
        <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 pl-1">
            <button type="button" wire:click="sourcingLueckenMelden({{ $st->id }})"
                    wire:loading.attr="disabled" wire:target="sourcingLueckenMelden"
                    class="text-[10px] text-amber-300/80 hover:text-amber-200 inline-flex items-center gap-1 select-none">
                @svg('heroicon-o-exclamation-triangle', 'w-3 h-3') Sourcing-Lücke melden
            </button>
            <button type="button" wire:click="favoritVorschlaegeLaden({{ $st->id }})"
                    wire:loading.attr="disabled" wire:target="favoritVorschlaegeLaden"
                    class="text-[10px] text-sky-300/80 hover:text-sky-200 inline-flex items-center gap-1 select-none">
                @svg('heroicon-o-bookmark', 'w-3 h-3') Favoriten-Vorschlag
            </button>
        </div>
        @php $fv = $favoritVorschlaege[$st->id] ?? null; @endphp
        @if(is_array($fv))
            <div class="mt-1 pl-1">
                @if($fv === [])
                    <p class="text-[10px] text-gray-500">Alle GPs dieses Entwurfs sind bereits Favorit.</p>
                @else
                    <div class="flex flex-wrap gap-1">
                        @foreach($fv as $k)
                            <button type="button" wire:click="favoritPinnen({{ (int) $k['id'] }})"
                                    class="px-1.5 py-0.5 rounded bg-sky-500/10 border border-sky-500/25 text-[10px] text-sky-200/90 hover:bg-sky-500/20 inline-flex items-center gap-1">
                                @svg('heroicon-o-plus', 'w-2.5 h-2.5') {{ $k['name'] }}
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif
    @endif
    @if($wissenFiles !== [])
        <details class="mt-0.5">
            <summary class="text-[10px] text-gray-500 cursor-pointer hover:text-gray-300">Verwendetes Wissen ({{ count($wissenFiles) }})</summary>
            <div class="mt-1 flex flex-wrap gap-1">
                @foreach(array_slice($wissenFiles, 0, 14) as $f)
                    <span class="px-1.5 py-0.5 rounded bg-white/5 text-[10px] text-gray-400">{{ \Illuminate\Support\Str::of((string) $f)->afterLast('/')->beforeLast('.') }}</span>
                @endforeach
                @if(count($wissenFiles) > 14)
                    <span class="text-[10px] text-gray-500">+{{ count($wissenFiles) - 14 }}</span>
                @endif
            </div>
        </details>
    @endif
    {{-- Schicht 3: Konformitäts-Hinweise (§-genau gegen die Regelwerke, hart=rot/weich=gelb) +
         on-demand Re-Check. Nur an Rezept-/Gericht-Steps mit erzeugtem Artefakt. --}}
    @php
        $konf = ($st->ref_type === 'recipe' && $st->ref_id) ? (($konformitaet ?? [])[(int) $st->ref_id] ?? []) : [];
        $konfHart = collect($konf)->contains(fn ($k) => ($k['schweregrad'] ?? '') === 'hart');
    @endphp
    @if($st->ref_type === 'recipe' && $st->ref_id && in_array($st->status, ['done', 'freigegeben', 'skipped'], true))
        <div class="mt-0.5 flex items-start gap-2">
            @if($konf !== [])
                <details class="flex-1">
                    <summary class="text-[10px] cursor-pointer {{ $konfHart ? 'text-rose-300' : 'text-amber-300' }} hover:opacity-80">⚠ Konformität ({{ count($konf) }}{{ $konfHart ? ', davon hart' : '' }})</summary>
                    <div class="mt-1 space-y-1">
                        @foreach($konf as $k)
                            <div class="text-[10px] text-gray-400 leading-snug">
                                <span class="{{ ($k['schweregrad'] ?? '') === 'hart' ? 'text-rose-300' : 'text-amber-300' }}">{{ $k['paragraph'] ?: '§?' }}</span>
                                {{ $k['reason'] }}@if(($k['feld'] ?? '') !== '') · <span class="text-gray-500">{{ $k['feld'] }}</span>@endif @if(($k['vorschlag'] ?? '') !== '')<span class="text-emerald-400/70">→ {{ $k['vorschlag'] }}</span>@endif
                            </div>
                        @endforeach
                    </div>
                </details>
            @else
                <span class="flex-1"></span>
            @endif
            <button type="button" wire:click="konformitaetPruefen({{ (int) $st->ref_id }})" wire:loading.attr="disabled" class="shrink-0 text-[10px] text-gray-500 hover:text-gray-300" title="Konformität gegen die Regelwerke prüfen">🔍 prüfen</button>
        </div>
    @endif
    {{-- Slice 4c: GP-Konformität dieses Steps — die Zutaten-GPs (v.a. die im Lauf frisch geminteten
         tentativen) tragen eigene §-Hinweise gegen das GP-Regelwerk. --}}
    @php $gpKonf = ($st->ref_type === 'recipe' && $st->ref_id) ? (($gpKonformitaet ?? [])[(int) $st->ref_id] ?? []) : []; @endphp
    @if($gpKonf !== [])
        <details class="mt-0.5">
            <summary class="text-[10px] cursor-pointer {{ collect($gpKonf)->contains(fn ($k) => ($k['schweregrad'] ?? '') === 'hart') ? 'text-rose-300' : 'text-amber-300' }} hover:opacity-80">⚠ GP-Konformität ({{ count($gpKonf) }})</summary>
            <div class="mt-1 space-y-1">
                @foreach($gpKonf as $k)
                    <div class="text-[10px] text-gray-400 leading-snug">
                        <span class="text-gray-300">{{ $k['gp'] }}</span> —
                        <span class="{{ ($k['schweregrad'] ?? '') === 'hart' ? 'text-rose-300' : 'text-amber-300' }}">{{ $k['paragraph'] ?: '§?' }}</span>
                        {{ $k['reason'] }}@if(($k['feld'] ?? '') !== '') · <span class="text-gray-500">{{ $k['feld'] }}</span>@endif
                    </div>
                @endforeach
            </div>
        </details>
    @endif
</div>
