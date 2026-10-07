{{-- M7-10: Voice — MediaRecorder (gemeinsamer Baustein) → Spracherkennung → Werkzeug-Schleife; Vorschläge mit
     Bestätigen (GL-07). Spec 53/D: Ablauf in zwei sichtbare Server-Schritte geteilt
     (Transkription → `verstehen()`), Anbieter-Anzeige, verständliche Fehlertexte.
     fa-pass (2026-10-05): Tokens + Bausteine (hell in der Planung, dunkel im Werkbank-Modus);
     Alpine-Logik des Recorders unverändert. --}}
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
@php
    // Knöpfe mit Alpine-Bindungen bleiben echte <button> (x-fa::button würde :disabled als PHP lesen) —
    // Optik identisch zu x-fa::button.
    $knopfBasis = 'inline-flex items-center justify-center gap-1.5 whitespace-nowrap font-medium rounded-[var(--fa-radius-control)] transition-colors duration-150 disabled:opacity-50 disabled:pointer-events-none';
    $knopfPrimaer = $knopfBasis . ' h-9 px-3.5 text-[length:var(--fa-text-md)] bg-[var(--fa-accent)] text-[var(--fa-on-accent)] hover:bg-[var(--fa-accent-hover)]';
    $knopfKlein = $knopfBasis . ' h-7 px-2.5 text-[length:var(--fa-text-sm)] bg-[var(--fa-surface)] text-[var(--fa-ink)] border border-[var(--fa-line-strong)] hover:bg-[var(--fa-hover)]';
    $knopfLeise = $knopfBasis . ' h-7 px-2.5 text-[length:var(--fa-text-sm)] bg-transparent text-[var(--fa-ink-2)] hover:bg-[var(--fa-hover)] hover:text-[var(--fa-ink)]';
    $chip = 'inline-flex items-center h-7 px-2.5 rounded-full border border-[var(--fa-line-strong)] bg-[var(--fa-surface)] text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)] hover:border-[var(--fa-accent)] hover:text-[var(--fa-accent)] transition-colors';
    $leise = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]';
    $status = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]';
    $statusAktiv = 'inline-flex items-center gap-1.5 text-[length:var(--fa-text-sm)] text-[var(--fa-accent)]';
    $vorschlag = 'flex flex-col gap-1.5 rounded-[var(--fa-radius-control)] border border-[var(--fa-accent-line)] bg-[var(--fa-accent-soft)] px-3 py-2 text-[length:var(--fa-text-md)] text-[var(--fa-ink)]';
    $feldLabel = fn ($k) => ucfirst(str_replace('_', ' ', (string) $k));
    $wertText = fn ($v) => is_array($v) ? implode(', ', $v) : (string) $v;
    $ebeneText = ['rezept' => 'Basisrezept', 'gericht' => 'Gericht', 'concept' => 'Konzept'];
    $autoBadge = 'Automatisch ausgeführt';
@endphp

{{-- Spec 55: kein globales Modal mehr — der Agent ist ein einklappbares Panel, fest in der
     Planungs-Leitstelle eingebettet (planung/index.blade.php entscheidet, OB es überhaupt
     rendert, über foodalchemist.ai.voice_agent_panel_planung). Startet eingeklappt (Team-
     Entscheid Dominique), `x-init` löst dieselbe Initialisierung aus, die früher der
     Öffnen-Klick auslöste (Sitzung wiederherstellen, Kontext setzen — siehe VoiceModal::oeffnen()). --}}
<div class="fa-surface" data-voice-panel-planung
     x-data="{ aufgeklappt: false }" x-init="$wire.oeffnen()">
    <button type="button" @click="aufgeklappt = ! aufgeklappt" x-bind:aria-expanded="aufgeklappt"
            class="w-full flex items-center justify-between gap-2 px-3.5 py-2.5 text-left rounded-[var(--fa-radius-surface)] hover:bg-[var(--fa-hover)] transition-colors"
            data-voice-panel-toggle>
        <span class="inline-flex items-center gap-2 text-[length:var(--fa-text-md)] font-semibold text-[var(--fa-ink)]">
            @svg('heroicon-o-microphone', 'w-[18px] h-[18px] text-[var(--fa-ink-3)]')
            Sprachbefehl
            <span class="font-normal {{ $leise }}">sprechen oder tippen</span>
        </span>
        <span class="text-[var(--fa-ink-3)] transition-transform" x-bind:class="aufgeklappt ? 'rotate-180' : ''">@svg('heroicon-m-chevron-down', 'w-4 h-4')</span>
    </button>
    <div class="px-3.5 pb-3.5 flex flex-col gap-3" data-voice x-show="aufgeklappt" x-cloak>

        {{-- Anbieter-Transparenz (Aufgabe 4): sichtbar, ob echt transkribiert wird oder der Testtext
             antwortet. Aufgabe F: Modus daneben — ein Klick öffnet die Einstellungen. --}}
        <div class="flex flex-wrap items-center gap-2">
            <x-fa::badge :tone="$aufnahmeMoeglich ? 'ok' : 'warn'" icon="heroicon-m-microphone" data-voice-provider>
                Spracherkennung: {{ ['openai' => 'OpenAI', 'assemblyai' => 'AssemblyAI', 'fake' => 'Testtext', 'none' => 'nicht eingerichtet'][$provider] ?? $provider }}
            </x-fa::badge>
            <a href="{{ route('foodalchemist.einstellungen') }}" wire:navigate
               class="inline-flex items-center gap-1 h-[22px] px-2 rounded-full bg-[var(--fa-neutral-soft)] text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)] hover:text-[var(--fa-accent)]"
               data-voice-modus title="In den Einstellungen ändern">
                Modus: {{ \Platform\FoodAlchemist\Livewire\Settings\Ki::MODUS_LABEL[$agentModus] ?? $agentModus }}
                @svg('heroicon-m-cog-6-tooth', 'w-3.5 h-3.5')
            </a>
            @unless($aufnahmeMoeglich)
                <x-fa::signal tone="warn" data-voice-provider-hinweis>Spracherkennung ist nicht eingerichtet. Befehl bitte tippen.</x-fa::signal>
            @endunless
            {{-- Spec 53 / Paket F (4): nur sichtbar, wenn es wirklich etwas zu vergessen gibt. --}}
            @if(!empty($ergebnis['proposals']))
                <button type="button" wire:click="vergessen" wire:loading.attr="disabled" wire:target="vergessen"
                        class="{{ $knopfLeise }} ml-auto" data-voice-vergessen>
                    @svg('heroicon-o-arrow-uturn-left', 'w-3.5 h-3.5') Gespräch vergessen
                </button>
            @endif
        </div>

        {{-- Aufnahme: gemeinsamer Recorder-Baustein (window.FaVoiceRecorder), Root mit eigenem
             wire:key — Server-Status liegt AUSSERHALB dieses Blocks, damit ein Re-Render des
             Server-Status (Polling/Redirect) den laufenden Alpine-Aufnahmezustand nicht zerstört.

             Spec 53 / Paket F (3): im Konversations-Modus (`konversationAktiv`, an
             `voice_tts_vorlesen` gekoppelt) läuft der Recorder mit VAD (Stille-Erkennung statt
             Klick-zum-Stoppen) und hört nach der vorgelesenen Antwort automatisch weiter zu —
             Ein-Klick-Modus (Standard) bleibt manuell. `<audio data-voice-tts>` ist das EINE
             Wiedergabe-Element für die TTS-Antwort; die Autoplay-Entsperrung passiert beim ERSTEN
             Aufnahme-Klick (s. u., `onclick` am Aufnahme-Knopf). --}}
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
                 class="flex flex-col gap-1.5">
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5">
                    {{-- Live-Bruch 2026-09-18 (b): STOPP überall — dieser Knopf muss WÄHREND
                         hört zu/sendet/spricht stoppen, darum die kombinierte Bedingung statt nur
                         `laeuft`. Spec 55: der erste Aufnahme-Klick ist die früheste echte
                         Nutzer-Geste, `onclick` läuft synchron VOR dem Alpine-`@click`
                         (Safari-Regel bleibt gewahrt). --}}
                    <button type="button"
                            onclick="window.FaVoiceAudioEntsperren && window.FaVoiceAudioEntsperren('fa-voice-tts-audio')"
                            @click="(laeuft || hochladenLaeuft || fallbackAktiv || $wire.sprichtGerade) ? stopAlles() : (autoZyklen = 0, konversationPausiert = false, start())"
                            :disabled="! unterstuetzt"
                            :class="laeuft ? 'animate-pulse' : ''" class="{{ $knopfPrimaer }}" data-voice-rec>
                        <span x-show="laeuft || hochladenLaeuft || fallbackAktiv || $wire.sprichtGerade" x-cloak>@svg('heroicon-m-stop', 'w-4 h-4')</span>
                        <span x-show="! (laeuft || hochladenLaeuft || fallbackAktiv || $wire.sprichtGerade)">@svg('heroicon-m-microphone', 'w-4 h-4')</span>
                        <span x-text="(laeuft || hochladenLaeuft || fallbackAktiv || $wire.sprichtGerade) ? 'Stopp' : 'Aufnahme starten'"></span>
                    </button>
                    <span class="min-w-0 flex-1 {{ $leise }}">Kurz sprechen, z. B. »Suche BBQ-Sauce«, »Öffne Rezept …« oder »Öffne die Planung«.</span>
                </div>
                <x-fa::signal tone="warn" x-show="! unterstuetzt" x-cloak data-voice-nicht-unterstuetzt>
                    Dieser Browser kann keine Sprache aufnehmen. Befehl bitte tippen.
                </x-fa::signal>
                {{-- „hört zu": VAD-Leerlauf VOR erkannter Sprache, nur im Konversations-Modus sichtbar. --}}
                <p class="{{ $statusAktiv }}" x-show="konversationAktiv && laeuft && ! hochladenLaeuft" x-cloak data-voice-status="hoert_zu">
                    <span class="inline-block w-1.5 h-1.5 rounded-full bg-[var(--fa-accent)] animate-pulse"></span> Hört zu …
                </p>
                <p class="{{ $statusAktiv }}" x-show="! konversationAktiv && laeuft" x-cloak data-voice-status-aufnahme>
                    <span class="inline-block w-1.5 h-1.5 rounded-full bg-[var(--fa-crit)] animate-pulse"></span> Aufnahme läuft <span class="tabular-nums"><span x-text="sekunden"></span> von 20 s</span>
                </p>
                <p class="{{ $status }}" x-show="hochladenLaeuft" x-cloak data-voice-status-upload>
                    Aufnahme wird übertragen …
                </p>
                <p class="{{ $statusAktiv }}" x-show="fallbackAktiv" x-cloak data-voice-status="spricht_fallback">
                    @svg('heroicon-m-speaker-wave', 'w-4 h-4') Antwort wird mit der Browser-Stimme vorgelesen, die Sprachausgabe des Servers war nicht erreichbar …
                </p>
                {{-- Live-Bruch 2026-09-18 (a): VAD hat in der ganzen Aufnahme NIE Sprache erkannt —
                     KEIN Upload, KEIN automatischer Wiedereinstieg. Nur ein echter Klick hört wieder zu. --}}
                <x-fa::signal tone="warn" x-show="keineSpracheErkannt" x-cloak data-voice-status="keine_sprache">
                    Keine Sprache erkannt. Zum Weiterhören klicken.
                </x-fa::signal>
                {{-- Live-Bruch 2026-09-18 (c): Sicherheitsdeckel nach 3 automatischen Zyklen ohne
                     echten Nutzer-Klick — verhindert eine Endlos-Schleife. --}}
                <x-fa::signal tone="warn" x-show="konversationPausiert" x-cloak data-voice-status="pausiert">
                    Gespräch pausiert, nach drei Antworten ohne Klick. Zum Weiterhören klicken.
                </x-fa::signal>
                <x-fa::signal tone="crit" x-show="fehler" x-cloak data-voice-rec-fehler><span x-text="fehler"></span></x-fa::signal>
                {{-- Einziges Wiedergabe-Element für die TTS-Antwort — versteckt, steuert sich rein
                     über `src`/`play()`/`ended` aus dem Alpine-Code oben. --}}
                <audio x-ref="ttsAudio" id="fa-voice-tts-audio" class="hidden" preload="none" playsinline data-voice-tts></audio>
            </div>
        @endif

        {{-- Server-Status: sendet → versteht → führt aus → spricht als eigene, sichtbare Zeilen
             (Spec 53/F: alle fünf `data-voice-status`-Werte stehen als Markup fest). --}}
        <p class="{{ $statusAktiv }}" wire:loading wire:target="audio" data-voice-status="sendet">
            @svg('heroicon-m-arrow-path', 'w-4 h-4 animate-spin') Sprache wird gesendet …
        </p>
        @if($transcript !== null && $phase === 'verstehen')
            <p class="{{ $status }}" data-voice-transcript>Verstanden: »{{ $transcript }}«</p>
        @endif
        {{-- Befund 2026-09-17: die Schleife läuft synchron in DIESEM Request — echte Rundenzahl
             ist serverseitig nicht live zeigbar, darum der Zeit-Hinweis statt eines Fortschrittsbalkens. --}}
        <p class="{{ $statusAktiv }}" wire:loading wire:target="verstehen,verarbeiteText" data-voice-status="versteht">
            @svg('heroicon-m-arrow-path', 'w-4 h-4 animate-spin') Befehl wird verstanden und ausgeführt. Das kann bis zu 45 s dauern …
        </p>
        <p class="{{ $statusAktiv }}" wire:loading wire:target="schreibaktionAusfuehren,planungStarten,anreicherungStarten,proposalUebernehmen" data-voice-status="fuehrt_aus">
            @svg('heroicon-m-arrow-path', 'w-4 h-4 animate-spin') Aktion wird ausgeführt …
        </p>
        @if($sprichtGerade)
            <p class="{{ $statusAktiv }}" data-voice-status="spricht">
                @svg('heroicon-m-speaker-wave', 'w-4 h-4') Antwort wird vorgelesen …
            </p>
        @endif

        {{-- Tippen geht IMMER, auch ohne Spracherkennung. --}}
        <form wire:submit.prevent="verarbeiteText($refs.cmd.value)" @submit="$refs.cmd.value = ''" class="flex items-center gap-2">
            <input type="text" x-ref="cmd" placeholder="Oder Befehl tippen" required minlength="2" aria-label="Befehl tippen"
                   wire:loading.attr="disabled" wire:target="verstehen,verarbeiteText"
                   class="fa-control h-9 text-[length:var(--fa-text-md)] flex-1" data-voice-text />
            <button type="submit" wire:loading.attr="disabled" wire:target="verstehen,verarbeiteText" class="{{ $knopfKlein }} h-9 px-3.5 text-[length:var(--fa-text-md)]">
                <span wire:loading.remove wire:target="verstehen,verarbeiteText" class="inline-flex items-center gap-1.5">@svg('heroicon-m-paper-airplane', 'w-4 h-4') Senden</span>
                <span wire:loading wire:target="verstehen,verarbeiteText">Wird gesendet …</span>
            </button>
        </form>

        @if($fehler !== null)
            <x-fa::notice tone="crit" data-voice-fehler>{{ $fehler }}</x-fa::notice>
        @endif

        @if($ergebnis !== null)
            <div class="flex flex-col gap-2 rounded-[var(--fa-radius-control)] bg-[var(--fa-ground)] border border-[var(--fa-line)] px-3 py-2.5" data-voice-ergebnis>
                @if($ergebnis['text'] !== null)
                    <p class="text-[length:var(--fa-text-md)] text-[var(--fa-ink)]">{{ $ergebnis['text'] }}</p>
                @endif
                <p class="{{ $leise }} tabular-nums" title="{{ $ergebnis['runden'] }} Durchläufe, {{ count($ergebnis['tool_laeufe']) }} Abfragen">Antwort in {{ number_format($ergebnis['elapsed_ms'] / 1000, 1, ',', '.') }} s</p>

                {{-- Aufgabe 6: mehrere Öffnen-Treffer — nur der erste navigiert,
                     der Rest bekommt hier einen Link (aktionZiel() in VoiceModal befüllt ihn). --}}
                @if(collect($ergebnis['aktionen'] ?? [])->contains(fn ($a) => isset($a['link'])))
                    <div class="flex flex-wrap gap-1.5">
                        @foreach(($ergebnis['aktionen'] ?? []) as $i => $a)
                            @if(isset($a['link']))
                                <a href="{{ $a['link'] }}" wire:navigate class="{{ $knopfKlein }}" wire:key="al-{{ $i }}" data-voice-aktion-link>
                                    @svg('heroicon-m-arrow-top-right-on-square', 'w-3.5 h-3.5') {{ $a['link_label'] ?? 'Öffnen' }}
                                </a>
                            @endif
                        @endforeach
                    </div>
                @endif

                {{-- Aufgabe F: im Modus auto_sicher wurden angenommene Vorschläge OHNE Klick ausgeführt —
                     eigener Text macht das sichtbar statt „übernommen"/„gestartet" zu behaupten. --}}
                @foreach($ergebnis['proposals'] as $i => $p)
                    @if(($p['type'] ?? 'speisen_klasse') === 'speisen_klasse')
                        <div class="{{ $vorschlag }}" wire:key="vp-{{ $i }}" data-voice-proposal>
                            <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                <span>Speisen-Klasse: <span class="font-semibold">{{ $p['klasse_name'] ?? 'kein Treffer' }}</span></span>
                                <span class="{{ $leise }} tabular-nums">{{ round(($p['confidence'] ?? 0) * 100) }} % sicher</span>
                                @if($p['accepted'] ?? false)
                                    <x-fa::badge tone="ok" icon="heroicon-m-check" data-voice-proposal-auto="{{ $agentModus === 'auto_sicher' ? '1' : '0' }}">{{ $agentModus === 'auto_sicher' ? $autoBadge : 'Übernommen' }}</x-fa::badge>
                                @elseif(($p['klasse_id'] ?? null) !== null)
                                    <button type="button" wire:click="proposalUebernehmen({{ $i }})" class="{{ $knopfKlein }} ml-auto" data-voice-proposal-accept>@svg('heroicon-m-check', 'w-3.5 h-3.5') Bestätigen</button>
                                @endif
                            </div>
                        </div>
                    {{-- Aufgabe 7 / GL-07: „erstelle ein …" ist bis hier NUR Vorschlag (das Werkzeug
                         schreibt nichts) — erst dieser Knopf legt die Planung an. --}}
                    @elseif($p['type'] === 'planung_start')
                        <div class="{{ $vorschlag }}" wire:key="vp-{{ $i }}" data-voice-proposal-planung>
                            <p>Planung: <span class="font-semibold">{{ $ebeneText[$p['scope']] ?? $p['scope'] }}</span>@if($p['titel']), {{ $p['titel'] }}@endif</p>
                            <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]">{{ $p['brief'] }}</p>
                            @if(!empty($p['leitplanken']))
                                <div class="flex flex-wrap gap-1">
                                    @foreach($p['leitplanken'] as $k => $v)
                                        <x-fa::badge>{{ $feldLabel($k) }}: {{ $wertText($v) }}</x-fa::badge>
                                    @endforeach
                                </div>
                            @endif
                            @if(!empty($p['unklar']))
                                <x-fa::signal tone="warn">Noch unklar: {{ implode(', ', array_map($feldLabel, $p['unklar'])) }}</x-fa::signal>
                            @endif
                            @if($p['accepted'] ?? false)
                                <div><x-fa::badge tone="ok" icon="heroicon-m-check" data-voice-proposal-auto="1">{{ $autoBadge }}: Planung angelegt, Editor geöffnet</x-fa::badge></div>
                            @else
                                <div class="flex justify-end">
                                    <button type="button" wire:click="planungStarten({{ $i }})" wire:loading.attr="disabled" wire:target="planungStarten({{ $i }})"
                                            class="{{ $knopfKlein }}" data-voice-proposal-planung-start>
                                        @svg('heroicon-m-play', 'w-3.5 h-3.5') Planung starten
                                    </button>
                                </div>
                            @endif
                        </div>
                    @elseif($p['type'] === 'anreicherung')
                        <div class="{{ $vorschlag }}" wire:key="vp-{{ $i }}" data-voice-proposal-anreicherung>
                            <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                <span>Vollständig anreichern: <span class="font-semibold">{{ $p['name'] ?? ('Rezept ' . $p['recipe_id']) }}</span></span>
                                @if($p['accepted'] ?? false)
                                    <x-fa::badge tone="ok" icon="heroicon-m-check" data-voice-proposal-auto="{{ $agentModus === 'auto_sicher' ? '1' : '0' }}">{{ $agentModus === 'auto_sicher' ? $autoBadge : 'Gestartet' }}</x-fa::badge>
                                @else
                                    <button type="button" wire:click="anreicherungStarten({{ $i }})" wire:loading.attr="disabled" wire:target="anreicherungStarten({{ $i }})"
                                            class="{{ $knopfKlein }} ml-auto" data-voice-proposal-anreicherung-start>
                                        @svg('heroicon-m-sparkles', 'w-3.5 h-3.5') Anreicherung starten
                                    </button>
                                @endif
                            </div>
                        </div>
                    {{-- Spec 55 (Design-Punkt b): Feld-Vorschläge für die OFFENE Planung —
                         Übernehmen schreibt NICHT hier, sondern dispatcht ein Event an Planung\Index
                         (VoiceModal::komponentenUebernehmen()). --}}
                    @elseif($p['type'] === 'komponenten_uebernahme')
                        <div class="{{ $vorschlag }}" wire:key="vp-{{ $i }}" data-voice-proposal-komponenten>
                            <p>Vorschlag für die offene Planung: <span class="font-semibold">{{ $ebeneText[$p['scope']] ?? $p['scope'] }}</span></p>
                            @if($p['brief'])
                                <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]">Auftrag: {{ $p['brief'] }}</p>
                            @endif
                            @if(!empty($p['felder']))
                                <div class="flex flex-wrap gap-1">
                                    @foreach($p['felder'] as $k => $v)
                                        <x-fa::badge>{{ $feldLabel($k) }}: {{ $wertText($v) }}</x-fa::badge>
                                    @endforeach
                                </div>
                            @endif
                            @if($p['accepted'] ?? false)
                                <div><x-fa::badge tone="ok" icon="heroicon-m-check" data-voice-proposal-auto="1">Übernommen</x-fa::badge></div>
                            @else
                                <div class="flex justify-end">
                                    <button type="button" wire:click="komponentenUebernehmen({{ $i }})" wire:loading.attr="disabled" wire:target="komponentenUebernehmen({{ $i }})"
                                            class="{{ $knopfKlein }}" data-voice-proposal-komponenten-start>
                                        @svg('heroicon-m-check', 'w-3.5 h-3.5') Übernehmen
                                    </button>
                                </div>
                            @endif
                        </div>
                    {{-- Spec 55 Nachtrag (Agent-am-Brief): Antwort auf die EIGENE Rückfrage des
                         Agenten — schon serverseitig angewendet (VoiceModal::verstehen()), hier NUR
                         noch die „gesetzt"-Zeile + Rückgängig (die Antwort IST die Bestätigung). --}}
                    @elseif($p['type'] === 'komponenten_direkt')
                        <div class="flex flex-col gap-1.5 rounded-[var(--fa-radius-control)] bg-[var(--fa-ok-soft)] px-3 py-2 text-[length:var(--fa-text-md)] text-[var(--fa-ink)]" wire:key="vp-{{ $i }}" data-voice-proposal-komponenten-direkt>
                            @if($p['rueckgaengig_gemacht'] ?? false)
                                <p class="text-[var(--fa-ink-3)]">Zurückgenommen.</p>
                            @else
                                <div class="flex flex-wrap items-center gap-1">
                                    <span class="mr-1">Gesetzt:</span>
                                    @foreach($p['felder'] as $k => $v)
                                        <x-fa::badge tone="ok">{{ $feldLabel($k) }}: {{ $wertText($v) }}</x-fa::badge>
                                    @endforeach
                                    @if($p['brief'])
                                        <x-fa::badge tone="ok">Auftrag aktualisiert</x-fa::badge>
                                    @endif
                                </div>
                                <div class="flex justify-end">
                                    <button type="button" wire:click="komponentenRueckgaengig({{ $i }})" wire:loading.attr="disabled" wire:target="komponentenRueckgaengig({{ $i }})"
                                            class="{{ $knopfLeise }}" data-voice-proposal-komponenten-direkt-undo>
                                        @svg('heroicon-m-arrow-uturn-left', 'w-3.5 h-3.5') Rückgängig
                                    </button>
                                </div>
                            @endif
                        </div>
                    {{-- Spec 55 Nachtrag: Rückfrage des Agenten zu EINER fehlenden Pflicht-
                         Leitplanke — Vokabular kommt vom Server (VoiceCommandService::regelVokabular()),
                         NICHT vom Modell. `null` = Zahlenfeld (Pax/Menge/Portion/Ziel-VK), sonst Chips. --}}
                    @elseif($p['type'] === 'rueckfrage')
                        <div class="{{ $vorschlag }}" wire:key="vp-{{ $i }}" data-voice-proposal-rueckfrage data-voice-rueckfrage-feld="{{ $p['feld'] }}">
                            @if($p['accepted'] ?? false)
                                <div class="flex flex-wrap items-center gap-2">
                                    <x-fa::badge tone="ok" icon="heroicon-m-check" data-voice-proposal-auto="1">{{ $feldLabel($p['feld']) }}: {{ $p['beantwortet_mit'] ?? '' }}</x-fa::badge>
                                    <button type="button" wire:click="komponentenRueckgaengig({{ $i }})" wire:loading.attr="disabled" wire:target="komponentenRueckgaengig({{ $i }})"
                                            class="{{ $knopfLeise }}" data-voice-proposal-rueckfrage-undo>
                                        @svg('heroicon-m-arrow-uturn-left', 'w-3.5 h-3.5') Rückgängig
                                    </button>
                                </div>
                            @elseif(is_array($p['vokabular'] ?? null))
                                <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]">{{ $feldLabel($p['feld']) }} wählen:</p>
                                <div class="flex flex-wrap gap-1.5">
                                    @foreach($p['vokabular'] as $wert => $lbl)
                                        @if($wert !== '')
                                            <button type="button" wire:click="rueckfrageChip({{ $i }}, '{{ $wert }}')" wire:loading.attr="disabled" wire:target="rueckfrageChip({{ $i }}, '{{ $wert }}')"
                                                    class="{{ $chip }}" data-voice-rueckfrage-chip="{{ $wert }}">
                                                {{ $lbl }}
                                            </button>
                                        @endif
                                    @endforeach
                                </div>
                            @else
                                <form wire:submit.prevent="rueckfrageZahl({{ $i }}, $refs.rueckfrageZahl{{ $i }}.value)" class="flex items-center gap-2">
                                    <input type="text" inputmode="decimal" x-ref="rueckfrageZahl{{ $i }}" placeholder="{{ $feldLabel($p['feld']) }} eingeben" aria-label="{{ $feldLabel($p['feld']) }}"
                                           class="fa-control h-7 text-[length:var(--fa-text-sm)] text-right tabular-nums flex-1" data-voice-rueckfrage-zahl />
                                    <button type="submit" wire:loading.attr="disabled" wire:target="rueckfrageZahl" class="{{ $knopfKlein }}">Setzen</button>
                                </form>
                            @endif
                        </div>
                    {{-- Paket F (1b): Schreibvorschlag für JEDES andere Schreib-Werkzeug.
                         Mit Alias (VoiceCommandService::SCHREIBAKTION_ALIAS) zeigt die Vorschau
                         alt→neu je Feld; ohne Alias nur die Argumente + Beschreibung. --}}
                    @elseif($p['type'] === 'schreibaktion')
                        <div class="{{ $vorschlag }}" wire:key="vp-{{ $i }}" data-voice-proposal-schreibaktion data-voice-proposal-tool="{{ $p['tool'] }}">
                            <p>
                                {{ ($p['objekt']['type'] ?? null) === null ? 'Aktion' : ucfirst($p['objekt']['type']) }}:
                                <span class="font-semibold">{{ $p['objekt']['name'] ?? (isset($p['objekt']['id']) ? 'Nr. ' . $p['objekt']['id'] : 'Änderung') }}</span>
                            </p>
                            @if($p['beschreibung'] ?? null)
                                <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]">{{ $p['beschreibung'] }}</p>
                            @endif
                            @if(!empty($p['vorschau']))
                                <ul class="flex flex-col gap-0.5 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]">
                                    @foreach($p['vorschau'] as $v)
                                        <li data-voice-vorschau-feld="{{ $v['feld'] }}">
                                            <span class="font-medium text-[var(--fa-ink)]">{{ $feldLabel($v['feld']) }}:</span>
                                            @if(array_key_exists('alt', $v))
                                                <span class="line-through text-[var(--fa-ink-3)]">{{ is_scalar($v['alt']) ? $v['alt'] : json_encode($v['alt']) }}</span>
                                                @svg('heroicon-m-arrow-right', 'w-3.5 h-3.5 inline-block align-middle text-[var(--fa-ink-3)]')
                                            @endif
                                            <span>{{ is_scalar($v['neu']) ? $v['neu'] : json_encode($v['neu']) }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                            @if($p['accepted'] ?? false)
                                <div><x-fa::badge tone="ok" icon="heroicon-m-check">Ausgeführt</x-fa::badge></div>
                            @else
                                <div class="flex justify-end">
                                    <button type="button" wire:click="schreibaktionAusfuehren({{ $i }})" wire:loading.attr="disabled" wire:target="schreibaktionAusfuehren({{ $i }})"
                                            class="{{ $knopfKlein }}" data-voice-proposal-schreibaktion-start>
                                        @svg('heroicon-m-check', 'w-3.5 h-3.5') Bestätigen
                                    </button>
                                </div>
                            @endif
                        </div>
                    @endif
                @endforeach
            </div>
        @endif
    </div>
</div>
