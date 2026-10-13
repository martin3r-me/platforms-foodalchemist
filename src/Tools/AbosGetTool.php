<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\AboService;

/** Spec 82 §8 / §13 B6 · Abos lesen: das Master-Team sieht alle seine Abos, ein Mandant (bzw. Unter-Team) die seines Oberteams. */
class AbosGetTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.abos.GET';
    }

    public function getDescription(): string
    {
        return 'Listet Abos (Sammlung des Master-Teams, live und nur lesend beim Mandanten). Im Master-Team: alle vergebenen Abos. '
            .'In einem Mandanten bzw. Unter-Team: die Abos des Oberteams. Optional mit gekündigten (nur_aktiv=false) und der '
            .'berechneten Hülle eines Mandanten (huelle_fuer_team_id, nur im Master-Team).';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'nur_aktiv' => ['type' => 'boolean', 'description' => 'Standard true.'],
            'huelle_fuer_team_id' => ['type' => 'integer', 'description' => 'Nur Master-Team: gespeicherte Abo-Hülle dieses Mandanten.'],
        ]];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, function (Team $team) use ($arguments) {
            $svc = app(AboService::class);
            $out = ['abos' => $svc->abos($team, (bool) ($arguments['nur_aktiv'] ?? true))->values()->all()];
            if (! empty($arguments['huelle_fuer_team_id'])) {
                if (! \Platform\FoodAlchemist\Support\MasterTeam::istMaster($team)) {
                    throw new \RuntimeException('Die Abo-Hülle eines Mandanten sieht nur das Master-Team.');
                }
                $out['huelle'] = \Illuminate\Support\Facades\DB::table('foodalchemist_abo_objekte')
                    ->where('mandant_team_id', (int) $arguments['huelle_fuer_team_id'])->orderBy('typ')->orderBy('objekt_id')
                    ->get(['typ', 'objekt_id'])->groupBy('typ')->map(fn ($g) => $g->pluck('objekt_id')->map(fn ($v) => (int) $v)->all())->all();
            }

            return $out;
        });
    }

    public function getMetadata(): array
    {
        return array_merge($this->metadaten(false, ['abos'], ['Welche Mandanten haben die Sammlung „Basis“ abonniert?'], ['foodalchemist.abos.POST']), [
            'tags' => ['foodalchemist', 'abos', 'kurator'],
        ]);
    }
}
