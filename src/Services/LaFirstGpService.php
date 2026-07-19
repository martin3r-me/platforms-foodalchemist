<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Jobs\ClassifyLaJob;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;

/**
 * 07·M1 — LA-First-GP-Mint als GETEILTE Fähigkeit (Keystone).
 *
 * Doktrin (Dominique 2026-07-18, verbindlich): Ein GP darf NIE ohne
 * Lieferantenartikel (LA) entstehen — der GP-Name kommt aus der LA. Ein GP aus
 * einer realen LA zu minten ist deshalb KEIN „autonomer Commit aus dem Nichts",
 * sondern die sanktionierte LA-First-Entstehung, die automatisch laufen darf:
 * status=tentative (ReviewQueue-Quarantäne, Mensch hebt später auf approved),
 * LA-verknüpft → Allergene/Nährwerte/EK fließen LA-abgeleitet.
 *
 * Geburtsort war `RecipeGeneratorService::versucheLaZuGp` (#505 Slice 2), dort
 * `private` eingesperrt → jeder andere Pfad (syncIngredients/Revise, gps.MATCH,
 * MCP) lief in Sackgassen (Ruby-Fall #76). M1 befreit die Logik hierher; die
 * weiteren Verdrahtungen (M2 syncIngredients, M3 MCP + mint-if-missing) hängen
 * sich an DIESEN Service.
 *
 * Verhaltens-Invariante: jede Fehlerquelle → null. Die aufrufende Generierung/
 * Sync-Strecke darf NIE am Mint scheitern — kein LA-Treffer heißt schlicht
 * „Stammdaten fehlen" (→ Sourcing-Wunsch beim Aufrufer), nicht Absturz.
 */
class LaFirstGpService
{
    /**
     * Lücke ohne GP-Treffer → passende LA suchen und FA-nativ ein GP minten
     * (status=tentative, §6-Naming aus GpNamingService, Dedup-Reuse via
     * anlageGuard) + LA verknüpfen (Struktur-Anlage falls fehlend). FA=Master
     * (gp_proposals war staging-only; Entscheid Dominique 2026-07-13). Ergebnis
     * ist direkt als gp_id nutzbar; die Freigabe (approved) bleibt menschlich.
     *
     * @param  string       $text    Rohe Zutaten-Bezeichnung (Mengen-Präfix wird geputzt)
     * @param  string|null  $slug    optionaler Hauptzutat-Slug (reserviert für schärferes LA-Matching)
     * @param  string|null  $wgHint  optionaler Warengruppen-Code aus dem Erzeugungs-Kontext (Spec 16·E1):
     *                               verengt die LA-Suche auf die WG-Leads. Fehlt er → Suche über alle Leads.
     * @return FoodAlchemistGp|null  gemintetes/wiederverwendetes GP oder null (keine LA / §6-Verstoß / Fehler)
     */
    public function mintFromLa(Team $team, string $text, ?string $slug = null, ?string $wgHint = null): ?FoodAlchemistGp
    {
        try {
            // Spec 16·S3: WG-Lead-gescopter, Terminologie-gerankter Kandidat statt naivem
            // searchGlobal->items()[0]. Ohne WG-Hint + Einzeltreffer verhaltensgleich.
            $la = app(LaCandidateFinder::class)->best($team, $text, $wgHint);
            if ($la === null) {
                return null;   // Kein LA → KEIN GP (Doktrin). Aufrufer erfasst Sourcing-Wunsch.
            }
            // LA bereits einem GP zugeordnet? → dieses GP direkt nutzen (kein Neu-Anlegen).
            $struktur = FoodAlchemistSupplierItemStructure::where('supplier_item_id', $la->id)
                ->whereNull('deleted_at')->first();
            if ($struktur !== null && $struktur->gp_id !== null) {
                return FoodAlchemistGp::visibleToTeam($team)->find($struktur->gp_id);
            }

            $naming = app(GpNamingService::class);
            $hauptzutat = trim($this->hauptzutatAusText($text));
            if ($hauptzutat === '') {
                return null;
            }
            // Dedup-first: existiert schon ein passendes GP (gp_key/Jaccard) → wiederverwenden.
            $guard = $naming->anlageGuard($team, $naming->buildGpKey($naming->slugify($hauptzutat), null, null), $hauptzutat);
            $gp = ($guard['blockiert'] && $guard['vorhandenes_gp'] !== null)
                ? $guard['vorhandenes_gp']
                : $naming->createGp($team, ['hauptzutat' => $hauptzutat]);   // wirft bei §6-Verstoß → catch → null

            // LA verknüpfen (legt Struktur an, falls fehlend) → Anreicherung LA-abgeleitet.
            try {
                app(LeadLaService::class)->verknuepfen($team, $gp, (int) $la->id);
            } catch (\RuntimeException $e) {
                // LA schon woanders gemappt o. ä. — GP bleibt trotzdem nutzbar.
            }

            // Spec 16·S4: getroffenen LA on-demand nachklassifizieren — ASYNC, nie inline.
            // Der Job blockiert den Mint nie und ist idempotent (klassifiziert → skip).
            if ($struktur === null || $struktur->classified_at === null) {
                ClassifyLaJob::dispatch((int) $la->id, $team->id);
            }

            return $gp;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** „500 ml brauner Kalbsfond" → Hauptzutat-Name ohne Mengen-Präfix. */
    private function hauptzutatAusText(string $text): string
    {
        return trim((string) preg_replace('/^[\d.,\/\s]+(g|kg|ml|l|el|tl|stk|stück|prise[n]?)?\s+/iu', '', $text)) ?: $text;
    }
}
