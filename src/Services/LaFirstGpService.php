<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Jobs\ClassifyLaJob;
use Platform\FoodAlchemist\Jobs\ConformanceCheckJob;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\Matching\MatchHeuristics;

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
    public function mintFromLa(Team $team, string $text, ?string $slug = null, ?string $wgHint = null, bool $allowDerivat = true): ?FoodAlchemistGp
    {
        try {
            // Spec 16·S3: WG-Lead-gescopter, Terminologie-gerankter Kandidat statt naivem
            // searchGlobal->items()[0]. Ohne WG-Hint + Einzeltreffer verhaltensgleich.
            $la = app(LaCandidateFinder::class)->best($team, $text, $wgHint);
            if ($la === null) {
                // D3 §11.2: Nebenprodukt (Knochen/Abschnitte/Karkasse/Schale/…) hat keinen LA →
                // als Derivat der Mutter anlegen statt still unbepreist. Nur einmal (kein Rekurs).
                if ($allowDerivat) {
                    $derivat = $this->mintDerivat($team, $text, $wgHint);
                    if ($derivat !== null) {
                        return $derivat;
                    }
                }

                return null;   // Kein LA (+ kein Derivat) → KEIN GP (Doktrin). Aufrufer erfasst Sourcing-Wunsch.
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
            $neuAngelegt = ! ($guard['blockiert'] && $guard['vorhandenes_gp'] !== null);
            $gp = $neuAngelegt
                ? $naming->createGp($team, ['hauptzutat' => $hauptzutat])     // wirft bei §6-Verstoß → catch → null
                : $guard['vorhandenes_gp'];

            // LA verknüpfen (legt Struktur an, falls fehlend) → Anreicherung LA-abgeleitet.
            try {
                app(LeadLaService::class)->verknuepfen($team, $gp, (int) $la->id);
            } catch (\RuntimeException $e) {
                // LA schon woanders gemappt o. ä. — GP bleibt trotzdem nutzbar.
            }

            // Allergen-Konfidenz SOFORT aus den LA-Daten setzen (statt null zu lassen). Sonst schlägt ein
            // frisch gemintetes GP im Rezept-Recompute als „unbewertet = low" durch und feuert den §7-Guard
            // (RecipeRecomputeService::allergene), der die ohnehin LA-abgeleiteten Allergen-WERTE des ganzen
            // Gerichts spurios auf „unbekannt" nullt. backfillAllergenKonfidenz rechnet la_union (leeres
            // LA-Profil → none/low, kein false-high) und respektiert manual/ki-Provenienz (skip).
            // Best-effort: ein Backfill-Fehler darf den Mint NIE scheitern lassen (Doktrin: jede Fehlerquelle
            // bricht höchstens die Konfidenz, nicht den Mint).
            try {
                app(GpAggregateService::class)->backfillAllergenKonfidenz($gp, apply: true);
            } catch (\Throwable $e) {
                // Konfidenz bleibt null (low); Werte-Vererbung ist davon unberührt.
            }

            // Spec 16·S4: getroffenen LA on-demand nachklassifizieren — ASYNC, nie inline.
            // Der Job blockiert den Mint nie und ist idempotent (klassifiziert → skip).
            if ($struktur === null || $struktur->classified_at === null) {
                ClassifyLaJob::dispatch((int) $la->id, $team->id);
            }

            // Schicht 3 · Slice 4b: frisch geminteten (tentativen) GP async gegen das GP-Regelwerk
            // prüfen — NUR bei Neu-Anlage (Bestandstreffer sind geprüft) und mit User-Kontext (der
            // Critic braucht Auth für den KI-Call). Best-effort: kippt den Mint nie.
            if ($neuAngelegt && $gp !== null && ($confUid = \Illuminate\Support\Facades\Auth::id()) !== null) {
                try {
                    ConformanceCheckJob::dispatch($team->id, (int) $confUid, 'gp', (int) $gp->id);
                } catch (\Throwable $e) {
                    // Dispatch-Fehler schlucken — der Mint steht.
                }
            }

            return $gp;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * D3 2026-08-18 — §11.2 Nebenprodukt-Derivat: Mutter auflösen (mit-minten via mintFromLa, das
     * LA-Lookup + Dedup + Stemming rinder→rind selbst macht; rekursions-sicher via allowDerivat=false),
     * dann Derivat »<Mutter>: frisch, <Form>« anlegen (is_derivat=1, derivat_von_gp_id, requires_la=0,
     * LIVE-Allergen-Vererbung §16). null bei keiner sicheren Mutter (keine erfundene Mutter).
     */
    private function mintDerivat(Team $team, string $text, ?string $wgHint = null): ?FoodAlchemistGp
    {
        $d = app(MatchHeuristics::class)->nebenproduktDerivat($text);
        if ($d === null) {
            return null;   // kein Nebenprodukt
        }
        // Compound-Präfix (»rinder«) auf die Lemma-/Stammform (»rind«) bringen, damit die LA-Suche
        // die Mutter trifft UND der Mutter-Name im §6.1-Singular landet.
        $engine = app(\Platform\FoodAlchemist\Services\Matching\TokenEngine::class);
        $mutterQuery = trim(implode(' ', array_map([$engine, 'stemGerman'], preg_split('/\s+/', $d['mutter_text']) ?: [])));
        $mutter = $this->mintFromLa($team, $mutterQuery !== '' ? $mutterQuery : $d['mutter_text'], null, $wgHint, false);
        if ($mutter === null) {
            return null;   // auch Mutter ohne LA → Sourcing-Lücke (keine erfundene Mutter)
        }
        $mutterBasis = trim((string) (preg_split('/[:,]/u', (string) $mutter->name)[0] ?? $mutter->name));
        if ($mutterBasis === '') {
            return null;
        }
        $name = $mutterBasis . ': frisch, ' . $d['form'];
        $naming = app(GpNamingService::class);
        try {
            return $naming->createGp($team, [
                'name' => $name,
                'hauptzutat' => $mutterBasis,
                'condition' => 'frisch',
                'form' => $d['form'],
                'is_derivat' => 1,
                'derivat_von_gp_id' => (int) $mutter->id,
            ]);
        } catch (\Throwable $e) {
            // Derivat existiert schon (Dedup-Hard-Stop) → vorhandenes wiederverwenden, sonst null.
            $g = $naming->anlageGuard($team, $naming->buildGpKey($naming->slugify($mutterBasis), null, $d['form']), $name);

            return $g['vorhandenes_gp'] ?? null;
        }
    }

    /** „500 ml brauner Kalbsfond" → Hauptzutat-Name ohne Mengen-Präfix. */
    private function hauptzutatAusText(string $text): string
    {
        return trim((string) preg_replace('/^[\d.,\/\s]+(g|kg|ml|l|el|tl|stk|stück|prise[n]?)?\s+/iu', '', $text)) ?: $text;
    }
}
