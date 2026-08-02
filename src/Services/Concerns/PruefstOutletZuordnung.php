<?php

namespace Platform\FoodAlchemist\Services\Concerns;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;

/**
 * Spec 33 · P2 — Guard für die Betriebs-Zuordnung der drei Ausgabeformen.
 *
 * **Warum das ein eigener Guard sein muss.** `outlet_id` ist keine ID auf dem eigenen
 * Datensatz, sondern ein Zeiger auf ein Vokabular, das einem Team gehört. Die `FELDER`-Liste
 * eines Service prüft, *ob* ein Feld gesetzt werden darf — nicht, *worauf* es zeigt. Der
 * Team-Guard am Datensatz greift ebenfalls nicht: geschrieben wird ja die eigene Karte, nur
 * eben mit fremdem Ziel.
 *
 * Ohne diese Prüfung konnte eine untergeschobene `outlet_id` (Dropdown-Wert aus dem Browser,
 * MCP-Argument) die eigene Ausgabe in die Konzern-Sicht eines fremden Betriebs hängen. Ein Test
 * hat es nachgewiesen, bevor der Guard existierte — siehe `PortfolioTenantTest`.
 *
 * Strikt team-eigen, nicht `visibleToTeam`: Betriebe gehören dem Team, das sie pflegt
 * (dieselbe Regel wie in der Betriebe-Verwaltung und in `PortfolioService::luecken`).
 *
 * Verhalten bei einer fremden id: **Feld fällt raus**, kein Fehler. Es wird also nichts
 * geschrieben, statt eine bestehende gültige Zuordnung stillschweigend zu löschen. Ein
 * ausdrückliches Leeren (`null` oder `''`) bleibt möglich — das ist kein Fremdzugriff.
 */
trait PruefstOutletZuordnung
{
    /**
     * @param  array<string,mixed>  $in
     * @return array<string,mixed>
     */
    protected function pruefeOutlet(Team $team, array $in): array
    {
        if (! array_key_exists('outlet_id', $in)) {
            return $in;
        }

        $roh = $in['outlet_id'];
        if ($roh === null || $roh === '' || $roh === 0 || $roh === '0') {
            $in['outlet_id'] = null;   // ausdrückliches Leeren bleibt erlaubt

            return $in;
        }

        $gehoert = FoodAlchemistOutlet::where('team_id', $team->id)->whereKey((int) $roh)->exists();
        if (! $gehoert) {
            unset($in['outlet_id']);

            return $in;
        }

        $in['outlet_id'] = (int) $roh;

        return $in;
    }
}
