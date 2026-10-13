<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\MandantBeendenService;

/**
 * Spec 82 §13 C3 · „Mandant beenden“ AUSFÜHREN, ABBRECHEN, ENTSPERREN. Nur Plattform-Admin. Ausführen braucht die Vorschau
 * (mandanten_beenden.GET) und `bestaetigt=true`. Der Lauf ist idempotent und fortsetzbar; ein fehlender Datenexport hält ihn vor dem
 * Sperren an. Das letzte Glied sperrt das Team voll (kein FA-Zugriff außer Export-Download). Löschen der Daten gehört nicht dazu.
 */
class MandantenBeendenPostTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.mandanten_beenden.POST';
    }

    public function getDescription(): string
    {
        return 'Mandant beenden (nur Plattform-Admin). aktion=ausfuehren: führt die Schritte in fester Reihenfolge aus (abos → stamm_paket → export → bereiche → sperren), '
            .'braucht bestaetigt=true NACH der Vorschau (mandanten_beenden.GET); optional schritte[] beim Start. Hält an, wenn der Datenexport fehlt oder ein Schritt scheitert; '
            .'derselbe Aufruf setzt fort. aktion=abbrechen schließt einen offenen Lauf (schon Erledigtes bleibt). aktion=entsperren setzt den Status zurück auf „aktiv“ '
            .'(die Bereiche bleiben aus, bis sie neu gebucht werden). Die Daten werden nie gelöscht.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'team_id' => ['type' => 'integer', 'description' => 'Mandant (Kunden-Hauptteam).'],
            'aktion' => ['type' => 'string', 'enum' => ['ausfuehren', 'abbrechen', 'entsperren']],
            'bestaetigt' => ['type' => 'boolean', 'description' => 'Pflicht bei ausfuehren: nach der Vorschau ausdrücklich bestätigt.'],
            'schritte' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => MandantBeendenService::SCHRITTE], 'description' => 'Nur beim Start eines neuen Laufs; Standard: alle.'],
        ], 'required' => ['team_id', 'aktion']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, function (Team $team) use ($arguments, $context) {
            $svc = app(MandantBeendenService::class);
            $mandant = Team::findOrFail((int) ($arguments['team_id'] ?? 0));

            return match ((string) ($arguments['aktion'] ?? '')) {
                'ausfuehren' => $svc->fuehreAus($mandant, $context->user, (bool) ($arguments['bestaetigt'] ?? false), isset($arguments['schritte']) ? (array) $arguments['schritte'] : null),
                'abbrechen' => ['lauf' => ($l = $svc->abbrechen($mandant, $context->user)) === null ? null : $svc->laufDarstellung($l)],
                'entsperren' => ['mandant' => $svc->entsperren($mandant, $context->user)],
                default => throw new \RuntimeException('aktion: ausfuehren | abbrechen | entsperren.'),
            };
        });
    }

    public function getMetadata(): array
    {
        return array_merge($this->metadaten(true, ['mandanten', 'beenden'], ['Beende den Mandanten Soulkitchen, nachdem ich die Vorschau gesehen habe.', 'Entsperre Soulkitchen wieder.'], ['foodalchemist.mandanten_beenden.GET', 'foodalchemist.mandanten.PUT']), [
            'tags' => ['foodalchemist', 'mandanten', 'plattform', 'beenden'],
            'risk_level' => 'high',
        ]);
    }
}
