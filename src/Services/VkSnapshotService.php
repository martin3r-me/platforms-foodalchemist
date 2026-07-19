<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\FoodAlchemistVkPriceSnapshot;

/**
 * R2.5 — Trennung interne Live-Marge ↔ veröffentlichter, freigegebener VK.
 *
 * - release(): friert den aktuellen Live-VK einer Darreichung als Snapshot ein
 *   (menschliche Batch-Freigabe). Einzige Art, den Kundenpreis zu ändern.
 * - publishedFor(): der zuletzt freigegebene VK (Kundensicht/R3.2 liest NUR den).
 * - pending(): Darreichungen, deren Live-VK vom freigegebenen Snapshot über die
 *   Leitplanke (max_vk_delta_pct) abweicht → Kandidaten für „VK-Anpassung empfohlen".
 *
 * Der Live-Preis (recipe_darreichungen.sales_net, DarreichungService::recomputePreise)
 * bleibt unberührt — ohne Freigabe kein Kunden-Preissprung.
 */
class VkSnapshotService
{
    public function __construct(private TeamSettingsService $settings)
    {
    }

    /**
     * Freigabe (Snapshot) für die genannten Darreichungen — kopiert den aktuellen
     * Live-VK. Nur team-EIGENE Darreichungen (isOwnedBy-Äquivalent); fremde/nicht
     * sichtbare werden übersprungen. Gibt die Zahl geschriebener Snapshots zurück.
     *
     * @param  list<int>  $presentationIds
     */
    public function release(Team $team, array $presentationIds, ?int $releasedBy = null): int
    {
        $ids = array_values(array_unique(array_map('intval', $presentationIds)));
        if ($ids === []) {
            return 0;
        }
        // Nur eigene Darreichungen (Schreibrecht = eigenes Team, D1).
        $darreichungen = FoodAlchemistRecipeDarreichung::whereIn('id', $ids)
            ->where('team_id', $team->id)->get();

        $n = 0;
        foreach ($darreichungen as $d) {
            FoodAlchemistVkPriceSnapshot::create([
                'team_id' => $team->id,
                'presentation_id' => $d->id,
                'sales_net' => $d->sales_net,
                'sales_gross' => $d->sales_gross,
                'released_at' => now(),
                'released_by' => $releasedBy,
            ]);
            $n++;
        }

        return $n;
    }

    /** Der zuletzt freigegebene VK-Snapshot einer Darreichung (oder null). */
    public function publishedFor(int $presentationId): ?FoodAlchemistVkPriceSnapshot
    {
        return FoodAlchemistVkPriceSnapshot::where('presentation_id', $presentationId)
            ->orderByDesc('released_at')->orderByDesc('id')->first();
    }

    /**
     * Darreichungen mit freigegebenem Snapshot, deren Live-VK über die Leitplanke
     * (max_vk_delta_pct) abweicht — die eigentlichen „VK-Anpassung empfohlen"-Kandidaten.
     *
     * @return list<array{presentation_id:int, recipe_id:int, recipe_name:string, published_net:?float, live_net:?float, delta_pct:?float, richtung:string}>
     */
    public function pending(Team $team, ?float $maxDeltaPct = null): array
    {
        $schwelle = $maxDeltaPct ?? $this->settings->maxVkDeltaPct($team);

        // Nur team-sichtbare VK-Gerichte betrachten.
        $recipeIds = FoodAlchemistRecipe::visibleToTeam($team)->where('is_sales_recipe', true)->pluck('id');
        if ($recipeIds->isEmpty()) {
            return [];
        }
        $recipeNames = FoodAlchemistRecipe::visibleToTeam($team)->whereIn('foodalchemist_recipes.id', $recipeIds)->pluck('name', 'id');

        // Live-Darreichungen dieser Rezepte.
        $darreichungen = FoodAlchemistRecipeDarreichung::whereIn('recipe_id', $recipeIds)->get(['id', 'recipe_id', 'sales_net']);
        if ($darreichungen->isEmpty()) {
            return [];
        }

        // Letzter Snapshot je Darreichung (ein Query).
        $letzte = DB::table('foodalchemist_vk_price_snapshots')
            ->whereIn('presentation_id', $darreichungen->pluck('id'))->whereNull('deleted_at')
            ->orderByDesc('released_at')->orderByDesc('id')
            ->get(['presentation_id', 'sales_net'])
            ->groupBy('presentation_id');

        $out = [];
        foreach ($darreichungen as $d) {
            $snap = $letzte->get($d->id)?->first();
            if ($snap === null || $snap->sales_net === null || (float) $snap->sales_net == 0.0) {
                continue;   // nie freigegeben oder Snapshot ohne Preis → kein Delta-Signal
            }
            $pub = (float) $snap->sales_net;
            $live = $d->sales_net !== null ? (float) $d->sales_net : null;
            if ($live === null) {
                continue;
            }
            $deltaPct = round(abs($live - $pub) / $pub * 100, 2);
            if ($deltaPct < $schwelle) {
                continue;
            }
            $out[] = [
                'presentation_id' => (int) $d->id,
                'recipe_id' => (int) $d->recipe_id,
                'recipe_name' => $recipeNames[$d->recipe_id] ?? (string) $d->recipe_id,
                'published_net' => $pub,
                'live_net' => $live,
                'delta_pct' => $deltaPct,
                'richtung' => $live > $pub ? 'erhoehen' : 'senken',
            ];
        }

        return $out;
    }
}
