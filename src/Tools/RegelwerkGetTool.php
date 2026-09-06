<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\Ai\KnowledgeContextService;
use Platform\FoodAlchemist\Services\Knowledge\KnowledgeCanonService;
use Platform\FoodAlchemist\Services\VorgangsRegisterService;

/**
 * Spec 50 · E-4 — welche Regelwerke für einen Vorgang oder Prompt-Key verbindlich sind.
 *
 * Zwei Auflösungen, ein Vertrag:
 *  · Kanon befüllt  → genau die kuratierten Dossiers, mit `mode` (pflicht | wenn_platz)
 *  · Kanon leer     → das ganze Regelwerk-Dossier des Features
 *
 * `quelle` sagt, welcher Fall vorliegt — der Agent sieht also, ob er §-genau kuratiertes
 * Wissen bekommt oder ein ganzes Dossier. Das ist die Zusage aus Spec §5.1: E-3/E-4
 * funktionieren, bevor der Kanon steht, und werden präziser, sobald er steht.
 *
 * Der Inhalt kommt bewusst NICHT mit: `knowledge.GET` liefert ihn, und ein Regelwerk-Dossier
 * ist mehrere tausend Zeichen gross. Dieses Tool ist die Packliste, nicht das Paket.
 */
class RegelwerkGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.regelwerk.GET';
    }

    public function getDescription(): string
    {
        return 'Nennt die Regelwerks-Dossiers, die für einen Vorgang (vorgang) oder eine Prompt-Registry-Zeile '
            . '(prompt_key) verbindlich sind — inklusive Quelle (kanon = kuratierte Auswahl, dossier = ganzes '
            . 'Regelwerk). Liefert die Packliste mit Slugs; den Volltext holt knowledge.GET.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'vorgang' => [
                    'type' => 'string',
                    'enum' => array_keys(VorgangsRegisterService::VORGAENGE),
                    'description' => 'Vorgangs-Code (siehe ablauf.GET).',
                ],
                'prompt_key' => [
                    'type' => 'string',
                    'description' => 'Alternativ: eine Prompt-Registry-Zeile, z. B. recipe.generator.',
                ],
                'role' => [
                    'type' => 'string',
                    'enum' => KnowledgeCanonService::ROLES,
                    'description' => 'Kanon-Rolle: root = Einstiegs-Aufruf (Default), child = Kaskaden-Kind.',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        $vorgang = trim((string) ($arguments['vorgang'] ?? ''));
        $promptKey = trim((string) ($arguments['prompt_key'] ?? ''));
        $role = trim((string) ($arguments['role'] ?? 'root')) ?: 'root';

        if ($vorgang === '' && $promptKey === '') {
            return ToolResult::error('vorgang oder prompt_key angeben.', 'VALIDATION_ERROR');
        }
        if (! in_array($role, KnowledgeCanonService::ROLES, true)) {
            return ToolResult::error('Unbekannte role. Erlaubt: ' . implode(', ', KnowledgeCanonService::ROLES) . '.', 'VALIDATION_ERROR');
        }

        if ($promptKey !== '') {
            if (! array_key_exists($promptKey, (array) config('foodalchemist.prompts', []))) {
                return ToolResult::error('Unbekannter prompt_key «' . $promptKey . '».', 'VALIDATION_ERROR');
            }
            $docs = $team !== null
                ? app(KnowledgeCanonService::class)->documentsFor('prompt_key', $promptKey, $team, $role)
                : collect();

            if ($docs->isEmpty()) {
                return ToolResult::success([
                    'prompt_key' => $promptKey, 'role' => $role, 'quelle' => 'keine', 'dokumente' => [],
                    'hinweis' => 'Für diesen Prompt-Key ist kein Kanon hinterlegt. Der Generator lädt dann das '
                        . 'per Routing gebundene Regelwerk-Dossier; über ablauf.GET siehst du, welches das ist.',
                ]);
            }

            return ToolResult::success([
                'prompt_key' => $promptKey,
                'role' => $role,
                'quelle' => 'kanon',
                'dokumente' => $docs->map(fn ($d) => [
                    'slug' => $d->slug, 'titel' => $d->title, 'kategorie' => $d->category,
                    'mode' => $d->mode, 'zeichen' => (int) $d->char_count,
                ])->values()->all(),
                'lesen_mit' => 'foodalchemist.knowledge.GET',
            ]);
        }

        $svc = app(VorgangsRegisterService::class);
        if (! $svc->kennt($vorgang)) {
            return ToolResult::error(
                'Unbekannter Vorgang «' . $vorgang . '». Bekannt: ' . implode(', ', array_keys(VorgangsRegisterService::VORGAENGE)) . '.',
                'VALIDATION_ERROR'
            );
        }

        $v = VorgangsRegisterService::VORGAENGE[$vorgang];

        return ToolResult::success(['vorgang' => $vorgang, 'role' => $role]
            + $svc->regelwerke($v, $team)
            + ['lesen_mit' => 'foodalchemist.knowledge.GET']);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'regelwerk', 'kanon', 'wissen', 'anleitung'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => false, 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.ablauf.GET', 'foodalchemist.knowledge.GET', 'foodalchemist.knowledge_canon.GET'],
            'examples' => [
                'Welche Regelwerke gelten beim Anlegen eines Basisrezepts?',
                'Welches Wissen bekommt recipe.generator verbindlich mit?',
            ],
        ];
    }
}
