{{--
    Spec 27 Phase 2 — Schritt-für-Schritt-Editor.
    Nummer + Text + Foto(s) sind EINE Zeile; Reorder per Griff/Pfeile (server-seitig, damit
    Foto-Verknüpfungen sofort möglich sind — sie brauchen echte Schritt-IDs).

    fa-pass: kein style-Block mehr — nur --fa-*-Tokens und x-fa-Bausteine, damit der Editor hell
    UND im Werkbank-Modus (data-fa-theme="dark") stimmt. Läuft im Rezept- und im Gericht-Editor.
--}}
@php
    $hinweis = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]';
    $kasten = 'rounded-[var(--fa-radius-control)] bg-[var(--fa-ground)] border border-[var(--fa-line)] px-3 py-2.5 flex flex-col gap-2';
    $nummer = 'shrink-0 inline-flex items-center justify-center w-6 h-6 rounded-full bg-[var(--fa-accent-soft)] text-[var(--fa-accent)] text-[length:var(--fa-text-sm)] font-semibold tabular-nums';
    $menuePunkt = 'flex w-full items-center gap-2 px-3 py-2 text-left text-[length:var(--fa-text-md)] text-[var(--fa-ink)] hover:bg-[var(--fa-hover)]';
    $segAn = 'bg-[var(--fa-surface)] text-[var(--fa-ink)] shadow-sm';
    $segAus = 'text-[var(--fa-ink-2)] hover:text-[var(--fa-ink)]';
    $anzahl = fn (int $n, string $eins, string $mehr) => $n . ' ' . ($n === 1 ? $eins : $mehr);
@endphp

<div data-schritt-editor class="flex flex-col gap-3">
    @if($fehler)
        <x-fa::notice tone="crit" data-schritt-fehler>{{ $fehler }}</x-fa::notice>
    @endif

    @if($rezept === null)
        <p class="{{ $hinweis }}">Rezept erst speichern, danach lässt sich die Anleitung aufbauen.</p>
    @else
    <div x-data="{ ansicht: 'bearbeiten', dragId: null }" class="flex flex-col gap-3">

        {{-- ── Kopfzeile: Ansicht + Aktionen ───────────────────────────── --}}
        <div class="flex flex-wrap items-center gap-2">
            <div class="inline-flex items-center gap-0.5 p-0.5 rounded-[var(--fa-radius-control)] bg-[var(--fa-neutral-soft)]" role="group" aria-label="Ansicht">
                {{-- Spec 65: <a role=button> statt <button> — die Ansicht bleibt auch im gesperrten Lesemodus
                     (fieldset disabled des Voll-Editors) umschaltbar; reine Alpine-Ansicht, schreibt nichts. --}}
                <a href="#" role="button" @click.prevent="ansicht = 'bearbeiten'"
                        :class="ansicht === 'bearbeiten' ? '{{ $segAn }}' : '{{ $segAus }}'"
                        class="inline-flex items-center h-7 px-2.5 rounded-[var(--fa-radius-control)] text-[length:var(--fa-text-sm)] font-medium transition-colors" data-tab-bearbeiten>Bearbeiten</a>
                <a href="#" role="button" @click.prevent="ansicht = 'anleitung'"
                        :class="ansicht === 'anleitung' ? '{{ $segAn }}' : '{{ $segAus }}'"
                        class="inline-flex items-center h-7 px-2.5 rounded-[var(--fa-radius-control)] text-[length:var(--fa-text-sm)] font-medium transition-colors" data-tab-anleitung>Anleitung ansehen</a>
            </div>
            <span class="{{ $hinweis }} tabular-nums">{{ $anzahl($schritte->count(), 'Schritt', 'Schritte') }} · {{ $anzahl($pool->count(), 'Foto', 'Fotos') }}</span>

            @if($schreibbar)
                <span class="ml-auto flex flex-wrap items-center gap-1.5">
                    {{-- Ein Knopf für beide Ebenen: der Prompt folgt der Ebene
                         (StepEditor::promptKey → `recipe.steps` bzw. `vk.plating`), damit
                         Anrichten nicht mit Fertigstellungs-Schritten befüllt wird. --}}
                    <x-foodalchemist::ki-action action="kiSchritte" variant="ai" icon="heroicon-o-sparkles"
                            :label="$kiKnopf" :title="$kiTitel . '. Nur ein Vorschlag, gespeichert wird erst beim Übernehmen.'"
                            busy="Denkt nach …" data-ki-schritte />
                    <x-fa::button size="sm" :variant="trim($kiBriefing) !== '' ? 'ai' : 'ghost'" icon="heroicon-o-chat-bubble-bottom-center-text" wire:click="$toggle('briefingOffen')"
                            title="Eigene Vorgabe für den KI-Vorschlag, sprechen oder tippen" data-briefing-toggle>
                        {{-- Kein geklebtes @if: nach einem Wortzeichen erkennt Blade die
                             Direktive nicht (\B), das @endif aber doch → ParseError. --}}
                        <span>Vorgabe</span>
                        @if(trim($kiBriefing) !== '')<span class="w-1.5 h-1.5 rounded-full bg-[var(--fa-accent)]" aria-label="Vorgabe gesetzt"></span>@endif
                    </x-fa::button>
                    <x-foodalchemist::ki-action action="kiFotos" variant="ai" icon="heroicon-o-photo" label="Fotos erzeugen"
                            title="KI-Fotos für alle Schritte ohne Foto erzeugen" busy="Malt …" data-ki-fotos />
                    <div class="relative" x-data="faMenu()" x-on:keydown.escape="offen = false" x-on:click.outside="offen = false">
                        <x-fa::icon-button icon="heroicon-m-ellipsis-horizontal" label="Weitere Aktionen zur Anleitung" size="sm" x-on:click="toggle($event)" aria-haspopup="menu" x-bind:aria-expanded="offen" />
                        <div x-bind:class="{ hidden: ! offen }" x-bind:style="pos" role="menu" class="hidden w-60 fa-surface shadow-lg py-1">
                            <button type="button" role="menuitem" wire:click="$toggle('importOffen')" x-on:click="offen = false" class="{{ $menuePunkt }}"
                                    title="Markdown einfügen und in Schritte umwandeln" data-import-toggle>
                                @svg('heroicon-o-document-arrow-down', 'w-4 h-4 text-[var(--fa-ink-3)]') Markdown einfügen
                            </button>
                            @if($schritte->isNotEmpty())
                                {{-- Spec 27 Phase 4: Postenzettel zum Aufhängen (mit oder ohne Fotos) --}}
                                <a href="{{ route('foodalchemist.rezepte.anleitung', ['recipe' => $rezept->id]) }}" target="_blank" role="menuitem"
                                   x-on:click="offen = false" class="{{ $menuePunkt }}" title="Anleitung als Postenzettel drucken" data-anleitung-drucken>
                                    @svg('heroicon-o-printer', 'w-4 h-4 text-[var(--fa-ink-3)]') Postenzettel drucken
                                </a>
                            @endif
                        </div>
                    </div>
                </span>
            @endif
        </div>

        {{-- ── KI-Vorgabe: eigene Vorgabe für DIESE Schrittfolge ──────────
             Eingabe-Werkzeug, kein Wissensspeicher: nicht persistiert, wirkt nur auf den
             nächsten KI-Klick und wird nach der Übernahme geleert. Leer = die KI entscheidet
             fachlich frei; gefüllt = sie folgt der Vorgabe und sieht den Kontext trotzdem
             vollständig (Zutaten, Komponenten, Regelwerk, Ebenen-Abgrenzung). --}}
        @if($briefingOffen && $schreibbar)
            <div class="{{ $kasten }}" data-ki-briefing>
                <div class="flex flex-wrap items-center gap-2">
                    <p class="text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]">Vorgabe für «{{ $kiKnopf }}»</p>
                    <span class="ml-auto flex items-center gap-1.5">
                        @include('foodalchemist::livewire.recipes.partials.diktat-knopf', [
                            'audio' => 'briefingAudio', 'marker' => 'briefing', 'label' => 'Sprechen',
                        ])
                        @if(trim($kiBriefing) !== '')
                            <x-fa::button size="sm" variant="ghost" wire:click="$set('kiBriefing', '')" data-briefing-leeren>Vorgabe leeren</x-fa::button>
                        @endif
                    </span>
                </div>
                <textarea wire:model.blur="kiBriefing" rows="3" aria-label="Vorgabe für die KI"
                          class="fa-control py-2 text-[length:var(--fa-text-md)] leading-relaxed"
                          data-briefing-feld
                          placeholder="{{ $kiPlatzhalter }}"></textarea>
                <p class="{{ $hinweis }}">Gilt nur für den nächsten KI-Vorschlag und wird nicht am Rezept gespeichert.</p>
            </div>
        @endif

        {{-- ── Markdown-Import ─────────────────────────────────────────── --}}
        @if($importOffen && $schreibbar)
            <div class="{{ $kasten }}" data-markdown-import>
                <p class="text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]">Markdown in Schritte umwandeln</p>
                <textarea wire:model="markdownImport" rows="6" aria-label="Markdown"
                          class="fa-control py-2 font-mono text-[length:var(--fa-text-md)] leading-relaxed"
                          placeholder="## Mise en Place&#10;1. Zwiebeln schneiden.&#10;2. Fond erhitzen.&#10;&#10;## Finish&#10;3. Montieren."></textarea>
                <div class="flex flex-wrap items-center gap-2">
                    <x-fa::button size="sm" icon="heroicon-m-arrow-path" wire:click="markdownUebernehmen" wire:confirm="Ersetzt die bestehenden Schritte. Fortfahren?"
                            data-import-uebernehmen>In Schritte umwandeln</x-fa::button>
                    <span class="{{ $hinweis }}">## wird ein Abschnitt, 1. oder - ein Schritt. Text ohne Zeichen hängt am vorigen Schritt.</span>
                </div>
            </div>
        @endif

        {{-- ── KI-Vorschlag (GL-07: nichts auto-persistiert) ───────────── --}}
        @if($kiVorschlag !== null)
            <div class="rounded-[var(--fa-radius-control)] bg-[var(--fa-accent-soft)] border border-[var(--fa-accent-line)] px-3 py-2.5 flex flex-col gap-2 max-h-64 overflow-y-auto" data-ki-vorschlag>
                <p class="text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-accent)]">KI-Vorschlag: {{ $anzahl(count($kiVorschlag['steps']), 'Schritt', 'Schritte') }} ({{ round($kiVorschlag['confidence'] * 100) }} % sicher)</p>
                <ol class="flex flex-col gap-1 text-[length:var(--fa-text-md)] text-[var(--fa-ink)] list-decimal list-inside">
                    @foreach($kiVorschlag['steps'] as $i => $s)
                        <li wire:key="kis-{{ $i }}">
                            @if($s['phase'])<span class="mr-1 font-semibold text-[var(--fa-accent)]">{{ $s['phase'] }}</span>@endif
                            {{ $s['text'] }}
                        </li>
                    @endforeach
                </ol>
                <div class="flex flex-wrap items-center gap-2">
                    <x-fa::button size="sm" icon="heroicon-m-check" wire:click="kiUebernehmen" wire:confirm="Ersetzt die bestehenden Schritte. Fortfahren?"
                            data-ki-uebernehmen>Vorschlag übernehmen</x-fa::button>
                    <x-fa::button size="sm" variant="ghost" wire:click="kiVerwerfen">Verwerfen</x-fa::button>
                </div>
            </div>
        @endif

        {{-- ── ANSICHT: BEARBEITEN ─────────────────────────────────────── --}}
        <div x-show="ansicht === 'bearbeiten'" class="flex flex-col" data-schritt-liste>
            @forelse($schritte as $s)
                <div class="flex items-start gap-2 py-2.5 border-t border-[var(--fa-line)] first:border-t-0 rounded-[var(--fa-radius-control)]" wire:key="step-{{ $s->id }}"
                     @dragover.prevent
                     @drop.prevent="if (dragId !== null && dragId !== {{ $s->id }}) $wire.verschieben(dragId, {{ $s->id }}); dragId = null"
                     :class="{ 'outline outline-2 outline-offset-1 outline-[var(--fa-accent)]': dragId !== null && dragId !== {{ $s->id }} }">

                    <span class="shrink-0 flex items-center gap-1 pt-1">
                        @if($schreibbar)
                            {{-- Umsortieren: Griff zum Ziehen + Pfeile als zuverlässige Alternative --}}
                            <span class="inline-flex cursor-grab active:cursor-grabbing text-[var(--fa-ink-3)] hover:text-[var(--fa-ink)] select-none"
                                  draggable="true"
                                  @dragstart="dragId = {{ $s->id }}; $event.dataTransfer.effectAllowed = 'move'"
                                  @dragend="dragId = null"
                                  title="Ziehen zum Umsortieren">@svg('heroicon-m-bars-3', 'w-4 h-4')</span>
                            <span class="inline-flex flex-col">
                                <button type="button" wire:click="hoch({{ $s->id }})" @disabled($loop->first) aria-label="Schritt nach oben"
                                        class="inline-flex text-[var(--fa-ink-3)] hover:text-[var(--fa-ink)] disabled:opacity-30 disabled:pointer-events-none" title="Nach oben">@svg('heroicon-m-chevron-up', 'w-4 h-4')</button>
                                <button type="button" wire:click="runter({{ $s->id }})" @disabled($loop->last) aria-label="Schritt nach unten"
                                        class="inline-flex text-[var(--fa-ink-3)] hover:text-[var(--fa-ink)] disabled:opacity-30 disabled:pointer-events-none" title="Nach unten">@svg('heroicon-m-chevron-down', 'w-4 h-4')</button>
                            </span>
                        @endif
                        <span class="{{ $nummer }}">{{ $s->position }}</span>
                    </span>

                    <div class="flex-1 min-w-0 flex flex-col gap-1.5">
                        <div class="flex flex-wrap items-center gap-1.5">
                            <x-fa::input size="sm" class="w-44 max-w-full" list="fa-phasen-{{ $rezept->id }}"
                                   wire:model.blur="phasen.{{ $s->id }}" placeholder="Abschnitt (optional)" aria-label="Abschnitt"
                                   :disabled="! $schreibbar" data-step-phase />
                            @if($schreibbar)
                                <x-fa::button size="sm" variant="ghost" icon="heroicon-m-photo" wire:click="poolOeffnen({{ $s->id }})"
                                        title="Foto aus dem Pool wählen oder hochladen" data-step-foto-add>Foto zuordnen</x-fa::button>
                                <x-fa::icon-button icon="heroicon-m-trash" label="Schritt löschen" tone="danger" size="sm" class="ml-auto"
                                        wire:click="schrittLoeschen({{ $s->id }})" wire:confirm="Schritt löschen?" data-step-loeschen />
                            @endif
                        </div>

                        <textarea class="fa-control py-2 text-[length:var(--fa-text-md)] leading-relaxed resize-y min-h-9" rows="2" wire:model.blur="texte.{{ $s->id }}"
                                  placeholder="Was passiert in diesem Schritt? Temperatur und Zeit konkret." aria-label="Schritt {{ $s->position }}"
                                  @disabled(! $schreibbar) data-step-text></textarea>

                        @if($s->photos->isNotEmpty())
                            <div class="flex flex-wrap gap-2">
                                @foreach($s->photos as $foto)
                                    <span class="relative group" wire:key="sp-{{ $s->id }}-{{ $foto->id }}">
                                        <img src="{{ $foto->url() }}" alt="{{ $foto->caption ?? '' }}" title="{{ $foto->caption ?? '' }}"
                                             class="w-14 h-10 object-cover rounded-[var(--fa-radius-control)] border border-[var(--fa-line)]" loading="lazy" />
                                        @if($schreibbar)
                                            <button type="button" wire:click="fotoEntkoppeln({{ $s->id }}, {{ $foto->id }})"
                                                    class="hidden group-hover:flex focus-visible:flex absolute -top-2 -right-2 w-5 h-5 items-center justify-center rounded-full bg-[var(--fa-ink)] text-[var(--fa-surface)]"
                                                    title="Vom Schritt lösen, das Foto bleibt im Pool" aria-label="Foto vom Schritt lösen" data-foto-entkoppeln>@svg('heroicon-m-x-mark', 'w-3.5 h-3.5')</button>
                                        @endif
                                    </span>
                                @endforeach
                            </div>
                        @endif

                        {{-- Foto-Pool, aufgeklappt für genau diesen Schritt --}}
                        @if($aktiverSchritt === $s->id && $schreibbar)
                            @include('foodalchemist::livewire.recipes.partials.step-photo-pool', [
                                'stepId' => $s->id, 'pool' => $pool, 'verlinkteIds' => $s->photos->pluck('id')->all(),
                            ])
                        @endif
                    </div>
                </div>
            @empty
                <x-fa::empty compact icon="heroicon-o-queue-list" title="Noch keine Schritte">
                    Ersten Schritt hinzufügen, Markdown einfügen oder die KI Schritte vorschlagen lassen.
                </x-fa::empty>
            @endforelse

            <datalist id="fa-phasen-{{ $rezept->id }}">
                @foreach($phasenVorschlaege as $p)
                    <option value="{{ $p }}"></option>
                @endforeach
                <option value="Mise en Place"></option>
                <option value="Garen"></option>
                <option value="Finish"></option>
            </datalist>

            @if($schreibbar)
                <div class="flex flex-wrap items-center gap-2 mt-1 pt-3 border-t border-[var(--fa-line)]">
                    <x-fa::button size="sm" icon="heroicon-m-plus" wire:click="schrittAnlegen" data-schritt-anlegen>Schritt hinzufügen</x-fa::button>
                    @if($aktiverSchritt === null)
                        <x-fa::button size="sm" variant="ghost" icon="heroicon-m-photo" wire:click="poolOeffnen(0)" data-pool-allgemein>Fotos verwalten</x-fa::button>
                    @endif
                </div>
            @endif

            {{-- Pool ohne Schritt-Bezug (Upload/Löschen von allgemeinen Rezept-Fotos) --}}
            @if($aktiverSchritt === 0 && $schreibbar)
                @include('foodalchemist::livewire.recipes.partials.step-photo-pool', [
                    'stepId' => 0, 'pool' => $pool, 'verlinkteIds' => [],
                ])
            @endif

            @if($freieFotoIds !== [] && $aktiverSchritt === null)
                <p class="{{ $hinweis }} mt-2">{{ count($freieFotoIds) === 1 ? '1 Foto hängt' : count($freieFotoIds) . ' Fotos hängen' }} an keinem Schritt und {{ count($freieFotoIds) === 1 ? 'gilt' : 'gelten' }} als allgemeine Rezept-Fotos.</p>
            @endif
        </div>

        {{-- ── ANSICHT: ANLEITUNG (Karten) ─────────────────────────────── --}}
        <div x-show="ansicht === 'anleitung'" x-cloak class="flex flex-col gap-2" data-anleitung>
            {{-- Endprodukt zuerst: der Koch will erst sehen, wo er hin will --}}
            @if($endprodukt !== null)
                <div class="flex flex-wrap items-start gap-3 rounded-[var(--fa-radius-control)] bg-[var(--fa-warn-soft)] px-3 py-2.5" data-endprodukt>
                    <img src="{{ $endprodukt->url() }}" alt="{{ $endprodukt->caption ?? 'Endprodukt' }}" loading="lazy"
                         class="w-36 h-26 object-cover rounded-[var(--fa-radius-control)] border border-[var(--fa-line)]" />
                    <div class="min-w-0">
                        <p class="text-[length:var(--fa-text-sm)] font-semibold text-[var(--fa-warn)]">So soll es fertig aussehen</p>
                        @if($endprodukt->caption)
                            <p class="mt-0.5 text-[length:var(--fa-text-md)] leading-snug text-[var(--fa-ink)]">{{ $endprodukt->caption }}</p>
                        @endif
                    </div>
                </div>
            @endif
            @php $letztePhase = '__init__'; @endphp
            @forelse($schritte as $s)
                @if(($s->phase ?? '') !== $letztePhase)
                    @php $letztePhase = $s->phase ?? ''; @endphp
                    @if($letztePhase !== '')
                        <p class="pt-2 text-[length:var(--fa-text-md)] font-semibold text-[var(--fa-accent)]">{{ $letztePhase }}</p>
                    @endif
                @endif
                <div class="flex items-start gap-2.5 rounded-[var(--fa-radius-control)] bg-[var(--fa-ground)] border border-[var(--fa-line)] px-3 py-2.5" wire:key="card-{{ $s->id }}">
                    <span class="{{ $nummer }} mt-px">{{ $s->position }}</span>
                    <div class="flex-1 min-w-0">
                        <div class="text-[length:var(--fa-text-md)] leading-snug text-[var(--fa-ink)]">{!! \Illuminate\Support\Str::inlineMarkdown((string) $s->text) !!}</div>
                        @if($s->photos->isNotEmpty())
                            <div class="flex flex-wrap gap-2 mt-2">
                                @foreach($s->photos as $foto)
                                    <figure class="w-32" wire:key="cardf-{{ $s->id }}-{{ $foto->id }}">
                                        <img src="{{ $foto->url() }}" alt="{{ $foto->caption ?? "Schritt {$s->position}" }}"
                                             class="w-32 h-24 object-cover rounded-[var(--fa-radius-control)] border border-[var(--fa-line)]" loading="lazy" />
                                        @if($foto->caption)
                                            <figcaption class="mt-0.5 truncate {{ $hinweis }}">{{ $foto->caption }}</figcaption>
                                        @endif
                                    </figure>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            @empty
                <x-fa::empty compact icon="heroicon-o-queue-list" title="Keine Schritte erfasst" />
            @endforelse
        </div>
    </div>
    @endif
</div>
