<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\Knowledge\WissensProfilService;

/**
 * Spec 52 · C0 + D6 (MCP-Fläche, Grundsatz E) — aufgelöstes Regelprofil je Prompt-Key.
 *
 * Abgrenzung zu `knowledge_versorgung.GET` (A2): das zählt, **ob** ein Prompt Wissen erreicht.
 * Dieses Tool sagt, **was genau** ihn erreicht und **ob es auflösbar ist** — eine Kanon-Zeile
 * auf ein deaktiviertes Dossier gilt dort als gesteuert und liefert trotzdem nichts.
 *
 * Der `fingerabdruck` fasst Kanon (inkl. Dossier-Versionen), Routing und Budget zu einer
 * 16-stelligen Signatur zusammen. Vor und nach einer Änderung abgefragt zeigt er, ob sich das
 * Verhalten eines Schritts geändert hat — auch wenn die Änderung in einer anderen Tabelle
 * stattfand.
 */
class KnowledgeProfilGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.knowledge_profil.GET';
    }

    public function getDescription(): string
    {
        return 'Liefert das AUFGELÖSTE Regelprofil eines KI-Schritts: Pflicht-Dossiers (mit Version), '
            . 'wenn_platz-Dossiers, Routing, beide Budgets, den Zustand (gesteuert | bewusst_leer | '
            . 'ungesteuert | fehlerhaft) und einen Fingerabdruck über alles zusammen. Meldet Befunde, '
            . 'die sonst still bleiben: Kanon-Zeilen auf deaktivierte oder gelöschte Dossiers, '
            . 'Pflichtwissen über Budget, Dossiers über dem Deckel, und Bindungen, die sich wieder '
            . 'scharf schalten würden, wenn der Kanon verschwindet. Ohne prompt_key: Lauf über die '
            . 'ganze Registry. Read-only.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'prompt_key' => ['type' => 'string', 'description' => 'optional: nur dieses Profil (voller Key, z. B. recipe.generator)'],
                'praefix' => ['type' => 'string', 'description' => 'optional: nur Keys dieses Bereichs (recipe, vk, gp, …)'],
                'nur_befunde' => ['type' => 'boolean', 'description' => 'optional: nur Profile mit Befunden zurückgeben'],
                'role' => ['type' => 'string', 'description' => 'optional: root (Standard) oder child'],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        $dienst = app(WissensProfilService::class);
        $role = trim((string) ($arguments['role'] ?? 'root')) ?: 'root';
        if (! in_array($role, ['root', 'child'], true)) {
            return ToolResult::error('role muss root oder child sein.', 'VALIDATION_ERROR');
        }

        $promptKey = trim((string) ($arguments['prompt_key'] ?? ''));
        if ($promptKey !== '') {
            if (! array_key_exists($promptKey, (array) config('foodalchemist.prompts', []))) {
                return ToolResult::error('Unbekannter prompt_key «'.$promptKey.'».', 'VALIDATION_ERROR');
            }

            return ToolResult::success(['profil' => $dienst->profil($promptKey, $team, $role)]);
        }

        $praefix = trim((string) ($arguments['praefix'] ?? ''));
        $bericht = $dienst->integritaet($team, $praefix !== '' ? $praefix : null);

        if ((bool) ($arguments['nur_befunde'] ?? false)) {
            $bericht['profile'] = array_values(array_filter($bericht['profile'], fn ($p) => $p['befunde'] !== []));
        }

        // Pflichtwissen wird vollständig reserviert; eine Überschreitung stoppt den Modellaufruf.
        $bericht['hinweis'] = $bericht['blockierend'] > 0
            ? $bericht['blockierend'].' Prompt-Key(s) mit blockierendem Befund. Je nach Code heisst das '
                .'Unterschiedliches: `dossier_*` und `art_nie_im_prompt` → hinterlegtes Wissen kommt NICHT an. '
                .'`pflicht_ueber_budget` → die Pflichtmenge überschreitet das gemeinsame Wissensbudget; '
                .'der Modellaufruf wird bis zur Korrektur abgebrochen.'
            : null;

        return ToolResult::success($bericht);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'knowledge', 'wissen', 'profil', 'kanon', 'integritaet', 'diagnose'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'related_tools' => [
                'foodalchemist.knowledge_versorgung.GET', 'foodalchemist.knowledge_canon.GET',
                'foodalchemist.knowledge_routings.GET', 'foodalchemist.knowledge_bindings.GET',
            ],
            'examples' => [
                'Was gilt verbindlich für recipe.generator?',
                'Zeig alle Prompt-Keys mit kaputten Kanon-Zeilen',
                'Hat sich das Profil von vk.generator geändert?',
            ],
        ];
    }
}
