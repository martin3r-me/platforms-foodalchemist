<?php

/**
 * Food Alchemist Service Provider
 * 
 * Dieser Service Provider ist das Herzstück jedes Platform-Moduls.
 * 
 * WICHTIG FÜR LLMs:
 * - Dieser Service Provider folgt dem exakten Muster von HCM und Planner
 * - Alle wichtigen Schritte sind kommentiert
 * - Config wird in register() geladen (Laravel Best Practice)
 * - Modul-Registrierung erfolgt in boot()
 * 
 * ANPASSUNGEN FÜR NEUES MODUL:
 * 1. Ersetze "FoodAlchemist" durch deinen Modul-Namen (PascalCase)
 * 2. Ersetze "foodalchemist" durch deinen Modul-Namen (kebab-case)
 * 3. Passe Namespaces an
 * 4. Füge Commands/Tools hinzu falls nötig
 * 
 * @see Platform\Core\PlatformCore für Modul-Registrierung
 * @see Platform\Core\Routing\ModuleRouter für Route-Registrierung
 */

namespace Platform\FoodAlchemist;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Platform\Core\PlatformCore;
use Platform\Core\Routing\ModuleRouter;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class FoodAlchemistServiceProvider extends ServiceProvider
{
    /**
     * Register Services
     * 
     * Wird VOR boot() aufgerufen.
     * Hier sollten nur leichte Registrierungen erfolgen.
     * 
     * LARAVEL BEST PRACTICE:
     * - Config sollte hier geladen werden (mergeConfigFrom)
     * - Commands können hier registriert werden
     */
    public function register(): void
    {
        $this->app->scoped(\Platform\FoodAlchemist\Services\Knowledge\KnowledgeRunContext::class);
        // Spec 84 §3.3: Merker des Team-Kontexts je Request/Job (scoped = Laravel leert ihn zwischen Jobs)
        $this->app->scoped(\Platform\FoodAlchemist\Support\FaTeam::class);
        $this->app->scoped(\Platform\FoodAlchemist\Support\AngebotFestpreise::class);   // eingefrorenes Angebot: Festpreise je Request
        $this->app->scoped(\Platform\FoodAlchemist\Services\Ai\KiKlassenEinstellung::class);   // Spec 85: KI-Klasse je Schritt, je Request gelesen
        // Spec 84 §4.4 (E3): aktive Support-Freigabe je Request/Job gemerkt (FaRechte::rolle fragt die Sperre sehr oft)
        $this->app->scoped(\Platform\FoodAlchemist\Services\SupportFreigabeService::class);
        // Spec 77c: Team-Namen der Standort-Spalte je Request merken
        $this->app->scoped(\Platform\FoodAlchemist\Services\StandortService::class);
        /**
         * Config laden
         * 
         * mergeConfigFrom lädt die Config aus dem Package-Verzeichnis
         * und merged sie mit der Config aus config/ (falls vorhanden).
         * 
         * WICHTIG: Muss in register() sein, nicht in boot()!
         */
        $this->mergeConfigFrom(__DIR__.'/../config/foodalchemist.php', 'foodalchemist');
        
        /**
         * Commands registrieren
         */
        if ($this->app->runningInConsole()) {
            $this->commands([
                \Platform\FoodAlchemist\Console\LangsameAbfragenCommand::class,   // Spec 84 E-1: Auswertung fa-langsam
                \Platform\FoodAlchemist\Console\FremdSchreibenCommand::class,   // Spec 84 B4: Auswertung fa-fremdschreiben
                \Platform\FoodAlchemist\Console\NectaNachholenCommand::class,   // Necta-Abgleich: Einmal-Nachholer (Bauauftrag 15.10.2026)
                \Platform\FoodAlchemist\Console\NectaAbgleichCommand::class,    // Necta-Abgleich: nächtlich + wöchentlich (PR 2)
                \Platform\FoodAlchemist\Console\NectaDeklarationReparierenCommand::class,   // Einmal-Reparatur Deklarationen (Lauf 3, 15.10.2026)
                \Platform\FoodAlchemist\Console\NectaDeklarationNachfuellenCommand::class,  // neue Deklarations-Spalte nachfüllen (Jod, 15.10.2026)
                \Platform\FoodAlchemist\Console\JodAbleitenCommand::class,                  // Jod aus der Zutatenliste ableiten (15.10.2026)
                \Platform\FoodAlchemist\Console\MengeKorrigierenCommand::class,              // Menge aus der Bezeichnung (EDI, 16.10.2026)
                \Platform\FoodAlchemist\Console\NectaLieferstatusCommand::class,             // Lieferstatus aus dem Preis-Lebenszyklus (16.10.2026)
                \Platform\FoodAlchemist\Console\NectaPreishistorieNachholenCommand::class,  // Necta PR 2b: Preis-Historie (Stufe 1: Analyse)
                \Platform\FoodAlchemist\Console\PlanNutzungZaehlenCommand::class,   // Spec 82 §13 D (täglich, Tageswerte → team_billable_usages)
                \Platform\FoodAlchemist\Console\PlanPreiseStartwerteCommand::class,   // Spec 82 §13 D (Platzhalter-Startpreise)
                \Platform\FoodAlchemist\Console\EquipmentStandardSetzenCommand::class,   // Spec 82 §6 (Daten per Befehl, nicht per Migration)
                \Platform\FoodAlchemist\Console\PreislisteImportCommand::class,   // Spec 82 §3 (Abgleich, schreibt keine Preise)
                \Platform\FoodAlchemist\Console\GpHomogenitaetBerichtCommand::class,   // Spec 82 §2 (nur lesend)
                \Platform\FoodAlchemist\Console\WareneingangAltbestandCommand::class,   // Spec 75c
                \Platform\FoodAlchemist\Console\RegelnKonsumentenCommand::class,   // Spec 81: Schutz vor Paket 5
                \Platform\FoodAlchemist\Console\GpFelderBackfillCommand::class,   // Spec 80 Paket 11
                \Platform\FoodAlchemist\Console\BehaelterKatalogCommand::class,
                \Platform\FoodAlchemist\Console\ImportSliceCommand::class,
                \Platform\FoodAlchemist\Console\RegenerationHochziehenCommand::class,
                \Platform\FoodAlchemist\Console\ImportMasterCommand::class,
                \Platform\FoodAlchemist\Console\GpBereinigenCommand::class,
                \Platform\FoodAlchemist\Console\GpUmhaengenCommand::class,
                \Platform\FoodAlchemist\Console\AbgleichEvalCommand::class,   // Abgleich-Dienst: Eval (schreibt nie, zurückgerollte Transaktion)
                \Platform\FoodAlchemist\Console\GpPaketImportCommand::class,   // E2: GP-Paket → io (global, stamm_paket)
                \Platform\FoodAlchemist\Console\GpAromaBackfillCommand::class,   // 27.10.2026: Anker + Geschmacksprofil für Lücken (Geschwister, dann KI)
                \Platform\FoodAlchemist\Console\GpStammPurgeCommand::class,   // Neu-Import: globalen GP-Stamm hart löschen (Trockenlauf, Riegel, Backup-Nachweis)
                \Platform\FoodAlchemist\Console\KnowledgeImportCommand::class,
                // 2026-09-03: die Routing-Politik hat den Import VERLASSEN. Sie lag dort und wurde
                // bei jedem Lauf mitgeschrieben — auf einer frischen DB hätte das den
                // Monolithen-Pfad wiederhergestellt, den Welle 0 abgebaut hat.
                \Platform\FoodAlchemist\Console\KnowledgePolicySeedCommand::class,
                // W2-5: der Rückweg — bis hierher gab es nur Import (Modul = SSOT ohne Backup-Pfad).
                \Platform\FoodAlchemist\Console\KnowledgeExportCommand::class,
                // W2-4: §-Deckung — hätte den verlorenen §12 gefunden.
                \Platform\FoodAlchemist\Console\WissenDeckungCommand::class,
                // W1-1: misst Findbarkeit jenseits des Embedding-Fensters (vor/nach).
                \Platform\FoodAlchemist\Console\WissenRecallProbeCommand::class,
                // One-Shot: condition (§9) nachtragen, wo der Name ihn nennt und das Feld leer ist.
                \Platform\FoodAlchemist\Console\GpZustandBackfillCommand::class,
                // One-Shot: Reifegrad an bestehenden Uebernahme-Schritten nachtragen.
                \Platform\FoodAlchemist\Console\ReuseReifeBackfillCommand::class,
                \Platform\FoodAlchemist\Console\WissenSteuerdatenW0Command::class,
                \Platform\FoodAlchemist\Console\WissenDeckelCheckCommand::class,
                // Spec 52/A2: welcher Prompt-Key bekommt welches Wissen — Kanon, Routing,
                // Bindung, Budget in einer Tabelle. Ungesteuerte Keys sind ein Befund.
                // Abgrenzung zu `wissen-deckung` (W2-4): das prüft die KORPUS-Richtung
                // („nennt ein Prompt einen §, den kein Dossier hat"), das hier die
                // VERSORGUNGS-Richtung („welches Wissen erreicht diesen Prompt überhaupt").
                \Platform\FoodAlchemist\Console\WissenVersorgungCommand::class,
                \Platform\FoodAlchemist\Console\WissenProfilCommand::class,
                \Platform\FoodAlchemist\Console\WissenVerweiseCommand::class,
                \Platform\FoodAlchemist\Console\WissenSpurenCommand::class,
                \Platform\FoodAlchemist\Console\WissenGlobalHebenCommand::class,
                \Platform\FoodAlchemist\Console\WechselkursHolenCommand::class,
                \Platform\FoodAlchemist\Console\PlattformBasisHebenCommand::class,
                \Platform\FoodAlchemist\Console\WissenKanonSicherungCommand::class,
                \Platform\FoodAlchemist\Console\WissenEinordnungSicherungCommand::class,
                \Platform\FoodAlchemist\Console\WissenSteuerungSicherungCommand::class,
                // Spec 52/A1: Grundlinie am tatsächlich versendeten Aufruf — inkl. der drei
                // Folge-Calls, die der Kontext-Inspektor ausblendet.
                \Platform\FoodAlchemist\Console\WissenGrundlinieCommand::class,
                \Platform\FoodAlchemist\Console\KnowledgeEmbedCommand::class,
                // Spec 50 Strang III: Arbeitsliste zu großer Dossiers (ein Thema pro Dossier ≤ Deckel).
                \Platform\FoodAlchemist\Console\KnowledgeOversizedCommand::class,
                \Platform\FoodAlchemist\Console\EmbedCommand::class,
                \Platform\FoodAlchemist\Console\EmbeddingsMigrateStoreCommand::class,
                // Bereitschafts-Prüfung für den Cutover (lesend) — GO/NO-GO je Pool.
                \Platform\FoodAlchemist\Console\EmbeddingCutoverCheckCommand::class,
                \Platform\FoodAlchemist\Console\EmbedEvalCommand::class,
                \Platform\FoodAlchemist\Console\NonFoodEinrichtenCommand::class,
                \Platform\FoodAlchemist\Console\NonFoodErkennenCommand::class,
                \Platform\FoodAlchemist\Console\GeschirrTypUebernehmenCommand::class,
                \Platform\FoodAlchemist\Console\LieferantenGruppenEinrichtenCommand::class,
                \Platform\FoodAlchemist\Console\LieferantenGruppenVorschlagenCommand::class,
                \Platform\FoodAlchemist\Console\MatcherEvalCommand::class,
                \Platform\FoodAlchemist\Console\GeneratorEvalCommand::class,
                \Platform\FoodAlchemist\Console\TerminologyImportCommand::class,
                \Platform\FoodAlchemist\Console\TeamOnboardingCommand::class,
                \Platform\FoodAlchemist\Console\SignaleDetektorCommand::class,
                \Platform\FoodAlchemist\Console\SpeiseplanAushangRollierenCommand::class,
                \Platform\FoodAlchemist\Console\AboHuelleNachfuehrenCommand::class,
                \Platform\FoodAlchemist\Console\InspireImportCommand::class,
                \Platform\FoodAlchemist\Console\AnkerWissenCommand::class,
                \Platform\FoodAlchemist\Console\RezeptProfileCommand::class,
                \Platform\FoodAlchemist\Console\DataQualityCommand::class,
                \Platform\FoodAlchemist\Console\LeadLaRepickCommand::class,
                \Platform\FoodAlchemist\Console\RecomputeCommand::class,
                \Platform\FoodAlchemist\Console\GpAllergenBackfillCommand::class,
                \Platform\FoodAlchemist\Console\GpFormsEstimateCommand::class,
                \Platform\FoodAlchemist\Console\TellerRollenKlassifizierenCommand::class,
                \Platform\FoodAlchemist\Console\BasisrezeptRollenVorschlagenCommand::class,
                \Platform\FoodAlchemist\Console\RecipePackagingUnitsCommand::class,
                \Platform\FoodAlchemist\Console\VocabUnitsDedupeCommand::class,
                \Platform\FoodAlchemist\Console\FavoriteGpsCommand::class,
                \Platform\FoodAlchemist\Console\BackfillKapitelZieleCommand::class,
                \Platform\FoodAlchemist\Console\RecipeFindingsCommand::class,
                \Platform\FoodAlchemist\Console\ImportArticlesCommand::class,
                \Platform\FoodAlchemist\Console\MoneyTruthReportCommand::class,
                \Platform\FoodAlchemist\Console\VollstaendigkeitReportCommand::class,
                \Platform\FoodAlchemist\Console\AllergenLaVorrangCommand::class,
                \Platform\FoodAlchemist\Console\AllergeneNurPositivCommand::class,   // Spec 85
                \Platform\FoodAlchemist\Console\MarkenEinspielenCommand::class,   // GP-Regelwerk v3.7.1 §5 Ausnahme 3
                \Platform\FoodAlchemist\Console\SeedRebateTiersCommand::class,
                \Platform\FoodAlchemist\Console\StepsBackfillCommand::class,
                \Platform\FoodAlchemist\Console\TrendsMessenCommand::class,      // Spec 79
                \Platform\FoodAlchemist\Console\TrendsStartbestandCommand::class, // Spec 79
                \Platform\FoodAlchemist\Console\AnchorsTranslateCsvCommand::class,
                \Platform\FoodAlchemist\Console\PaketeToConceptsCommand::class,
                \Platform\FoodAlchemist\Console\FormatEditionsToSlotsCommand::class,
                \Platform\FoodAlchemist\Console\DynamicPricingMigrationCommand::class,
                \Platform\FoodAlchemist\Console\ConformanceBackfillCommand::class,
            ]);

            $this->planeLaeufe();
        }
    }

    /**
     * Den Qualitäts-Lauf täglich einplanen — **im Modul**, nicht im Host-Kernel.
     *
     * Der Command trug seit seiner Entstehung den Kommentar „Registrierung der Cron-Frequenz
     * ist Host-/Deploy-Sache (Console-Kernel der office.bhg-App)". Dort ist es nie passiert.
     * Die Folge war ein blinder Fleck, der wie ein fehlendes Feature aussah: die 20+ Signal-
     * Typen für Rezepte/Konzepte/Foodbooks und die komplette Zeitreihe existierten im Code,
     * aber nie in den Daten — eine Ampel, die nur leuchtet, wenn jemand eine Shell öffnet.
     *
     * Ein Modul, dessen Kernfunktion von einer Registrierung in einem fremden Repo abhängt,
     * hat diese Funktion nicht. Darum hängt der Plan jetzt hier: mitdeployt, mitversioniert,
     * ohne Zutun von außen. Der Host behält die Hoheit — er kann den Eintrag über
     * `foodalchemist.scheduler.enabled` abschalten.
     *
     * Bewusst NUR der Detektor. `recipe-findings` bleibt manuell: er ruft das Modell pro
     * Rezept, und ein nächtlicher Job, der ungefragt Provider-Geld ausgibt, ist keine
     * Bequemlichkeit, sondern eine unbemerkte Rechnung.
     *
     * 03:20 Uhr: nach dem DB-Snapshot (23:00) und weit vor dem Arbeitstag — der Lauf ist die
     * teuerste lesende Operation des Moduls und soll nicht neben der Nutzung liegen.
     */
    private function planeLaeufe(): void
    {
        if (! config('foodalchemist.scheduler.enabled', true)) {
            return;
        }

        // `booted`, weil der Scheduler beim Registrieren des Providers noch nicht steht.
        $this->app->booted(function () {
            $schedule = $this->app->make(\Illuminate\Console\Scheduling\Schedule::class);

            $schedule->command(\Platform\FoodAlchemist\Console\SignaleDetektorCommand::class)
                ->dailyAt(config('foodalchemist.scheduler.detektor_zeit', '03:20'))
                ->withoutOverlapping()   // ein zweiter Lauf würde in dieselbe Zeitreihe schreiben
                ->onOneServer()          // Hausschreibweise des Hosts (routes/console.php)
                ->runInBackground()
                ->description('FoodAlchemist: Qualitäts-Lauf (Signale, DQ-Kaskade, Zeitreihen-Snapshot, Drift)');

            // Necta-Abgleich (PR 2): nachts Preis-Delta + neue Artikel, wöchentlich Vollvergleich. NUR bewusst eingeschaltet
            // (foodalchemist.necta.abgleich_aktiv, Standard aus): erst Probelauf und Dominiques Ja, dann einschalten.
            if (config('foodalchemist.necta.abgleich_aktiv', false)) {
                \Platform\FoodAlchemist\Console\NectaAbgleichCommand::planen($schedule);
            }

            // KI-Kosten (Dominique 18.10.2026): EZB-Tageskurs EUR/USD, 16:30 Europe/Berlin — fern vom Necta-/Gebühren-Fenster (nachts)
            if (config('foodalchemist.scheduler.wechselkurs_aktiv', true)) {
                \Platform\FoodAlchemist\Console\WechselkursHolenCommand::planen($schedule);
            }

            // Spec 82 §13 D: FA-Gebühren täglich zählen (Tageswerte in team_billable_usages). Idempotent; verpasste Tage mit --von/--bis nachholen.
            // Nur mit bewusst eingeschalteter Abrechnung (config foodalchemist.gebuehren.zaehlen_aktiv, Default aus): der Lauf schreibt in die Core-Rechnungstabelle.
            if (config('foodalchemist.gebuehren.zaehlen_aktiv', false)) {
                $schedule->command(\Platform\FoodAlchemist\Console\PlanNutzungZaehlenCommand::class)
                    ->dailyAt(config('foodalchemist.scheduler.plan_zaehlen_zeit', '02:10'))
                    ->withoutOverlapping()
                    ->onOneServer()
                    ->runInBackground()
                    ->description('FoodAlchemist: Gebühren je Mandant zählen (Basis, User, Unter-Teams, Stamm-Paket, Abo)');
            }

            // Wissens-Steuerdaten-Wächter (2026-09-03). Rein LESEND (`--verify`), kein
            // Provider-Call, kein Schreibvorgang an den Steuerdaten — er meldet nur.
            //
            // Warum er sein muss: die Routings/Bindings sind per Hand editierbar (MCP, SQL), und
            // genau das ist schon passiert (Live-Tabelle `regelwerk|discovery|4x8000` gegen
            // Migration `always|1|7000`). Ein Regelwerk, das leise aus dem Prompt fällt, erzeugt
            // KEINEN Fehler — der Generator läuft weiter und liefert nur schlechtere Rezepte.
            // Ohne diesen Lauf ist jede Token- und Qualitätsarbeit an den Prompts in Wochen
            // wieder aufgebraucht, ohne dass jemand den Grund benennen kann.
            //
            // Der Befund geht als SIGNAL ins Cockpit, nicht in ein Log: eine Log-Zeile hätte
            // dieselbe Eigenschaft wie der alte Deckel-Vermerk in `params` — technisch vorhanden,
            // faktisch unsichtbar.
            $schedule->command(\Platform\FoodAlchemist\Console\WissenSteuerdatenW0Command::class, ['--verify'])
                ->weeklyOn(1, config('foodalchemist.scheduler.steuerdaten_zeit', '06:30'))
                ->withoutOverlapping()
                ->onOneServer()
                ->runInBackground()
                ->description('FoodAlchemist: Wissens-Steuerdaten gegen das Soll prüfen (Drift → Signal)');

            // Spec 50 · Etappe 8b — der Deckel- und Verdrahtungs-Wächter.
            //
            // Zwei Invarianten, die beide STILL brechen: ein Dossier über 4.000 Zeichen fällt
            // erst auf, wenn jemand die Fehlermeldung in der Oberfläche sieht; ein Dossier, das
            // im Register fehlt, fällt GAR NICHT auf — `ablauf.GET` liefert es einfach nie.
            // Nach dem Split liegen sieben Dossiers zwischen 3.900 und 3.993 Zeichen, die
            // nächste Ergänzung kippt eines davon. Deshalb wöchentlich statt auf Zuruf.
            $schedule->command(\Platform\FoodAlchemist\Console\WissenDeckelCheckCommand::class)
                ->weeklyOn(1, config('foodalchemist.scheduler.deckel_check_zeit', '06:45'))
                ->withoutOverlapping()
                ->onOneServer()
                ->runInBackground()
                ->description('FoodAlchemist: Wissens-Dossiers gegen Zeichen-Deckel und Vorgangs-Verdrahtung prüfen');

            // Spec 52 · Paket 3 — hält die Kanon-Sicherung gegen den Live-Stand.
            //
            // Der Kanon entsteht nur über MCP und lebt danach ausschliesslich als DB-Zeilen (H7).
            // Jede Kuration, die nicht exportiert wurde, ist bei einem Neuaufbau weg — und weil
            // `hasCanon()` dann false liefert, greift der Bindungs-Fallback, den es dort ebenfalls
            // nicht gibt. Der Generator läuft ohne Regelwerk, ohne Fehler. Genau die Sorte Verlust,
            // die niemand bemerkt, solange niemand danach fragt: also wöchentlich fragen.
            // `kanon_team = 0` schaltet ihn ab — eine frische Instanz ohne dieses Team soll
            // keinen wöchentlich fehlschlagenden Wächter erben, sondern gar keinen.
            $kanonTeam = (int) config('foodalchemist.scheduler.kanon_team', 6);
            if ($kanonTeam > 0) {
                $schedule->command(\Platform\FoodAlchemist\Console\WissenKanonSicherungCommand::class, [
                    'richtung' => 'pruefen', '--team' => $kanonTeam,
                ])
                    ->weeklyOn(1, config('foodalchemist.scheduler.kanon_sicherung_zeit', '06:50'))
                    ->withoutOverlapping()
                    ->onOneServer()
                    ->runInBackground()
                    ->description('FoodAlchemist: Kanon-Sicherung gegen den Live-Kanon prüfen (Drift → Signal)');
            }

            // Spec 84 B3 Regel B: Abo-Hüllen nachts nachführen (gelöschte Verweise lassen ältere Fassungen sonst zu lange sichtbar).
            // 01:40 = vor Gebühren-Zählung (02:10) und Necta-Abgleich (02:30, eigene Sperre); eigene Sperre über withoutOverlapping.
            $schedule->command(\Platform\FoodAlchemist\Console\AboHuelleNachfuehrenCommand::class)
                ->dailyAt(config('foodalchemist.scheduler.abo_huelle_zeit', '01:40'))
                ->withoutOverlapping(30)
                ->onOneServer()
                ->runInBackground()
                ->description('FoodAlchemist: Abo-Hüllen nachführen (Regel B: ältere Fassungen nur mit eigenem Verweis)');

            // Spec 57 · E9: Speiseplan-Aushänge „immer die laufende Woche“ jeden Montag früh neu
            // einfrieren. Nur lesend+veröffentlichend, kein Provider-Call.
            $schedule->command(\Platform\FoodAlchemist\Console\SpeiseplanAushangRollierenCommand::class)
                ->weeklyOn(1, config('foodalchemist.scheduler.speiseplan_rollieren_zeit', '00:20'))
                ->withoutOverlapping()
                ->onOneServer()
                ->runInBackground()
                ->description('FoodAlchemist: Speiseplan-Aushänge auf die laufende Woche neu einfrieren');

            // Spec 79: wöchentliche Google-Trends-Messung — nur Teams mit trend_dataforseo_enabled, Budget-Stopp je Team.
            if (config('foodalchemist.scheduler.trends_messen_enabled', true)) {
                $schedule->command(\Platform\FoodAlchemist\Console\TrendsMessenCommand::class)
                    ->weeklyOn(2, config('foodalchemist.scheduler.trends_messen_zeit', '06:10'))
                    ->withoutOverlapping()
                    ->onOneServer()
                    ->runInBackground()
                    ->description('FoodAlchemist: Trendradar → Google Trends je Trend messen (DataForSEO)');
            }
        });
    }

    /**
     * Boot Services
     * 
     * Wird NACH register() aufgerufen.
     * Hier erfolgt die eigentliche Modul-Registrierung.
     * 
     * REIHENFOLGE IST WICHTIG:
     * 1. Config prüfen (bereits in register() geladen)
     * 2. Modul bei PlatformCore registrieren
     * 3. Routes laden (nur wenn Modul registriert)
     * 4. Migrationen, Views, Livewire registrieren
     */
    public function boot(): void
    {
        // Spec 84 §3.3 · Team-Transport in Jobs an EINER Stelle: jeder Job trägt das Team, das beim Versand galt — egal ob aus
        // Livewire, MCP oder FaTeam::imTeam. Läuft auf der Kopie, die in den Payload geht (der laufende Request bleibt unberührt).
        \Illuminate\Support\Facades\Context::dehydrating(function (\Illuminate\Log\Context\Repository $context) {
            if (! $context->hasHidden(\Platform\FoodAlchemist\Support\FaTeam::SCHLUESSEL)) {
                $context->addHidden(\Platform\FoodAlchemist\Support\FaTeam::SCHLUESSEL, \Platform\FoodAlchemist\Support\FaTeam::aktuellId());
            }
        });

        // Spec 65 · Bearbeitungssperre: serverseitige Durchsetzung für alle Editoren mit MitBearbeitungssperre.
        // Jede Methode außerhalb von sperrFreieMethoden() läuft nur, wenn die aufrufende Person die Sperre hält
        // (Klick auf „Bearbeiten"); sonst Abweisung mit Hinweis statt stillem Schreiben.
        // Spec 77b · Bereich der Komponente: gesperrter Bereich = keine Aktion (Seitenaufruf sperrt die Middleware).
        \Livewire\on('call', function ($component, $method, $params, $context, $returnEarly) {
            $bereich = \Platform\FoodAlchemist\Support\FaBereiche::fuerKomponente($component);
            $user = \Illuminate\Support\Facades\Auth::user();
            // Spec 82 §13 C3: Vollsperre eines beendeten Mandanten — keine Aktion, auch nicht für Plattform-Admins
            if ($user instanceof \Platform\Core\Models\User && ! \Platform\FoodAlchemist\Support\TeamSperre::komponenteErlaubt($component)
                && \Platform\FoodAlchemist\Support\TeamSperre::istGesperrt($user->currentTeamRelation)) {
                $component->dispatch('fa-saved', message: \Platform\FoodAlchemist\Support\TeamSperre::MELDUNG, type: 'error');
                $returnEarly(null);

                return;
            }
            // Spec 82 §13 G: Plattform-Admin ohne echte Mitgliedschaft — keine Aktion auf Inhalten eines Kunden-Teams
            if ($user instanceof \Platform\Core\Models\User && ! \Platform\FoodAlchemist\Support\InhaltsSperre::komponenteErlaubt($component)
                && \Platform\FoodAlchemist\Support\InhaltsSperre::istGesperrt($user, $user->currentTeamRelation)) {
                $component->dispatch('fa-saved', message: \Platform\FoodAlchemist\Support\InhaltsSperre::MELDUNG, type: 'error');
                $returnEarly(null);

                return;
            }
            // Spec 84 §4.4 (E3): Aktion im Support-Zugang → Protokoll (wer, wann, welche Komponente/Methode)
            // Polls ($refresh …) und der Sperr-Herzschlag sind kein Zugriff — sonst flutet ein offenes Fenster das Protokoll
            if ($user instanceof \Platform\Core\Models\User && ! str_starts_with((string) $method, '$') && $method !== 'sperreHerzschlag'
                && ($sf = \Platform\FoodAlchemist\Support\InhaltsSperre::perSupportZugang($user, $user->currentTeamRelation)) !== null) {
                app(\Platform\FoodAlchemist\Services\SupportFreigabeService::class)->protokolliereZugriff($sf, $user, $user->currentTeamRelation, 'livewire:'.$component::class.'::'.$method);
            }
            if ($bereich !== null && $user instanceof \Platform\Core\Models\User && ($team = $user->currentTeamRelation) !== null
                && ! app(\Platform\FoodAlchemist\Services\FaRechte::class)->darfBereich($user, $team, $bereich)) {
                $component->dispatch('fa-saved', message: 'Dieser Bereich ist für dich bzw. dein Team nicht freigeschaltet.', type: 'error');
                $returnEarly(null);
            }
        });
        // Spec 77d · Inhalt einer freigegebenen Ausgabe geändert → Hüllen der Standorte neu rechnen (nur wenn es Freigaben gibt)
        foreach ([
            \Platform\FoodAlchemist\Models\FoodAlchemistFormatSlot::class, \Platform\FoodAlchemist\Models\FoodAlchemistConceptSlot::class,
            \Platform\FoodAlchemist\Models\FoodAlchemistPaketGericht::class, \Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient::class,
            \Platform\FoodAlchemist\Models\FoodAlchemistFoodbookBlock::class, \Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel::class,
            \Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplanEintrag::class, \Platform\FoodAlchemist\Models\FoodAlchemistSpeisekartePosition::class,
        ] as $inhaltsModel) {
            $inhaltsModel::saved(fn () => app(\Platform\FoodAlchemist\Services\InhaltsFreigabeService::class)->vormerken());
            $inhaltsModel::deleted(fn () => app(\Platform\FoodAlchemist\Services\InhaltsFreigabeService::class)->vormerken());
        }
        // Spec 77b · Seiten je Bereich (Route → Bereich), für alle Web-Routen; Nicht-FA-Routen laufen durch.
        $this->app['router']->pushMiddlewareToGroup('web', \Platform\FoodAlchemist\Http\Middleware\FaBereichMiddleware::class);

        \Livewire\on('call', function ($component, $method, $params, $context, $returnEarly) {
            if (! method_exists($component, 'sperreErlaubt') || $component->sperreErlaubt((string) $method)) {
                return;
            }
            $component->sperreAbgewiesen((string) $method);
            $returnEarly(null);
        });

        // GP-Regelwerk v3.7.1 §5 Ausnahme 3: Marken-Kürzel einmal je Anfrage laden (Karte im Dienst)
        $this->app->scoped(\Platform\FoodAlchemist\Services\Marken\MarkeAusArtikel::class);

        // Spec 79: Google Trends für den Trendradar — produktiv über DataForSEO (Integrations-Modul)
        $this->app->bind(\Platform\FoodAlchemist\Services\Trends\GoogleTrendsQuelle::class, \Platform\FoodAlchemist\Services\Trends\DataForSeoGoogleTrends::class);
        // Web-Recherche zu Lieferantenartikeln (Spec 85 I5-8): Websuche über den Core-Contract (include_web_search, Core #935)
        $this->app->bind(\Platform\FoodAlchemist\Services\Websuche\WebSucheTransport::class, \Platform\FoodAlchemist\Services\Websuche\CoreWebSucheTransport::class);
        // Produktdaten-Quellen fuer den Produktpass-Panel: die Websuche haengt als Quelle websuche im Tag (Knopf Im Web recherchieren)
        $this->app->tag([\Platform\FoodAlchemist\Services\ArtikelWebRecherche::class], 'produktdaten.quellen');

        // M7-10 / D8: STT-Fassade — Binding-Tausch genügt für einen späteren Core-Contract
        // STT-Treiber: 'auto' nimmt den Dienst, für den ein Zugang existiert. Der
        // frühere Default 'fake' hat auf demo jeden gesprochenen Befehl durch den
        // Fixtext ersetzt — fehlerfrei und trotzdem falsch. Ein Fallback auf Testdaten
        // darf nie der Normalfall sein.
        $this->app->bind(\Platform\FoodAlchemist\Services\Stt\SttServiceContract::class, function () {
            $provider = (string) config('foodalchemist.stt.provider', 'auto');
            if ($provider === 'auto') {
                $provider = match (true) {
                    (string) config('services.openai.api_key') !== '' => 'openai',
                    (string) config('foodalchemist.stt.key') !== '' => 'assemblyai',
                    default => 'fake',
                };
            }
            // Spec 53/D: Fake ist ausserhalb von testing/local nur mit dem expliziten Flag erlaubt.
            // Vorher landete eine demo-/Produktions-Instanz ohne jeden Zugang HIER auf Fake und
            // ersetzte jeden Sprachbefehl stumm durch den Fixtext — fehlerfrei und trotzdem falsch.
            if ($provider === 'fake' && ! $this->app->environment(['testing', 'local'])
                && ! config('foodalchemist.stt.allow_fake', false)) {
                $provider = 'none';
            }

            return match ($provider) {
                'openai' => new \Platform\FoodAlchemist\Services\Stt\OpenAiSttService(),
                'assemblyai' => new \Platform\FoodAlchemist\Services\Stt\AssemblyAiSttService(),
                'fake' => new \Platform\FoodAlchemist\Services\Stt\FakeSttService(),
                default => new \Platform\FoodAlchemist\Services\Stt\UnkonfiguriertSttService(),
            };
        });

        // Spec 53 / Paket F (3): TTS-Fassade — gleiches Auto/Fake-Prinzip wie STT oben.
        $this->app->bind(\Platform\FoodAlchemist\Services\Tts\TtsServiceContract::class, function () {
            $provider = (string) config('foodalchemist.tts.provider', 'auto');
            if ($provider === 'auto') {
                $provider = match (true) {
                    (string) config('services.openai.api_key') !== '' => 'openai',
                    default => 'fake',
                };
            }
            if ($provider === 'fake' && ! $this->app->environment(['testing', 'local'])
                && ! config('foodalchemist.tts.allow_fake', false)) {
                $provider = 'none';
            }

            return match ($provider) {
                'openai' => new \Platform\FoodAlchemist\Services\Tts\OpenAiTtsService(),
                'fake' => new \Platform\FoodAlchemist\Services\Tts\FakeTtsService(),
                default => new \Platform\FoodAlchemist\Services\Tts\UnkonfiguriertTtsService(),
            };
        });

        // E1 (#507): Embedding-Observer — halten die GP-/Rezept-Recall-Vektoren bei
        // interaktiven Einzeledits synchron (Bulk = foodalchemist:embed). Unbedingt
        // registriert (nicht table-guarded — der Guard liefe zur Boot-Zeit, bevor
        // Migrationen durch sind). Ungefährlich: die Observer feuern nur auf Model-
        // Events (nie während Migrationen), und queueGp/deleteGp no-oppen ohne Provider.
        \Platform\FoodAlchemist\Models\FoodAlchemistGp::observe(\Platform\FoodAlchemist\Observers\GpEmbeddingObserver::class);
        \Platform\FoodAlchemist\Models\FoodAlchemistGp::observe(\Platform\FoodAlchemist\Observers\GpAromaObserver::class);   // 27.10.2026: Anker + Profil auf allen Anlagewegen
        // Spec 67: neuer/klassifizierter GP → Stellplatz-Vorschlag im Standardlager des Teams
        \Platform\FoodAlchemist\Models\FoodAlchemistGp::observe(\Platform\FoodAlchemist\Observers\GpLagerObserver::class);
        \Platform\FoodAlchemist\Models\FoodAlchemistRecipe::observe(\Platform\FoodAlchemist\Observers\RecipeEmbeddingObserver::class);

        // Spec 15 §5a/§5b: die kleinen Geschwister-Pools (Lieferant/Konzept/Foodbook/Lab-Note).
        \Platform\FoodAlchemist\Models\FoodAlchemistSupplier::observe(\Platform\FoodAlchemist\Observers\SupplierEmbeddingObserver::class);
        \Platform\FoodAlchemist\Models\FoodAlchemistConcept::observe(\Platform\FoodAlchemist\Observers\ConceptEmbeddingObserver::class);
        \Platform\FoodAlchemist\Models\FoodAlchemistFoodbook::observe(\Platform\FoodAlchemist\Observers\FoodbookEmbeddingObserver::class);
        \Platform\FoodAlchemist\Models\FoodAlchemistLabNote::observe(\Platform\FoodAlchemist\Observers\LabNoteEmbeddingObserver::class);

        // RAG-Autoindex A3: Lieferartikel-Einzeledits synchron halten. Der Katalog-Bulk-Import
        // umgeht Eloquent → bleibt der explizite Backfill (foodalchemist:embed --pool=la).
        \Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem::observe(\Platform\FoodAlchemist\Observers\SupplierItemEmbeddingObserver::class);

        // Ausbau (b): Ausgabe-/Container-Pools (Speisekarte/Angebot/Paket/Format) — Einzeledits
        // synchron; Bulk je Pool via foodalchemist:embed --pool=speisekarten|angebote|pakete|formate.
        \Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarte::observe(\Platform\FoodAlchemist\Observers\SpeisekarteEmbeddingObserver::class);
        \Platform\FoodAlchemist\Models\FoodAlchemistAngebot::observe(\Platform\FoodAlchemist\Observers\AngebotEmbeddingObserver::class);
        \Platform\FoodAlchemist\Models\FoodAlchemistPaket::observe(\Platform\FoodAlchemist\Observers\PaketEmbeddingObserver::class);
        \Platform\FoodAlchemist\Models\FoodAlchemistFormat::observe(\Platform\FoodAlchemist\Observers\FormatEmbeddingObserver::class);

        // Embedding-Store-Routing (Runbook 34_Qdrant): FA deklariert selbst, in welchen
        // Store seine Pools gehen — Cores EmbeddingStoreRegistry bleibt entity-agnostisch
        // (der bevorzugte, lose gekoppelte Weg statt zentraler config('embeddings.routing')).
        // INVARIANTE: alle neun foodalchemist_*-Pools teilen sich EINEN Store. candidates()
        // in SemanticRetrievalService übergibt gemischte entity_type-Arrays (z. B. [GP, RECIPE]);
        // search() routet am ersten Typ → nur bei gemeinsamem Store liefern Mixed-Type-Suchen
        // vollständig. Store-Wechsel deshalb immer für ALLE neun gleichzeitig.
        // Kill-Switch/Rollback: FOODALCHEMIST_EMBEDDING_STORE — Default 'mysql' = No-op
        // (Verhalten wie bisher), Flip auf 'qdrant' beim Cutover per ENV (kein Deploy nötig).
        // Geschützt wie die Tool-Registrierung: das Registry-Binding kann auf älteren
        // Core-Versionen / während der Initial-Migration fehlen → nie den Boot brechen.
        if (
            class_exists(\Platform\Core\Services\EmbeddingStoreRegistry::class)
            && $this->app->bound(\Platform\Core\Services\EmbeddingStoreRegistry::class)
        ) {
            try {
                $embeddingStore = (string) config('foodalchemist.embedding_store', 'mysql');
                $embeddingRegistry = $this->app->make(\Platform\Core\Services\EmbeddingStoreRegistry::class);
                foreach ([
                    \Platform\FoodAlchemist\Services\Ai\PoolEmbeddingService::ENTITY_TYPE_GP,
                    \Platform\FoodAlchemist\Services\Ai\PoolEmbeddingService::ENTITY_TYPE_RECIPE,
                    \Platform\FoodAlchemist\Services\Ai\PoolEmbeddingService::ENTITY_TYPE_SUPPLIER,
                    \Platform\FoodAlchemist\Services\Ai\PoolEmbeddingService::ENTITY_TYPE_CONCEPT,
                    \Platform\FoodAlchemist\Services\Ai\PoolEmbeddingService::ENTITY_TYPE_FOODBOOK,
                    \Platform\FoodAlchemist\Services\Ai\PoolEmbeddingService::ENTITY_TYPE_LAB_NOTE,
                    \Platform\FoodAlchemist\Services\Ai\PoolEmbeddingService::ENTITY_TYPE_SUPPLIER_ITEM,
                    \Platform\FoodAlchemist\Services\Ai\KnowledgeEmbeddingService::ENTITY_TYPE,
                    \Platform\FoodAlchemist\Services\Ai\KnowledgeEmbeddingService::ENTITY_TYPE_ANKER,
                ] as $embeddingEntityType) {
                    $embeddingRegistry->route($embeddingEntityType, $embeddingStore);
                }
            } catch (\Throwable $e) {
                // Ohne Registrierung greift Cores Default-Store (config('embeddings.store')).
            }
        }

        // Roadmap Planung-Leitstelle · Et.8 »Worker-Präsenz«: jeder lebende Queue-Worker stempelt einen
        // Herzschlag ({@see \Platform\FoodAlchemist\Services\WorkerHealthService}). `Looping` feuert bei
        // JEDER Worker-Schleife — auch im Leerlauf — und ist damit die ehrliche „ein Worker ist da"-Quelle;
        // `JobProcessing` hält busy-Phasen frisch. Der Stempel ist gedrosselt (10 s) und fail-soft, die
        // Signale sind plattformweit (FA-Jobs teilen die Default-Queue → jeder Worker, der sie leert, zählt).
        \Illuminate\Support\Facades\Event::listen(
            \Illuminate\Queue\Events\Looping::class,
            fn () => $this->app->make(\Platform\FoodAlchemist\Services\WorkerHealthService::class)->heartbeat(),
        );
        \Illuminate\Support\Facades\Event::listen(
            \Illuminate\Queue\Events\JobProcessing::class,
            fn () => $this->app->make(\Platform\FoodAlchemist\Services\WorkerHealthService::class)->heartbeat(),
        );

        // Spec 82 §12 A3: Der Opt-in-Stand (StammPaket::$cache, static) darf Request-/Job-Grenzen nicht überleben —
        // sonst sehen langlebige Prozesse (queue:work, Octane) einen Opt-in, der anderswo geändert wurde, erst nach Neustart.
        // Dasselbe gilt für den Sperr-Merker eines beendeten Mandanten (TeamSperre::$cache, Spec 82 §13 C3): Eine Sperre oder
        // Entsperrung in einem anderen Prozess muss ein Queue-Worker schon beim nächsten Job sehen, nicht erst nach einem Neustart.
        \Illuminate\Support\Facades\Event::listen(
            [\Illuminate\Queue\Events\JobProcessing::class, \Illuminate\Queue\Events\JobProcessed::class, \Illuminate\Queue\Events\JobFailed::class],
            function () {
                \Platform\FoodAlchemist\Support\StammPaket::flush();
                \Platform\FoodAlchemist\Support\TeamSperre::flush();
                app(\Platform\FoodAlchemist\Services\ConceptPreisService::class)->vergessen();   // Listen-Merker der Fassungs-Pfad-Prüfung (PR 5)
            },
        );
        // Spec 84 E-1: langsame Abfragen messen (aus, solange foodalchemist.leistung.langsam_ms null ist)
        \Platform\FoodAlchemist\Support\LangsameAbfragen::registrieren($this->app);
        \Platform\FoodAlchemist\Support\FremdSchreiben::registrieren($this->app);   // Spec 84 B4: Kanal fa-fremdschreiben

        $this->app->terminating(function () {
            \Platform\FoodAlchemist\Support\StammPaket::flush();
            \Platform\FoodAlchemist\Support\TeamSperre::flush();
            app(\Platform\FoodAlchemist\Services\ConceptPreisService::class)->vergessen();
        });

        /**
         * SCHRITT 1: Modul-Registrierung prüfen
         * 
         * Prüft ob:
         * - Config vorhanden ist
         * - modules-Tabelle existiert (für Datenbank-Registrierung)
         * 
         * Nur wenn beide Bedingungen erfüllt, wird das Modul registriert.
         */
        if (
            config()->has('foodalchemist.routing') &&
            config()->has('foodalchemist.navigation') &&
            PlatformCore::modulesTableExists()
        ) {
            /**
             * Modul bei PlatformCore registrieren
             * 
             * Dies registriert das Modul in:
             * - Der Modul-Registry (für Navigation, Sidebar)
             * - Der Datenbank (modules-Tabelle)
             * 
             * Die Config wird automatisch aus config/foodalchemist.php geladen.
             */
            PlatformCore::registerModule([
                'key'        => 'foodalchemist', // Eindeutiger Schlüssel
                'title'      => 'Food Alchemist', // Anzeige-Name
                'routing'    => config('foodalchemist.routing'),
                'guard'      => config('foodalchemist.guard'),
                'navigation' => config('foodalchemist.navigation'),
                'sidebar'    => config('foodalchemist.sidebar'),
            ]);
        }

        /**
         * SCHRITT 2: Routes laden
         * 
         * Routes werden nur geladen, wenn das Modul erfolgreich registriert wurde.
         * 
         * ModuleRouter::group() erstellt automatisch:
         * - Route-Prefix (aus Config)
         * - Middleware (web, auth, etc.)
         * - Domain-Handling (für Subdomain-Modus)
         */
        if (PlatformCore::getModule('foodalchemist')) {
            /**
             * Web-Routes (authentifiziert)
             * 
             * Standard: requireAuth = true
             * Für öffentliche Routes: requireAuth = false
             */
            ModuleRouter::group('foodalchemist', function () {
                $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
            });
            
            /**
             * API-Routes (optional)
             * 
             * Falls dein Modul API-Endpoints hat:
             * 
             * ModuleRouter::apiGroup('foodalchemist', function () {
             *     $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
             * });
             */
        }

        /**
         * SCHRITT 2b: Öffentliche Routes (kein Auth) — Pairing-Netz-Frontend-Bundle
         *
         * Analog platform-core's _platform/assets/{file}: statische JS-Bundles
         * ohne Team-/Auth-Kontext, daher NICHT über ModuleRouter::group (das
         * hängt 'auth' + Tenancy-Middleware an), sondern direkt registriert.
         */
        \Illuminate\Support\Facades\Route::domain(parse_url(config('app.url'), PHP_URL_HOST))
            ->middleware(['web'])
            ->group(__DIR__.'/../routes/public.php');

        // Pairing-Netz-Bundle-Hash fürs Cache-Busting (analog CoreServiceProvider Tiptap/Workshop/Echo).
        $faManifestPath = __DIR__.'/../resources/dist/manifest.json';
        if (file_exists($faManifestPath)) {
            $faManifest = json_decode(file_get_contents($faManifestPath), true) ?? [];
            config(['platform.fa_pairing_netz_hash' => $faManifest['foodalchemist-pairing-netz.iife.js'] ?? '0']);
            // Spec 53 / Paket D: gemeinsamer Voice-Recorder (Sprachbefehl + Diktat), analog zum Pairing-Netz-Bundle.
            config(['platform.fa_voice_recorder_hash' => $faManifest['foodalchemist-voice-recorder.iife.js'] ?? '0']);
        }

        /**
         * SCHRITT 3: Migrationen laden
         * 
         * Lädt alle Migrationen aus database/migrations/
         * Wird automatisch bei `php artisan migrate` ausgeführt.
         */
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        /**
         * SCHRITT 4: Config veröffentlichen
         * 
         * Ermöglicht es, die Config in config/foodalchemist.php zu überschreiben.
         * 
         * Publizieren mit:
         * php artisan vendor:publish --tag=config --provider="Platform\FoodAlchemist\FoodAlchemistServiceProvider"
         * 
         * WICHTIG: mergeConfigFrom funktioniert auch OHNE Publizierung!
         */
        $this->publishes([
            __DIR__.'/../config/foodalchemist.php' => config_path('foodalchemist.php'),
        ], 'config');

        /**
         * SCHRITT 5: Views laden
         * 
         * Registriert Views unter dem Namespace 'foodalchemist'
         * 
         * Verwendung in Views:
         * @return view('foodalchemist::livewire.dashboard')
         */
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'foodalchemist');

        // fa-pass Welle 0: Bausteinbibliothek <x-fa::…> (resources/views/components/fa).
        // EINE Quelle für Knöpfe, Felder, Chips, Status, Kennzahlen, Allergene … — Doku: /foodalchemist/_ui
        \Illuminate\Support\Facades\Blade::anonymousComponentPath(__DIR__ . '/../resources/views/components/fa', 'fa');
        
        /**
         * SCHRITT 6: Livewire Components registrieren
         * 
         * Registriert alle Livewire-Komponenten automatisch.
         * 
         * Pattern:
         * - Datei: src/Livewire/Dashboard.php
         * - Alias: foodalchemist.dashboard
         * 
         * Verwendung:
         * <livewire:foodalchemist.dashboard />
         */
        $this->registerLivewireComponents();

        // M8-02: generische Modul-Policy für die Kern-Models (view = Team-Kette,
        // update/delete = Curate-Gate M1-08 — dieselben Regel-Stellen wie die Services)
        foreach ([
            \Platform\FoodAlchemist\Models\FoodAlchemistGp::class,
            \Platform\FoodAlchemist\Models\FoodAlchemistRecipe::class,
            \Platform\FoodAlchemist\Models\FoodAlchemistSupplier::class,
            \Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem::class,
            \Platform\FoodAlchemist\Models\FoodAlchemistMarkupClass::class,
            \Platform\FoodAlchemist\Models\FoodAlchemistDishClass::class,
            \Platform\FoodAlchemist\Models\FoodAlchemistRecipeCustomerName::class,
            \Platform\FoodAlchemist\Models\FoodAlchemistRecipeRegeneration::class,
            \Platform\FoodAlchemist\Models\FoodAlchemistFoodbook::class,                 // M11-10
            \Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel::class,
            \Platform\FoodAlchemist\Models\FoodAlchemistFoodbookBlock::class,
        ] as $modelClass) {
            \Illuminate\Support\Facades\Gate::policy($modelClass, \Platform\FoodAlchemist\Policies\FoodAlchemistPolicy::class);
        }

        // M8-01: Modul-Tools (ToolContract) — idempotent in die Core-Registry;
        // afterResolving, damit die Registrierung auch greift, wenn der MCP-
        // Server die Registry erst später aufbaut (Auto-Discovery-Pfad variiert)
        if (class_exists(\Platform\Core\Tools\ToolRegistry::class)) {
            $toolHook = fn ($registry) => \Platform\FoodAlchemist\Support\ToolListe::registriere($registry);
            // Singleton ggf. schon resolved (Core-Boot) → sofort; sonst beim ersten make
            if ($this->app->resolved(\Platform\Core\Tools\ToolRegistry::class)) {
                $toolHook($this->app->make(\Platform\Core\Tools\ToolRegistry::class));
            } else {
                $this->app->afterResolving(\Platform\Core\Tools\ToolRegistry::class, $toolHook);
            }
        }
        
        /**
         * SCHRITT 7: Tools registrieren (optional)
         * 
         * Falls dein Modul AI/Chat-Tools hat:
         * 
         * $this->registerTools();
         */
    }

    /**
     * Registriert alle Livewire-Komponenten automatisch
     * 
     * Scant das src/Livewire/ Verzeichnis rekursiv und registriert
     * alle PHP-Dateien als Livewire-Komponenten.
     * 
     * NAMING CONVENTION:
     * - Datei: src/Livewire/Dashboard.php
     * - Namespace: Platform\FoodAlchemist\Livewire\Dashboard
     * - Alias: foodalchemist.dashboard
     * 
     * - Datei: src/Livewire/Entity/Index.php
     * - Namespace: Platform\FoodAlchemist\Livewire\Entity\Index
     * - Alias: foodalchemist.entity.index
     * 
     * @return void
     */
    protected function registerLivewireComponents(): void
    {
        $basePath = __DIR__ . '/Livewire';
        $baseNamespace = 'Platform\\FoodAlchemist\\Livewire';
        $prefix = 'foodalchemist';

        // Kritische eingebettete Komponenten explizit registrieren: diese Tags werden in
        // Blade direkt gerendert und dürfen nicht von Auto-Discovery/Cache-Zustand abhängen.
        Livewire::component('foodalchemist.orders.editor', \Platform\FoodAlchemist\Livewire\Orders\Editor::class);

        // Spec 84 E-4: Alias → Klasse aus dem Scan-Cache (mit Rückfall auf den Live-Scan, kein Cache-Befehl nötig).
        // Spart je Request das Autoloaden aller Livewire-Klassen (class_exists im Scan).
        $map = \Platform\FoodAlchemist\Support\ScanCache::livewire(fn () => $this->livewireLiveScan($basePath, $baseNamespace, $prefix));
        foreach ($map as $alias => $class) {
            Livewire::component($alias, $class);
        }
    }

    /** @return array<string, class-string> Alias → Klasse (bisheriger Scan) */
    private function livewireLiveScan(string $basePath, string $baseNamespace, string $prefix): array
    {
        $map = [];
        if (! is_dir($basePath)) {
            return $map;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($basePath)
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relativePath = str_replace($basePath . DIRECTORY_SEPARATOR, '', $file->getPathname());
            $classPath = str_replace(['/', '.php'], ['\\', ''], $relativePath);
            $class = $baseNamespace . '\\' . $classPath;

            if (! class_exists($class)) {
                continue;
            }

            // Alias generieren (z.B. Settings/Einheiten -> settings.einheiten).
            // WICHTIG: jedes Pfad-Segment einzeln kebab-en — Str::kebab über den ganzen
            // Pfad macht aus "Settings/Einheiten" sonst "settings/-einheiten" (M1-01-Fund).
            $aliasPath = collect(explode(DIRECTORY_SEPARATOR, str_replace('.php', '', $relativePath)))
                ->map(fn (string $segment) => Str::kebab($segment))
                ->implode('.');
            $map[$prefix . '.' . $aliasPath] = $class;
        }
        ksort($map);

        return $map;
    }
}
