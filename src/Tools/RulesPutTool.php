<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistRule;
use Platform\FoodAlchemist\Services\Regeln\RegelService;
use Platform\FoodAlchemist\Services\Regeln\RegelUngueltig;

/**
 * Spec 81 — Regel anlegen oder ändern per MCP. Wie `knowledge.POST`: die gespeicherte Fassung ist AUS —
 * Einschalten bleibt Kuration in Einstellungen › Regeln (Probelauf ansehen, dann einschalten). Schema und
 * Beispiele werden geprüft; globale Regeln schreibt nur das Master-Team.
 */
class RulesPutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.rules.PUT';
    }

    public function getDescription(): string
    {
        return 'Legt eine Regel an oder ändert sie (gleicher schluessel = neue Version). Die neue Fassung ist immer AUS; '
            . 'eingeschaltet wird sie von einem Menschen in Einstellungen › Regeln. Achtung: Wer eine aktive Regel ändert, '
            . 'schaltet sie damit aus, bis sie wieder eingeschaltet wird. Vorher foodalchemist.rules.PREVIEW nutzen. '
            . 'Schema je Art: vokabular{werte[{wert,aliase,gruppe}],muster}, ersetzung{paare[{von,nach}],ausnahmen}, '
            . 'pflichtangabe{bedingung{feld:[werte]},tokens|muster,hinweis}, verbot{tokens|teile|muster,ausnahmen,bedingung,grund}, '
            . 'zuordnung{eintraege[{begriff,aliase,ziel_typ,ziel_name,kontext}],vergleich}, schwelle{vergleich,wert|min,max,einheit}.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['schluessel'],
            'properties' => [
                'schluessel' => ['type' => 'string'],
                'regelwerk' => ['type' => 'string'],
                'paragraph' => ['type' => 'string'],
                'titel' => ['type' => 'string'],
                'art' => ['type' => 'string', 'enum' => FoodAlchemistRule::ARTEN],
                'ziel' => ['type' => 'string', 'description' => 'z. B. gp.name, rezept.name, rezeptzeile, vk.name, rezept.schritt'],
                'wirkung' => ['type' => 'string', 'enum' => FoodAlchemistRule::WIRKUNGEN],
                'params' => ['type' => 'object'],
                'beispiele' => ['type' => 'object'],
                'notiz' => ['type' => 'string'],
                'dossier_slug' => ['type' => 'string'],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        if (! RegelService::darf($context->user)) {
            return ToolResult::error('Regeln pflegt nur der Plattform-Administrator.', 'FORBIDDEN');
        }
        $alt = FoodAlchemistRule::query()->whereNull('team_id')->where('schluessel', (string) ($arguments['schluessel'] ?? ''))->first();
        $daten = ($alt?->only(['schluessel', 'regelwerk', 'paragraph', 'titel', 'art', 'ziel', 'wirkung', 'params', 'beispiele', 'notiz', 'dossier_slug']) ?? [])
            + [];
        foreach (['schluessel', 'regelwerk', 'paragraph', 'titel', 'art', 'ziel', 'wirkung', 'params', 'beispiele', 'notiz', 'dossier_slug'] as $f) {
            if (array_key_exists($f, $arguments)) {
                $daten[$f] = $arguments[$f];
            }
        }
        if ($alt !== null && isset($arguments['art']) && $arguments['art'] !== $alt->art) {
            return ToolResult::error('Die Art einer bestehenden Regel ist fest — neue Regel mit eigenem Schlüssel anlegen.', 'VALIDATION_ERROR');
        }
        try {
            $r = app(RegelService::class)->speichere($daten, $context->user, false, 'mcp');
        } catch (RegelUngueltig $e) {
            return ToolResult::error(implode(' · ', $e->fehler), 'VALIDATION_ERROR');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'FORBIDDEN');
        }

        return ToolResult::success([
            'schluessel' => $r->schluessel, 'version' => $r->version, 'aktiv' => false,
            'hinweis' => ($alt?->aktiv ? 'Die Regel war aktiv und ist jetzt AUS. ' : '')
                . 'Einschalten in Einstellungen › Regeln, nach Ansicht des Probelaufs.',
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'config', 'tags' => ['foodalchemist', 'regeln', 'regelwerk', 'konfiguration'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.rules.GET', 'foodalchemist.rules.PREVIEW'],
            'examples' => ['Ergänze im Typ-Vokabular den Typ „Crumble" unter Knusprige Komponenten'],
        ];
    }
}
