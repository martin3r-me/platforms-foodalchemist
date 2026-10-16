<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\Produktpass\ProduktpassService;

/** Produktpass (PDF/Foto) an einen Lieferantenartikel hängen; die KI liest ihn im Hintergrund. */
class ProduktpassPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    use Concerns\DateiAusArgumenten;

    public function getName(): string
    {
        return 'foodalchemist.produktpass.POST';
    }

    public function getDescription(): string
    {
        return 'Lädt den Produktpass bzw. das Datenblatt eines Lieferantenartikels hoch (datei als base64 oder url; PDF, JPG, PNG, WebP, max. 15 MB) '
            .'und startet das Lesen im Hintergrund. Die KI liest Allergene (nur Vorschlag), Zusatzstoffe, Nährwerte, Zutatenliste, Gebinde, Haltbarkeit, '
            .'Herkunft und Marke; Nährwerte/Zutatenliste/Zusatzstoffe/Haltbarkeit trägt sie bei Konfidenz ≥ 0,8 direkt ein (Quelle produktpass_ki), '
            .'Gebinde nur in leere Felder, Allergene erst nach Bestätigung. Nur das Team, dem der Artikel gehört (Master: globale Stamm-Artikel). '
            .'Stand und Vorschlag danach mit foodalchemist.produktpass.GET.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'supplier_item_id' => ['type' => 'integer', 'description' => 'ID des Lieferantenartikels'],
                'datei' => self::dateiSchema(),
            ],
            'required' => ['supplier_item_id', 'datei'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $item = FoodAlchemistSupplierItem::visibleToTeam($team)->find((int) ($arguments['supplier_item_id'] ?? 0));
        if ($item === null) {
            return ToolResult::error('Artikel nicht gefunden.', 'NOT_FOUND');
        }
        try {
            $datei = $this->dateiAusArgumenten($arguments['datei'] ?? null);
            if ($datei === null) {
                return ToolResult::error('datei fehlt (base64 oder url).', 'VALIDATION_ERROR');
            }
            $pass = app(ProduktpassService::class)->hochladen($team, $item, $datei, $context->user?->id);
        } catch (FaRechtFehltException $e) {
            return ToolResult::error($e->getMessage(), 'FORBIDDEN');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        } finally {
            $this->dateiAufraeumen();
        }

        return ToolResult::success(['produktpass_id' => $pass->id, 'version' => $pass->version, 'status' => $pass->status,
            'hinweis' => 'Die KI liest die Datei im Hintergrund. Stand: foodalchemist.produktpass.GET.']);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action', 'tags' => ['foodalchemist', 'produktpass', 'lieferantenartikel', 'upload', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'ai_call', 'side_effects' => ['creates', 'queues'],
            'related_tools' => ['foodalchemist.produktpass.GET', 'foodalchemist.produktpass.ALLERGENE_BESTAETIGEN'],
            'examples' => ['Lade den Produktpass als PDF an Artikel 4711 hoch.'],
        ];
    }
}
