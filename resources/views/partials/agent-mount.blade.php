{{--
    Spec 53 / Paket F, Stufe 2: Sprachbefehl-Mount auf SEITENEBENE statt im Sidebar-`x-if`-Slot.

    WARUM: `x-ui-sidebar` (Core) rendert den FA-Modul-Slot in einem `x-if` — beim Einklappen
    der Sidebar fliegt der GANZE Slot aus dem DOM, inklusive eines dort gemounteten Modals
    (Nebenbefund, betraf auch `saved-toast`). Dieses Partial wird stattdessen von JEDER FA-
    Vollseite im Root-Element eingebunden (eine Zeile: `@include('foodalchemist::partials.agent-mount')`),
    überlebt also das Einklappen. Die Sidebar behält NUR noch den Öffnen-Knopf, der das
    bestehende `voice-modal.oeffnen`-Event schickt — das Modal selbst mountet hier.

    LEHRE AUS DEM ROOT-HOTFIX (2026-09-17): dieses Include gehört INS Root-Element der
    aufrufenden Seite, NIE davor/danach als eigener Geschwister-Knoten — sonst besteht dasselbe
    Risiko wie beim rohen `<script>`-Tag (`Utils::insertAttributesIntoHtmlRoot` hängt `wire:id`
    an das ERSTE Tag der Komponente). Root-ZÄHLUNG beweist dabei nichts über Attribut-INJEKTION.

    „Dauerhaft aktiv" (Team-Setting, Radio+Schalter in den Einstellungen) zeigt zusätzlich ein
    schwebendes, ziehbares Mikrofon-Element — Positions-Spiegel in localStorage (rein clientseitig,
    kein Server-State). Ohne den Schalter (Default) bleibt es wie heute: nur der Sidebar-Knopf.

    REGEL (Live-Bruch 2026-09-18): NIE ein geradeaus-Anführungszeichen in JS/Kommentaren
    INNERHALB der x-data- bzw. x-init-Attribute unten — das Attribut endet beim ERSTEN
    Anführungszeichen, egal ob roher Text oder Kommentar; Alpine bekommt dann nur ein
    Bruchstück und fällt für die GANZE Komponente aus (hier: der schwebende Knopf war komplett
    unsichtbar). Backticks (`) oder Guillemets (»«) statt Anführungszeichen. Wächter-Test:
    tests/Feature/BladeXDataAttributeGuardTest.php (scannt ALLE Blade-Dateien).
--}}
@php($__voiceAgentTeam = auth()->user()?->currentTeamRelation)
@php($__voiceAgentDauerhaftAktiv = $__voiceAgentTeam !== null
    ? app(\Platform\FoodAlchemist\Services\TeamSettingsService::class)->voiceAgentDauerhaftAktiv($__voiceAgentTeam)
    : false)
@livewire('foodalchemist.voice-modal')
<div
    x-data="{
        aktiv: @js($__voiceAgentDauerhaftAktiv),
        x: null, y: null,
        dragOffsetX: 0, dragOffsetY: 0, dragging: false, moved: false,
        // Live-Bruch 2026-09-18 (Punkt 3, erster Schritt Spec 54 »schwebender Begleiter«):
        // Zustand am Knopf statt Modal-Aufreissen — GENAU vier Zustände (nichts Feineres).
        schwebeStatus: 'wartet',
        schwebeTranskript: null,
        schwebeAntwort: null,
        blaseSichtbar: false,
        _letzterSchwebeStatus: 'wartet',
        init() {
            try {
                const gespeichert = JSON.parse(localStorage.getItem('fa-voice-mount-pos') || 'null');
                if (gespeichert && typeof gespeichert.x === 'number') { this.x = gespeichert.x; this.y = gespeichert.y; }
            } catch (e) { /* localStorage kann in Private-Mode werfen — Default-Ecke bleibt */ }
            window.addEventListener('voice-agent-dauerhaft-aktiv-geaendert', (e) => {
                this.aktiv = !!(e.detail && e.detail.aktiv);
            });
            // Der Recorder im Modal (eigene Komponente) schreibt seinen Zustand nach
            // window.FaVoiceZustand — ein Intervall-Poll statt eines Custom-Events, weil Alpine
            // reine window-Property-Änderungen nicht automatisch beobachtet (kein reaktiver
            // Proxy dahinter). 300 ms ist unauffällig, aber oft genug für den schwebenden Knopf.
            setInterval(() => {
                const z = window.FaVoiceZustand;
                if (! z) {
                    return;
                }
                this.schwebeStatus = z.status;
                if (z.antwort && z.antwort !== this.schwebeAntwort) {
                    this.schwebeTranskript = z.transkript;
                    this.schwebeAntwort = z.antwort;
                    this.blaseSichtbar = true;
                }
                // Blase verschwindet, sobald das Vorlesen endet ODER ein neuer Zuhör-Zyklus
                // beginnt (deckt auch den Fall »Vorlesen ist aus« ab — die Blase bleibt bis
                // zum nächsten gesprochenen Befehl stehen statt für immer).
                if (this._letzterSchwebeStatus === 'spricht' && z.status !== 'spricht') {
                    this.blaseSichtbar = false;
                }
                if (this._letzterSchwebeStatus !== 'hoert_zu' && z.status === 'hoert_zu') {
                    this.blaseSichtbar = false;
                }
                this._letzterSchwebeStatus = z.status;
            }, 300);
        },
        startDrag(e) {
            this.dragging = true; this.moved = false;
            const rect = this.$refs.knopf.getBoundingClientRect();
            this.dragOffsetX = e.clientX - rect.left;
            this.dragOffsetY = e.clientY - rect.top;
        },
        onDrag(e) {
            if (! this.dragging) return;
            this.moved = true;
            this.x = e.clientX - this.dragOffsetX;
            this.y = e.clientY - this.dragOffsetY;
        },
        stopDrag() {
            if (! this.dragging) return;
            this.dragging = false;
            if (this.moved) {
                try { localStorage.setItem('fa-voice-mount-pos', JSON.stringify({ x: this.x, y: this.y })); } catch (e) {}
            }
        },
        oeffnen() {
            if (this.moved) {
                return;
            }
            // Live-Bruch 2026-09-18 (b): ein Klick auf den schwebenden Knopf WÄHREND ein Zyklus
            // läuft (hört zu/sendet/spricht) muss STOPPEN statt erneut zu öffnen — sonst gibt es
            // keinen erreichbaren Weg mehr, eine hängende/schleifende Konversation zu beenden.
            // `window.FaVoiceKonversationAktiv`/`FaVoiceStopAlles` werden vom Recorder im Modal
            // selbst gepflegt (eigene Komponente, dieser Alpine-Scope kann sie nicht direkt
            // erreichen) — kein zweiter, unabhängig gepflegter Zustand hier.
            if (window.FaVoiceKonversationAktiv && window.FaVoiceStopAlles) {
                window.FaVoiceStopAlles();

                return;
            }
            // Spec 53/F (3): dieselbe Entsperrung wie der Sidebar-Knopf — noch im
            // selben Klick, bevor `$dispatch` das Modal-Event schickt.
            window.FaVoiceAudioEntsperren && window.FaVoiceAudioEntsperren('fa-voice-tts-audio');
            // Live-Befund Dominique (2026-09-18): der schwebende Knopf öffnete bisher nur
            // den Ein-Klick-Zustand (»Aufnahme starten«) — im Konversations-Modus musste
            // NOCH ein zweiter Klick folgen. `autostart` wird HIER unbedingt mitgeschickt
            // (der Recorder im Modal entscheidet selbst anhand des FRISCH aus dem Team-
            // Setting gelesenen `konversationAktiv`, ob er wirklich sofort startet — der
            // Ein-Klick-Modus bleibt dadurch unverändert).
            // Live-Bruch 2026-09-18 (Punkt 3): `schwebend:true` sagt `VoiceModal::oeffnen()`,
            // dass dieser Klick vom Float-Knopf kommt — im Konversations-Modus bleibt das Modal
            // dann zu (der Knopf selbst zeigt den Zustand, siehe Sprechblase unten), solange es
            // nichts zu bestätigen gibt.
            $dispatch('voice-modal.oeffnen', { autostart: true, schwebend: true });
        },
    }"
    x-show="aktiv" x-cloak
    x-init="init()"
    @mousemove.window="onDrag($event)"
    @mouseup.window="stopDrag()"
    {{-- Live-Befund Dominique (2026-09-18): z-[90] lag UNTER jedem Editor (RecipeModal/VkModal/...
         alle z-[100]+) — der Knopf verschwand, sobald irgendein Editor offen war. z-[210] liegt
         über dem fest gepinnten Sprachbefehl-Modal selbst (z-[190], siehe components/modal.blade.php
         `zFest`) UND über dem Speichern-Toast (z-[200], components/saved-toast.blade.php) — der
         Knopf muss IMMER erreichbar bleiben, auch während beide offen/sichtbar sind. --}}
    class="fixed z-[210]"
    :style="x !== null ? ('left:' + x + 'px; top:' + y + 'px;') : 'right:1.5rem; bottom:1.5rem;'"
    data-voice-float-mount
>
    {{-- Sprechblase (drittes Element, Punkt 3): Transkript + Antwort statt des grossen Modals.
         Max. 2 Zeilen für die Antwort (line-clamp), verschwindet nach dem Vorlesen (s. Poll oben).
         Ein Klick öffnet das Modal ganz normal (`schwebend:false` — wie der Sidebar-Knopf). --}}
    <div x-show="blaseSichtbar" x-cloak x-transition
         @click="blaseSichtbar = false; window.FaVoiceAudioEntsperren && window.FaVoiceAudioEntsperren('fa-voice-tts-audio'); $dispatch('voice-modal.oeffnen', { autostart: false, schwebend: false })"
         class="absolute bottom-full right-0 mb-2 max-w-[240px] rounded-lg bg-white shadow-lg border border-black/10 px-3 py-2 cursor-pointer"
         data-voice-blase>
        <p class="text-[10px] text-gray-400 truncate" x-show="schwebeTranskript" x-text="schwebeTranskript"></p>
        <p class="text-xs text-gray-800 line-clamp-2" x-text="schwebeAntwort"></p>
    </div>
    <button type="button" x-ref="knopf" @mousedown="startDrag($event)" @click="oeffnen()"
            :class="{
                'animate-pulse': schwebeStatus === 'hoert_zu',
                'animate-bounce': schwebeStatus === 'spricht',
                'bg-gradient-to-r from-violet-500 to-indigo-500': schwebeStatus !== 'fehler',
                'bg-rose-500': schwebeStatus === 'fehler',
            }"
            class="w-12 h-12 rounded-full text-white shadow-lg shadow-violet-500/30 flex items-center justify-center cursor-move select-none"
            title="Sprachbefehl (ziehbar)" data-voice-float-button :data-voice-float-status="schwebeStatus">
        @svg('heroicon-o-microphone', 'w-5 h-5')
    </button>
</div>
