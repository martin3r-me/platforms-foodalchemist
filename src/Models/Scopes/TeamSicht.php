<?php

namespace Platform\FoodAlchemist\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Log;
use Platform\FoodAlchemist\Exceptions\TeamKontextFehltException;
use Platform\FoodAlchemist\Support\FaTeam;

/**
 * Spec 84 B3 · Automatischer Sichtfilter: jede Eloquent-Abfrage eines Modells der aktiven Stufe liest nur, was das betrachtende
 * Team ({@see FaTeam::aktuell()}) sehen darf — dieselbe Regel wie `visibleToTeam()` (Kette, 77d-Hülle, Abo-Zweig, Fassungen).
 *
 * - Stufen hinter einem Schalter (`foodalchemist.team_sicht.stufe`, Standard 0 = aus). Stufe 1 = Abo-Typen (Wurzel-Modelle).
 *   Kind-Tabellen bekommen den Scope nie (B2-Befund: Kinder einer Abo-Fassung tragen die Master-team_id und werden über den
 *   geprüften Eltern-Datensatz erreicht).
 * - Ein ausdrückliches `visibleToTeam($team)` ersetzt den Scope (entfernt ihn), statt ihn doppelt anzuhängen.
 * - `mitFassungen()` + Scope: Kette einschließlich eigener Fassungen, dazu der Abo-Zweig.
 * - Einzige Umgehung: `->ohneTeamSicht('grund')` (grep-bar).
 * - Ohne Team-Kontext: an einem FA-Eingang mit angemeldetem Nutzer (Seite, MCP-Tool) {@see TeamKontextFehltException} + Log —
 *   eine stille Leerliste sähe für KI-Tools wie „keine Daten“ aus. Notschalter `ohne_kontext = leer`. Konsole, Queue und
 *   Abfragen außerhalb eines FA-Eingangs bleiben offen, werden aber (einmal je Modell und Prozess) geloggt.
 * - Keine Rekursion: die Sicht-Regel selbst fragt nur über `DB::table` ab (Hülle, Abo, Team-Kette über Core).
 */
final class TeamSicht implements Scope
{
    /** @var array<int, list<class-string>> Stufe → Modelle. Reihenfolge Spec 84 §4.2 B3. */
    public const STUFEN = [
        1 => [
            \Platform\FoodAlchemist\Models\FoodAlchemistRecipe::class,
            \Platform\FoodAlchemist\Models\FoodAlchemistPaket::class,
            \Platform\FoodAlchemist\Models\FoodAlchemistConcept::class,
            \Platform\FoodAlchemist\Models\FoodAlchemistFormat::class,
        ],
        // Spec 84 B3 Stufe 2: Ausgaben (Foodbook, Speiseplan, Speisekarte, Angebot)
        2 => [
            \Platform\FoodAlchemist\Models\FoodAlchemistFoodbook::class,
            \Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplan::class,
            \Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarte::class,
            \Platform\FoodAlchemist\Models\FoodAlchemistAngebot::class,   // Kurator 17.10.: Angebote sind Ausgaben (77d-Hülle seit E8)
        ],
        // Spec 84 B3 Stufe 3: Stamm (GP, Lieferantenartikel, Lieferant) — mit Stamm-Paket-Opt-in (StammPaket::globaleZeilen in sichtBedingung)
        3 => [
            \Platform\FoodAlchemist\Models\FoodAlchemistGp::class,
            \Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem::class,
            \Platform\FoodAlchemist\Models\FoodAlchemistSupplier::class,
        ],
        // Spec 84 B3 Stufe 4: Einstellungen und Vokabular (Wurzel-Modelle; Kind-Tabellen nie)
        4 => [
            \Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit::class,
            \Platform\FoodAlchemist\Models\FoodAlchemistVocabKlasse::class,
            \Platform\FoodAlchemist\Models\FoodAlchemistVocabContainer::class,
            \Platform\FoodAlchemist\Models\FoodAlchemistVocabKochequipment::class,
            \Platform\FoodAlchemist\Models\FoodAlchemistVocabRegenerationDevice::class,
            \Platform\FoodAlchemist\Models\FoodAlchemistVocabRolle::class,
            \Platform\FoodAlchemist\Models\FoodAlchemistRecipeCategory::class,
            \Platform\FoodAlchemist\Models\FoodAlchemistRecipeMainGroup::class,
            \Platform\FoodAlchemist\Models\FoodAlchemistDishMainGroup::class,
            \Platform\FoodAlchemist\Models\FoodAlchemistConceptCategory::class,
            \Platform\FoodAlchemist\Models\FoodAlchemistLookupWarengruppe::class,
            \Platform\FoodAlchemist\Models\FoodAlchemistServierform::class,
            \Platform\FoodAlchemist\Models\FoodAlchemistEventtyp::class,
            \Platform\FoodAlchemist\Models\FoodAlchemistEinsatzmoment::class,
            \Platform\FoodAlchemist\Models\FoodAlchemistSaison::class,
            \Platform\FoodAlchemist\Models\FoodAlchemistTargetGroup::class,
            \Platform\FoodAlchemist\Models\FoodAlchemistTeamSetting::class,
            \Platform\FoodAlchemist\Models\FoodAlchemistOutlet::class,
            \Platform\FoodAlchemist\Models\FoodAlchemistOutletSetting::class,
            \Platform\FoodAlchemist\Models\FoodAlchemistMarkupClass::class,
            \Platform\FoodAlchemist\Models\FoodAlchemistProductionStation::class,
            \Platform\FoodAlchemist\Models\FoodAlchemistRule::class,
            \Platform\FoodAlchemist\Models\FoodAlchemistBriefTemplate::class,
            \Platform\FoodAlchemist\Models\FoodAlchemistCanvasTemplate::class,
            \Platform\FoodAlchemist\Models\FoodAlchemistLabelTemplate::class,
        ],
    ];

    /** Aktueller FA-Eingang (`seite`, `mcp`) mit angemeldetem Nutzer — nur dort ist fehlender Kontext ein Fehler. */
    private static ?string $eingang = null;

    /** @var array<string, true> geloggte offene Fälle je Modell (einmal je Prozess) */
    private static array $offenGeloggt = [];

    public function apply(Builder $builder, Model $model): void
    {
        if (! self::gilt($model)) {
            return;
        }
        $team = FaTeam::aktuell();
        if ($team === null) {
            self::ohneKontext($builder, $model);

            return;
        }
        $mitFassungen = in_array(OhneFassungenScope::class, $builder->removedScopes(), true);
        $model::sichtBedingung($builder, $team, $mitFassungen);
    }

    /** Konfigurierte Stufe (0 = aus). */
    public static function stufe(): int
    {
        return (int) config('foodalchemist.team_sicht.stufe', 0);
    }

    /** Gilt der Scope für dieses Modell (Schalter + Stufe)? */
    public static function gilt(Model|string $model): bool
    {
        $stufe = self::stufe();
        if ($stufe < 1) {
            return false;
        }
        $klasse = is_string($model) ? $model : $model::class;
        foreach (self::STUFEN as $nr => $modelle) {
            if ($nr <= $stufe && in_array($klasse, $modelle, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Wird der Scope auf DIESER Abfrage greifen? Für {@see OhneFassungenScope}: Greift TeamSicht, regelt sie die Fassungen selbst
     * (normale Sicht ohne, Abo-Zweig nur Fassungen) — ein zusätzliches `ist_fassung = 0` auf oberster Ebene schnitte den Abo-Zweig ab.
     */
    public static function greiftAuf(Builder $builder, Model $model): bool
    {
        return self::gilt($model)
            && ! in_array(self::class, $builder->removedScopes(), true)
            && FaTeam::aktuell() !== null;
    }

    /** Abo-Zweig als Menge (UNION ALL, materialisiert) statt OR — nur mit eingeschaltetem B3 (Messung Spec 84 §5a E-3). */
    public static function aboAlsMenge(): bool
    {
        return self::stufe() >= 1;
    }

    /**
     * $fn als FA-Eingang ausführen (Seite bzw. MCP-Tool). Nur mit angemeldetem Nutzer streng; danach voriger Stand.
     *
     * @template T
     * @param  callable(): T  $fn
     * @return T
     */
    public static function imEingang(string $eingang, bool $mitNutzer, callable $fn): mixed
    {
        $vorher = self::$eingang;
        self::$eingang = $mitNutzer ? $eingang : null;
        try {
            return $fn();
        } finally {
            self::$eingang = $vorher;
        }
    }

    /**
     * Spec 84 B3 C · Service mit ausdrücklichem Team liest in dessen Sicht — aber NUR mit eingeschaltetem Filter. `FaTeam::imTeam`
     * setzt mehr als die Sicht (Team versandter Jobs, KI-Abrechnung, Messung); bei Stufe 0 bleibt darum alles wie bisher.
     * Verleiht kein Recht: die Services prüfen am übergebenen Team + angemeldeten Nutzer, nie am Kontext.
     *
     * @template T
     * @param  callable(): T  $fn
     * @return T
     */
    public static function lesenIm(\Platform\Core\Models\Team|int $team, callable $fn): mixed
    {
        return self::stufe() >= 1 ? FaTeam::imTeam($team, $fn) : $fn();
    }

    /** Aktueller FA-Eingang (`seite`, `mcp`) oder null — für Protokolle (Spec 84 B4). */
    public static function eingang(): ?string
    {
        return self::$eingang;
    }

    /** Prozess-Merker leeren (Tests): Eingang und „schon geloggt“ je Modell. */
    public static function zuruecksetzen(): void
    {
        self::$eingang = null;
        self::$offenGeloggt = [];
    }

    private static function ohneKontext(Builder $builder, Model $model): void
    {
        $klasse = $model::class;
        if (self::$eingang !== null) {
            Log::warning('Spec 84 B3: Abfrage ohne Team-Kontext an einem FA-Eingang', ['modell' => $klasse, 'eingang' => self::$eingang]);
            if (config('foodalchemist.team_sicht.ohne_kontext', 'werfen') === 'leer') {
                $builder->whereRaw('1 = 0');

                return;
            }
            throw new TeamKontextFehltException(class_basename($klasse));
        }
        if (! isset(self::$offenGeloggt[$klasse])) {
            self::$offenGeloggt[$klasse] = true;
            Log::info('Spec 84 B3: Abfrage ohne Team-Kontext, offen (Konsole/Queue/außerhalb eines FA-Eingangs)', [
                'modell' => $klasse, 'konsole' => app()->runningInConsole(),
            ]);
        }
    }
}
