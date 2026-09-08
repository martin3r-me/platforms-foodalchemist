<?php

namespace Platform\FoodAlchemist\Tools;

use Illuminate\Support\Facades\DB;
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
                // Spec 52/A4: die alte Antwort verwies auf `ablauf.GET` — das liest aber
                // DENSELBEN Kanon und antwortet also genauso leer. Der Agent lief im Kreis und
                // hielt „schau woanders" für „es gibt etwas". Jetzt wird das Routing wirklich
                // aufgelöst, und wo nichts ist, steht `ungesteuert`.
                return ToolResult::success(
                    ['prompt_key' => $promptKey, 'role' => $role]
                    + $this->routingAuskunft($promptKey)
                );
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

    /**
     * Spec 52/A4 — was bekommt dieser Prompt-Key an Regelwerk, wenn KEIN Kanon hinterlegt ist?
     *
     * Zwei ehrliche Antworten statt einer Ausrede:
     *   · `routing` — eine Route greift; wir nennen Kategorie, Modus und Deckel, und dazu die
     *     Auswahl-Mechanik: `always` holt per `->first()` **genau EIN** Dossier (bei 61
     *     Regelwerks-Splits praktisch eine Zufallsauswahl), `discovery` rankt bis zu `max_docs`.
     *   · `ungesteuert` — nichts davon. Dann erreicht diesen Prompt kein Regelwerk, und das
     *     soll auch so dastehen.
     *
     * ★ `bindung` gab es bis Spec 52 · F2 als dritte Antwort. Der Gateway liest
     * `knowledge_bindings` nicht mehr — eine vorhandene Alt-Bindung ist also KEINE Versorgung
     * und darf hier nicht als Quelle erscheinen. Sie wird stattdessen als Ballast erwähnt,
     * damit der Befund „ungesteuert" nicht wie ein Widerspruch zum Wissens-Browser wirkt, der
     * die Zeile weiter anzeigt.
     *
     * @return array<string, mixed>
     */
    private function routingAuskunft(string $promptKey): array
    {
        $routingKey = KnowledgeContextService::routingFeatureFuer($promptKey);
        $bereich = str_contains($promptKey, '.') ? explode('.', $promptKey, 2)[0] : $promptKey;

        $route = DB::table('foodalchemist_knowledge_routings')
            ->where('feature', $routingKey)->where('category', 'regelwerk')
            ->first(['mode', 'max_docs', 'max_chars_per_doc']);

        if ($route !== null && (string) $route->mode !== 'none') {
            $mode = (string) $route->mode;

            return [
                'quelle' => 'routing',
                'dokumente' => [],
                'routing' => [
                    'feature' => $routingKey,
                    'alt_schluessel' => $routingKey !== $promptKey,
                    'kategorie' => 'regelwerk',
                    'mode' => $mode,
                    'max_docs' => $route->max_docs !== null ? (int) $route->max_docs : null,
                    'max_chars_per_doc' => $route->max_chars_per_doc !== null ? (int) $route->max_chars_per_doc : null,
                ],
                'hinweis' => $mode === 'always'
                    ? 'Kein Kanon. Das Routing lädt `regelwerk` als `always` — und das holt per '
                        . '`->first()` genau EIN Dossier. Bei vielen §-Splits ist das keine Auswahl, '
                        . 'sondern ein Zufall. Eine Kanon-Zeile ist hier die verlässliche Antwort.'
                    : 'Kein Kanon. Das Routing lädt `regelwerk` per `discovery`, also bis zu '
                        . (string) ((int) ($route->max_docs ?? 0)) . ' Dossier(s) nach Relevanz zur '
                        . 'Aufgabenbeschreibung — welche das sind, entscheidet die Suche pro Aufruf.',
            ];
        }

        // Alt-Bindungen NUR noch erwähnen, nie als Quelle ausgeben. Wer sie im Wissens-Browser
        // stehen sieht, soll hier lesen, warum sie trotzdem nichts liefern.
        $altBindungen = DB::table('foodalchemist_knowledge_bindings as b')
            ->join('foodalchemist_knowledge_documents as d', 'd.id', '=', 'b.knowledge_document_id')
            ->whereNull('b.deleted_at')->where('b.active', 1)->where('b.binding_type', 'layer')
            ->whereIn('b.target_key', array_unique([$promptKey, $bereich]))
            ->where('d.active', 1)->whereNull('d.deleted_at')
            ->pluck('d.slug')->map(fn ($x) => (string) $x)->all();

        return [
            'quelle' => 'ungesteuert',
            'dokumente' => [],
            'alt_bindungen' => $altBindungen,
            'hinweis' => 'Diesen Prompt-Key erreicht KEIN Regelwerk: kein Kanon, kein Routing. Das ist '
                . 'ein Befund, kein Normalzustand — `foodalchemist:wissen-versorgung` listet alle solchen '
                . 'Keys.'
                . ($altBindungen !== []
                    ? ' Es hängen zwar noch ' . count($altBindungen) . ' Alt-Bindung(en) daran ('
                        . implode(', ', $altBindungen) . '), die wirken seit Spec 52 aber NICHT mehr — '
                        . 'der Gateway liest die Tabelle nicht. Verbindlich machen: `knowledge_canon.PUT`.'
                    : ''),
        ];
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
