{{--
    Spec 27 Phase 2 — Schritt-für-Schritt-Editor.
    Nummer + Text + Foto(s) sind EINE Zeile; Reorder per ⠿/▲▼ (server-seitig, damit
    Foto-Verknüpfungen sofort möglich sind — sie brauchen echte Schritt-IDs).

    Styling als rohes CSS in einem gescopeten <style>-Block (hell + .fa-editor-panel
    dunkel): Ui.php wird von Tailwind nicht gescannt, arbitrary Klassen wären im
    Host-Build nicht kompiliert (s. modal.blade.php / zutaten-kern.blade.php).
--}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<div data-schritt-editor>
    <style>
        [data-schritt-editor] .fa-step-row{ display:flex; align-items:flex-start; gap:.5rem; padding:.4rem .25rem; border-top:1px solid rgba(0,0,0,.05); }
        [data-schritt-editor] .fa-step-row:first-of-type{ border-top:0; }
        [data-schritt-editor] .fa-step-nr{ flex:0 0 auto; width:1.5rem; height:1.5rem; display:inline-flex; align-items:center; justify-content:center;
            border-radius:9999px; background:rgba(139,92,246,.14); color:#6d28d9; font-size:11px; font-weight:600; font-variant-numeric:tabular-nums; }
        [data-schritt-editor] .fa-step-text{ width:100%; font-size:13px; line-height:1.45; resize:vertical; min-height:2.1rem; }
        [data-schritt-editor] .fa-step-phase{ width:11rem; font-size:11px; }
        [data-schritt-editor] .fa-step-thumb{ width:3.5rem; height:2.6rem; object-fit:cover; border-radius:.375rem; border:1px solid rgba(0,0,0,.10); }
        [data-schritt-editor] .fa-step-pool{ background:rgba(0,0,0,.03); border-radius:.5rem; padding:.5rem .625rem; }
        [data-schritt-editor] .fa-step-pool-on{ outline:2px solid #7c3aed; outline-offset:1px; }
        [data-schritt-editor] .fa-step-card{ background:rgba(0,0,0,.03); border-radius:.625rem; padding:.625rem .75rem; }
        [data-schritt-editor] .fa-step-phasehead{ font-size:10px; font-weight:600; letter-spacing:.06em; text-transform:uppercase; color:#6d28d9; }
        [data-schritt-editor] .fa-step-hint{ font-size:11px; color:#6b7280; }

        .fa-editor-panel [data-schritt-editor] .fa-step-row{ border-color:rgba(255,255,255,.08); }
        .fa-editor-panel [data-schritt-editor] .fa-step-nr{ background:rgba(139,92,246,.30); color:#e9d5ff; }
        .fa-editor-panel [data-schritt-editor] .fa-step-thumb{ border-color:rgba(255,255,255,.15); }
        .fa-editor-panel [data-schritt-editor] .fa-step-pool,
        .fa-editor-panel [data-schritt-editor] .fa-step-card{ background:rgba(255,255,255,.06); }
        .fa-editor-panel [data-schritt-editor] .fa-step-phasehead{ color:#c4b5fd; }
        .fa-editor-panel [data-schritt-editor] .fa-step-hint{ color:#94a3b8; }
    </style>

    @if($fehler)
        <div class="mb-2 rounded-lg bg-rose-500/10 border border-rose-500/30 px-3 py-1.5 text-[11px] text-rose-600" data-schritt-fehler>{{ $fehler }}</div>
    @endif

    @if($rezept === null)
        <p class="fa-step-hint">Rezept erst speichern — danach lässt sich die Anleitung aufbauen.</p>
    @else
    <div x-data="{ ansicht: 'bearbeiten', dragId: null }">

        {{-- ── Kopfzeile: Ansicht + Aktionen ───────────────────────────── --}}
        <div class="flex flex-wrap items-center gap-1 mb-2">
            <button type="button" @click="ansicht = 'bearbeiten'"
                    :class="ansicht === 'bearbeiten' ? '{{ $variantPill['primary'] }}' : '{{ $variantPill['secondary'] }}'"
                    class="{{ $pill }}" data-tab-bearbeiten>Bearbeiten</button>
            <button type="button" @click="ansicht = 'anleitung'"
                    :class="ansicht === 'anleitung' ? '{{ $variantPill['primary'] }}' : '{{ $variantPill['secondary'] }}'"
                    class="{{ $pill }}" data-tab-anleitung>Anleitung</button>
            <span class="fa-step-hint ml-2">{{ $schritte->count() }} Schritte · {{ $pool->count() }} Fotos</span>

            @if($schreibbar)
                <span class="ml-auto flex items-center gap-1.5">
                    <button type="button" wire:click="kiSchritte" wire:loading.attr="disabled" wire:target="kiSchritte"
                            class="{{ $btnAi }}" title="Schritt-Vorschlag aus den Zutaten (nichts wird gespeichert)" data-ki-schritte>
                        @svg('heroicon-o-sparkles', 'w-3.5 h-3.5')
                        <span wire:loading.remove wire:target="kiSchritte">Schritte</span>
                        <span wire:loading wire:target="kiSchritte">denkt …</span>
                    </button>
                    <button type="button" wire:click="$toggle('importOffen')" class="{{ $btnGhostXs }}"
                            title="Markdown einfügen und in Schritte parsen" data-import-toggle>Markdown einfügen</button>
                    @if($schritte->isNotEmpty())
                        {{-- Spec 27 Phase 4: Postenzettel zum Aufhängen (mit oder ohne Fotos) --}}
                        <a href="{{ route('foodalchemist.rezepte.anleitung', ['recipe' => $rezept->id]) }}" target="_blank"
                           class="{{ $btnGhostXs }}" title="Anleitung als Postenzettel drucken" data-anleitung-drucken>Drucken</a>
                    @endif
                </span>
            @endif
        </div>

        {{-- ── Markdown-Import ─────────────────────────────────────────── --}}
        @if($importOffen && $schreibbar)
            <div class="fa-step-pool mb-2" data-markdown-import>
                <p class="{{ $dt }} mb-1">Markdown → Schritte</p>
                <textarea wire:model="markdownImport" rows="6" class="{{ $input }} font-mono text-[11px]"
                          placeholder="## Mise en Place&#10;1. Zwiebeln schneiden.&#10;2. Fond erhitzen.&#10;&#10;## Finish&#10;3. Montieren."></textarea>
                <div class="flex items-center gap-2 mt-1.5">
                    <button type="button" wire:click="markdownUebernehmen" wire:confirm="Ersetzt die bestehenden Schritte. Fortfahren?"
                            class="{{ $btnPrimary }}" data-import-uebernehmen>In Schritte umwandeln</button>
                    <span class="fa-step-hint"><code>##</code> = Abschnitt · <code>1.</code> / <code>-</code> = Schritt · Text ohne Marker hängt am vorigen Schritt</span>
                </div>
            </div>
        @endif

        {{-- ── KI-Vorschlag (GL-07: nichts auto-persistiert) ───────────── --}}
        @if($kiVorschlag !== null)
            <div class="mb-2 rounded-lg bg-violet-500/10 border border-violet-500/30 px-3 py-2 max-h-56 overflow-y-auto" data-ki-vorschlag>
                <p class="{{ $dt }} mb-1">KI-Vorschlag — {{ count($kiVorschlag['steps']) }} Schritte ({{ round($kiVorschlag['confidence'] * 100) }} %)</p>
                <ol class="text-[11px] text-violet-700 space-y-0.5 list-decimal list-inside">
                    @foreach($kiVorschlag['steps'] as $i => $s)
                        <li wire:key="kis-{{ $i }}">
                            @if($s['phase'])<span class="fa-step-phasehead mr-1">{{ $s['phase'] }}</span>@endif
                            {{ $s['text'] }}
                        </li>
                    @endforeach
                </ol>
                <div class="flex items-center gap-2 mt-1.5">
                    <button type="button" wire:click="kiUebernehmen" wire:confirm="Ersetzt die bestehenden Schritte. Fortfahren?"
                            class="{{ $btnGhostXs }} text-emerald-600" data-ki-uebernehmen>Übernehmen</button>
                    <button type="button" wire:click="kiVerwerfen" class="{{ $btnGhostXs }}">Verwerfen</button>
                </div>
            </div>
        @endif

        {{-- ── ANSICHT: BEARBEITEN ─────────────────────────────────────── --}}
        <div x-show="ansicht === 'bearbeiten'" data-schritt-liste>
            @forelse($schritte as $s)
                <div class="fa-step-row" wire:key="step-{{ $s->id }}"
                     @dragover.prevent
                     @drop.prevent="if (dragId !== null && dragId !== {{ $s->id }}) $wire.verschieben(dragId, {{ $s->id }}); dragId = null"
                     :class="{ 'fa-step-pool-on': dragId !== null && dragId !== {{ $s->id }} }">

                    <span class="shrink-0 flex items-center gap-1 pt-0.5">
                        @if($schreibbar)
                            @include('foodalchemist::livewire.settings.partials.reorder-cell', [
                                'id' => $s->id, 'upMethod' => 'hoch', 'downMethod' => 'runter',
                                'first' => $loop->first, 'last' => $loop->last,
                            ])
                        @endif
                        <span class="fa-step-nr">{{ $s->position }}</span>
                    </span>

                    <div class="flex-1 min-w-0 space-y-1">
                        <div class="flex flex-wrap items-center gap-1.5">
                            <input type="text" class="{{ $input }} fa-step-phase !py-0.5" list="fa-phasen-{{ $rezept->id }}"
                                   wire:model.blur="phasen.{{ $s->id }}" placeholder="Abschnitt (optional)"
                                   @disabled(! $schreibbar) data-step-phase />
                            @if($schreibbar)
                                <button type="button" wire:click="poolOeffnen({{ $s->id }})" class="{{ $btnGhostXs }}"
                                        title="Foto aus dem Pool wählen oder hochladen" data-step-foto-add>
                                    ＋ Foto
                                </button>
                                <button type="button" wire:click="schrittLoeschen({{ $s->id }})" wire:confirm="Schritt löschen?"
                                        class="{{ $btnGhostXs }} text-rose-500 ml-auto" data-step-loeschen>Schritt löschen</button>
                            @endif
                        </div>

                        <textarea class="{{ $input }} fa-step-text" rows="2" wire:model.blur="texte.{{ $s->id }}"
                                  placeholder="Was passiert in diesem Schritt? (Temperatur/Zeit konkret)"
                                  @disabled(! $schreibbar) data-step-text></textarea>

                        @if($s->photos->isNotEmpty())
                            <div class="flex flex-wrap gap-1.5">
                                @foreach($s->photos as $foto)
                                    <span class="relative group" wire:key="sp-{{ $s->id }}-{{ $foto->id }}">
                                        <img src="{{ $foto->url() }}" alt="{{ $foto->caption ?? '' }}" title="{{ $foto->caption ?? '' }}"
                                             class="fa-step-thumb" loading="lazy" />
                                        @if($schreibbar)
                                            <button type="button" wire:click="fotoEntkoppeln({{ $s->id }}, {{ $foto->id }})"
                                                    class="hidden group-hover:flex absolute -top-1.5 -right-1.5 w-4 h-4 items-center justify-center rounded-full bg-slate-700 text-white text-[9px]"
                                                    title="von diesem Schritt lösen (Foto bleibt im Pool)" data-foto-entkoppeln>✕</button>
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
                <p class="fa-step-hint py-2">Noch keine Schritte. „＋ Schritt" anlegen oder oben Markdown einfügen.</p>
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
                <div class="flex items-center gap-2 mt-2 pt-2 border-t border-black/5">
                    <button type="button" wire:click="schrittAnlegen" class="{{ $btnPrimary }}" data-schritt-anlegen>＋ Schritt</button>
                    @if($aktiverSchritt === null)
                        <button type="button" wire:click="poolOeffnen(0)" class="{{ $btnGhostXs }}" data-pool-allgemein>Fotos verwalten</button>
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
                <p class="fa-step-hint mt-1">{{ count($freieFotoIds) }} Foto(s) hängen an keinem Schritt — sie gelten als allgemeine Rezept-Fotos.</p>
            @endif
        </div>

        {{-- ── ANSICHT: ANLEITUNG (Karten) ─────────────────────────────── --}}
        <div x-show="ansicht === 'anleitung'" x-cloak class="space-y-2" data-anleitung>
            @php($letztePhase = '__init__')
            @forelse($schritte as $s)
                @if(($s->phase ?? '') !== $letztePhase)
                    @php($letztePhase = $s->phase ?? '')
                    @if($letztePhase !== '')
                        <p class="fa-step-phasehead pt-1">{{ $letztePhase }}</p>
                    @endif
                @endif
                <div class="fa-step-card flex items-start gap-2.5" wire:key="card-{{ $s->id }}">
                    <span class="fa-step-nr mt-px">{{ $s->position }}</span>
                    <div class="flex-1 min-w-0">
                        <div class="text-[13px] leading-snug">{!! \Illuminate\Support\Str::inlineMarkdown((string) $s->text) !!}</div>
                        @if($s->photos->isNotEmpty())
                            <div class="flex flex-wrap gap-2 mt-1.5">
                                @foreach($s->photos as $foto)
                                    <figure class="w-32" wire:key="cardf-{{ $s->id }}-{{ $foto->id }}">
                                        <img src="{{ $foto->url() }}" alt="{{ $foto->caption ?? "Schritt {$s->position}" }}"
                                             class="w-32 h-24 object-cover rounded-lg border border-black/10" loading="lazy" />
                                        @if($foto->caption)
                                            <figcaption class="text-[10px] text-gray-500 mt-0.5 truncate">{{ $foto->caption }}</figcaption>
                                        @endif
                                    </figure>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            @empty
                <p class="fa-step-hint">Keine Schritte erfasst.</p>
            @endforelse
        </div>
    </div>
    @endif
</div>
