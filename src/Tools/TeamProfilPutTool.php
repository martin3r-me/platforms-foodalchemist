<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\TeamProfilService;

/** Spec 82 §6 · Teamprofil des EIGENEN Teams pflegen. Fremde Teams werden nie geschrieben (Wechsel ins Team nötig). */
class TeamProfilPutTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.team_profile.PUT';
    }

    public function getDescription(): string
    {
        return 'Pflegt das Teamprofil des aktuellen Teams (Recht: Kuratieren). Felder je Aufruf optional: profil {max_pax_parallel, convenience_grad '
            .'(from_scratch|teil_convenience|voll_convenience), notes}; equipment [{equipment_id, status (vorhanden|nicht_vorhanden|null = unbekannt), anzahl?, note?}]; '
            .'flaechen [{id? (ändern), name, max_pax?, saison_von?, saison_bis?}]; loesche_flaechen [id]. Unbekannt = keine Angabe: status null '
            .'löscht die Angabe. Fehlendes Recht: FORBIDDEN.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'profil' => ['type' => 'object', 'properties' => [
                'max_pax_parallel' => ['description' => 'Ganzzahl oder null (null hebt auf)'],
                'convenience_grad' => ['enum' => ['from_scratch', 'teil_convenience', 'voll_convenience', null], 'description' => 'Text oder null (null hebt auf)'],
                'notes' => ['description' => 'Text oder null (null hebt auf)'],
            ]],
            'equipment' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                'equipment_id' => ['type' => 'integer'], 'status' => ['enum' => ['vorhanden', 'nicht_vorhanden', null], 'description' => 'Text oder null (null = unbekannt, entfernt die Angabe)'],
                'anzahl' => ['description' => 'Ganzzahl oder null (null hebt auf)'], 'note' => ['description' => 'Text oder null (null hebt auf)'],
            ], 'required' => ['equipment_id']]],
            'flaechen' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                'id' => ['type' => 'integer'], 'name' => ['type' => 'string'], 'max_pax' => ['description' => 'Ganzzahl oder null (null hebt auf)'],
                'saison_von' => ['description' => 'Ganzzahl oder null (null hebt auf)'], 'saison_bis' => ['description' => 'Ganzzahl oder null (null hebt auf)'],
            ], 'required' => ['name']]],
            'loesche_flaechen' => ['type' => 'array', 'items' => ['type' => 'integer']],
        ]];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, function (Team $team) use ($arguments, $context) {
            $svc = app(TeamProfilService::class);
            $user = $context->user;
            if (isset($arguments['profil']) && is_array($arguments['profil'])) {
                $svc->setzeProfil($team, $user, $arguments['profil']);
            }
            foreach ((array) ($arguments['equipment'] ?? []) as $e) {
                $svc->setzeEquipment($team, $user, (int) ($e['equipment_id'] ?? 0), $e['status'] ?? null, isset($e['anzahl']) ? (int) $e['anzahl'] : null, $e['note'] ?? null);
            }
            foreach ((array) ($arguments['flaechen'] ?? []) as $f) {
                $svc->setzeFlaeche($team, $user, isset($f['id']) ? (int) $f['id'] : null, (string) ($f['name'] ?? ''),
                    isset($f['max_pax']) ? (int) $f['max_pax'] : null, isset($f['saison_von']) ? (int) $f['saison_von'] : null, isset($f['saison_bis']) ? (int) $f['saison_bis'] : null);
            }
            foreach ((array) ($arguments['loesche_flaechen'] ?? []) as $id) {
                $svc->loescheFlaeche($team, $user, (int) $id);
            }

            return $svc->lies($team, $team);
        });
    }

    public function getMetadata(): array
    {
        return array_merge($this->metadaten(true, ['teamprofil'], ['Wir haben keinen Kombidämpfer.'], ['foodalchemist.team_profile.GET']), [
            'tags' => ['foodalchemist', 'teamprofil', 'machbarkeit', 'write'],
        ]);
    }
}
