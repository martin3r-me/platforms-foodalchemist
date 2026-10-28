<?php

namespace Platform\FoodAlchemist\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\GpNamingService;

/**
 * Spec 16·S4 — On-demand-Klassifikation EINES getroffenen Lieferantenartikels.
 *
 * Anlass: 93 % der WG-Lead-Kataloge sind unklassifiziert (nur 6,7 % strukturiert).
 * Der Finder (S2) matcht rein lexikalisch auf der rohen designation und braucht
 * KEINE Klassifikation — dieser Job ist die Lernschleife DANACH: er reichert den
 * einen tatsächlich gemintet/getroffenen LA nach, damit dieselbe Zutat beim
 * nächsten Mal strukturiert (WG/Hauptzutat/Zustand) vorliegt.
 *
 * Doktrin (E3, Dominique 2026-07-20): ASYNC. Der Mint/das Grounding blockiert NIE
 * an der KI — der Job wird nach dem Mint dispatched, nie inline. Idempotent
 * (bereits klassifiziert ⇒ skip). Ein roter LLM-Call ist ein No-op, kein Absturz
 * (BulkEnrich-Philosophie). Provider-gated: ohne Live-LLM passiert nichts Falsches.
 *
 * Reuse: Feld-Ableitung über den bestehenden Prompt `gp.suggest`
 * (= WaWi-105-Spiegel: hauptzutat/condition/processing/form/pflichtangabe).
 */
class ClassifyLaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, \Platform\FoodAlchemist\Support\MitKiVerursacher;

    public int $timeout = 120;

    /** Confidence-Schwelle unter der ein Treffer in die Review-Queue geht. */
    private const REVIEW_FLOOR = 0.70;

    public function __construct(
        public int $supplierItemId,
        public int $teamId,
    ) {
    }

    public function handle(AiGatewayService $ki, GpNamingService $naming): void
    {
        $team = Team::find($this->teamId);
        if ($team === null) {
            return;
        }

        $la = FoodAlchemistSupplierItem::find($this->supplierItemId);
        if ($la === null) {
            return;
        }

        $struktur = FoodAlchemistSupplierItemStructure::where('supplier_item_id', $la->id)
            ->whereNull('deleted_at')->first();

        // Idempotent: schon klassifiziert → nichts tun (skip).
        if ($struktur !== null && $struktur->classified_at !== null) {
            return;
        }

        // Non-Food bekommt keine Food-Struktur (Zustand/Verarbeitung/Hauptzutat) — der Klassifikator würde sie erfinden.
        if (app(\Platform\FoodAlchemist\Services\NonFoodService::class)->istNonFood($la->id)) {
            return;
        }

        $designation = trim((string) $la->designation);
        if ($designation === '') {
            return;
        }

        try {
            $vorschlag = $ki->propose('gp.suggest', ['label' => $designation]);
        } catch (\Throwable $e) {
            return;   // roter/deaktivierter LLM-Call = No-op, kein Absturz (async, unkritisch)
        }

        $werte = $vorschlag->werte;
        // „unbekannt" & Co. sind kein Wert (Regelwerk GP §6, §12): bleibt leer
        $hauptzutat = $naming->bereinigeFeld($werte['hauptzutat'] ?? null);
        $flavor = $naming->bereinigeFeld($werte['flavor'] ?? null);
        $zusatz = $naming->zusatzAusArtikel($werte['zusatz'] ?? null, [$designation, $la->marketing_name ?? null],
            app(\Platform\FoodAlchemist\Services\Marken\MarkeAusArtikel::class)->fuer($la)->marke);   // §6.6: nur exakt aus dem Artikel; §5 Ausn. 3: Marke aus MarkeAusArtikel
        if (trim((string) ($werte['commodity_group_code'] ?? '')) === '15') {
            // Getränke (Dominique 28.10.): Marke als Klammer-Zusatz statt in der Hauptzutat
            $marke = app(\Platform\FoodAlchemist\Services\Marken\MarkeAusArtikel::class)->fuer($la)->marke;
            ['hauptzutat' => $hauptzutat, 'zusatz' => $zusatz] = $naming->getraenkFelder(['hauptzutat' => $hauptzutat, 'zusatz' => $zusatz], $marke, [$designation, $la->marketing_name ?? null]);
        }
        $condition = $naming->bereinigeFeld($werte['condition'] ?? null);
        $processing = $naming->bereinigeFeld($werte['processing'] ?? null);
        $form = $naming->bereinigeFeld($werte['form'] ?? null);
        $pflichtangabe = $naming->bereinigeFeld($werte['pflichtangabe'] ?? null);

        if ($hauptzutat === '') {
            return;   // kein verwertbares Klassifikat → lieber gar nicht schreiben
        }

        $confidence = (float) $vorschlag->confidence;
        // §8-Pflichtangabe fehlt ODER geringe Confidence → Review-Queue (WaWi-105-Logik).
        $needsReview = $confidence < self::REVIEW_FLOOR || $pflichtangabe === '';

        FoodAlchemistSupplierItemStructure::updateOrCreate(
            ['supplier_item_id' => $la->id],   // gp_id NICHT überschreiben (LA→GP-Link aus dem Mint bleibt)
            [
                'team_id' => $la->team_id,
                'is_food' => true,   // ein realer Lieferanten-Lebensmittelartikel
                'main_ingredient_slug' => $naming->slugify($hauptzutat),
                'main_ingredient_display' => $hauptzutat,
                'main_ingredient_confidence' => $confidence,
                'flavor' => $flavor !== '' ? $flavor : null,
                'zusatz' => $zusatz !== '' ? $zusatz : null,
                'condition' => $condition !== '' ? $condition : null,
                'processing' => $processing !== '' ? $processing : null,
                'form' => $form !== '' ? $form : null,
                'classifier' => 'fa.gp_suggest',
                'classifier_version' => 'spec16.s4.v1',
                'classified_at' => now(),
                'needs_review' => $needsReview,
                'review_reason' => $needsReview
                    ? ($pflichtangabe === '' ? 'fehlt_§8_pflichtangabe' : 'low_confidence')
                    : null,
                'ai_reasoning' => $vorschlag->reasoning,
            ],
        );
    }

    /**
     * 22·H3b · V-054 (Queue-Pfad): kein Lauf, kein Statusfeld — der LA bleibt einfach
     * unklassifiziert, und das ist fachlich richtig (der Mint hängt nicht daran). Der
     * Unterschied, den dieser Hook macht: „nie klassifiziert, weil kein Provider" und
     * „nie klassifiziert, weil der Job starb" waren bis hier dasselbe Nichts. Ein
     * unklassifizierter LA ohne Spur wird nie nachgeholt, weil ihn keiner vermisst.
     */
    public function failed(?\Throwable $e): void
    {
        Log::warning('[FA/H3b] ClassifyLaJob abgebrochen — LA bleibt unklassifiziert', [
            'supplier_item_id' => $this->supplierItemId, 'team_id' => $this->teamId,
            'fehler' => $e?->getMessage(),
        ]);
    }
}
