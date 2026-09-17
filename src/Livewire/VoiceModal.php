<?php

namespace Platform\FoodAlchemist\Livewire;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;
use Platform\FoodAlchemist\Jobs\EnrichRecipeJob;
use Platform\FoodAlchemist\Services\PlanningSessionService;
use Platform\FoodAlchemist\Services\RecipeService;
use Platform\FoodAlchemist\Services\Stt\SttServiceContract;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Services\VoiceCommandService;
use Platform\FoodAlchemist\Support\VoiceFehlerText;
use Platform\FoodAlchemist\Support\VoiceMime;

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
    }

    /**
     * @param  array{type: string, id: int}|null  $kontext  Rezept-/Gericht-Kontext der öffnenden Seite (Aufgabe 7).
     */
    #[On('voice-modal.oeffnen')]
    public function oeffnen(?array $kontext = null): void
    {
        $this->reset('audio', 'transcript', 'ergebnis', 'fehler', 'phase');
        $this->kontext = $kontext;
        $this->dispatch('modal.open', name: 'voice-modal');
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
        try {
            $this->ergebnis = app(VoiceCommandService::class)->verarbeite(
                (string) $this->transcript, $this->kontextFuerAuftrag(), $modus,
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
