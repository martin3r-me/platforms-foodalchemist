{{-- Live-Cascade-Cockpit: eine Kaskaden-Step-Zeile (root ODER Fan-out-Kind via $indent).
     Zeigt Status, In-Context-Ansicht des Entwurfs (kein Wegspringen), Freigabe/Verwerfen und
     — zur Kontrolle (#1a) — welches Wissen der Generator für DIESEN Schritt genutzt hat
     (context_snapshot, je Step persistiert). Erwartet: $st, $stepLabel, $stepColor, $refRoute, $indent. --}}
@php
    $indent = $indent ?? false;
    $snap = is_array($st->context_snapshot) ? $st->context_snapshot : [];
    $wissenFiles = (array) ($snap['knowledge_files'] ?? []);
@endphp
<div wire:key="step-{{ $st->id }}" class="{{ $indent ? 'ml-1 pl-3 border-l border-white/10' : '' }}">
    <div class="flex items-center justify-between gap-3 text-xs">
        <span class="truncate text-gray-200">{{ $indent ? '↳ ' : '' }}{{ $st->label ?: ucfirst($st->kind) }}</span>
        <span class="shrink-0 flex items-center gap-2">
            <span class="{{ $stepColor[$st->status] ?? 'text-gray-400' }}">{{ $stepLabel[$st->status] ?? $st->status }}</span>
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
                {{-- Etappe 7 — Bild-Status (Teil 1): erzeugt / angefordert-aber-leer, analog zum
                     Anreicherungs-Badge. Nur wenn KI-Fotos angefordert waren ($bilderAngefordert,
                     run-level) UND die Anreicherung durch ist (enrich=done — die Fotos laufen im
                     selben Job DANACH). 0 Fotos trotz angefordert = nichts erzeugt (die Bild-
                     Erzeugung ist still fail-soft; kein erfundenes »fehlgeschlagen«-Badge). --}}
                @if(!empty($bilderAngefordert) && $enrStatus === 'done')
                    @php $fotoN = (int) (($fotoCounts ?? [])[$st->ref_id] ?? 0); @endphp
                    @if($fotoN > 0)
                        <span class="text-[10px] text-emerald-400/80 inline-flex items-center gap-1" data-bild-status="{{ $st->id }}" title="{{ $fotoN }} KI-Foto(s) erzeugt">@svg('heroicon-o-photo', 'w-3 h-3') {{ $fotoN }} Foto{{ $fotoN === 1 ? '' : 's' }} ✓</span>
                    @else
                        <span class="text-[10px] text-amber-300/80 inline-flex items-center gap-1" data-bild-status="{{ $st->id }}" title="KI-Fotos angefordert, aber keine erzeugt">@svg('heroicon-o-photo', 'w-3 h-3') keine Fotos erzeugt</span>
                    @endif
                @endif
            @endif
            @if(in_array($st->status, ['done', 'failed'], true) && in_array($st->kind, ['rezept', 'gericht', 'concept'], true))
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
    @if($st->status === 'failed' && $st->error)
        <p class="text-[10px] text-rose-400/80 pl-1">{{ \Illuminate\Support\Str::limit($st->error, 160) }}</p>
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
                </div>
            @endif
        </div>
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
</div>
