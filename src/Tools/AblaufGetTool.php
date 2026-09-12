<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\VorgangsRegisterService;

/**
 * Spec 50 · E-3 — „Wie geht das hier?" als Werkzeug.
 *
 * Bis hierher musste ein Agent den Ablauf raten oder mit dem richtigen Suchbegriff auf ein
 * Workflow-Dossier stossen. Die Kategorie `workflow` hat kein Routing — kein Generator lädt
 * sie, keine Kaskade zieht sie. Dieses Tool ist der Abholpunkt, den die Spec dafür vorsieht.
 *
 * Ohne `vorgang` listet es die verfügbaren Vorgänge — der Einstieg, wenn der Agent den
 * passenden Code noch nicht kennt.
 */
class AblaufGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.ablauf.GET';
    }

    public function getDescription(): string
    {
        return 'Das Vorgangs-Register: wie ein Vorgang im Food Alchemist abläuft — Einstiegs-Tool, '
            . 'Werkzeug-Kette, Soll-Aspekte (woran das Ergebnis gemessen wird), geltende Regelwerke '
            . 'und das Workflow-Dossier mit Anti-Patterns. VOR dem Anlegen von Rezept, Gericht, Konzept, '
            . 'Foodbook, Angebot, Speiseplan, Speisekarte, Format oder GP aufrufen. Ohne vorgang: Liste aller Vorgänge. '
            . 'Mit mit_wissen=true kommen die Kanon-Dossiers als VOLLTEXT mit — ein Aufruf statt einem je Dossier.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'vorgang' => [
                    'type' => 'string',
                    'enum' => array_keys(VorgangsRegisterService::VORGAENGE),
                    'description' => 'Vorgangs-Code. Weglassen für die Liste aller Vorgänge.',
                ],
                'mit_wissen' => [
                    'type' => 'boolean',
                    'description' => 'true = die VOLLTEXTE der verbindlichen Kanon-Dossiers gleich mitliefern, '
                        . 'statt nur ihre Titel. Spart den Umweg über einzelne knowledge.GET-Aufrufe und gibt dir '
                        . 'dasselbe Pflichtwissen, das der Generator auf diesem Weg automatisch bekommt. '
                        . 'Ohne den Schalter arbeitest du auf eigene Faust.',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        $svc = app(VorgangsRegisterService::class);
        $code = trim((string) ($arguments['vorgang'] ?? ''));

        if ($code === '') {
            return ToolResult::success([
                'vorgaenge' => $svc->liste(),
                'hinweis' => 'Mit vorgang=<code> den vollen Ablauf holen.',
            ]);
        }

        if (! $svc->kennt($code)) {
            return ToolResult::error(
                'Unbekannter Vorgang «' . $code . '». Bekannt: ' . implode(', ', array_keys(VorgangsRegisterService::VORGAENGE)) . '.',
                'VALIDATION_ERROR'
            );
        }

        $vorgang = $svc->vorgang($code, $team);
        $mitWissen = (bool) ($arguments['mit_wissen'] ?? false);
        $dokumente = $vorgang['regelwerke']['dokumente'] ?? [];

        if ($mitWissen && $dokumente !== []) {
            // Volltexte ueber denselben Dienst, den knowledge.GET benutzt — kein zweiter Lesepfad,
            // keine eigene Sichtbarkeitsregel.
            $texte = \Illuminate\Support\Facades\DB::table('foodalchemist_knowledge_documents')
                ->whereIn('slug', array_column($dokumente, 'slug'))
                ->whereNull('deleted_at')->where('active', 1)
                ->pluck('content_md', 'slug');
            $vorgang['regelwerke']['dokumente'] = array_map(
                fn (array $d) => $d + ['text' => $texte[$d['slug']] ?? null],
                $dokumente
            );
        }

        $hinweis = 'soll_aspekte nennt die Lücken-Codes, die reife.GET an einem konkreten Objekt meldet — '
            . 'wie: null heisst, es gibt kein Werkzeug dafür (nicht: es sei egal).';

        // ★ Der Hinweis muss im ERGEBNIS stehen, nicht nur im Schema. Gemessen am 12.09.2026:
        // ein Agent bekam hier 13 Kanon-Dossiers samt Titeln und Zeichenzahl aufgelistet, las
        // davon ZWEI und baute den Rest aus dem Kopf — während der Generator auf demselben
        // Vorgang alle 13 automatisch im Prompt hat. Die Liste allein bewirkt nichts: 13 Titel
        // zu lesen kostete 13 Aufrufe, und nichts machte das bequem oder verbindlich.
        // Eine Fähigkeit, von der man erst im Schema erfährt, ist keine.
        if (! $mitWissen && $dokumente !== []) {
            $zeichen = array_sum(array_column($dokumente, 'zeichen'));
            $hinweis = sprintf(
                '⚠ %d VERBINDLICHE Kanon-Dossiers (%s Zeichen) gehören zu diesem Vorgang — hier stehen nur '
                . 'ihre Titel. Genau dieses Wissen bekommt der Generator auf diesem Weg automatisch in den '
                . 'Prompt. Hol es dir mit ablauf.GET(vorgang="%s", mit_wissen=true) in EINEM Aufruf, bevor '
                . 'du etwas anlegst. ',
                count($dokumente), number_format($zeichen, 0, ',', '.'), $code
            ) . $hinweis;
        }

        return ToolResult::success($vorgang + ['hinweis' => $hinweis]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'ablauf', 'workflow', 'vorgang', 'anleitung'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => false, 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.regelwerk.GET', 'foodalchemist.reife.GET', 'foodalchemist.knowledge.GET'],
            'examples' => [
                'Wie lege ich ein Gericht an?',
                'Welche Vorgänge kennst du?',
                'Was gehört zu einem vollständigen Konzept?',
            ],
        ];
    }
}
