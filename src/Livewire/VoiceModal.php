<?php

namespace Platform\FoodAlchemist\Livewire;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Http\Controllers\VoiceAudioController;
use Platform\FoodAlchemist\Jobs\EnrichRecipeJob;
use Platform\FoodAlchemist\Services\PlanningSessionService;
use Platform\FoodAlchemist\Services\RecipeService;
use Platform\FoodAlchemist\Services\Stt\SttServiceContract;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Services\Tts\TtsServiceContract;
use Platform\FoodAlchemist\Services\VoiceCommandService;
use Platform\FoodAlchemist\Services\VoiceSessionService;
use Platform\FoodAlchemist\Support\VoiceFehlerText;
use Platform\FoodAlchemist\Support\VoiceMime;
use Platform\FoodAlchemist\Support\VoiceReferenzResolver;

/**
 * M7-10 / Phase C2: 🎙 Voice-Interface — zweiter Bedienweg (UI bleibt parallel).
 * MediaRecorder (gemeinsamer Baustein, siehe resources/js/voice-recorder) → Livewire-Upload →
 * sync STT (D8-Fassade) → agentischer Tool-Loop (Tier D) → Antwort + UI-Aktionen;
 * Schreibaktionen NUR als Proposal mit Bestätigen-Button (GL-07).
 *
 * Liegt bewusst NICHT mehr unter `Livewire\Recipes`: das Mikrofon steuert seit
 * Phase C2 den ganzen FoodAlchemist (Dominique: „das Mikrofon ist der eigentliche
 * MCP-Agent im System") und ist EINMAL global in der Sidebar gemountet, die auf
 * jeder FA-Seite liegt. Die Rezept-Seite behält nur ihren Knopf und schickt
 * dasselbe Event — kein zweiter Mount, eine Identität.
 *
 * Spec 53 / Paket D: Roundtrip in zwei sichtbare Schritte gesplittet
 * (transkribieren → `verstehen()`) statt einem stummen Rundgang von 5-20 s
 * (`$phase` trägt den Server-Zwischenstand, die Blade zeigt ihn über wire:loading).
 */
class VoiceModal extends Component
{
    use WithFileUploads;

    public $audio = null;                                            // Livewire-Upload (Blob aus MediaRecorder)

    public ?string $transcript = null;

    public ?array $ergebnis = null;

    public ?string $fehler = null;

    /** null = kein laufender Server-Schritt zwischen Transkription und Tool-Loop; 'verstehen' dazwischen. */
    public ?string $phase = null;

    /** Provider-Transparenz (Spec 53/D): openai|assemblyai|fake|none — als Pill sichtbar. */
    public string $provider = 'none';

    /** Nur mit einem echten STT-Zugang darf aufgenommen werden — sonst bleibt nur Tippen. */
    public bool $aufnahmeMoeglich = false;

    /**
     * Spec 53 / Paket F: Agenten-Modus (fragen|auto_sicher|nur_lesen) — Team-Setting, gelesen
     * in {@see mount()}, NUR für die Pill-Anzeige. `#[Locked]` (Review-Fix cooking-jarvis-03):
     * jede public Livewire-Property ist sonst per `$wire.set()` vom Client setzbar — ohne den
     * Schutz könnte ein Team-Mitglied im Modus `nur_lesen` sich selbst auf `auto_sicher`
     * hochstufen. Die tatsächliche Entscheidung (Tool-Loop-Policy, Direktausführung) liest
     * IMMER frisch {@see agentModusAktuell()}, nie diese Property.
     */
    #[Locked]
    public string $agentModus = TeamSettingsService::VOICE_AGENT_MODE_DEFAULT;

    /**
     * Spec 53 / Paket F (3): true während die Antwort abgespielt wird — der Konversations-
     * Modus (Stufe 3B, Browser-Teil) pausiert das VAD-Zuhören solange, damit der Agent sich
     * nicht selbst über die Lautsprecher-Wiedergabe zuhört. `sprechenBeendet()` setzt zurück.
     */
    public bool $sprichtGerade = false;

    /**
     * Spec 53 / Paket F (3): steuert im Blade, ob der Recorder im VAD-Hands-free-Modus läuft
     * (Stille-Erkennung + Auto-Weiterhören nach der Antwort) statt im Ein-Klick-Modus. Gespiegelt
     * vom „dauerhaft aktiv"-Team-Setting (Stufe 2) — reine UX-Weiche, keine Rechte-Entscheidung,
     * darum kein `#[Locked]` nötig (anders als `$agentModus`).
     */
    public bool $konversationAktiv = false;

    /**
     * Spec 53 / Paket F (3): Event-Namen als Konstanten — die Blade-Seite hört über
     * `$wire.on(...)` auf GENAU diese Strings, nicht auf eine zweite, unabhängig gepflegte
     * Kopie. Review-Auflage cooking-jarvis-03: „kein Magic-String an zwei Stellen".
     */
    public const EVENT_TTS_BEREIT = 'voice-tts-bereit';

    public const EVENT_TTS_FEHLGESCHLAGEN = 'voice-tts-fehlgeschlagen';

    /**
     * Rezept-Kontext, falls das Modal von einer Rezept-Seite aus geöffnet wurde (Spec 53/D,
     * Aufgabe 7: „Reichere DIESES Rezept an" ohne dass der Nutzer den Namen nennen muss).
     *
     * @var array{type: string, id: int}|null
     */
    public ?array $kontext = null;

    /**
     * Die Route, auf der das (global gemountete) Modal beim Öffnen der Seite lag — gemerkt in
     * {@see mount()}, damit `verarbeite()` weiss, ob ein geöffneter Datensatz schon auf der
     * aktuellen Seite sichtbar wäre (⇒ Event) oder eine andere Seite braucht (⇒ Redirect).
     */
    public ?string $herkunftRoute = null;

    /**
     * type (aus `foodalchemist.ui.OPEN`) → Route/Query-Param/Same-Page-Event. Routen und Query-
     * Parameter aus routes/web.php und den jeweiligen Browser-/Index-Komponenten verifiziert
     * (nicht geraten) — 2026-09: `#[Url(as: '<param>')]` auf dem jeweiligen `<Typ>Id`/`selectedId`.
     * `event = null` heisst: keine Same-Page-Übernahme bekannt, IMMER per Redirect öffnen.
     */
    private const ZIELE = [
        'recipe' => ['route' => 'foodalchemist.recipes.index', 'param' => 'rezept', 'event' => 'recipe-selected', 'label' => 'Basisrezept öffnen'],
        'verkaufsrezept' => ['route' => 'foodalchemist.verkauf.index', 'param' => 'rezept', 'event' => 'vk-recipe-selected', 'label' => 'Gericht öffnen'],
        'gp' => ['route' => 'foodalchemist.gps.index', 'param' => 'gp', 'event' => null, 'label' => 'Grundprodukt öffnen'],
        'concept' => ['route' => 'foodalchemist.concepts.index', 'param' => 'c', 'event' => null, 'label' => 'Konzept öffnen'],
        'paket' => ['route' => 'foodalchemist.pakete.index', 'param' => 'b', 'event' => null, 'label' => 'Paket öffnen'],
        'foodbook' => ['route' => 'foodalchemist.foodbooks.index', 'param' => 'fb', 'event' => null, 'label' => 'Foodbook öffnen'],
        'speisekarte' => ['route' => 'foodalchemist.speisekarte.index', 'param' => 'sk', 'event' => null, 'label' => 'Speisekarte öffnen'],
        'speiseplan' => ['route' => 'foodalchemist.speiseplan.index', 'param' => 'sp', 'event' => null, 'label' => 'Speiseplan öffnen'],
        'angebot' => ['route' => 'foodalchemist.angebote.index', 'param' => 'sel', 'event' => null, 'label' => 'Angebot öffnen'],
        'format' => ['route' => 'foodalchemist.formate.index', 'param' => 'sel', 'event' => null, 'label' => 'Format öffnen'],
        'supplier' => ['route' => 'foodalchemist.suppliers.index', 'param' => 'lieferant', 'event' => null, 'label' => 'Lieferant öffnen'],
        'order' => ['route' => 'foodalchemist.orders.index', 'param' => 'o', 'event' => null, 'label' => 'Bestellung öffnen'],
        'production_order' => ['route' => 'foodalchemist.produktion.index', 'param' => 'auftrag', 'event' => null, 'label' => 'Produktionsauftrag öffnen'],
    ];

    public function mount(): void
    {
        $stt = app(SttServiceContract::class);
        $this->provider = $stt->name();
        // Fake/None sind nie ein echtes Aufnahmeziel: Fake ignoriert die Aufnahme (fester Fixtext,
        // GENAU der Bug, den Spec 53/D behebt), None wirft nur einen Fehler. Beide bleiben aufs
        // Tippen beschränkt statt eine Aufnahme zu erlauben, die serverseitig ins Leere läuft.
        $this->aufnahmeMoeglich = in_array($this->provider, ['openai', 'assemblyai'], true);
        $this->herkunftRoute = request()->route()?->getName();
        $this->agentModus = $this->agentModusAktuell();   // NUR für die Pill — Entscheidungen lesen immer frisch
        $team = Auth::user()?->currentTeamRelation;
        $this->konversationAktiv = $team !== null && app(TeamSettingsService::class)->voiceAgentDauerhaftAktiv($team);
    }

    /**
     * @param  array{type: string, id: int}|null  $kontext  Rezept-/Gericht-Kontext der öffnenden Seite (Aufgabe 7).
     */
    #[On('voice-modal.oeffnen')]
    public function oeffnen(?array $kontext = null, bool $schwebend = false): void
    {
        $this->reset('audio', 'transcript', 'fehler', 'phase');
        $this->kontext = $kontext;
        // Live-Befund Dominique (2026-09-18): `mount()` liest Modus + "dauerhaft aktiv" NUR beim
        // ersten Seitenaufbau — ändert sich das Team-Setting danach (z. B. auf der Einstellungen-
        // Seite selbst, deren Modal ja auch nur einmal mountet), zeigte die Pille weiter den
        // ALTEN Modus und der Recorder blieb im Ein-Klick-Modus. Öffnen ist der richtige Moment,
        // beides frisch zu lesen — die Pille ist nur Anzeige, `agentModusAktuell()` bleibt die
        // EINZIGE Quelle für die tatsächliche Schreib-Entscheidung (unverändert).
        $this->agentModus = $this->agentModusAktuell();
        $team = Auth::user()?->currentTeamRelation;
        $this->konversationAktiv = $team !== null && app(TeamSettingsService::class)->voiceAgentDauerhaftAktiv($team);
        // Spec 53 / Paket F (4): OHNE das hier wäre jedes Öffnen (auch ohne Seitenwechsel —
        // das Modal mountet zwar nur einmal pro Seite, aber `reset('ergebnis', ...)` lief
        // bisher IMMER beim Öffnen) ein sauberer Neustart, der offene Vorschläge wegwirft.
        // Restauriert wird NUR die Vorschlags-Liste (minimal-valide $ergebnis-Form — die
        // Bestätigen-Methoden lesen ausschliesslich `proposals[$index]`), kein alter Text/
        // keine alten Aktionen, die ohnehin nicht mehr zur aktuellen Seite passen.
        $offene = $this->sitzungOffeneVorschlaege();
        $this->ergebnis = $offene !== [] ? [
            'text' => null, 'runden' => 0, 'tool_laeufe' => [], 'aktionen' => [],
            'proposals' => $offene, 'unklar' => false, 'elapsed_ms' => 0,
        ] : null;
        // Live-Bruch Dominique (2026-09-18, Punkt 3 — erster Schritt Spec 54 „schwebender
        // Begleiter"): der schwebende Knopf soll im Konversations-Modus NICHT mehr das grosse
        // Modal aufreissen — der Knopf selbst zeigt den Zustand (Alpine-Store-Brücke oben in
        // voice-modal.blade.php), eine Sprechblase in agent-mount.blade.php zeigt Transkript +
        // Antwort. Das Modal öffnet nur, wenn es WIRKLICH etwas zu bestätigen gibt (offene
        // Vorschläge aus einer wiederhergestellten Sitzung) — neue Vorschläge WÄHREND dieses
        // Turns öffnen es am Ende von `verarbeite()` nachträglich (dort ist zum Zeitpunkt
        // dieses Aufrufs noch nichts bekannt). Sidebar (`$schwebend=false`, Ein-Klick-Modus)
        // UND ein Klick auf die Blase selbst (ebenfalls `$schwebend=false`) öffnen wie bisher.
        if ($schwebend && $this->konversationAktiv && $offene === []) {
            return;
        }
        $this->dispatch('modal.open', name: 'voice-modal');
    }

    /**
     * Spec 53 / Paket F (4): „Gespräch vergessen" — kompletter Reset des Server-Gedächtnisses
     * UND des sichtbaren Zustands. Eigene Methode statt Wiederverwendung von {@see oeffnen()},
     * weil sie explizit VOR dem Wiederherstellen aufgerufen wird (der Knopf soll wirklich
     * NICHTS übrig lassen, nicht nur den Client-State).
     */
    public function vergessen(): void
    {
        $ids = $this->sitzungIds();
        if ($ids !== null) {
            app(VoiceSessionService::class)->vergessen(...$ids);
        }
        $this->reset('audio', 'transcript', 'ergebnis', 'fehler', 'phase');
    }

    /**
     * Schritt 1 (Roundtrip-Split): NUR transkribieren, dann sichtbar in Schritt 2 übergeben.
     * Vorher lief hier synchron auch der Tool-Loop mit — ein stummer Rundgang von 5-20 s ohne
     * jeden Ladezustand dazwischen.
     */
    public function updatedAudio(): void
    {
        $this->fehler = null;
        $this->phase = null;
        if ($this->audio === null) {
            return;
        }
        try {
            $this->transcript = trim(app(SttServiceContract::class)->transcribe(
                (string) file_get_contents($this->audio->getRealPath()),
                VoiceMime::aufgeloest($this->audio),
            ));
        } catch (\Throwable $e) {
            $this->fehler = VoiceFehlerText::aus($e)['text'];
            $this->audio = null;

            return;
        }
        $this->audio = null;                                          // Temp-Datei freigeben — Transkript ist gesichert.
        if ($this->transcript === '') {
            // Kann auch der Prompt-Echo-Riegel im STT sein (Aufnahme ohne Sprache).
            $this->fehler = 'Aufnahme war leer.';

            return;
        }
        $this->phase = 'verstehen';
        $this->js('$wire.verstehen()');                                // Schritt 2: eigener, sichtbarer Server-Roundtrip.
    }

    /** Schritt 2 (Roundtrip-Split): Tool-Loop über das bereits vorliegende Transkript. */
    public function verstehen(): void
    {
        $this->verarbeite();
        $this->phase = null;
    }

    /** Test-/Tipp-Pfad: Transkript direkt verarbeiten (auch als Fallback-Eingabe, IMMER verfügbar). */
    public function verarbeiteText(string $text): void
    {
        $this->fehler = null;
        $this->transcript = trim($text);
        if ($this->transcript !== '') {
            $this->verarbeite();
        }
    }

    private function verarbeite(): void
    {
        $modus = $this->agentModusAktuell();

        // Spec 53 / Paket F (4): "ja"/"das zweite" bestätigt einen bereits gezeigten, noch
        // offenen Vorschlag — über DIESELBEN Methoden wie der Bestätigen-Klick, KEIN Tool-Loop
        // für ein einzelnes Wort (GL-07 unverändert: nichts läuft hier direkter als der Knopf).
        $offeneIndizes = $this->offeneVorschlagIndizes();
        $referenzPosition = VoiceReferenzResolver::erkenne((string) $this->transcript, count($offeneIndizes));
        if ($referenzPosition !== null) {
            $this->fuehreReferenzAus($offeneIndizes[$referenzPosition]);

            return;
        }

        try {
            $verlauf = app(VoiceSessionService::class)->promptKontext($this->sitzungGeladen());
            $this->ergebnis = app(VoiceCommandService::class)->verarbeite(
                (string) $this->transcript, $this->kontextFuerAuftrag(), $modus, $verlauf,
            );
        } catch (\Throwable $e) {
            $this->fehler = VoiceFehlerText::aus($e)['text'];

            return;
        }
        $navigiert = false;
        foreach ($this->ergebnis['aktionen'] as $i => $aktion) {
            $ziel = $this->aktionZiel($aktion);
            if ($ziel === null) {
                continue;
            }
            if ($ziel['event'] !== null) {
                $this->dispatch($ziel['event'], id: $aktion['id'] ?? null);

                continue;
            }
            if (! $navigiert) {
                // Nur die ERSTE navigierende Aktion navigiert wirklich — eine Antwort kann
                // mehrere ui.OPEN/NAVIGATE-Aufrufe enthalten, aber der Browser kann nicht an
                // zwei Orten gleichzeitig sein. Die übrigen werden unten zu Links.
                $navigiert = true;
                $this->redirect($ziel['url'], navigate: true);

                continue;
            }
            $this->ergebnis['aktionen'][$i]['link'] = $ziel['url'];
            $this->ergebnis['aktionen'][$i]['link_label'] = $ziel['label'];
        }
        // Spec 53/F: im Modus `auto_sicher` laufen die REVERSIBLEN Vorschläge sofort — dieselben
        // Methoden wie der Bestätigen-Klick, nur ohne Klick. AUTO_ERLAUBT ist die einzige
        // Entscheidungsquelle (keine Namensmuster); alles andere bleibt Vorschlag mit Knopf.
        // NACH den aktionen: ein planungStarten()-Redirect hier gewinnt gegen eine ui.OPEN/
        // NAVIGATE-Navigation weiter oben (derselbe Befehl erzeugt praktisch nie beides).
        if ($modus === 'auto_sicher') {
            foreach ($this->ergebnis['proposals'] as $i => $p) {
                $typ = $p['type'] ?? null;
                if (($p['accepted'] ?? false) || ! in_array($typ, VoiceCommandService::AUTO_ERLAUBT, true)) {
                    continue;
                }
                match ($typ) {
                    'speisen_klasse' => $this->proposalUebernehmen($i),
                    'planung_start' => $this->planungStarten($i),
                    'anreicherung' => $this->anreicherungStarten($i),
                    default => null,
                };
            }
        }
        $this->sitzungAktualisieren();
        // Live-Bruch Dominique (2026-09-18, Punkt 3): im Konversations-Modus bleibt das Modal
        // beim Öffnen zu (siehe `oeffnen()`) — entsteht aber WÄHREND dieses Turns ein neuer,
        // noch nicht bestätigter Vorschlag, muss er trotzdem sichtbar werden. `oeffnen()` weiss
        // das zum Öffnen-Zeitpunkt noch nicht (der Tool-Loop läuft ja erst danach) — dieser
        // Aufruf hier ist der einzig richtige Moment.
        if ($this->offeneVorschlagIndizes() !== []) {
            $this->dispatch('modal.open', name: 'voice-modal');
        }
        // Spec 53 / Paket F (3): Konversations-Modus liest die Antwort vor, wenn das Team-Setting
        // an ist. NICHT bei einer Navigation — der Ton würde auf der Seite ankommen, die der Nutzer
        // gerade verlässt (`redirect()` plant den Wechsel, hält die Methode aber nicht an).
        // Live-Bruch Dominique (2026-09-18, Punkt d): NICHT bei "wartet" — das ist das verabredete
        // Signal des Systemprompts für Rauschen/unklares Gemurmel (VoiceCommandService), STUMM
        // bleiben heisst hier auch: keine Sprachausgabe, sonst hätte der Agent sich selbst wieder
        // "wartet" vorgelesen und (über den auf die Wiedergabe folgenden Auto-Zyklus) trotzdem
        // weitergehört, ohne dass echte Sprache da war.
        $istWartetSignal = $this->ergebnis['text'] !== null && mb_strtolower(trim((string) $this->ergebnis['text'])) === 'wartet';
        if (! $navigiert && ! $istWartetSignal) {
            $this->sprichWennAktiviert((string) $this->ergebnis['text']);
        }
    }

    /**
     * Spec 53 / Paket F (4): führt einen per {@see \Platform\FoodAlchemist\Support\VoiceReferenzResolver}
     * erkannten Vorschlag aus — GENAU die Methode, die auch der Bestätigen-Klick in der Blade
     * aufruft, mit demselben Original-Index in `$ergebnis['proposals']`.
     */
    private function fuehreReferenzAus(int $originalIndex): void
    {
        $typ = $this->ergebnis['proposals'][$originalIndex]['type'] ?? null;
        match ($typ) {
            'speisen_klasse' => $this->proposalUebernehmen($originalIndex),
            'planung_start' => $this->planungStarten($originalIndex),
            'anreicherung' => $this->anreicherungStarten($originalIndex),
            'schreibaktion' => $this->schreibaktionAusfuehren($originalIndex),
            default => null,
        };
        $erfolg = ($this->ergebnis['proposals'][$originalIndex]['accepted'] ?? false) === true;
        $antwortText = $erfolg ? 'Erledigt.' : ((string) ($this->fehler ?? 'Konnte nicht ausgeführt werden.'));
        $this->sitzungAktualisieren($antwortText);
        $this->sprichWennAktiviert($antwortText);
    }

    /** @return list<int> Original-Indizes (in `$ergebnis['proposals']`) der noch NICHT bestätigten Vorschläge, in Reihenfolge. */
    private function offeneVorschlagIndizes(): array
    {
        $indizes = [];
        foreach (($this->ergebnis['proposals'] ?? []) as $i => $p) {
            if (! ($p['accepted'] ?? false)) {
                $indizes[] = $i;
            }
        }

        return $indizes;
    }

    /** @return array{0: int, 1: int, 2: string}|null [teamId, userId, sessionId] — null ohne Team/User. */
    private function sitzungIds(): ?array
    {
        $user = Auth::user();
        $team = $user?->currentTeamRelation;
        if ($user === null || $team === null) {
            return null;
        }

        return [(int) $team->id, (int) $user->id, (string) session()->getId()];
    }

    /** @return array<string, mixed> leere Sitzungs-Struktur ohne Team/User. */
    private function sitzungGeladen(): array
    {
        $ids = $this->sitzungIds();
        if ($ids === null) {
            return app(VoiceSessionService::class)->leer();
        }
        [$teamId, $userId, $sessionId] = $ids;

        return app(VoiceSessionService::class)->lade($teamId, $userId, $sessionId);
    }

    /** @return array<int, array<string, mixed>> alle bisher NICHT bestätigten Vorschläge, Original-Indizes. */
    private function sitzungOffeneVorschlaege(): array
    {
        return $this->sitzungGeladen()['offene_vorschlaege'];
    }

    /**
     * Sichert den aktuellen Turn (User-Transkript + Agent-Antwort), die offenen Vorschläge
     * und best-effort das zuletzt geöffnete Objekt. `$agentTextOverride` deckt den Referenz-
     * Ausführungs-Pfad ab, dort bleibt `$ergebnis['text']` null (kein zweiter Tool-Loop-Text).
     */
    private function sitzungAktualisieren(?string $agentTextOverride = null): void
    {
        $ids = $this->sitzungIds();
        if ($ids === null) {
            return;
        }
        [$teamId, $userId, $sessionId] = $ids;
        $svc = app(VoiceSessionService::class);
        $sitzung = $svc->lade($teamId, $userId, $sessionId);
        $sitzung = $svc->zugHinzufuegen($sitzung, 'user', (string) $this->transcript);
        $sitzung = $svc->zugHinzufuegen($sitzung, 'agent', $agentTextOverride ?? (string) ($this->ergebnis['text'] ?? ''));
        $sitzung['offene_vorschlaege'] = $this->ergebnis['proposals'] ?? [];
        $objekt = $this->neuGeoeffnetesObjekt();
        if ($objekt !== null) {
            $sitzung['geoeffnetes_objekt'] = $objekt;
        }
        $svc->speichere($teamId, $userId, $sessionId, $sitzung);
    }

    /** Best-effort aus dem Seiten-Kontext ODER der ersten ui.OPEN/NAVIGATE-Aktion dieser Antwort. */
    private function neuGeoeffnetesObjekt(): ?array
    {
        if ($this->kontext !== null && isset($this->kontext['type'], $this->kontext['id'])) {
            return ['type' => (string) $this->kontext['type'], 'id' => (int) $this->kontext['id'], 'name' => null];
        }
        foreach (($this->ergebnis['aktionen'] ?? []) as $aktion) {
            if (($aktion['type'] ?? null) !== null && isset($aktion['id'])) {
                return ['type' => (string) $aktion['type'], 'id' => (int) $aktion['id'], 'name' => $aktion['label'] ?? null];
            }
        }

        return null;
    }

    /** Nur der Gate-Check (Team-Setting) — {@see sprechen()} bleibt unbedingt aufrufbar. */
    private function sprichWennAktiviert(string $text): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || trim($text) === '' || ! app(TeamSettingsService::class)->voiceTtsVorlesen($team)) {
            return;
        }
        $this->sprechen($text);
    }

    /**
     * Synthetisiert Text zu Sprache und dispatcht eine signierte Kurzzeit-Audio-URL an den
     * Browser (Wiedergabe/Autoplay ist Browser-Teil, Stufe 3B). Schlägt die Synthese fehl,
     * bleibt die Text-Antwort stehen und der Browser fällt auf `speechSynthesis` zurück —
     * kein harter Fehler, denn Vorlesen ist ein Komfort-Extra, nie ein Blocker.
     */
    public function sprechen(string $text): void
    {
        $start = hrtime(true);
        $this->sprichtGerade = true;
        try {
            $team = Auth::user()?->currentTeamRelation;
            $tts = app(TtsServiceContract::class);
            $stimme = $team !== null ? app(TeamSettingsService::class)->voiceTtsStimme($team) : null;
            $audio = $tts->synthesize($text, $stimme);
            $ttlMinuten = (int) config('foodalchemist.tts.audio_ttl_minuten', 5);
            $token = (string) Str::uuid();
            // Live-Bruch Dominique (2026-09-18): auf demo läuft `cache.default=database` — rohe
            // MP3-Bytes in einer utf8mb4-Textspalte lässt MySQL (strict mode) NICHT zu
            // (SQLSTATE 1366 "Incorrect string value" — reproduziert per Tinker: random_bytes
            // wirft, base64_encode geht durch). Base64 ist reines ASCII, passt in JEDEN
            // Cache-Treiber. Gegenstück: VoiceAudioController::stream() dekodiert wieder.
            Cache::put(VoiceAudioController::cacheKey($token), ['bytes' => base64_encode($audio), 'mime' => $tts->mimeType()], now()->addMinutes($ttlMinuten));
            $url = URL::temporarySignedRoute('foodalchemist.voice.audio', now()->addMinutes($ttlMinuten), ['token' => $token]);
            $this->protokolliereTts($tts->name(), (int) ((hrtime(true) - $start) / 1_000_000), true);
            // `text` reist mit — der speechSynthesis-Fallback (Autoplay blockiert ODER Synthese
            // fehlgeschlagen) braucht ihn, das Modal selbst hat ihn sonst nirgends griffbereit.
            $this->dispatch(self::EVENT_TTS_BEREIT, url: $url, text: $text);
        } catch (\Throwable $e) {
            $this->sprichtGerade = false;
            // Live-Bruch Dominique (2026-09-18): `catch (\Throwable)` ohne Variable verschluckte
            // die Fehlermeldung komplett — das Call-Log zeigte nur "fehler — fehlgeschlagen" ohne
            // jeden Hinweis, WARUM (Memory „Etikett lügt": ein Fehler ohne Text ist nicht
            // diagnostizierbar; 40 Minuten für diesen einen Bruch, weil die Ursache nirgends stand).
            $this->protokolliereTts('fehler', (int) ((hrtime(true) - $start) / 1_000_000), false, $e->getMessage());
            $this->dispatch(self::EVENT_TTS_FEHLGESCHLAGEN, text: $text);
        }
    }

    /** Vom Browser gerufen, wenn die Audio-Wiedergabe endet (Stufe 3B) — hebt die VAD-Pause auf. */
    public function sprechenBeendet(): void
    {
        $this->sprichtGerade = false;
    }

    /**
     * Eigener, schlanker Audit-Trail — Spiegel von {@see protokolliereSchreibaktion()}.
     * `$fehlerText` (Live-Bruch 2026-09-18): OHNE ihn war ein fehlgeschlagener TTS-Aufruf im
     * Call-Log nicht diagnostizierbar — nur "fehler — fehlgeschlagen", keine Ursache.
     */
    private function protokolliereTts(string $provider, int $elapsedMs, bool $erfolg, ?string $fehlerText = null): void
    {
        try {
            DB::table('foodalchemist_ai_call_log')->insert([
                'uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(),
                'team_id' => Auth::user()?->currentTeamRelation?->id,
                'user_id' => Auth::id(),
                'feature' => 'voice.tts',
                'tier' => 'D',
                'prompt_hash' => hash('sha256', $provider),
                'response_summary' => mb_strimwidth($provider . ($erfolg ? ' — synthetisiert' : ' — fehlgeschlagen'), 0, 200, '…'),
                'error' => $fehlerText !== null ? mb_strimwidth($fehlerText, 0, 2000, '…') : null,
                'elapsed_ms' => $elapsedMs,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (\Throwable) {
            // Audit darf die eigentliche Aktion nie reissen.
        }
    }

    /**
     * Wohin führt eine Aktion aus dem Tool-Loop? `null` = nichts zu tun (unbekannter Typ, oder
     * ui.NAVIGATE meldet die aktuelle Seite selbst — dann ist der Nutzer schon da).
     *
     * @return array{event: ?string, url: ?string, label: ?string}|null
     */
    private function aktionZiel(array $aktion): ?array
    {
        $typ = $aktion['type'] ?? null;

        if ($typ === 'navigate') {
            $route = $aktion['route'] ?? null;
            if (! is_string($route) || $route === '' || $route === $this->herkunftRoute) {
                return null;                                          // unbekannt oder schon auf der Zielseite
            }
            $url = is_string($aktion['url'] ?? null) && $aktion['url'] !== ''
                ? $aktion['url']
                : (Route::has($route) ? route($route, (array) ($aktion['params'] ?? [])) : null);

            return $url === null ? null : ['event' => null, 'url' => $url, 'label' => (string) ($aktion['label'] ?? 'Öffnen')];
        }

        $ziel = self::ZIELE[$typ] ?? null;
        if ($ziel === null || ! isset($aktion['id'])) {
            return null;
        }
        // Sicherer Default: kennen wir die aktuelle Seite nicht (z. B. Komponenten-Test ohne
        // echten HTTP-Request) oder stimmt sie mit dem Ziel überein, wird — sofern es ein
        // Same-Page-Event gibt — NUR dispatcht, nicht navigiert. Gibt es keins (11 der 13 Typen),
        // ist ein Redirect der einzige Weg, den Datensatz zu zeigen.
        $vermutlichSchonDa = $ziel['event'] !== null
            && ($this->herkunftRoute === null || $this->herkunftRoute === $ziel['route']);
        if ($vermutlichSchonDa) {
            return ['event' => $ziel['event'], 'url' => null, 'label' => null];
        }

        return ['event' => null, 'url' => route($ziel['route'], [$ziel['param'] => $aktion['id']]), 'label' => $ziel['label']];
    }

    /** GL-07: Proposal aus dem Sprachbefehl BESTÄTIGEN (sprechen → Proposal → bestätigen). */
    public function proposalUebernehmen(int $index): void
    {
        $team = Auth::user()?->currentTeamRelation;
        $p = $this->ergebnis['proposals'][$index] ?? null;
        if ($team === null || $p === null || ($p['type'] ?? 'speisen_klasse') !== 'speisen_klasse' || $p['klasse_id'] === null) {
            return;
        }
        try {
            app(\Platform\FoodAlchemist\Services\SpeisenKlassenService::class)->acceptKlasse(
                $team, (int) $p['recipe_id'], (int) $p['klasse_id'],
                (float) ($p['confidence'] ?? 0), $p['reasoning'] ?? null, $p['call_log_id'] ?? null,
            );
            $this->ergebnis['proposals'][$index]['accepted'] = true;
            $this->dispatch('recipe-gespeichert');
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    /**
     * Aufgabe 7 / GL-07: „Planung starten" — DER Schreibpunkt. Bis hierher war alles Vorschlag
     * ({@see \Platform\FoodAlchemist\Tools\PlanungVorschlagPostTool}, read_only). Legt die
     * Session an, übernimmt die (unverbindlich vorgeschlagenen) Leitplanken und öffnet den
     * Editor auf dem passenden Tab mit vorbefülltem Brief. Die Kaskade selbst startet der Mensch
     * dort per `goKaskade()` (zweiter, bewusster Klick — dieser Knopf legt nur die Session an).
     */
    public function planungStarten(int $index): void
    {
        $team = Auth::user()?->currentTeamRelation;
        $p = $this->ergebnis['proposals'][$index] ?? null;
        if ($team === null || $p === null || ($p['type'] ?? null) !== 'planung_start') {
            return;
        }
        $tab = match ($p['scope'] ?? null) {
            'rezept' => 'basisrezept', 'gericht' => 'gericht', 'concept' => 'concept', default => null,
        };
        $brief = trim((string) ($p['brief'] ?? ''));
        if ($tab === null || $brief === '') {
            $this->fehler = 'Vorschlag unvollständig — Planung kann nicht gestartet werden.';

            return;
        }
        try {
            $svc = app(PlanningSessionService::class);
            $titel = trim((string) ($p['titel'] ?? ''));
            $session = $svc->create($team, [
                'title' => $titel !== '' ? $titel : 'Sprachbefehl: ' . mb_strimwidth($brief, 0, 60, '…'),
                'brief' => $brief,
                'created_via' => 'voice',
            ]);
            $leitplanken = $p['leitplanken'] ?? null;
            if (is_array($leitplanken) && $leitplanken !== []) {
                $svc->setGenerationParams($team, (int) $session->id, $leitplanken);   // fail-soft egal, filtert selbst
            }
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        $this->ergebnis['proposals'][$index]['accepted'] = true;   // Spec 53/F: auch hier setzen (auto_sicher, Audit)
        $this->redirect(route('foodalchemist.planung.index', ['session' => $session->id, 'open' => 1, 'tab' => $tab]), navigate: true);
    }

    /**
     * Aufgabe 7 / GL-07: „Reichere dieses Rezept an" — dispatcht {@see EnrichRecipeJob} wie der
     * bestehende Editor-Knopf ({@see \Platform\FoodAlchemist\Livewire\Recipes\RecipeModal::allesAnreichern}).
     * Ownership wird HIER noch einmal geprüft (nicht dem Sekunden alten Proposal-Snapshot vertraut).
     */
    public function anreicherungStarten(int $index): void
    {
        $team = Auth::user()?->currentTeamRelation;
        $p = $this->ergebnis['proposals'][$index] ?? null;
        if ($team === null || $p === null || ($p['type'] ?? null) !== 'anreicherung') {
            return;
        }
        $recipe = app(RecipeService::class)->detail($team, (int) ($p['recipe_id'] ?? 0));
        if ($recipe === null) {
            $this->fehler = 'Rezept nicht mehr sichtbar/vorhanden.';

            return;
        }
        EnrichRecipeJob::dispatch($team->id, (int) (Auth::id() ?? 0), (int) $recipe->id, null, false, null, false, true);
        $this->ergebnis['proposals'][$index]['accepted'] = true;
        $this->fehler = null;
    }

    /**
     * Paket F (1b) / GL-07: der generische Schreibvorschlag — Bestätigen führt DASSELBE Tool
     * mit DENSELBEN Argumenten aus, die der Agent vorgeschlagen hatte, JETZT über den echten
     * Team-Kontext des angemeldeten Nutzers (nicht den Sekunden alten Proposal-Snapshot). Ein
     * im Vorschlag neutralisiertes Commit-Flag (`entschaerfeArgumente()` erzwingt `confirm`
     * u. a. auf `false`, siehe VoiceCommandService) wird HIER — und nur hier, am menschlichen
     * Bestätigen-Klick — wieder auf `true` gesetzt.
     */
    public function schreibaktionAusfuehren(int $index): void
    {
        $team = Auth::user()?->currentTeamRelation;
        $p = $this->ergebnis['proposals'][$index] ?? null;
        if ($team === null || $p === null || ($p['type'] ?? null) !== 'schreibaktion' || ($p['accepted'] ?? false)) {
            return;
        }
        $tool = app(ToolRegistry::class)->get((string) ($p['tool'] ?? ''));
        if ($tool === null) {
            $this->fehler = 'Werkzeug nicht mehr verfügbar.';

            return;
        }
        $argumente = (array) ($p['arguments'] ?? []);
        foreach (VoiceCommandService::COMMIT_FLAGS as $flag) {
            if (array_key_exists($flag, $argumente)) {
                $argumente[$flag] = true;                             // JETZT bestätigt der Mensch wirklich
            }
        }
        $start = hrtime(true);
        $resultat = $tool->execute($argumente, new ToolContext(Auth::user(), $team));
        $this->protokolliereSchreibaktion((string) $p['tool'], (int) ((hrtime(true) - $start) / 1_000_000), $resultat->success);
        if (! $resultat->success) {
            $this->fehler = $resultat->error ?? 'Aktion fehlgeschlagen.';

            return;
        }
        $this->ergebnis['proposals'][$index]['accepted'] = true;
        $this->fehler = null;
    }

    /**
     * Eigener, schlanker Audit-Trail für tatsächlich ausgeführte Schreibvorschläge — getrennt
     * vom `voice.command`-Log des Tool-Loops (der läuft schon, bevor der Mensch bestätigt hat).
     * Graceful: ein Logging-Fehler darf die eigentliche Aktion nie reissen.
     */
    private function protokolliereSchreibaktion(string $tool, int $elapsedMs, bool $erfolg): void
    {
        try {
            DB::table('foodalchemist_ai_call_log')->insert([
                'uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(),
                'team_id' => Auth::user()?->currentTeamRelation?->id,
                'user_id' => Auth::id(),
                'feature' => 'voice.schreibaktion',
                'tier' => 'D',
                'prompt_hash' => hash('sha256', $tool),
                'response_summary' => mb_strimwidth($tool . ($erfolg ? ' — ausgeführt' : ' — fehlgeschlagen'), 0, 200, '…'),
                'elapsed_ms' => $elapsedMs,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (\Throwable) {
            // Audit darf die eigentliche Aktion nie reissen.
        }
    }

    /**
     * Rezept-Kontext für „reichere DIESES Rezept an" ohne genannten Namen: erst das Kontext-Event
     * ({@see oeffnen()}), sonst Fallback auf `?rezept=` (trägt z. B. die Rezepte-Browser-Seite
     * schon heute über `#[Url(as: 'rezept')]`). Ohne beides bleibt es `null` — der Systemprompt
     * weist den Agenten an, dann nach dem Rezeptnamen zu fragen statt zu raten.
     *
     * @return array{type: string, id: int}|null
     */
    private function kontextFuerAuftrag(): ?array
    {
        if ($this->kontext !== null && isset($this->kontext['type'], $this->kontext['id'])) {
            return ['type' => (string) $this->kontext['type'], 'id' => (int) $this->kontext['id']];
        }
        $rezeptId = request()->query('rezept');
        if (is_string($rezeptId) && ctype_digit($rezeptId)) {
            return ['type' => 'recipe', 'id' => (int) $rezeptId];
        }

        return null;
    }

    /**
     * Review-Fix (cooking-jarvis-03): den Modus für die SCHREIB-Entscheidung immer frisch aus
     * dem Team-Setting lesen statt aus `$this->agentModus` — die Property ist zwar `#[Locked]`,
     * aber die Wahrheit steht im Team-Setting, nicht in einem Zwischenstand der Komponente
     * (kein Vertrauen auf einen möglicherweise veralteten/umgangenen Client-Zustand).
     */
    private function agentModusAktuell(): string
    {
        $team = Auth::user()?->currentTeamRelation;

        return $team !== null ? app(TeamSettingsService::class)->voiceAgentModus($team) : TeamSettingsService::VOICE_AGENT_MODE_DEFAULT;
    }

    public function render()
    {
        return view('foodalchemist::livewire.voice-modal');
    }
}
