<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\Naehrwerte\NaehrwertEntscheidService;

/** Einen Artikel mit Nährwert-Befund freigeben oder korrigieren — schließt das Signal „Nährwerte am Artikel prüfen“. */
class NaehrwertPruefungEntscheidenTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.naehrwert_pruefung.ENTSCHEIDEN';
    }

    public function getDescription(): string
    {
        return 'Entscheidet über einen Lieferantenartikel mit hartem Nährwert-Befund (la_id aus dem Signal „Nährwerte am Artikel prüfen“ bzw. naehrwert_pruefung.GET). '
            .'aktion=freigeben: die Werte stimmen (Etikett/Produktpass geprüft) → der Artikel zählt wieder in den GP-Nährwerten, solange sich seine Werte nicht ändern; grund optional. '
            .'aktion=korrigieren: werte={energy_kcal, protein, fat, saturated_fat, carbs_absorbable, sugar, sodium (mg/100 g), …} je 100 g; fehlende Felder bleiben, '
            .'danach wird neu geprüft. Beides schließt das Signal, wenn der Artikel nicht mehr auffällig ist. Nur das Besitzer-Team des Artikels (globale: Master-Team), Rolle Kuratieren.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'la_id' => ['type' => 'integer'],
            'aktion' => ['type' => 'string', 'enum' => ['freigeben', 'korrigieren']],
            'werte' => ['type' => 'object', 'description' => 'nur bei korrigieren: Nährwerte je 100 g, Natrium in mg'],
            'grund' => ['type' => 'string', 'description' => 'nur bei freigeben: warum die Werte stimmen (optional)'],
        ], 'required' => ['la_id', 'aktion']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $aktion = (string) ($arguments['aktion'] ?? '');
        $laId = (int) ($arguments['la_id'] ?? 0);
        if ($laId <= 0 || ! in_array($aktion, ['freigeben', 'korrigieren'], true)) {
            return ToolResult::error('la_id und aktion (freigeben | korrigieren) sind Pflicht.', 'VALIDATION_ERROR');
        }
        if ($aktion === 'korrigieren' && (! is_array($arguments['werte'] ?? null) || $arguments['werte'] === [])) {
            return ToolResult::error('korrigieren braucht werte={feld: zahl, …}.', 'VALIDATION_ERROR');
        }

        return $this->ausfuehren($context, function (Team $team) use ($arguments, $aktion, $laId, $context) {
            $svc = app(NaehrwertEntscheidService::class);

            return $aktion === 'freigeben'
                ? $svc->freigeben($team, $context->user, $laId, isset($arguments['grund']) ? (string) $arguments['grund'] : null)
                : $svc->korrigieren($team, $context->user, $laId, $arguments['werte']);
        });
    }

    public function getMetadata(): array
    {
        return array_merge($this->metadaten(true, ['naehrwerte', 'pruefung'], ['Gib die Nährwerte von Artikel 4711 frei, das Etikett stimmt.'], ['foodalchemist.naehrwert_pruefung.GET', 'foodalchemist.signale.LIST']), [
            'tags' => ['foodalchemist', 'naehrwerte', 'pruefung', 'write'],
        ]);
    }
}
