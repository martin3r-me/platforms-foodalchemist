{{-- M7-10: Voice — MediaRecorder (gemeinsamer Baustein) → STT → Tool-Loop; Proposals mit
     Bestätigen (GL-07). Spec 53/D: Roundtrip in zwei sichtbare Server-Schritte gesplittet
     (Transkription → `verstehen()`), Provider-Pill, verständliche Fehlertexte. --}}
{{-- Spec 53/D Hotfix: Recorder-Bundle über @assets (server-seitig in den <head> gehoben, vor Alpine).
     Ein rohes <script> als ERSTES Tag der Komponente bekam von Livewire das wire:id
     (Utils::insertAttributesIntoHtmlRoot hängt es an das erste Tag) — das Modal gehörte damit zur
     Eltern-Komponente (Sidebar), $wire.upload lief gegen foodalchemist.sidebar ohne WithFileUploads. --}}
@assets
<script src="/_platform/fa-assets/foodalchemist-voice-recorder.iife.js?v={{ config('platform.fa_voice_recorder_hash', '0') }}"></script>
@endassets
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<x-foodalchemist::modal name="voice-modal" title="Sprachbefehl" size="max-w-xl">
    <div class="space-y-3" data-voice>

        {{-- Provider-Transparenz (Aufgabe 4): vorher unsichtbar, ob echt transkribiert wird
             oder der Fake-Fixtext antwortet. Aufgabe F: Modus-Pill daneben — ein Klick öffnet
             die Einstellungen (dort steht die Radio-Gruppe, settings/ki.blade.php). --}}
        <div class="flex items-center gap-2 flex-wrap">
            <span class="{{ $pill }} {{ $aufnahmeMoeglich ? $variantPill['success'] : $variantPill['warning'] }}" data-voice-provider>
                STT: {{ ['openai' => 'OpenAI', 'assemblyai' => 'AssemblyAI', 'fake' => 'Test-Fixtext', 'none' => 'nicht konfiguriert'][$provider] ?? $provider }}
            </span>
            <a href="{{ route('foodalchemist.einstellungen') }}" wire:navigate class="{{ $pill }} {{ $variantPill['secondary'] }}" data-voice-modus title="Klicken zum Ändern in den Einstellungen">
                Modus: {{ \Platform\FoodAlchemist\Livewire\Settings\Ki::MODUS_LABEL[$agentModus] ?? $agentModus }}
            </a>
            @unless($aufnahmeMoeglich)
                <span class="text-[11px] text-amber-600" data-voice-provider-hinweis>Spracherkennung ist nicht konfiguriert — Befehl tippen.</span>
            @endunless
        </div>

        {{-- Aufnahme: gemeinsamer Recorder-Baustein (window.FaVoiceRecorder), Root mit eigenem
             wire:key — Server-Status liegt AUSSERHALB dieses Blocks, damit ein Re-Render des
             Server-Status (Polling/Redirect) den laufenden Alpine-Aufnahmezustand nicht zerstört. --}}
        @if($aufnahmeMoeglich)
            <div wire:key="voice-recorder" x-data="FaVoiceRecorder({ property: 'audio', maxMs: 20000, minMs: 700 })" class="space-y-1.5">
                <div class="flex items-center gap-2">
                    <button type="button" @click="laeuft ? stop() : start()" :disabled="! unterstuetzt"
                            :class="laeuft ? 'animate-pulse' : ''" class="{{ $btnPrimary }} disabled:opacity-40" data-voice-rec>
                        <span class="inline-flex items-center gap-1.5">
                            <span x-show="laeuft" x-cloak>@svg('heroicon-o-stop', 'w-3.5 h-3.5')</span>
                            <span x-show="! laeuft">@svg('heroicon-o-microphone', 'w-3.5 h-3.5')</span>
                            <span x-text="laeuft ? 'Stopp & senden' : 'Aufnahme starten'"></span>
                        </span>
                    </button>
                    <span class="text-[11px] text-gray-500">Kurz-Befehl sprechen (wenige Sekunden) — z. B. »Suche BBQ-Sauce«, »Öffne Rezept …«, »Öffne die Planung«</span>
                </div>
                <p class="text-[11px] text-gray-500" x-show="! unterstuetzt" x-cloak data-voice-nicht-unterstuetzt>
                    Sprachaufnahme wird von diesem Browser nicht unterstützt — Befehl tippen.
                </p>
                <p class="text-[11px] text-gray-500" x-show="laeuft" x-cloak data-voice-status-aufnahme>
                    Aufnahme läuft … <span x-text="sekunden"></span>s / 20s
                </p>
                <p class="text-[11px] text-gray-500" x-show="hochladenLaeuft" x-cloak data-voice-status-upload>
                    Audio wird hochgeladen …
                </p>
                <p class="text-xs text-rose-500" x-show="fehler" x-cloak x-text="fehler" data-voice-rec-fehler></p>
            </div>
        @endif

        {{-- Server-Status: OHNE Ladezustand liefen vorher goKaskade & Co. still 5-20 s — hier
             transkribieren → verstehen → ausführen als eigene, sichtbare Zeile. --}}
        <p class="text-[11px] text-violet-600" wire:loading wire:target="audio" data-voice-status="erkennen">
            Sprache wird erkannt …
        </p>
        @if($transcript !== null && $phase === 'verstehen')
            <p class="text-[11px] text-gray-500" data-voice-transcript>Transkript: »{{ $transcript }}«</p>
        @endif
        {{-- Befund 2026-09-17: der Tool-Loop läuft synchron in DIESEM Request — echte Rundenzahl
             ist serverseitig nicht live zeigbar, darum der Zeitbudget-Hinweis statt eines
             Fortschrittsbalkens (asynchrone Job-Variante wie Paket C ist eine spätere Entscheidung). --}}
        <p class="text-[11px] text-violet-600 inline-flex items-center gap-1.5" wire:loading wire:target="verstehen,verarbeiteText" data-voice-status="verstehen">
            <span class="inline-block w-1.5 h-1.5 rounded-full bg-violet-500 animate-pulse"></span>
            Befehl wird verstanden und ausgeführt … (kann bis zu 30 s dauern)
        </p>

        {{-- Fallback/Sandbox: Befehl tippen — IMMER verfügbar, auch ohne STT-Zugang. --}}
        <form wire:submit.prevent="verarbeiteText($refs.cmd.value)" @submit="$refs.cmd.value = ''" class="flex gap-2">
            <input type="text" x-ref="cmd" placeholder="… oder Befehl tippen" required minlength="2"
                   wire:loading.attr="disabled" wire:target="verstehen,verarbeiteText"
                   class="{{ $input }} flex-1" data-voice-text />
            <button type="submit" wire:loading.attr="disabled" wire:target="verstehen,verarbeiteText" class="{{ $btnGhostXs }} disabled:opacity-40">
                <span wire:loading.remove wire:target="verstehen,verarbeiteText">Senden</span>
                <span wire:loading wire:target="verstehen,verarbeiteText">…</span>
            </button>
        </form>

        @if($fehler !== null)
            <p class="text-xs text-rose-500" data-voice-fehler>{{ $fehler }}</p>
        @endif

        @if($ergebnis !== null)
            <div class="rounded-lg bg-black/[0.03] px-3 py-2 space-y-1.5" data-voice-ergebnis>
                @if($ergebnis['text'] !== null)
                    <p class="text-xs text-gray-900">{{ $ergebnis['text'] }}</p>
                @endif
                <p class="text-[10px] text-gray-500">{{ $ergebnis['runden'] }} Runde(n) · {{ count($ergebnis['tool_laeufe']) }} Tool-Aufruf(e) · {{ $ergebnis['elapsed_ms'] }} ms</p>

                {{-- Aufgabe 6: mehrere ui.OPEN/ui.NAVIGATE-Treffer — nur der erste navigiert,
                     der Rest bekommt hier einen Link (aktionZiel() in VoiceModal befüllt ihn). --}}
                @foreach(($ergebnis['aktionen'] ?? []) as $i => $a)
                    @if(isset($a['link']))
                        <a href="{{ $a['link'] }}" wire:navigate class="{{ $btnGhostXs }} inline-flex items-center gap-1" wire:key="al-{{ $i }}" data-voice-aktion-link>
                            {{ $a['link_label'] ?? 'Öffnen' }}
                        </a>
                    @endif
                @endforeach

                {{-- Aufgabe F: im Modus auto_sicher wurden accepted-Vorschläge OHNE Klick ausgeführt —
                     eigener Badge-Text macht das sichtbar statt „übernommen"/„gestartet" zu behaupten. --}}
                @php($autoBadge = '✓ automatisch ausgeführt')
                @foreach($ergebnis['proposals'] as $i => $p)
                    @if(($p['type'] ?? 'speisen_klasse') === 'speisen_klasse')
                        <div class="rounded bg-violet-500/10 border border-violet-500/30 px-2 py-1.5 text-xs" wire:key="vp-{{ $i }}" data-voice-proposal>
                            Speisen-Klasse: <span class="font-medium">{{ $p['klasse_name'] ?? 'kein Treffer' }}</span>
                            <span class="text-[11px] text-gray-500">· {{ round(($p['confidence'] ?? 0) * 100) }} %</span>
                            @if($p['accepted'] ?? false)
                                <span class="{{ $pill }} {{ $variantPill['success'] }} ml-1" data-voice-proposal-auto="{{ $agentModus === 'auto_sicher' ? '1' : '0' }}">{{ $agentModus === 'auto_sicher' ? $autoBadge : 'übernommen' }}</span>
                            @elseif(($p['klasse_id'] ?? null) !== null)
                                <button type="button" wire:click="proposalUebernehmen({{ $i }})" class="{{ $btnGhostXs }} text-emerald-600 ml-1" data-voice-proposal-accept>Bestätigen</button>
                            @endif
                        </div>
                    {{-- Aufgabe 7 / GL-07: „erstelle ein …" ist bis hier NUR Vorschlag (das Tool
                         schreibt nichts) — erst dieser Knopf legt die Planungs-Session an. --}}
                    @elseif($p['type'] === 'planung_start')
                        <div class="rounded bg-violet-500/10 border border-violet-500/30 px-2 py-1.5 text-xs space-y-1" wire:key="vp-{{ $i }}" data-voice-proposal-planung>
                            <p>Planung: <span class="font-medium">{{ ['rezept' => 'Basisrezept', 'gericht' => 'Gericht', 'concept' => 'Concept'][$p['scope']] ?? $p['scope'] }}</span>
                                @if($p['titel']) — {{ $p['titel'] }} @endif</p>
                            <p class="text-[11px] text-gray-600">{{ $p['brief'] }}</p>
                            @if(!empty($p['leitplanken']))
                                <p class="text-[11px] text-gray-500">
                                    @foreach($p['leitplanken'] as $k => $v)
                                        <span class="{{ $pill }} {{ $variantPill['secondary'] }} mr-1">{{ $k }}: {{ is_array($v) ? implode(',', $v) : $v }}</span>
                                    @endforeach
                                </p>
                            @endif
                            @if(!empty($p['unklar']))
                                <p class="text-[11px] text-amber-600">unklar: {{ implode(', ', $p['unklar']) }}</p>
                            @endif
                            @if($p['accepted'] ?? false)
                                <span class="{{ $pill }} {{ $variantPill['success'] }}" data-voice-proposal-auto="1">{{ $autoBadge }}: Planung angelegt, Editor geöffnet</span>
                            @else
                                <button type="button" wire:click="planungStarten({{ $i }})" wire:loading.attr="disabled" wire:target="planungStarten({{ $i }})"
                                        class="{{ $btnGhostXs }} text-emerald-600 disabled:opacity-40" data-voice-proposal-planung-start>
                                    Planung starten
                                </button>
                            @endif
                        </div>
                    @elseif($p['type'] === 'anreicherung')
                        <div class="rounded bg-violet-500/10 border border-violet-500/30 px-2 py-1.5 text-xs" wire:key="vp-{{ $i }}" data-voice-proposal-anreicherung>
                            Vollständig anreichern: <span class="font-medium">{{ $p['name'] ?? ('Rezept #' . $p['recipe_id']) }}</span>
                            @if($p['accepted'] ?? false)
                                <span class="{{ $pill }} {{ $variantPill['success'] }} ml-1" data-voice-proposal-auto="{{ $agentModus === 'auto_sicher' ? '1' : '0' }}">{{ $agentModus === 'auto_sicher' ? $autoBadge : 'gestartet' }}</span>
                            @else
                                <button type="button" wire:click="anreicherungStarten({{ $i }})" wire:loading.attr="disabled" wire:target="anreicherungStarten({{ $i }})"
                                        class="{{ $btnGhostXs }} text-emerald-600 ml-1 disabled:opacity-40" data-voice-proposal-anreicherung-start>
                                    Anreicherung starten
                                </button>
                            @endif
                        </div>
                    {{-- Paket F (1b): generischer Schreibvorschlag für JEDES andere FA-Write-Tool.
                         Mit Alias (VoiceCommandService::SCHREIBAKTION_ALIAS) zeigt die Vorschau
                         alt→neu je Feld; ohne Alias nur die rohen Argumente + Tool-Beschreibung. --}}
                    @elseif($p['type'] === 'schreibaktion')
                        <div class="rounded bg-violet-500/10 border border-violet-500/30 px-2 py-1.5 text-xs space-y-1" wire:key="vp-{{ $i }}" data-voice-proposal-schreibaktion data-voice-proposal-tool="{{ $p['tool'] }}">
                            <p>
                                {{ ($p['objekt']['type'] ?? null) === null ? 'Aktion' : ucfirst($p['objekt']['type']) }}:
                                <span class="font-medium">{{ $p['objekt']['name'] ?? (isset($p['objekt']['id']) ? '#' . $p['objekt']['id'] : $p['tool']) }}</span>
                                @if(!empty($p['objekt']['id']) && !empty($p['objekt']['name'])) (ID {{ $p['objekt']['id'] }}) @endif
                            </p>
                            @if($p['beschreibung'] ?? null)
                                <p class="text-[11px] text-gray-500">{{ $p['beschreibung'] }}</p>
                            @endif
                            @if(!empty($p['vorschau']))
                                <ul class="text-[11px] text-gray-600 space-y-0.5">
                                    @foreach($p['vorschau'] as $v)
                                        <li data-voice-vorschau-feld="{{ $v['feld'] }}">
                                            <span class="font-medium">{{ $v['feld'] }}</span>:
                                            @if(array_key_exists('alt', $v))
                                                <span class="line-through text-gray-400">{{ is_scalar($v['alt']) ? $v['alt'] : json_encode($v['alt']) }}</span> →
                                            @endif
                                            <span>{{ is_scalar($v['neu']) ? $v['neu'] : json_encode($v['neu']) }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                            @if($p['accepted'] ?? false)
                                <span class="{{ $pill }} {{ $variantPill['success'] }}">✓ ausgeführt</span>
                            @else
                                <button type="button" wire:click="schreibaktionAusfuehren({{ $i }})" wire:loading.attr="disabled" wire:target="schreibaktionAusfuehren({{ $i }})"
                                        class="{{ $btnGhostXs }} text-emerald-600 disabled:opacity-40" data-voice-proposal-schreibaktion-start>
                                    Bestätigen
                                </button>
                            @endif
                        </div>
                    @endif
                @endforeach
            </div>
        @endif
    </div>
</x-foodalchemist::modal>
