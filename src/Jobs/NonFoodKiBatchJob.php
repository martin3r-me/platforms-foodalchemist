<?php

namespace Platform\FoodAlchemist\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemNonfood;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\NonFoodService;
use Platform\FoodAlchemist\Support\NonFoodRegeln;

/**
 * Stufe 2 der Non-Food-Erkennung: die KI entscheidet NUR die Artikel, die die Regelstufe als „unklar“ markiert hat — in
 * Stapeln, auf der Anreicherungs-Schlange. Ein sicheres Urteil (Konfidenz ab {@see NonFoodService::KI_SCHWELLE}) wird
 * gespeichert und nimmt den Artikel aus der Prüfliste; alles Unsichere bleibt `pruefung_offen`. Ein roter oder abgeschalteter
 * LLM-Call ist ein No-op (die Prüfliste bleibt), nie ein Absturz. Von Hand gesetzte Urteile fasst der Job nicht an.
 */
class NonFoodKiBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 180;

    public const STAPEL = 20;

    /** @param  list<int>  $itemIds */
    public function __construct(public array $itemIds)
    {
    }

    public function handle(AiGatewayService $ki): void
    {
        $zeilen = FoodAlchemistSupplierItemNonfood::query()
            ->whereIn('supplier_item_id', $this->itemIds)
            ->whereNull('ist_nonfood')->where('quelle', FoodAlchemistSupplierItemNonfood::QUELLE_REGEL)
            ->with('item:id,designation,marketing_name,supplier_id', 'item.supplier:id,name')
            ->get();
        if ($zeilen->isEmpty()) {
            return;
        }

        $artikel = $zeilen->map(fn ($z) => [
            'id' => (int) $z->supplier_item_id,
            'bezeichnung' => (string) $z->item?->designation,
            'verkaufsname' => $z->item?->marketing_name,
            'lieferant' => $z->item?->supplier?->name,
        ])->values()->all();

        try {
            $v = $ki->propose('la.nonfood', ['artikel' => $artikel, 'unterkategorien' => NonFoodRegeln::unterkategorien()]);
        } catch (\Throwable $e) {
            return;   // roter/deaktivierter Call = No-op; die Artikel bleiben in der Prüfliste
        }

        $urteile = collect($v->werte['urteile'] ?? [])->filter(fn ($u) => is_array($u) && isset($u['id']))->keyBy(fn ($u) => (int) $u['id']);
        foreach ($zeilen as $z) {
            $u = $urteile->get((int) $z->supplier_item_id);
            $z->ki_versucht_at = now();
            if ($u === null || ! is_bool($u['nonfood'] ?? null)) {
                $z->save();

                continue;
            }
            $konfidenz = max(0.0, min(1.0, (float) ($u['konfidenz'] ?? 0)));
            $sub = in_array($u['unterkategorie'] ?? null, NonFoodRegeln::unterkategorien(), true) ? $u['unterkategorie'] : null;
            $z->konfidenz = $konfidenz;
            $z->begruendung = mb_substr('KI: '.(string) ($u['grund'] ?? ''), 0, 250);
            if ($konfidenz >= NonFoodService::KI_SCHWELLE) {
                $z->ist_nonfood = $u['nonfood'];
                $z->quelle = FoodAlchemistSupplierItemNonfood::QUELLE_KI;
                $z->subkategorie = $u['nonfood'] ? ($sub ?? $z->subkategorie) : null;
                $z->pruefung_offen = false;
            } elseif ($u['nonfood'] && $sub !== null) {
                $z->subkategorie = $sub;   // Vorschlag bleibt sichtbar, Urteil trifft der Mensch
            }
            $z->save();
        }
    }
}
