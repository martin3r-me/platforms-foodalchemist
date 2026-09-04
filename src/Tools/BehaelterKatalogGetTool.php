<?php

namespace Platform\FoodAlchemist\Tools;

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\BehaelterRechner;
use Platform\FoodAlchemist\Support\TeamScope;

/**
 * Spec 51 · MCP: der Behälter-Katalog, wie ihn die Bemessung sieht.
 *
 * Zeigt nicht nur Namen, sondern was gerechnet werden kann: Maße, Nennvolumen, Nutzvolumen,
 * Handhabungs-Deckel und die Freigaben je Zweck. Zeilen ohne Maße und ohne Volumen sind für die
 * Bemessung blind — das steht als `bemessbar: false` dran, statt still zu fehlen.
 */
class BehaelterKatalogGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.behaelter_katalog.GET';
    }

    public function getDescription(): string
    {
        return 'Listet die sichtbaren Behälter mit Familie, Maßen, Nennvolumen, Nutzvolumen, '
            . 'Handhabungs-Deckel und Zweck-Freigaben. Optional gefiltert auf einen Zweck oder eine Familie.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'zweck' => ['type' => 'string', 'description' => 'Nur für diesen Zweck freigegebene (eignung NULL = nicht gepflegt, gilt als frei).'],
                'familie' => ['type' => 'string', 'description' => 'z. B. GN, Eimer, Traeger.'],
                'nur_traeger' => ['type' => 'boolean', 'description' => 'Nur Träger (nehmen Füllbehälter auf).'],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        $zeilen = TeamScope::applyVisible(
            DB::table('foodalchemist_vocab_containers')->whereNull('deleted_at'), 'team_id', $team
        )->orderBy('familie')->orderBy('sort_order')->orderBy('name')->get();

        $zweck = $arguments['zweck'] ?? null;
        $familie = $arguments['familie'] ?? null;
        $nurTraeger = (bool) ($arguments['nur_traeger'] ?? false);

        $raus = [];
        $blind = 0;

        foreach ($zeilen as $z) {
            if ($familie !== null && $z->familie !== $familie) {
                continue;
            }
            if ($nurTraeger && ! $z->ist_traeger) {
                continue;
            }
            $eignung = $z->eignung !== null ? (json_decode((string) $z->eignung, true) ?: []) : null;
            if ($zweck !== null && $eignung !== null && ! in_array($zweck, $eignung, true)) {
                continue;
            }

            $nutz = BehaelterRechner::nutzvolumenL($z);
            // Ein Träger wird über STECKPLÄTZE bemessen, nicht über Liter — er wird nie befüllt.
            // Ihn unter »ohne Bemessungsgrundlage« zu zählen wäre eine Falschmeldung.
            $fuellbar = ! $z->ist_traeger;
            if ($fuellbar && $nutz === null && $z->kapazitaet_kg === null) {
                $blind++;
            }

            $raus[] = [
                'id' => (int) $z->id,
                'name' => $z->name,
                'familie' => $z->familie,
                'format' => $z->format_code,
                'masse_mm' => $z->laenge_mm !== null ? "{$z->laenge_mm}×{$z->breite_mm}×{$z->tiefe_mm}" : null,
                'volumen_l' => $z->volumen_l !== null ? (float) $z->volumen_l : null,
                'nutzvolumen_l' => $nutz,
                'max_fuellgewicht_kg' => $z->max_fuellgewicht_kg !== null ? (float) $z->max_fuellgewicht_kg : null,
                'eignung' => $eignung,                       // null = nicht gepflegt, keine Einschränkung
                'ist_traeger' => (bool) $z->ist_traeger,
                'traeger_plaetze' => $z->traeger_plaetze,
                'is_inactive' => (bool) $z->is_inactive,
                'bemessbar' => $z->ist_traeger ? $z->traeger_plaetze !== null : ($nutz !== null || $z->kapazitaet_kg !== null),
                'grund' => $this->grund($z, $nutz),
            ];
        }

        return ToolResult::success([
            'anzahl' => count($raus),
            'ohne_bemessungsgrundlage' => $blind,
            'behaelter' => $raus,
            'hinweis' => $blind > 0
                ? "{$blind} Zeile(n) haben weder Maße noch Volumen — für die kann kein Bedarf gerechnet werden."
                : null,
        ]);
    }

    /**
     * Warum eine Zeile nicht bemessbar ist — ohne das steht dort nur `false` und niemand weiss,
     * welches Feld fehlt.
     */
    private function grund(object $z, ?float $nutz): ?string
    {
        if ($z->ist_traeger) {
            return $z->traeger_plaetze === null
                ? 'Träger ohne Steckplätze — die Zahl hängt an Innenhöhe und Behältertiefe und muss gepflegt werden.'
                : null;
        }
        if ($nutz !== null || $z->kapazitaet_kg !== null) {
            return null;
        }
        if ($z->laenge_mm !== null) {
            // Der haeufigste Fall im Bestand: 20-mm-Einlegeschalen, fuer die der Handel gar kein
            // Litermass veroeffentlicht. Aus den Kantenlaengen laesst es sich nicht ableiten —
            // GN-Behaelter sind konisch, die Kantenrechnung liegt rund ein Fuenftel daneben.
            return 'Maße vorhanden, aber kein Nennvolumen — bei konischen Behältern nicht ableitbar. Liter eintragen.';
        }

        return 'Weder Maße noch Nennvolumen hinterlegt.';
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'behaelter', 'stammdaten', 'read'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'read',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => [],
            'related_tools' => ['foodalchemist.recipe_container.PUT', 'foodalchemist.behaelter_bedarf.GET'],
            'examples' => ['Welche GN-Behälter sind fürs Regenerieren freigegeben?'],
        ];
    }
}
