<?php

namespace Platform\FoodAlchemist\Tools;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistOfferChapter;
use Platform\FoodAlchemist\Services\OfferCompositionService;

/**
 * #380 Composer · MCP-Lockstep: ein Angebot-Kapitel bearbeiten (Titel/Kundentitel/Claim/Text/
 * Preis-Modus/Per-Kapitel-Pax). Spiegelt FoodbookBlocksPutTool (felder-Allow-List), die
 * Struktur-/Preis-Wahrheit bleibt im {@see OfferCompositionService::updateKapitel}.
 * `format_price_mode` (Spec 85 D-2, nur Format-Kapitel) läuft über
 * {@see OfferCompositionService::setFormatPriceMode} — gleiche Eigentums-Prüfung, eine Transaktion.
 */
class OfferChapterPutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    /** Allow-List (Teilmenge von OfferCompositionService::KAPITEL_FELDER; der Service intersect't final). */
    private const FELDER = ['title', 'consumer_title', 'claim', 'description', 'price_mode', 'price_per_person',
        'personen', 'serving_form_id', 'service_moment_id', 'writing_style_id', 'is_struktur', 'creative_mode',
        'target_count', 'price_anchor', 'price_min', 'price_max', 'target_food_cost_pct'];

    public function getName(): string
    {
        return 'foodalchemist.offer_chapter.PUT';
    }

    public function getDescription(): string
    {
        return 'Bearbeitet ein Kapitel eines team-eigenen Angebots (felder: title, consumer_title, claim, description, '
            . 'price_mode [auto|manuell], price_per_person, personen; nur bei Format-Kapiteln: format_price_mode '
            . '[additiv|alternativen] — additiv = Summe aller Teile, alternativen = Gast wählt eine Variante, Preis als Spanne). '
            . 'Nur bekannte Felder werden übernommen.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'chapter_id' => ['type' => 'integer', 'description' => 'Kapitel-Id.'],
                'felder' => ['type' => 'object', 'description' => 'Zu ändernde Felder (Allow-List).'],
            ],
            'required' => ['chapter_id', 'felder'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $felder = $arguments['felder'] ?? null;
        if (! is_array($felder) || $felder === []) {
            return ToolResult::error('felder muss ein nicht-leeres Objekt sein.', 'VALIDATION_ERROR');
        }
        $in = array_intersect_key($felder, array_flip(self::FELDER));
        $formatModus = array_key_exists('format_price_mode', $felder) ? (string) $felder['format_price_mode'] : null;
        if ($in === [] && $formatModus === null) {
            return ToolResult::error('Keine bekannten Felder in felder.', 'VALIDATION_ERROR');
        }
        $chapterId = (int) ($arguments['chapter_id'] ?? 0);
        if (($guard = $this->guardOwned($team, FoodAlchemistOfferChapter::class, $chapterId, 'Kapitel')) !== null) {
            return $guard;
        }

        $comp = app(OfferCompositionService::class);
        try {
            DB::transaction(function () use ($comp, $team, $chapterId, $in, $formatModus) {
                if ($formatModus !== null) {   // zuerst: lehnt der Service ab (kein Format-Kapitel), wird nichts geschrieben
                    $comp->setFormatPriceMode($team, $chapterId, $formatModus);
                }
                if ($in !== []) {
                    $comp->updateKapitel($team, $chapterId, $in);
                }
            });
        } catch (ModelNotFoundException) {
            return ToolResult::error('Kapitel nicht sichtbar/vorhanden.', 'NOT_FOUND');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        $updated = array_keys($in);
        if ($formatModus !== null) {
            $updated[] = 'format_price_mode';
        }

        return ToolResult::success(['chapter_id' => $chapterId, 'updated' => $updated]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'angebot', 'composer', 'kapitel', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.offer_chapter.POST', 'foodalchemist.offer_chapter.DELETE'],
            'examples' => ['Ändere bei Kapitel 12 den Kundentitel auf „Unser Menü".'],
        ];
    }
}
