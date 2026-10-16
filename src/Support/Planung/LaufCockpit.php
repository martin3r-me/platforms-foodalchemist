<?php

namespace Platform\FoodAlchemist\Support\Planung;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\RecipeImageService;
use Platform\FoodAlchemist\Services\SalesRecipeService;

/**
 * Spec 84 C1 Schritt 1 · Cockpit-Daten des aktiven Kaskaden-Laufs (Kalkulation je Stufe, KI-Bild-Calls, Foto-Zahlen,
 * Konformitäts-Hinweise je Rezept und je Zutaten-GP), aus `Livewire\Planung\Index::render()` herausgelöst (wortgleich
 * verschoben). Rein lesend; gebündelte Abfragen über die Steps des Laufs (kein N+1 aus der Blade-Schleife).
 */
final class LaufCockpit
{
    /** @return array{kalkulation: array, bildCalls: array, bilderAngefordert: bool, fotoCounts: array, konformitaet: array, gpKonformitaet: array} */
    public function fuer(?Team $team, $lauf): array
    {
        // Etappe 6: EK/VK/Marge je Stufe im Cockpit sichtbar — schon am Draft, nicht erst nach dem
        // Speichern/Öffnen im VK-Editor. Für jeden Rezept-/Gericht-Step mit erzeugtem Artefakt
        // (ref_type=recipe) die ABGELEITETE Kalkulation über den EINEN Bündler ziehen
        // (SalesRecipeService::cockpit → MargeService; GL-02 I9: VK/Marge sind abgeleitet, nicht
        // persistiert). Rezepte gebündelt per whereIn laden (kein N+1 über die Blade-Step-Schleife).
        // Concept-Steps tragen keine Rezept-Marge (Menü ≠ Rezept) → hier bewusst nicht gerechnet.
        // Map: ref_id → kompakte Kachel-Werte (EK gesamt · VK netto · Marge % · Wareneinsatz % + Ampel).
        $kalkulation = [];
        // Etappe 7: Kosten-Transparenz je Call — Map recipe_id → {n, model} der KI-Bild-Calls.
        $bildCalls = [];
        // Etappe 7 — Bild-Status im Cockpit (Teil 1): wurden KI-Fotos beim Go angefordert
        // (run-level `ki_bilder`) + Map recipe_id → Zahl real existierender Fotos. Ehrlicher
        // Status-Badge (erzeugt / angefordert-aber-leer), analog zum Anreicherungs-Badge.
        $bilderAngefordert = $lauf !== null && (bool) (is_array($lauf->params ?? null) ? ($lauf->params['ki_bilder'] ?? false) : false);
        $fotoCounts = [];
        // Schicht 3: offene Konformitäts-Hinweise je Rezept-/Gericht-Draft ins Cockpit heben
        // (Map recipe_id → Hinweise, hart vor weich) — gefüllt unten aus denselben ref-ids.
        $konformitaet = [];
        // Slice 4c: GP-Konformität je Rezept-Step — die Zutaten-GPs (v.a. die im Lauf frisch
        // geminteten tentativen) tragen ihre eigenen §-Hinweise; Map recipe_id → [gp+Hinweis].
        $gpKonformitaet = [];
        if ($lauf !== null && $team !== null) {
            $rezeptRefIds = $lauf->steps
                ->whereIn('kind', ['rezept', 'gericht'])
                ->where('ref_type', 'recipe')
                ->whereIn('status', ['done', 'freigegeben', 'skipped'])
                ->pluck('ref_id')
                ->filter()
                ->map(fn ($v) => (int) $v)
                ->unique()
                ->values()
                ->all();
            // Schicht 3: die offenen Hinweise für alle Draft-Rezepte in EINER Abfrage.
            $conformance = app(\Platform\FoodAlchemist\Services\ConformanceService::class);
            $konformitaet = $conformance->offeneFuerViele($team, 'recipe', $rezeptRefIds);

            // Slice 4c: GP-Konformität — Zutaten-GPs der Lauf-Rezepte laden, ihre offenen
            // Hinweise ziehen (ein Query) und je Rezept dedupliziert zuordnen.
            $ingrRows = DB::table('foodalchemist_recipe_ingredients')
                ->whereIn('recipe_id', $rezeptRefIds)
                ->whereNotNull('gp_id')
                ->whereNull('deleted_at')
                ->get(['recipe_id', 'gp_id']);
            $gpIds = $ingrRows->pluck('gp_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
            if ($gpIds !== []) {
                $gpFind = $conformance->offeneFuerViele($team, 'gp', $gpIds);
                $gpNamen = DB::table('foodalchemist_gps')->whereIn('id', $gpIds)->pluck('name', 'id');
                $recipeGps = [];
                foreach ($ingrRows as $z) {
                    $recipeGps[(int) $z->recipe_id][(int) $z->gp_id] = true;   // Set → GP je Rezept nur einmal
                }
                foreach ($recipeGps as $rid => $gset) {
                    foreach (array_keys($gset) as $gid) {
                        foreach ($gpFind[$gid] ?? [] as $f) {
                            $gpKonformitaet[$rid][] = ['gp' => $gpNamen[$gid] ?? ('GP #' . $gid)] + $f;
                        }
                    }
                }
            }
            if ($rezeptRefIds !== []) {
                $sales = app(SalesRecipeService::class);
                $rezepte = FoodAlchemistRecipe::visibleToTeam($team)->whereIn('id', $rezeptRefIds)->get();
                foreach ($rezepte as $rz) {
                    $c = $sales->cockpit($rz, $team);
                    $marge = is_array($c['marge'] ?? null) ? $c['marge'] : [];
                    // Etappe 6: unvollständige Bepreisung ehrlich markieren. »teil-unbepreist« =
                    // ein EK IST da, aber nicht alle costed Zutaten tragen einen Preis — die
                    // gezeigte EK/Marge ist damit ZU GÜNSTIG gerechnet (die Lücken tragen 0 €).
                    // 1:1 die kanonische Wahrheit aus DataQualityService (SignalTyp::EkKetteUnvollstaendig
                    // `vk_ek_teil`/`br_ek_teil`): ek_total_eur != null && priced < total — KEINE zweite,
                    // qs-schlaue Definition (das wäre ein Widerspruch zum Signal-Cockpit).
                    $nTotal = $rz->ek_n_ingredients_total !== null ? (int) $rz->ek_n_ingredients_total : null;
                    $nPriced = $rz->ek_n_ingredients_priced !== null ? (int) $rz->ek_n_ingredients_priced : null;
                    $kalkulation[(int) $rz->id] = [
                        'ek_total' => $rz->ek_total_eur !== null ? (float) $rz->ek_total_eur : null,
                        'vk_netto' => $c['vk']['sales_net'] ?? null,
                        'marge_pct' => $marge['marge_pct'] ?? null,
                        'we_pct' => $marge['wareneinsatz_pct'] ?? null,
                        'ampel' => (string) ($c['ampel'] ?? 'unbekannt'),
                        'formel_fehlt' => (bool) ($c['formel_fehlt'] ?? false),
                        'ek_n_priced' => $nPriced,
                        'ek_n_total' => $nTotal,
                        'ek_teil_unbepreist' => $rz->ek_total_eur !== null
                            && $nTotal !== null && $nPriced !== null && $nPriced < $nTotal,
                    ];
                }
            }

            // Etappe 7 — Kosten-Transparenz je Call: die bei der Anreicherung erzeugten KI-Fotos
            // werden je Call in foodalchemist_ai_call_log protokolliert (RecipeImageService). Hier
            // die Zahl der kostenpflichtigen Bild-Calls je Rezept-/Gericht-Draft ins Cockpit heben,
            // damit die KI-Foto-Kosten dort sichtbar sind, wo der Mensch den Go setzt. BEWUSST KEIN
            // EUR-Betrag (keine Preisquelle im Code → wäre Erfindung): gezeigt werden Call-Anzahl +
            // genutztes Modell. Calls → Rezept über die erzeugten Fotos (target_id = photo.id,
            // target_table = photos). Team-eigener Log (die Kosten dieses Teams). Map: recipe_id → {n, model}.
            if ($rezeptRefIds !== []) {
                $bildRows = DB::table('foodalchemist_ai_call_log as l')
                    ->join('foodalchemist_recipe_step_photos as p', function ($j) {
                        $j->on('p.id', '=', 'l.target_id')->whereNull('p.deleted_at');
                    })
                    ->where('l.team_id', $team->id)
                    ->where('l.target_table', 'foodalchemist_recipe_step_photos')
                    ->whereIn('l.feature', RecipeImageService::BILD_FEATURES)
                    ->whereIn('p.recipe_id', $rezeptRefIds)
                    ->groupBy('p.recipe_id')
                    ->selectRaw('p.recipe_id as rid, COUNT(*) as n, MAX(l.model) as model')
                    ->get();
                foreach ($bildRows as $row) {
                    $bildCalls[(int) $row->rid] = ['n' => (int) $row->n, 'model' => (string) ($row->model ?? '')];
                }
            }

            // Etappe 7 — Bild-Status (Teil 1): Zahl der real existierenden Fotos je Draft
            // (team-eigen, nicht gelöscht). NUR wenn KI-Fotos angefordert waren — sonst gibt es
            // keinen Status zu zeigen (Bestandsverhalten). Ehrlich ableitbar OHNE neue Persistenz:
            // Die KI-Fotos laufen im EnrichRecipeJob NACH der Anreicherung → am freigegebenen Step
            // mit deferred.enrich=done heisst »0 Fotos trotz angefordert« = nichts erzeugt (die
            // Bild-Erzeugung ist still fail-soft, ein expliziter »fehlgeschlagen«-Zustand wird
            // heute NICHT protokolliert → kein erfundenes Fehler-Badge). Explizite Fehler-
            // Persistenz + „neu erzeugen" = Folge-Chunk. Actual-Fotos statt bildCalls, weil ein
            // manueller Upload keinen Kosten-Call trägt, für »erzeugt« aber zählt.
            if ($bilderAngefordert && $rezeptRefIds !== []) {
                $fotoRows = DB::table('foodalchemist_recipe_step_photos')
                    ->whereNull('deleted_at')
                    ->where('team_id', $team->id)
                    ->whereIn('recipe_id', $rezeptRefIds)
                    ->groupBy('recipe_id')
                    ->selectRaw('recipe_id as rid, COUNT(*) as n')
                    ->get();
                foreach ($fotoRows as $row) {
                    $fotoCounts[(int) $row->rid] = (int) $row->n;
                }
            }
        }

        return compact('kalkulation', 'bildCalls', 'bilderAngefordert', 'fotoCounts', 'konformitaet', 'gpKonformitaet');
    }
}
