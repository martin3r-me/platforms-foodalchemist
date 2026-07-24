<?php

namespace Platform\FoodAlchemist\Tools;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistDishIdea;
use Platform\FoodAlchemist\Services\IdeenService;

/**
 * Spec 19 „Foodbook-Leitstelle" (E6.5): Kreativ-Skizze ODER Paket-Gruppe ändern. Diskriminator
 * `objekt` + `id`:
 *  - objekt=idee: Inhalt/Form (title/description/position/ziel_form/paket_gruppe) und/oder
 *    Status (nur entwurf|verworfen — verwerfen/reaktivieren). `freigegeben` bleibt dem
 *    Kapitel-Go (E7) vorbehalten und ist hier gesperrt.
 *  - objekt=gruppe: name/paket_zielpreis_pp/position ODER aufloesen=true (Mitglieder werden zu
 *    Einzel-Skizzen gelöst, die Gruppe soft-gelöscht).
 *
 * Materialisierung/Anlage passiert NIE über MCP (human-only Kapitel-Go).
 */
class KapitelIdeenPutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.kapitel_ideen.PUT';
    }

    public function getDescription(): string
    {
        return 'Ändert eine Skizze (objekt=idee) oder Paket-Gruppe (objekt=gruppe) per id. '
            . 'Skizze: title/description/position/ziel_form/paket_gruppe und/oder status (entwurf|verworfen). '
            . 'Gruppe: name/paket_zielpreis_pp/position oder aufloesen=true (löst das Paket auf). '
            . 'status=freigegeben ist gesperrt (das macht der Kapitel-Go, kein MCP).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'objekt' => ['type' => 'string', 'enum' => ['idee', 'gruppe'], 'default' => 'idee'],
                'id' => ['type' => 'integer', 'description' => 'ID der Skizze bzw. Paket-Gruppe'],
                // objekt=idee
                'title' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'position' => ['type' => 'integer'],
                'ziel_form' => ['type' => 'string', 'enum' => FoodAlchemistDishIdea::TARGET_FORMS, 'description' => 'einzel | paket'],
                'paket_gruppe' => ['type' => 'integer', 'description' => 'group_id (bei ziel_form=paket; 0/null = aus Paket lösen)'],
                'status' => ['type' => 'string', 'enum' => ['entwurf', 'verworfen'], 'description' => 'verwerfen/reaktivieren (freigegeben nur via Kapitel-Go)'],
                // objekt=gruppe
                'name' => ['type' => 'string', 'description' => 'Paket-Name'],
                'paket_zielpreis_pp' => ['type' => 'number', 'description' => '€/Gast-Ziel des Pakets'],
                'aufloesen' => ['type' => 'boolean', 'description' => 'true = Paket auflösen (Mitglieder → Einzel, Gruppe gelöscht)'],
            ],
            'required' => ['id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $svc = app(IdeenService::class);
        $objekt = (string) ($arguments['objekt'] ?? 'idee');
        $id = (int) $arguments['id'];

        try {
            if ($objekt === 'gruppe') {
                if ((bool) ($arguments['aufloesen'] ?? false)) {
                    $svc->loescheGruppe($team, $id);

                    return ToolResult::success(['aufgeloest' => true, 'id' => $id]);
                }
                $patch = [];
                if (array_key_exists('name', $arguments)) {
                    $patch['name'] = $arguments['name'];
                }
                if (array_key_exists('paket_zielpreis_pp', $arguments)) {
                    $patch['target_price_pp'] = $arguments['paket_zielpreis_pp'];
                }
                if (array_key_exists('position', $arguments)) {
                    $patch['position'] = (int) $arguments['position'];
                }
                $gruppe = $svc->updateGruppe($team, $id, $patch);

                return ToolResult::success(['gruppe' => $this->paketArr($gruppe)]);
            }
            if ($objekt !== 'idee') {
                return ToolResult::error('objekt muss idee|gruppe sein.', 'VALIDATION_ERROR');
            }

            // Inhalt/Form patchen (nur mitgesandte Felder — sonst würde update() nichts anfassen).
            $patch = [];
            foreach (['title', 'description', 'position'] as $f) {
                if (array_key_exists($f, $arguments)) {
                    $patch[$f] = $arguments[$f];
                }
            }
            if (array_key_exists('ziel_form', $arguments)) {
                $patch['target_form'] = (string) $arguments['ziel_form'];
            }
            if (array_key_exists('paket_gruppe', $arguments)) {
                $patch['group_id'] = (int) $arguments['paket_gruppe'];
            }
            $idee = null;
            if ($patch !== []) {
                $idee = $svc->update($team, $id, $patch);
            }
            if (array_key_exists('status', $arguments)) {
                $idee = $svc->setStatus($team, $id, (string) $arguments['status']);
            }
            if ($idee === null) {
                return ToolResult::error('Keine änderbaren Felder übergeben.', 'VALIDATION_ERROR');
            }

            return ToolResult::success(['idee' => $this->skizzeArr($idee)]);
        } catch (ModelNotFoundException $e) {
            return ToolResult::error('Skizze/Gruppe nicht sichtbar/vorhanden.', 'NOT_FOUND');
        } catch (\RuntimeException $e) {
            $code = str_contains($e->getMessage(), 'Geerbt') ? 'ACCESS_DENIED' : 'VALIDATION_ERROR';

            return ToolResult::error($e->getMessage(), $code);
        }
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'foodbook', 'kapitel', 'ideen', 'skizzen', 'kreativ', 'leitstelle'],
            'read_only' => false,
            'idempotent' => false,
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'side_effects' => ['updates', 'deletes'],
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.kapitel_ideen.GET', 'foodalchemist.kapitel_ideen.POST'],
            'examples' => ['Verwirf Skizze 42', 'Benenne Paket 7 in "Herbst-Menü" um und setze 18 € pro Gast'],
        ];
    }
}
