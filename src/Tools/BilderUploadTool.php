<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistAngebot;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\AngebotService;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\FoodAlchemistMediaService;
use Platform\FoodAlchemist\Services\PresentationDesignService;
use Platform\FoodAlchemist\Services\RecipeService;
use Platform\FoodAlchemist\Tools\Concerns\DateiAusArgumenten;

/**
 * Bild per MCP hochladen (Datei als Base64 oder URL) und direkt zuweisen: Angebots-Cover, Angebots-Logo,
 * Gericht-Foto, Konzept-Titelbild — oder frei für einen image-Block im Präsentations-Design (liefert context_file_id).
 * Gleicher Schreibpfad wie die Oberfläche (Service → FoodAlchemistMediaService → Core ContextFiles).
 */
class BilderUploadTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    use DateiAusArgumenten;

    private const ZIELE = ['angebot_cover', 'angebot_logo', 'angebot_bild', 'gericht_foto', 'konzept_bild', 'design_block'];

    public function getName(): string
    {
        return 'foodalchemist.bilder.UPLOAD';
    }

    public function getDescription(): string
    {
        return 'Lädt ein Bild hoch (datei.base64 – auch data-URL – ODER datei.url, max. 15 MB, nur Bilder) und weist es zu. '
            . 'ziel: angebot_cover / angebot_logo (id = Angebots-Id; erscheint im Cover der Angebots-Präsentation nach erneutem Veröffentlichen), '
            . 'angebot_bild (id = Angebots-Id, nummer 1–3; erscheint in image-Blöcken des Designs mit style.quelle=angebot_<nummer>), '
            . 'gericht_foto (id = Gericht-/Rezept-Id; sichtbar mit Design-Option show_dish_photos, auch im Angebot), konzept_bild (id = Konzept-Id), '
            . 'design_block (ohne id; liefert context_file_id + path für einen image-Block in presentation_designs layout_json: style{context_file_id, path}). '
            . 'Ersetzt ein vorhandenes Bild am Ziel.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'ziel' => ['type' => 'string', 'enum' => self::ZIELE, 'description' => 'Wohin das Bild gehört.'],
                'id' => ['type' => 'integer', 'description' => 'Angebots-/Gericht-/Konzept-Id (nicht bei design_block).'],
                'nummer' => ['type' => 'integer', 'enum' => [1, 2, 3], 'description' => 'Nur bei angebot_bild: Präsentationsbild 1, 2 oder 3.'],
                'datei' => [
                    'type' => 'object',
                    'description' => 'Bilddatei: base64 (auch data:image/…;base64,…) ODER url (http/https), dazu optional name.',
                    'properties' => [
                        'base64' => ['type' => 'string'],
                        'url' => ['type' => 'string'],
                        'name' => ['type' => 'string', 'description' => 'Dateiname, z. B. cover.jpg'],
                    ],
                ],
            ],
            'required' => ['ziel', 'datei'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $ziel = (string) ($arguments['ziel'] ?? '');
        if (! in_array($ziel, self::ZIELE, true)) {
            return ToolResult::error('ziel muss eines von ' . implode('|', self::ZIELE) . ' sein.', 'VALIDATION_ERROR');
        }
        $id = (int) ($arguments['id'] ?? 0);
        if ($ziel !== 'design_block' && $id <= 0) {
            return ToolResult::error('id ist für ziel=' . $ziel . ' Pflicht.', 'VALIDATION_ERROR');
        }
        $guard = match ($ziel) {
            'angebot_cover', 'angebot_logo', 'angebot_bild' => $this->guardOwned($team, FoodAlchemistAngebot::class, $id, 'Angebot'),
            'gericht_foto' => $this->guardOwned($team, FoodAlchemistRecipe::class, $id, 'Gericht'),
            'konzept_bild' => $this->guardOwned($team, FoodAlchemistConcept::class, $id, 'Konzept'),
            default => null,
        };
        if ($guard !== null) {
            return $guard;
        }

        try {
            $datei = $this->dateiAusArgumenten(is_array($arguments['datei'] ?? null) ? $arguments['datei'] : null);
            if ($datei === null) {
                return ToolResult::error('datei.base64 oder datei.url angeben.', 'VALIDATION_ERROR');
            }
            if (! str_starts_with((string) $datei->getMimeType(), 'image/')) {
                return ToolResult::error('Nur Bilddateien (image/*) erlaubt, erkannt: ' . $datei->getMimeType() . '.', 'VALIDATION_ERROR');
            }

            $nummer = (int) ($arguments['nummer'] ?? 0);
            if ($ziel === 'angebot_bild' && ($nummer < 1 || $nummer > 3)) {
                return ToolResult::error('nummer (1–3) ist für ziel=angebot_bild Pflicht.', 'VALIDATION_ERROR');
            }
            [$contextFileId, $pfad] = match ($ziel) {
                'angebot_bild' => (function () use ($team, $id, $nummer, $datei) {
                    $m = app(AngebotService::class)->storePraesentationsBild($team, $id, $nummer, $datei);

                    return [$m['context_file_id'], $m['path']];
                })(),
                'angebot_cover' => $this->angebotBild($team, $id, fn () => app(AngebotService::class)->storeCover($team, $id, $datei), 'cover_context_file_id'),
                'angebot_logo' => $this->angebotBild($team, $id, fn () => app(AngebotService::class)->storeLogo($team, $id, $datei), 'logo_context_file_id'),
                'gericht_foto' => $this->nachSpeichern(fn () => app(RecipeService::class)->storeDishImage($team, $id, $datei),
                    fn () => FoodAlchemistRecipe::whereKey($id)->value('image_context_file_id')),
                'konzept_bild' => $this->nachSpeichern(fn () => app(ConceptService::class)->storeImage($team, $id, $datei),
                    fn () => FoodAlchemistConcept::whereKey($id)->value('image_context_file_id')),
                'design_block' => (function () use ($team, $datei) {
                    $m = app(PresentationDesignService::class)->storeBlockImage($team, $datei);

                    return [$m['context_file_id'], $m['path']];
                })(),
            };
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return ToolResult::error('Ziel nicht gefunden.', 'NOT_FOUND');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        } finally {
            $this->dateiAufraeumen();
        }

        return ToolResult::success(array_filter([
            'ziel' => $ziel,
            'id' => $id ?: null,
            'context_file_id' => $contextFileId !== null ? (int) $contextFileId : null,
            'path' => $pfad,
            'url' => app(FoodAlchemistMediaService::class)->url($contextFileId !== null ? (int) $contextFileId : null, $pfad),
            'hinweis' => in_array($ziel, ['angebot_cover', 'angebot_logo', 'angebot_bild'], true)
                ? 'Im öffentlichen Link sichtbar nach erneutem Veröffentlichen (foodalchemist.angebot_presentation.PUBLISH).' : null,
        ], fn ($v) => $v !== null));
    }

    /** @return array{0:?int,1:string} */
    private function angebotBild(\Platform\Core\Models\Team $team, int $id, \Closure $speichern, string $contextSpalte): array
    {
        $pfad = $speichern();

        return [FoodAlchemistAngebot::whereKey($id)->value($contextSpalte), $pfad];
    }

    /** @return array{0:?int,1:string} */
    private function nachSpeichern(\Closure $speichern, \Closure $contextId): array
    {
        $pfad = $speichern();

        return [$contextId(), $pfad];
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'bild', 'upload', 'cover', 'logo', 'foto', 'praesentation', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'external_io',
            'side_effects' => ['creates', 'updates'],
            'related_tools' => ['foodalchemist.angebot_presentation.PUBLISH', 'foodalchemist.presentation_designs.PUT'],
            'examples' => ['Setze https://…/cover.jpg als Cover von Angebot 2.', 'Lade das Logo als Angebots-Logo für Angebot 2 hoch.'],
        ];
    }
}
