{{-- M7-10: Voice — MediaRecorder (gemeinsamer Baustein) → STT → Tool-Loop; Proposals mit
     Bestätigen (GL-07). Spec 53/D: Roundtrip in zwei sichtbare Server-Schritte gesplittet
     (Transkription → `verstehen()`), Provider-Pill, verständliche Fehlertexte. --}}
{{-- REGEL (Live-Bruch 2026-09-18): NIE ein geradeaus-Anführungszeichen in JS/Kommentaren
     INNERHALB der x-data- bzw. x-init-Attribute unten — das Attribut endet beim ERSTEN
     Anführungszeichen, egal ob roher Text oder Kommentar; Alpine bekommt dann nur ein
     Bruchstück und fällt für die GANZE Komponente aus. Backticks (`) oder Guillemets (»«)
     statt Anführungszeichen. `{{ }}`/`@js()`-Ausgaben sind sicher (escapen automatisch) —
     reiner Kommentartext/hartkodiertes JS ist es NICHT. Wächter-Test:
     tests/Feature/BladeXDataAttributeGuardTest.php (scannt ALLE Blade-Dateien des Moduls). --}}
{{-- Spec 53/D Hotfix: Recorder-Bundle über @assets (server-seitig in den <head> gehoben, vor Alpine).
     Ein rohes <script> als ERSTES Tag der Komponente bekam von Livewire das wire:id
     (Utils::insertAttributesIntoHtmlRoot hängt es an das erste Tag) — das Modal gehörte damit zur
     Eltern-Komponente (Sidebar), $wire.upload lief gegen foodalchemist.sidebar ohne WithFileUploads. --}}
@assets
<script src="/_platform/fa-assets/foodalchemist-voice-recorder.iife.js?v={{ config('platform.fa_voice_recorder_hash', '0') }}"></script>
@endassets
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

{{-- Spec 55: kein globales Modal mehr — der Agent ist ein einklappbares Panel, fest in der
     Planungs-Leitstelle eingebettet (planung/index.blade.php entscheidet, OB es überhaupt
     rendert, über foodalchemist.ai.voice_agent_panel_planung). Startet eingeklappt (Team-
     Entscheid Dominique), `x-init` löst dieselbe Initialisierung aus, die früher der
     Öffnen-Klick auslöste (Sitzung wiederherstellen, Kontext setzen — siehe VoiceModal::oeffnen()). --}}
<div class="rounded-lg border border-[var(--ui-border)] bg-[var(--ui-surface)]" data-voice-panel-planung
     x-data="{ aufgeklappt: false }" x-init="$wire.oeffnen()">
    <button type="button" @click="aufgeklappt = ! aufgeklappt"
            class="w-full flex items-center justify-between px-3 py-2 text-xs font-medium text-[var(--ui-secondary)]"
            data-voice-panel-toggle>
        <span class="inline-flex items-center gap-1.5">
            @svg('heroicon-o-microphone', 'w-4 h-4')
            Sprachbefehl-Agent
        </span>
        <span x-text="aufgeklappt ? '−' : '+'"></span>
    </button>
    <div class="px-3 pb-3 space-y-3" data-voice x-show="aufgeklappt" x-cloak>

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
            {{-- Spec 53 / Paket F (4): nur sichtbar, wenn es wirklich etwas zu vergessen gibt —
                 ein Knopf, der immer dasteht, suggeriert fälschlich ein laufendes Gespräch. --}}
            @if(!empty($ergebnis['proposals']))
                <button type="button" wire:click="vergessen" wire:loading.attr="disabled" wire:target="vergessen"
                        class="{{ $btnGhostXs }} text-gray-500 ml-auto disabled:opacity-40" data-voice-vergessen>
                    Gespräch vergessen
                </button>
            @endif
        </div>

        {{-- Aufnahme: gemeinsamer Recorder-Baustein (window.FaVoiceRecorder), Root mit eigenem
             wire:key — Server-Status liegt AUSSERHALB dieses Blocks, damit ein Re-Render des
             Server-Status (Polling/Redirect) den laufenden Alpine-Aufnahmezustand nicht zerstört.

             Spec 53 / Paket F (3): im Konversations-Modus (`konversationAktiv`, jetzt an
             `voice_tts_vorlesen` gekoppelt statt am entfernten „dauerhaft aktiv") läuft der
             Recorder mit VAD (Stille-Erkennung statt Klick-zum-Stoppen) und hört nach der
             vorgelesenen Antwort automatisch weiter zu — Ein-Klick-Modus (Standard) bleibt
             unverändert manuell. `<audio data-voice-tts>` ist das EINE Wiedergabe-Element für
             die TTS-Antwort; die Autoplay-Entsperrung passiert jetzt beim ERSTEN Aufnahme-Klick
             (s. u., `onclick` am Aufnahme-Knopf) — hier wird nur geprüft, ob sie stattgefunden
             hat (`dataset.faEntsperrt`), sonst greift sofort der `speechSynthesis`-Fallback. --}}
        @if($aufnahmeMoeglich)
            <div wire:key="voice-recorder"
                 x-data="{
                    ...FaVoiceRecorder({ property: 'audio', maxMs: 20000, minMs: 700, vad: @js($konversationAktiv) }),
                    konversationAktiv: @js($konversationAktiv),
                    fallbackAktiv: false,
                    autoZyklen: 0,
                    konversationPausiert: false,
                    initKonversation() {
                        const audio = this.$refs.ttsAudio;
                        $wire.on('{{ \Platform\FoodAlchemist\Livewire\VoiceModal::EVENT_TTS_BEREIT }}', (payload) => this._wiedergeben(audio, payload.url, payload.text));
                        $wire.on('{{ \Platform\FoodAlchemist\Livewire\VoiceModal::EVENT_TTS_FEHLGESCHLAGEN }}', (payload) => this._sprachausgabeFallback(payload.text));
                        if (audio) {
                            audio.addEventListener('ended', () => this._nachDemSprechen());
                        }
                        // Live-Befund Dominique (2026-09-18): `oeffnen()` liest das Team-Setting
                        // jetzt frisch (server-seitig), aber dieses x-data-Objekt wurde beim
                        // ERSTEN Rendern EINMAL mit dem damaligen Wert initialisiert — die Blade-
                        // Direktive fuer den Server-Wert laeuft nur EINMAL beim ersten Rendern,
                        // ein spaeterer Livewire-Roundtrip re-initialisiert dieses Objekt NICHT.
                        // `wire.konversationAktiv` selbst IST live (Alpines Livewire-Plugin liest
                        // es bei jedem Zugriff frisch) — dieser Watcher zieht den lokalen Zustand
                        // UND den Recorder-internen VAD-Schalter nach, statt an jeder Stelle
                        // `wire.konversationAktiv` einzeln aufzuloesen.
                        this.$watch(() => $wire.konversationAktiv, (aktiv) => {
                            this.konversationAktiv = !!aktiv;
                            this.vad = !!aktiv;
                        });
                    },
                    // Spec 55: STOPP-Sammelstelle bleibt (Stopp-Knopf im Panel, ESC) — die
                    // Fenster-Brücke für einen schwebenden Knopf ist weg (kein globales Element
                    // mehr, das von aussen stoppen können müsste).
                    stopAlles() {
                        if (this.laeuft) {
                            this.stop();
                        }
                        const audio = this.$refs.ttsAudio;
                        if (audio) {
                            audio.pause();
                        }
                        if ('speechSynthesis' in window) {
                            window.speechSynthesis.cancel();
                        }
                        this.fallbackAktiv = false;
                        this.konversationPausiert = true;
                        $wire.call('sprechenBeendet');
                    },
                    _wiedergeben(audio, url, text) {
                        if (! audio || audio.dataset.faEntsperrt !== '1') {
                            this._sprachausgabeFallback(text);
                            return;
                        }
                        audio.src = url;
                        audio.play().catch(() => this._sprachausgabeFallback(text));
                    },
                    _sprachausgabeFallback(text) {
                        // Autoplay blockiert ODER die Synthese ist serverseitig fehlgeschlagen —
                        // in BEIDEN Fällen bleibt speechSynthesis der Fallback, sichtbar als
                        // Status-Hinweis, nicht stumm. `sprechenBeendet()` läuft in jedem Fall,
                        // damit die VAD-Pause (`sprichtGerade`) nie hängen bleibt.
                        if ('speechSynthesis' in window && text) {
                            this.fallbackAktiv = true;
                            const u = new SpeechSynthesisUtterance(text);
                            u.lang = 'de-DE';
                            u.onend = () => this._nachDemSprechen();
                            u.onerror = () => this._nachDemSprechen();
                            window.speechSynthesis.speak(u);
                        } else {
                            this._nachDemSprechen();
                        }
                    },
                    _nachDemSprechen() {
                        this.fallbackAktiv = false;
                        $wire.call('sprechenBeendet');
                        if (! this.konversationAktiv || this.laeuft) {
                            return;
                        }
                        // Live-Bruch 2026-09-18 (c): Sicherheitsdeckel — nach 3 automatischen
                        // Zyklen OHNE echten Nutzer-Klick pausiert die Konversation, statt endlos
                        // weiterzuhören (das war der eigentliche »nicht stoppbar«-Bruch: eine
                        // ECHTE Antwort auf ECHTE Sprache kann sich genauso wiederholen wie eine
                        // Stille-Schleife, darum zählt dieser Deckel JEDEN automatischen Zyklus,
                        // nicht nur stille). Ein Klick (autostart-Listener oben) setzt zurück.
                        this.autoZyklen++;
                        if (this.autoZyklen >= 3) {
                            this.konversationPausiert = true;

                            return;
                        }
                        this.start();
                    },
                 }"
                 x-init="initKonversation()"
                 @keydown.window.escape="stopAlles()"
                 class="space-y-1.5">
                <div class="flex items-center gap-2">
                    {{-- Live-Bruch 2026-09-18 (b): STOPP überall — dieser Knopf muss WÄHREND
                         hört zu/sendet/spricht (nicht nur während der Aufnahme selbst) stoppen,
                         darum die kombinierte Bedingung statt nur `laeuft`. Spec 55: die
                         Autoplay-Entsperrung sass vorher im Öffnen-Klick (Sidebar/schwebender
                         Knopf, beide entfernt) — der erste Aufnahme-Klick hier ist jetzt die
                         früheste echte Nutzer-Geste, `onclick` läuft synchron VOR dem
                         Alpine-`@click` (Safari-Regel bleibt gewahrt), entsperrt bleibt für die
                         Lebensdauer des Elements gültig. --}}
                    <button type="button"
                            onclick="window.FaVoiceAudioEntsperren && window.FaVoiceAudioEntsperren('fa-voice-tts-audio')"
                            @click="(laeuft || hochladenLaeuft || fallbackAktiv || $wire.sprichtGerade) ? stopAlles() : (autoZyklen = 0, konversationPausiert = false, start())"
                            :disabled="! unterstuetzt"
                            :class="laeuft ? 'animate-pulse' : ''" class="{{ $btnPrimary }} disabled:opacity-40" data-voice-rec>
                        <span class="inline-flex items-center gap-1.5">
                            <span x-show="laeuft || hochladenLaeuft || fallbackAktiv || $wire.sprichtGerade" x-cloak>@svg('heroicon-o-stop', 'w-3.5 h-3.5')</span>
                            <span x-show="! (laeuft || hochladenLaeuft || fallbackAktiv || $wire.sprichtGerade)">@svg('heroicon-o-microphone', 'w-3.5 h-3.5')</span>
                            <span x-text="(laeuft || hochladenLaeuft || fallbackAktiv || $wire.sprichtGerade) ? 'Stopp' : 'Aufnahme starten'"></span>
                        </span>
                    </button>
                    <span class="text-[11px] text-gray-500">Kurz-Befehl sprechen (wenige Sekunden) — z. B. »Suche BBQ-Sauce«, »Öffne Rezept …«, »Öffne die Planung«</span>
                </div>
                <p class="text-[11px] text-gray-500" x-show="! unterstuetzt" x-cloak data-voice-nicht-unterstuetzt>
                    Sprachaufnahme wird von diesem Browser nicht unterstützt — Befehl tippen.
                </p>
                {{-- „hört zu": VAD-Leerlauf VOR erkannter Sprache, nur im Konversations-Modus sichtbar
                     (im Ein-Klick-Modus sagt der Knopftext selbst schon „Aufnahme starten"). --}}
                <p class="text-[11px] text-gray-500" x-show="konversationAktiv && laeuft && ! hochladenLaeuft" x-cloak data-voice-status="hoert_zu">
                    Hört zu …
                </p>
                <p class="text-[11px] text-gray-500" x-show="! konversationAktiv && laeuft" x-cloak data-voice-status-aufnahme>
                    Aufnahme läuft … <span x-text="sekunden"></span>s / 20s
                </p>
                <p class="text-[11px] text-gray-500" x-show="hochladenLaeuft" x-cloak data-voice-status-upload>
                    Audio wird hochgeladen …
                </p>
                <p class="text-[11px] text-violet-600" x-show="fallbackAktiv" x-cloak data-voice-status="spricht_fallback">
                    Antwort wird vorgelesen (Browser-Stimme — Server-Sprachausgabe war nicht erreichbar) …
                </p>
                {{-- Live-Bruch 2026-09-18 (a): VAD hat in der ganzen Aufnahme NIE Sprache erkannt
                     (Sprechschwelle nie überschritten) — KEIN Upload, KEIN automatischer
                     Wiedereinstieg. Nur ein echter Klick hört wieder zu. --}}
                <p class="text-[11px] text-amber-600" x-show="keineSpracheErkannt" x-cloak data-voice-status="keine_sprache">
                    Keine Sprache erkannt — zum Weiterhören klicken.
                </p>
                {{-- Live-Bruch 2026-09-18 (c): Sicherheitsdeckel nach 3 automatischen Zyklen ohne
                     echten Nutzer-Klick — verhindert eine Endlos-Schleife, die sich nicht mehr von
                     selbst stoppt. --}}
                <p class="text-[11px] text-amber-600" x-show="konversationPausiert" x-cloak data-voice-status="pausiert">
                    Konversation pausiert (3× automatisch weitergehört) — zum Weiterhören klicken.
                </p>
                <p class="text-xs text-rose-500" x-show="fehler" x-cloak x-text="fehler" data-voice-rec-fehler></p>
                {{-- Einziges Wiedergabe-Element für die TTS-Antwort — versteckt, steuert sich rein
                     über `src`/`play()`/`ended` aus dem Alpine-Code oben. --}}
                <audio x-ref="ttsAudio" id="fa-voice-tts-audio" class="hidden" preload="none" playsinline data-voice-tts></audio>
            </div>
        @endif

        {{-- Server-Status: OHNE Ladezustand liefen vorher goKaskade & Co. still 5-20 s — hier
             sendet → versteht → führt aus → spricht als eigene, sichtbare Zeilen (Spec 53/F,
             Zustandsanzeige-Auflage cooking-jarvis-03: alle fünf `data-voice-status`-Werte
             stehen als Markup fest, unabhängig davon, welcher gerade sichtbar ist). --}}
        <p class="text-[11px] text-violet-600" wire:loading wire:target="audio" data-voice-status="sendet">
            Sprache wird gesendet …
        </p>
        @if($transcript !== null && $phase === 'verstehen')
            <p class="text-[11px] text-gray-500" data-voice-transcript>Transkript: »{{ $transcript }}«</p>
        @endif
        {{-- Befund 2026-09-17: der Tool-Loop läuft synchron in DIESEM Request — echte Rundenzahl
             ist serverseitig nicht live zeigbar, darum der Zeitbudget-Hinweis statt eines
             Fortschrittsbalkens (asynchrone Job-Variante wie Paket C ist eine spätere Entscheidung). --}}
        <p class="text-[11px] text-violet-600 inline-flex items-center gap-1.5" wire:loading wire:target="verstehen,verarbeiteText" data-voice-status="versteht">
            <span class="inline-block w-1.5 h-1.5 rounded-full bg-violet-500 animate-pulse"></span>
            Befehl wird verstanden und ausgeführt … (kann bis zu 45 s dauern)
        </p>
        <p class="text-[11px] text-violet-600 inline-flex items-center gap-1.5" wire:loading wire:target="schreibaktionAusfuehren,planungStarten,anreicherungStarten,proposalUebernehmen" data-voice-status="fuehrt_aus">
            <span class="inline-block w-1.5 h-1.5 rounded-full bg-violet-500 animate-pulse"></span>
            Aktion wird ausgeführt …
        </p>
        @if($sprichtGerade)
            <p class="text-[11px] text-violet-600" data-voice-status="spricht">
                Antwort wird vorgelesen …
            </p>
        @endif

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
                    {{-- Spec 55 (Design-Punkt b): Feld-Vorschläge für die OFFENE Planungs-Session —
                         Übernehmen schreibt NICHT hier, sondern dispatcht ein Event an Planung\Index
                         (VoiceModal::komponentenUebernehmen()). --}}
                    @elseif($p['type'] === 'komponenten_uebernahme')
                        <div class="rounded bg-violet-500/10 border border-violet-500/30 px-2 py-1.5 text-xs space-y-1" wire:key="vp-{{ $i }}" data-voice-proposal-komponenten>
                            <p>Vorschlag für die offene Planung: <span class="font-medium">{{ ['rezept' => 'Basisrezept', 'gericht' => 'Gericht', 'concept' => 'Concept'][$p['scope']] ?? $p['scope'] }}</span></p>
                            @if($p['brief'])
                                <p class="text-[11px] text-gray-600">Brief: {{ $p['brief'] }}</p>
                            @endif
                            @if(!empty($p['felder']))
                                <p class="text-[11px] text-gray-500">
                                    @foreach($p['felder'] as $k => $v)
                                        <span class="{{ $pill }} {{ $variantPill['secondary'] }} mr-1">{{ $k }}: {{ is_array($v) ? implode(',', $v) : $v }}</span>
                                    @endforeach
                                </p>
                            @endif
                            @if($p['accepted'] ?? false)
                                <span class="{{ $pill }} {{ $variantPill['success'] }}" data-voice-proposal-auto="1">✓ übernommen</span>
                            @else
                                <button type="button" wire:click="komponentenUebernehmen({{ $i }})" wire:loading.attr="disabled" wire:target="komponentenUebernehmen({{ $i }})"
                                        class="{{ $btnGhostXs }} text-emerald-600 disabled:opacity-40" data-voice-proposal-komponenten-start>
                                    Übernehmen
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
</div>
