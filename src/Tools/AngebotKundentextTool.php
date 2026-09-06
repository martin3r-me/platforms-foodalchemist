<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistAngebot;
use Platform\FoodAlchemist\Models\FoodAlchemistOfferChapter;
use Platform\FoodAlchemist\Services\AngebotService;

/**
 * Spec 50 · C-7 — KI-Kundentext fürs Angebot, auf Foodbook-Niveau.
 *
 * {@see AngebotService::kiKundentextVorschlag()} und `kiKapitelKundentextVorschlag()` gab es
 * schon, erreichbar waren sie aber nur über den Livewire-Editor. Das Foodbook hat für beide
 * Ebenen je ein MCP-Tool; am Angebot fehlten sie — ein Agent konnte ein Angebot bauen, aber
 * seinen Kundentext nicht vorschlagen lassen.
 *
 * Ein Tool für beide Ebenen, weil es dieselbe Wahrheit ist: `chapter_id` gesetzt ⇒
 * Kapitel-Text, sonst Angebots-Text. Persistiert nichts — Übernehmen bleibt ein bewusster
 * Akt über `angebote.PUT` bzw. `offer_chapter.PUT`.
 */
class AngebotKundentextTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.angebot.KUNDENTEXT_GENERATE';
    }

    public function getDescription(): string
    {
        return 'Erzeugt einen KI-Kundentext-Vorschlag fürs Angebot: mit chapter_id für ein Kapitel, '
            . 'sonst für das ganze Angebot (offer_id). Persistiert nichts — gibt Text + Konfidenz '
            . 'zurück; Übernehmen via offer_chapter.PUT (description) bzw. angebote.PUT.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'offer_id' => ['type' => 'integer', 'description' => 'Angebot-Id (team-eigen). Ohne chapter_id: Text für das ganze Angebot.'],
                'chapter_id' => ['type' => 'integer', 'description' => 'Optional: Kapitel-Id — dann Text für dieses Kapitel.'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $chapterId = (int) ($arguments['chapter_id'] ?? 0);
        $offerId = (int) ($arguments['offer_id'] ?? 0);
        if ($chapterId <= 0 && $offerId <= 0) {
            return ToolResult::error('offer_id oder chapter_id angeben.', 'VALIDATION_ERROR');
        }

        $svc = app(AngebotService::class);
        try {
            if ($chapterId > 0) {
                if (($guard = $this->guardOwned($team, FoodAlchemistOfferChapter::class, $chapterId, 'Kapitel')) !== null) {
                    return $guard;
                }
                $res = $svc->kiKapitelKundentextVorschlag($team, $chapterId);
                $bezug = ['chapter_id' => $chapterId];
            } else {
                if (($guard = $this->guardOwned($team, FoodAlchemistAngebot::class, $offerId, 'Angebot')) !== null) {
                    return $guard;
                }
                $res = $svc->kiKundentextVorschlag($team, $offerId);
                $bezug = ['offer_id' => $offerId];
            }
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        // Der Service faengt Provider-Fehler ab und liefert dann einen leeren Text
        // (AngebotService: „OHNE gebundenen Provider NIE werfen"). Das hier ehrlich melden,
        // statt einen leeren String als Ergebnis auszugeben.
        if (trim((string) ($res['text'] ?? '')) === '') {
            return ToolResult::error(
                'Kein Textvorschlag entstanden — meist fehlt ein gebundener KI-Provider für den Prompt foodbook.kundentext.',
                'NO_RESULT'
            );
        }

        return ToolResult::success($bezug + [
            'text' => $res['text'],
            'confidence' => $res['confidence'] ?? null,
            'call_log_id' => $res['call_log_id'] ?? null,
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'angebot', 'kundentext', 'ki'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'llm',
            'side_effects' => [],
            'related_tools' => ['foodalchemist.angebote.PUT', 'foodalchemist.offer_chapter.PUT'],
            'examples' => ['Schlag einen Kundentext für Angebot 8 vor.', 'Schlag einen Kundentext für Kapitel 21 vor.'],
        ];
    }
}
