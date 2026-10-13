<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Models\FoodAlchemistAngebot;
use Platform\FoodAlchemist\Services\AngebotEinfrierService;

/** Spec 82 §12 B3 · Angebot einfrieren oder den eingefrorenen Stand verwerfen. Der Status-Wechsel auf „angebot“ friert ohnehin ein; dieses Tool ist der direkte Weg. */
class AngeboteEinfrierenPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.angebote.EINFRIEREN_POST';
    }

    public function getDescription(): string
    {
        return 'aktion=einfrieren: hält die Gerichte mit Abo-Fassung im Baum fest (Fassungs-IDs, EK mit Status, Allergene); ist schon eingefroren, bleibt der Stand. Ohne Gericht mit Abo-Fassung entsteht nichts. '
            .'aktion=verwerfen: verwirft den aktiven Stand (das Angebot folgt wieder der neuesten Fassung) und braucht bestaetigt=true. Ab Rolle Kuratieren.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'id' => ['type' => 'integer', 'description' => 'Angebot-Id.'],
            'aktion' => ['type' => 'string', 'enum' => ['einfrieren', 'verwerfen']],
            'bestaetigt' => ['type' => 'boolean', 'description' => 'Pflicht bei verwerfen.'],
        ], 'required' => ['id', 'aktion']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $id = (int) ($arguments['id'] ?? 0);
        if (($guard = $this->guardOwned($team, FoodAlchemistAngebot::class, $id, 'Angebot')) !== null) {
            return $guard;
        }
        $angebot = FoodAlchemistAngebot::visibleToTeam($team)->findOrFail($id);
        $svc = app(AngebotEinfrierService::class);
        try {
            if (($arguments['aktion'] ?? '') === 'verwerfen') {
                if (empty($arguments['bestaetigt'])) {
                    return ToolResult::error('Verwerfen braucht bestaetigt=true: das Angebot folgt danach wieder der neuesten Fassung.', 'VALIDATION_ERROR');
                }

                return ToolResult::success(['id' => $id, 'verworfen' => $svc->verwerfen($team, $context->user, $angebot)]);
            }
            $e = $svc->einfrieren($team, $context->user, $angebot);
        } catch (FaRechtFehltException $ex) {
            return ToolResult::error($ex->getMessage(), 'FORBIDDEN');
        } catch (\RuntimeException $ex) {
            return ToolResult::error($ex->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['id' => $id, 'eingefroren' => $e !== null, 'zeilen' => $e?->zeilen->count() ?? 0,
            'hinweis' => $e === null ? 'Kein Gericht mit Abo-Fassung im Angebot, nichts einzufrieren.' : null]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action', 'tags' => ['foodalchemist', 'angebot', 'einfrieren', 'fassung', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write', 'confirmation_required' => true,
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db', 'side_effects' => ['creates', 'updates'],
            'related_tools' => ['foodalchemist.angebote.EINFRIEREN_GET', 'foodalchemist.angebote.STATUS'],
            'examples' => ['Friere Angebot 5 ein.'],
        ];
    }
}
