<?php

namespace Platform\FoodAlchemist\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Support\StammPaket;
use Platform\FoodAlchemist\Support\TeamAncestryRegistry;

/**
 * D1 (revidiert 2026-06-11): Eltern→Kinder-Katalog-Vererbung statt NULL-Global.
 *
 * Modell wie beim echten Caterer/Gastronomen: das Eltern-Team pflegt den Katalog
 * (GPs, LAs, Preise, Basisrezept-Vorlagen), Kind-Teams erben ihn lesend über die
 * Eltern-Kette und legen eigene Datensätze in ihrem Team an.
 *
 * - Sichtbar  = team_id ∈ {eigenes Team, alle Eltern bis Root}  → scopeVisibleToTeam()
 * - Editierbar = team_id == aktuelles Team                       → isOwnedBy()
 *
 * Einzel-Gastronom (1 Team, keine Kinder) und Konzern (Root + n Betriebe)
 * funktionieren damit ohne Sonderfall. Nutzt Cores teams.parent_team_id.
 */
trait BelongsToTeamHierarchy
{
    /**
     * Ancestry-Cache, je Request (HTTP) bzw. je Prozess (Tests/CLI) — pro Model-Klasse.
     *
     * @var array<int, array<int>>
     */
    protected static array $teamAncestryCache = [];

    public function scopeVisibleToTeam(Builder $query, Team $team): Builder
    {
        // Spec 84 B3: die ausdrückliche Sicht ersetzt den automatischen Filter (kein zweites Mal dieselbe Bedingung)
        $query->withoutGlobalScope(\Platform\FoodAlchemist\Models\Scopes\TeamSicht::class);

        return static::sichtBedingung($query, $team);
    }

    /**
     * Spec 84 B3 · Einzige Umgehung des automatischen Sichtfilters. Der Grund steht im Aufruf (grep-bar), z. B.
     * `eindeutigkeit`, `verwendung`, `unterteam-verwaltung`, `verweis`, `master-kuration`.
     */
    public function scopeOhneTeamSicht(Builder $query, string $grund): Builder
    {
        return $query->withoutGlobalScope(\Platform\FoodAlchemist\Models\Scopes\TeamSicht::class);
    }

    /**
     * Die Sicht-Regel selbst — gemeinsam für `visibleToTeam()` und den Global Scope {@see \Platform\FoodAlchemist\Models\Scopes\TeamSicht}.
     * `$mitFassungen`: nur aus TeamSicht nach `mitFassungen()` — dann zählt die eigene Kette einschließlich Fassungen.
     */
    public static function sichtBedingung(Builder $query, Team $team, bool $mitFassungen = false): Builder
    {
        $col = $query->getModel()->getTable() . '.team_id';

        // Sichtbar = globaler Seed (team_id NULL) ODER eigenes Team + Master-Kette (Ancestry bis
        // BHG.DIGITAL). Editierbar bleibt allein das eigene Team (isOwnedBy) — Master/Seed sind
        // für Kind-Teams read-only. In Klammer gruppiert, damit nachfolgende where() nicht am OR hängen.
        // Spec 77d: Inhalts-Typen (Rezept, Konzept, Format, Paket, Ausgaben) eines Unter-Teams ohne
        // „übernimmt alles" sehen von oben nur ihre Freigabe-Hülle — Stammdaten bleiben voll geerbt.
        if (($typ = \Platform\FoodAlchemist\Support\InhaltsFreigabe::typFuerModel(static::class)) !== null) {
            $tabelle = $query->getModel()->getTable();
            // Spec 82: die Fassungs-Regel trifft InhaltsFreigabe selbst (normale Sicht ohne, Abo-Zweig nur Fassungen)
            $query->withoutGlobalScope(\Platform\FoodAlchemist\Models\Scopes\OhneFassungenScope::class);

            return $query->where(fn (Builder $q) => \Platform\FoodAlchemist\Support\InhaltsFreigabe::anwenden(
                $q, $col, $tabelle . '.id', $typ, $team, self::teamAncestryIds($team), $tabelle, $mitFassungen));
        }

        // Spec 82 §12 A3: Modelle mit STAMM_KLASSE trennen globale Zeilen in basis | stamm_paket —
        // stamm_paket sieht nur, wer das Opt-in hat (oder der Master). Alle anderen: unverändert.
        if (defined(static::class . '::STAMM_KLASSE')) {
            $tabelle = $query->getModel()->getTable();

            return $query->where(function (Builder $q) use ($col, $team, $tabelle) {
                $q->where(fn (Builder $g) => StammPaket::globaleZeilen($g, $col, $tabelle . '.stamm_class', $team, $tabelle))
                    ->orWhereIn($col, self::teamAncestryIds($team));
            });
        }

        return $query->where(function (Builder $q) use ($col, $team) {
            $q->whereNull($col)->orWhereIn($col, self::teamAncestryIds($team));
        });
    }

    /**
     * Spec 82 §12 A3: Klasse ist basis | stamm_paket, und stamm_paket gibt es nur bei globalen Zeilen.
     */
    public static function bootBelongsToTeamHierarchy(): void
    {
        // Spec 84 B3: Wurzel-Modelle der Stufen bekommen den Sichtfilter (greift erst mit Schalter, siehe TeamSicht::gilt)
        foreach (\Platform\FoodAlchemist\Models\Scopes\TeamSicht::STUFEN as $modelle) {
            if (in_array(static::class, $modelle, true)) {
                static::addGlobalScope(new \Platform\FoodAlchemist\Models\Scopes\TeamSicht);
            }
        }
        if (! defined(static::class . '::STAMM_KLASSE')) {
            return;
        }
        static::saving(function ($model) {
            $klasse = $model->stamm_class ?? StammPaket::BASIS;
            if (! in_array($klasse, StammPaket::KLASSEN, true)) {
                throw new \InvalidArgumentException("stamm_class '{$klasse}' ungueltig (basis|stamm_paket).");
            }
            if ($klasse === StammPaket::PAKET && $model->team_id !== null) {
                throw new \InvalidArgumentException('stamm_paket gibt es nur bei globalen Zeilen (team_id NULL).');
            }
        });
    }

    public function isOwnedBy(Team $team): bool
    {
        if ((int) $this->team_id === (int) $team->id) {
            return true;
        }

        if ($this->team_id !== null || ! \Platform\FoodAlchemist\Support\MasterTeam::istMaster($team)) {
            return false;
        }

        // Spec 82 §13 I: Basis-Kataloge — globale Zeilen (team_id NULL) pflegt der Master (streng, kein „elternlos"-Rückfall).
        if (static::istBasisKatalog()) {
            return true;
        }

        // Spec 82 (Stamm global): globale STAMM-Zeilen (GP, Lieferant, Lieferantenartikel mit stamm_class = stamm_paket) pflegt ebenfalls der Master.
        // Basis-Zeilen der Stamm-Modelle bleiben wie bisher nur lesbar; Mandanten (auch mit Paket) lesen nur. EINE Stelle für Dienste, Livewire und MCP.
        return defined(static::class . '::STAMM_KLASSE') && ($this->stamm_class ?? StammPaket::BASIS) === StammPaket::PAKET;
    }

    /** Modelle der Basis-Kataloge überschreiben das mit `true` (Master pflegt globale Zeilen); Standard: nein. */
    public static function istBasisKatalog(): bool
    {
        return false;
    }

    /**
     * Team-Kette aufwärts (eigenes Team zuerst, Root zuletzt), gecacht.
     *
     * @return array<int>
     */
    public static function teamAncestryIds(Team $team): array
    {
        if (!isset(static::$teamAncestryCache[$team->id])) {
            // V-048: die Klasse trägt sich ein, sobald sie etwas zu leeren hat — damit ist
            // TeamAncestryRegistry::flushAll() vollständig, ohne dass jemand eine Liste pflegt.
            TeamAncestryRegistry::register(static::class);

            $ids = [];
            $current = $team;
            $guard = 0;
            while ($current && $guard < 32) { // Zyklen-Schutz
                $ids[] = (int) $current->id;
                $current = $current->parent_team_id ? $current->parentTeam : null;
                $guard++;
            }
            static::$teamAncestryCache[$team->id] = $ids;
        }

        return static::$teamAncestryCache[$team->id];
    }

    /**
     * Cache leeren — Pflicht in Test-Setups, die Teams neu seeden (M0-06-Harness):
     * im selben Prozess wiederverwendete Team-IDs würden sonst stale Ketten liefern.
     *
     * Klassenweise. Für „alle" gibt es {@see TeamAncestryRegistry::flushAll()} (V-048) —
     * die Modul-`TestCase::setUp` ruft das unbedingt, ein Test muss hier nichts aufzählen.
     */
    public static function flushTeamAncestryCache(): void
    {
        static::$teamAncestryCache = [];
    }
}
