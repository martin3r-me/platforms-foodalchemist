<?php

namespace Platform\FoodAlchemist\Tools;

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\Pairing\AnkerWissenFreigabe;

/**
 * Spec 60 · P8: das Anker-Wissen eines Ankers mit Status je Eintrag — Grundlage für die
 * Freigabe ({@see AnkerWissenStatusTool}) und für die Signale `pairing_wissen_pruefen` /
 * `pairing_widerspruch_messung`.
 */
class AnkerWissenGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.anker_wissen.GET';
    }

    public function getDescription(): string
    {
        return 'Anker-Wissen (Spec 60) eines Aroma-Ankers mit Status je Eintrag (entwurf = aus dem Dossier ausgelesen, '
            . 'ungeprüft · geprueft · verworfen): bedarfe (was der Anker von außen braucht, muss|soll), eigenschaften '
            . '(was er selbst liefert, Stufe 0–3), komponenten (Formen mit Technik), beziehungen (kombination = Klassiker, '
            . 'konflikt = stört sich; messung_3_sterne zeigt den Widerspruch zur Foodpairing-Messung) und offen '
            . '(Rohnamen ohne Anker). Anker per anker_id oder anker_slug (Suche: foodalchemist.composer.ANKER_SUCHE). Read-only.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'anker_id' => ['type' => 'integer', 'description' => 'ID aus dem Aroma-Anker-Vokabular (nicht die gp_id)'],
                'anker_slug' => ['type' => 'string', 'description' => 'alternativ der Slug'],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        if ($this->team($context) === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $id = isset($arguments['anker_id']) ? (int) $arguments['anker_id']
            : (int) DB::table('foodalchemist_vocab_pairing_anchors')->where('slug', (string) ($arguments['anker_slug'] ?? ''))->value('id');
        $daten = $id > 0 ? app(AnkerWissenFreigabe::class)->ansicht($id) : null;
        if ($daten === null) {
            return ToolResult::error('Anker nicht gefunden — anker_id/anker_slug über foodalchemist.composer.ANKER_SUCHE.', 'NOT_FOUND');
        }

        return ToolResult::success($daten);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'pairing', 'anker', 'wissen', 'dossier', 'freigabe', 'kontrast', 'konflikt'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.anker_wissen.STATUS', 'foodalchemist.composer.ANKER_SUCHE', 'foodalchemist.kombination.GET'],
            'examples' => ['Welches Anker-Wissen hat Kürbis und was ist noch ungeprüft?'],
        ];
    }
}
