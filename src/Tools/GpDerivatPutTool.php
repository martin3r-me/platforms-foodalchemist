<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\DerivatAusbeuteService;
use Platform\FoodAlchemist\Services\GpService;
use Platform\FoodAlchemist\Support\Derivat\DerivatArt;
use Platform\FoodAlchemist\Support\Derivat\DerivatAusbeute;

/**
 * Spec 96 · Art und Ausbeute eines Derivats von Hand setzen (Herkunft manual, gewinnt gegen KI). Nur team-eigene Derivat-GPs.
 */
class GpDerivatPutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.gp_derivat.PUT';
    }

    public function getDescription(): string
    {
        return 'Setzt an einem team-eigenen Derivat-GP (is_derivat) die Art und die Ausbeute aus der Mutter (Spec 96). '
            . 'derivat_art: nebenprodukt (Abschnitte, Parüren, Knochen — fällt ohnehin an, EK 0 €, wird nie bestellt) | zweck '
            . '(Saft, Abrieb — die Mutter wird dafür gekauft, EK und Bestellung über die Mutter ÷ Ausbeute); leer = aus dem Namen abgeleitet. '
            . 'Ausbeute: derivat_ausbeute_basis g_je_stueck (Gramm Derivat aus einem Stück Mutter, z. B. Limette Saft 30) oder prozent '
            . '(Anteil der Mutter-Masse); derivat_ausbeute_wert leer löscht. Gilt als manuell gepflegt (die KI überschreibt ihn nicht).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'gp_id' => ['type' => 'integer', 'description' => 'Derivat-GP (team-eigen).'],
                'derivat_art' => ['type' => ['string', 'null'], 'enum' => [...DerivatArt::ARTEN, null], 'description' => 'nebenprodukt | zweck | null (abgeleitet).'],
                'derivat_ausbeute_wert' => ['type' => ['number', 'null'], 'description' => 'Ausbeute-Wert; null löscht.'],
                'derivat_ausbeute_basis' => ['type' => 'string', 'enum' => DerivatAusbeute::BASEN, 'description' => 'g_je_stueck | prozent.'],
            ],
            'required' => ['gp_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $gp = app(GpService::class)->find((int) ($arguments['gp_id'] ?? 0), $team);
        if ($gp === null) {
            return ToolResult::error('GP nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }
        if (! $gp->isOwnedBy($team)) {
            return ToolResult::error('Derivate pflegt nur das Besitzer-Team.', 'ACCESS_DENIED');
        }
        try {
            $gp = app(DerivatAusbeuteService::class)->setze($team, (int) $gp->id,
                array_intersect_key($arguments, array_flip(['derivat_art', 'derivat_ausbeute_wert', 'derivat_ausbeute_basis'])));
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(GpDerivatPutTool::sicht($gp));
    }

    /** @return array<string, mixed> */
    public static function sicht(\Platform\FoodAlchemist\Models\FoodAlchemistGp $gp): array
    {
        return [
            'gp_id' => (int) $gp->id,
            'name' => $gp->name,
            'derivat_art' => DerivatArt::von($gp),
            'derivat_art_gesetzt' => $gp->derivat_art,
            'derivat_ausbeute_wert' => $gp->derivat_ausbeute_wert !== null ? (float) $gp->derivat_ausbeute_wert : null,
            'derivat_ausbeute_basis' => $gp->derivat_ausbeute_basis,
            'derivat_ausbeute_quelle' => $gp->derivat_ausbeute_quelle,
            'ausbeute_fehlt' => DerivatAusbeute::fehlt($gp),
        ];
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'gp', 'derivat', 'ausbeute', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.gp_derivat.ESTIMATE', 'foodalchemist.gps.GET'],
            'examples' => ['Setze bei GP 123 (Limette: frisch, Saft) Art zweck und Ausbeute 30 g je Stück.'],
        ];
    }
}
