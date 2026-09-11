<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Exceptions\WissenGesperrtException;
use Platform\FoodAlchemist\Services\Knowledge\KnowledgeLinkService;
use Platform\FoodAlchemist\Services\Knowledge\Wissensverbindung;

/**
 * Spec 52 · H6 (MCP-Fläche, Grundsatz E) — Verbindungen zwischen Dossiers.
 *
 * Ein Tool mit `action` statt drei einzelnen: die drei Operationen teilen Argumente und
 * Validierung vollständig, und die Tool-Registry ist ohnehin schon gross. `ALIAS` macht es
 * im selben Modul genauso.
 *
 * ★ Der Grund, warum es das gibt: beim Neuschnitt des Korpus werden aus einem Dossier zwei und
 * aus dreien eins. Ohne `ersetzt`-Kante ist hinterher nicht feststellbar, was wodurch abgelöst
 * wurde — und der Integritäts-Bericht kann bei einer toten Kanon-Zeile keinen Nachfolger
 * nennen. Genau diese Information lässt sich später **nicht rekonstruieren**.
 */
class KnowledgeLinksTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.knowledge_links.SET';
    }

    public function getDescription(): string
    {
        return 'Verbindungen zwischen Wissens-Dossiers: action=get (beide Richtungen zu einem slug), '
            . 'set (Verbindung anlegen/aktualisieren) oder delete. Arten: ersetzt (Nachfolge — beim '
            . 'Neuschnitt eines Dossiers die wichtigste; der Integritäts-Bericht nennt den Nachfolger, '
            . 'wenn eine Kanon-Zeile auf ein abgelöstes Dossier zeigt) · verfeinert (Detail von) · '
            . 'siehe_auch · widerspricht (beide gelten, sagen Unterschiedliches — festhalten statt '
            . 'raten). Eine `ersetzt`-Schleife wird abgewiesen. Das Schreibrecht entscheidet das '
            . 'AUSGANGS-Dossier; auf fremdes/globales Wissen darf man verweisen.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'enum' => ['get', 'set', 'delete']],
                'slug' => ['type' => 'string', 'description' => 'bei action=get: das Dossier, dessen Verbindungen gelistet werden'],
                'von' => ['type' => 'string', 'description' => 'bei set/delete: Ausgangs-Dossier (ihm gehört die Kante)'],
                'nach' => ['type' => 'string', 'description' => 'bei set/delete: Ziel-Dossier'],
                'art' => ['type' => 'string', 'enum' => Wissensverbindung::ALLE],
                'notiz' => ['type' => 'string', 'description' => 'optional: warum diese Verbindung besteht'],
            ],
            'required' => ['action'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        $dienst = app(KnowledgeLinkService::class);
        if (! $dienst->verfuegbar()) {
            return ToolResult::error('Verbindungs-Tabelle fehlt — Migration nicht gelaufen.', 'NOT_AVAILABLE');
        }

        $action = trim((string) ($arguments['action'] ?? ''));

        try {
            if ($action === 'get') {
                $slug = trim((string) ($arguments['slug'] ?? ''));
                if ($slug === '') {
                    return ToolResult::error('slug ist bei action=get Pflicht.', 'VALIDATION_ERROR');
                }

                return ToolResult::success($dienst->fuerDossier($team, $slug) + [
                    'nachfolger' => $dienst->nachfolgerVon($team, $slug),
                ]);
            }

            $von = trim((string) ($arguments['von'] ?? ''));
            $nach = trim((string) ($arguments['nach'] ?? ''));
            $art = trim((string) ($arguments['art'] ?? ''));
            if ($von === '' || $nach === '' || $art === '') {
                return ToolResult::error('von, nach und art sind bei set/delete Pflicht.', 'VALIDATION_ERROR');
            }

            if ($action === 'set') {
                $notiz = trim((string) ($arguments['notiz'] ?? '')) ?: null;

                return ToolResult::success($dienst->set($team, $von, $nach, $art, $notiz) + [
                    'hinweis' => $art === Wissensverbindung::ERSETZT
                        ? 'Zeigt eine Kanon-Zeile auf «'.$nach.'», nennt der Integritäts-Bericht jetzt «'.$von.'» als Nachfolger.'
                        : null,
                ]);
            }

            if ($action === 'delete') {
                return ToolResult::success(['geloest' => $dienst->remove($team, $von, $nach, $art)]);
            }

            return ToolResult::error('action muss get, set oder delete sein.', 'VALIDATION_ERROR');
        } catch (WissenGesperrtException $e) {
            return ToolResult::error($e->getMessage(), 'LOCKED');
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();

            return ToolResult::error($msg, str_contains($msg, 'Master-/Seed-Wissen') ? 'LOCKED' : 'VALIDATION_ERROR');
        }
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'knowledge_links', 'knowledge', 'wissen', 'verbindung', 'nachfolge'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'related_tools' => [
                'foodalchemist.knowledge_profil.GET', 'foodalchemist.knowledge_canon.PUT',
                'foodalchemist.knowledge.LIST',
            ],
            'examples' => [
                'Dossier A ersetzt Dossier B',
                'Welche Verbindungen hat regelwerk-basisrezepte-6-mengen-einheiten-yield?',
                'Was ist der Nachfolger des abgelösten Dossiers?',
            ],
        ];
    }
}
