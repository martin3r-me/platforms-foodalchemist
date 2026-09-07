<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\Knowledge\WissensVersorgungService;

/**
 * Spec 52 · A2 (MCP-Fläche) — welches Wissen erreicht welchen Prompt-Key.
 *
 * Warum dieses Tool existiert, obwohl es das Kommando `foodalchemist:wissen-versorgung` gibt:
 * **auf demo gibt es keine Shell.** Ein Bericht, den man nur am Terminal einer lokalen Sandbox
 * fahren kann, misst die falsche Umgebung — und genau das ist mir beim Bau passiert: die
 * ersten Zahlen (42 von 71 ungesteuert) kamen aus der Dev-MySQL, die dem Frisch-DB-Zustand
 * entspricht und mit demo nichts zu tun hat. Grundsatz E der Spec (alles in der UI einstellbar,
 * alles per MCP bedienbar) ist deshalb keine Kür, sondern die Bedingung dafür, dass eine
 * Messung überhaupt etwas über den Betrieb sagt.
 *
 * Die Rechnung liegt in {@see WissensVersorgungService} — Kommando und Tool teilen sie.
 *
 * Verdikt je Zeile:
 *   · `gesteuert`   — Kanon und/oder ein wirksames Routing greifen
 *   · `none`        — ausdrücklich leer geroutet (bewusste Entscheidung)
 *   · `nur-bindung` — nur über die Alt-Struktur versorgt (#469-Fallback)
 *   · `UNGESTEUERT` — aus dem Wissens-Korpus erreicht diesen Prompt NICHTS. Ein Befund.
 *
 * ⚠ `UNGESTEUERT` heisst nicht „der Prompt hat keine Regeln" — ein Prompt-Task kann Regeln im
 * Text tragen. Die Aussage ist: kein Dossier kommt an.
 */
class KnowledgeVersorgungGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.knowledge_versorgung.GET';
    }

    public function getDescription(): string
    {
        return 'Zeigt je Prompt-Key der KI-Registry, welches Wissen ihn TATSÄCHLICH erreicht: '
            . 'Kanon-Dossiers, Routing (feature × category, inkl. des hartkodierten Alt-Schlüssels '
            . 'wie ai_generate_recipe für recipe.generator), Bindungen der Alt-Struktur und die '
            . 'beiden Budgets. Verdikt je Zeile: gesteuert | none (bewusst leer) | nur-bindung | '
            . 'UNGESTEUERT (kein Dossier erreicht den Prompt — ein Befund). Nennt zusätzlich '
            . 'Routing-Features ohne jeden Aufrufer (konfigurierte Politik, die nichts steuert). '
            . 'Read-only. Ändern via knowledge_canon.PUT bzw. knowledge_routings.PUT.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'praefix' => ['type' => 'string', 'description' => 'optional: nur Keys dieses Bereichs (z. B. recipe, vk, gp, concept)'],
                'nur_befunde' => ['type' => 'boolean', 'description' => 'optional: nur ungesteuerte Keys zurückgeben'],
                'prompt_key' => ['type' => 'string', 'description' => 'optional: nur diese eine Registry-Zeile'],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        $dienst = app(WissensVersorgungService::class);
        $promptKey = trim((string) ($arguments['prompt_key'] ?? ''));

        if ($promptKey !== '') {
            if (! array_key_exists($promptKey, (array) config('foodalchemist.prompts', []))) {
                return ToolResult::error('Unbekannter prompt_key «'.$promptKey.'».', 'VALIDATION_ERROR');
            }

            return ToolResult::success(['zeile' => $dienst->zeileFuer($promptKey, $team)]);
        }

        $praefix = trim((string) ($arguments['praefix'] ?? ''));
        $bericht = $dienst->bericht($team, $praefix !== '' ? $praefix : null);

        if ((bool) ($arguments['nur_befunde'] ?? false)) {
            $bericht['zeilen'] = array_values(array_filter(
                $bericht['zeilen'],
                fn ($z) => $z['verdikt'] === 'UNGESTEUERT',
            ));
        }

        // Die Deutung mitgeben, nicht nur die Zahl: ein Agent, der „ungesteuert: 55" liest,
        // soll nicht raten, ob das schlimm ist.
        $bericht['hinweis'] = $bericht['ungesteuert'] > 0
            ? $bericht['ungesteuert'].' von '.$bericht['keys'].' Prompt-Keys erreicht KEIN Dossier aus dem '
                .'Wissens-Korpus (kein Kanon, kein Routing, keine Bindung). Regeln im Prompt-TEXT sind '
                .'davon unberührt — die prüft `foodalchemist:wissen-deckung`. Jede Zeile braucht eine '
                .'Entscheidung: Kanon-Zeile, Routing-Zeile oder ausdrücklich `none`.'
            : null;

        return ToolResult::success($bericht);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'knowledge', 'wissen', 'versorgung', 'routing', 'kanon', 'diagnose'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'related_tools' => [
                'foodalchemist.knowledge_canon.GET', 'foodalchemist.knowledge_routings.GET',
                'foodalchemist.knowledge_bindings.GET', 'foodalchemist.regelwerk.GET',
            ],
            'examples' => [
                'Welche Prompt-Keys bekommen gar kein Wissen?',
                'Was erreicht recipe.generator an Wissen?',
                'Zeig die Wissens-Versorgung aller gp-Prompts',
            ],
        ];
    }
}
