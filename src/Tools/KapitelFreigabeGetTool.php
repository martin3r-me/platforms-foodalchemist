<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Services\IdeenService;
use Platform\FoodAlchemist\Services\LeitstelleService;
use Platform\FoodAlchemist\Services\TeamSettingsService;

/**
 * Spec 19 „Foodbook-Leitstelle" (E7.6): der Anlage-Stand eines Kapitels lesen —
 * READ-ONLY. Spiegelt das Anlage-Modal (E7.5) für die KI-Führung: (a) was der
 * Kapitel-Go stempeln WÜRDE (aufgelöster Kontext = leitplanken()+kapitelZiele(),
 * bit-identisch zu {@see FoodbookService::kapitelFreigeben}), (b) eine Trocken-
 * Vorschau, was er aus den Entwurf-Skizzen materialisieren würde (Paket-Gruppen →
 * Konzepte, Einzel-Bestand → recipe_ref-Blöcke, Freitext → KI-Queue), und (c) den
 * aktuellen Anlage-Stand (released_*, Undo-Fenster).
 *
 * KAPITEL-GO OHNE MCP-TRIGGER (Spec 19 MCP-Lockstep): das „Anlegen" selbst ist
 * human-only — dieses Tool liest nur, es materialisiert NICHTS.
 */
class KapitelFreigabeGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.kapitel_freigabe.GET';
    }

    public function getDescription(): string
    {
        return 'Den Anlage-Stand eines Kapitels lesen (Spec 19 Kapitel-Go „Anlegen"). READ-ONLY. Liefert: '
            . 'stempel (was der Go stempeln würde — Niveau, Zielgruppen, Servierform, Eventtyp, Einsatzmomente; '
            . 'aufgelöst über die Ziele-Kaskade), vorschau (Trockenlauf: pakete[] mit Bestand-/Freitext-Zählung, '
            . 'einzel_bestand → recipe_ref-Blöcke, freitext_einzel → KI-Queue), anlage_stand (released/released_at/'
            . 'released_by/release_note/release_result + undo_moeglich). Das Kapitel-Go („Anlegen") ist human-only '
            . 'und läuft NICHT über MCP — dieses Tool materialisiert nichts.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'chapter_id' => ['type' => 'integer', 'description' => 'ID des Kapitels (team-sichtbar).'],
            ],
            'required' => ['chapter_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        $kapitel = FoodAlchemistFoodbookKapitel::visibleToTeam($team)->find((int) $arguments['chapter_id']);
        if ($kapitel === null) {
            return ToolResult::error('Kapitel nicht gefunden oder nicht team-sichtbar.', 'NOT_FOUND');
        }

        $foodbooks = app(FoodbookService::class);
        $fb = $kapitel->foodbook;

        // ── Stempel-Kontext: bit-identisch zur Auflösung in kapitelFreigeben ──
        $leit = $foodbooks->leitplanken($team, $fb, null, $kapitel);
        $ziele = $foodbooks->kapitelZiele($team, $kapitel);
        $momentIds = $ziele['service_moment_id'] !== null ? [(int) $ziele['service_moment_id']] : $leit['service_moment_ids'];
        $stempel = [
            'niveau' => TeamSettingsService::denormNiveauFuerConcept($ziele['niveau'] ?? $leit['niveau']),
            'zielgruppen' => array_values($leit['zielgruppen']),
            'serving_form_id' => $ziele['serving_form_id'] !== null ? (int) $ziele['serving_form_id'] : null,
            'event_type_id' => $leit['event_type_id'] !== null ? (int) $leit['event_type_id'] : null,
            'service_moment_ids' => array_values(array_map(static fn ($m) => (int) $m, $momentIds)),
        ];

        // ── Trockenlauf-Vorschau: nur ENTWURF-Skizzen (genau die verarbeitet der Go) ──
        $liste = app(IdeenService::class)->liste($team, (int) $kapitel->id, null, false);
        $entwurf = static fn ($ideen, bool $bestand) => $ideen
            ->where('status', 'entwurf')
            ->filter(static fn ($i) => $bestand ? $i->sales_recipe_id !== null : $i->sales_recipe_id === null)
            ->count();

        $pakete = [];
        foreach ($liste['gruppen'] as $g) {
            $gr = $g['gruppe'];
            $pakete[] = [
                'gruppe_id' => (int) $gr->id,
                'name' => (string) ($gr->name ?? 'Paket'),
                'target_price_pp' => $gr->target_price_pp !== null ? (float) $gr->target_price_pp : null,
                'bestand' => $entwurf($g['ideen'], true),   // → Konzept-Slots
                'freitext' => $entwurf($g['ideen'], false), // → KI-Queue im Konzept
            ];
        }
        $einzelBestand = $entwurf($liste['einzel'], true);  // → recipe_ref-Blöcke
        $freitextEinzel = $entwurf($liste['einzel'], false); // → KI-Queue

        $summeSkizzen = $einzelBestand + $freitextEinzel
            + array_sum(array_map(static fn ($p) => $p['bestand'] + $p['freitext'], $pakete));

        // ── Aktueller Anlage-Stand (+ Undo-Fenster wie E7.5 / LeitstelleRail) ──
        $undoMoeglich = $kapitel->released_at !== null
            && $kapitel->snapshot_at === null
            && $kapitel->status !== 'sent';

        return ToolResult::success([
            'kapitel' => [
                'id' => (int) $kapitel->id,
                'titel' => (string) $kapitel->title,
                'foodbook_id' => (int) $kapitel->foodbook_id,
                'depth' => app(LeitstelleService::class)->kapitelStand($team, $kapitel)['depth'],
            ],
            'stempel' => $stempel,
            'vorschau' => [
                'pakete' => $pakete,
                'einzel_bestand' => $einzelBestand,
                'freitext_einzel' => $freitextEinzel,
                'summe_skizzen' => $summeSkizzen,
            ],
            'anlage_stand' => [
                'released' => $kapitel->released_at !== null,
                'released_at' => $kapitel->released_at?->toIso8601String(),
                'released_by' => $kapitel->released_by !== null ? (int) $kapitel->released_by : null,
                'release_note' => $kapitel->release_note,
                'release_result' => $kapitel->release_result,
                'undo_moeglich' => $undoMoeglich,
            ],
            'hinweis' => 'Kapitel-Go („Anlegen") ist human-only — dieses Tool liest nur, es materialisiert nichts.',
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'foodbook', 'kapitel', 'freigabe', 'anlage', 'leitstelle', 'vorschau'],
            'read_only' => true,
            'idempotent' => true,
            'risk_level' => 'safe',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.leitstelle.GET', 'foodalchemist.kapitel_ideen.GET', 'foodalchemist.foodbook_kapitel.PUT'],
            'examples' => ['Was würde die Anlage von Kapitel 40 erzeugen?', 'Ist Kapitel 12 schon angelegt und kann ich es noch zurückziehen?'],
        ];
    }
}
