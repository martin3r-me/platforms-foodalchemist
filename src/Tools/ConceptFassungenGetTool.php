<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Support\MasterTeam;

/** Spec 82 Abo Konzepte · Änderungsprotokoll der Fassungen eines Konzepts (Nummer, Datum, Notiz — kein Inhalt, keine Kosten). */
class ConceptFassungenGetTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.concept_fassungen.GET';
    }

    public function getDescription(): string
    {
        return 'Änderungsprotokoll eines Abo- bzw. Master-Konzepts: alle Fassungen mit Nummer, Datum und Notiz. concept_id = Arbeitszeile (Master) '
            .'oder eine sichtbare Fassung (Mandant). Liefert keine Zusammensetzung und keine Preise.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => ['concept_id' => ['type' => 'integer']], 'required' => ['concept_id']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, function (Team $team) use ($arguments) {
            $id = (int) ($arguments['concept_id'] ?? 0);
            $familie = null;
            if (MasterTeam::istMaster($team)) {
                $familie = FoodAlchemistConcept::where('team_id', $team->id)->whereKey($id)->value('id');
            }
            if ($familie === null) {   // Mandant: nur über eine Fassung, die er sieht
                $familie = FoodAlchemistConcept::visibleToTeam($team)->where('foodalchemist_concepts.ist_fassung', true)
                    ->whereKey($id)->value('fassung_von_id');
            }
            if ($familie === null) {
                throw new \Illuminate\Database\Eloquent\ModelNotFoundException;
            }

            return ['fassungen' => FoodAlchemistConcept::mitFassungen()->where('fassung_von_id', $familie)->where('ist_fassung', true)
                ->orderByDesc('fassung_nr')->get(['id', 'name', 'fassung_nr', 'veroeffentlicht_at', 'aenderungsnotiz'])
                ->map(fn ($f) => ['id' => (int) $f->id, 'name' => $f->name, 'nr' => (int) $f->fassung_nr,
                    'veroeffentlicht_at' => $f->veroeffentlicht_at, 'notiz' => $f->aenderungsnotiz])->all()];
        });
    }

    public function getMetadata(): array
    {
        return array_merge($this->metadaten(false, ['konzepte', 'fassung'], ['Was hat sich am Abo-Konzept Grill-Buffet geändert?'], ['foodalchemist.concept_fassungen.PUBLISH']), [
            'tags' => ['foodalchemist', 'konzepte', 'fassung', 'abo'],
        ]);
    }
}
