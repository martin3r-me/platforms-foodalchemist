<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\BulkProposalStatus;
use Platform\FoodAlchemist\Enums\BulkRunStatus;
use Platform\FoodAlchemist\Enums\BulkRunType;
use Platform\FoodAlchemist\Models\FoodAlchemistBulkGpProposal;
use Platform\FoodAlchemist\Models\FoodAlchemistBulkProposal;
use Platform\FoodAlchemist\Models\FoodAlchemistBulkRun;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeCategory;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabContainer;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\Ai\KnowledgeContextService;
use Platform\FoodAlchemist\Support\TeamScope;

/**
 * M7-06 / D-5 §4.4 + V-15: Bulk-Autopilot — der Job erzeugt VORSCHLÄGE in
 * die Review-Liste (nie Auto-Persistenz, GL-07); Übernahme einzeln/alle
 * bleibt interaktiv und respektiert Override-First. Schritte hier: die
 * implementierten Feld-KIs (description · kategorie · geschmack) — weitere
 * Orchestrator-Schritte docken über SCHRITTE an, sobald ihre Accept-Pfade
 * existieren (Registry-Prompts stehen seit M7-04).
 */
class BulkEnrichService
{
    /**
     * Spec 50 · B-10: `dichteklasse` ist seit 2026-09-06 Teil der Basisrezept-Schrittfolge. Bis
     * dahin gab es den Accept-Pfad nur als ✨-Knopf im Editor ({@see \Platform\FoodAlchemist\Livewire\Recipes\RecipeModal::kiDichteklasse});
     * der Reife-Report führte das Feld darum als „ohne Schrittfolge" und `recipes.ENRICH` konnte die
     * Lücke nicht schließen — der Behälterbedarf (Spec 51) blieb für jedes per MCP angelegte Rezept
     * unrechenbar. Der Schritt schreibt Dichteklasse UND Behälter je Zweck (Behälter werden am
     * Basisrezept angereichert, Entscheid 2026-09-05), weil `BehaelterRechner::varianten` beides braucht.
     */
    public const SCHRITTE = ['description', 'category', 'geschmack', 'dichteklasse', 'regeneration', 'garverlust'];

    /**
     * Spec 03 L1b: die VK-Schrittfolge — bewusst NICHT `SCHRITTE`. `category` ist
     * dort die 186er-Rezept-Kategorie (Basisrezept-Ebene); am Gericht heißt die
     * Klassifikation `speisen_klasse` und läuft über eine eigene Taxonomie. Der
     * Schritt-Name ist überall das Suffix seines Prompt-Keys (`recipe.description`,
     * `vk.wording`, `vk.plating`, `vk.speisen_klasse`) — so bleibt ablesbar, welche
     * Registry-Zeile ein Vorschlag erzeugt hat.
     */
    public const SCHRITTE_VK = ['description', 'wording', 'plating', 'speisen_klasse', 'geschmack', 'servier_vehikel', 'rollen'];

    /** Die Teilmenge, die es nur am Gericht gibt — auf einem Basisrezept ehrlicher Fehler statt Unsinn. */
    private const NUR_GERICHT = ['wording', 'plating', 'speisen_klasse', 'servier_vehikel', 'rollen'];

    /**
     * Spec 50 · A5 — das Gegenstück, das bisher fehlte.
     *
     * `category` ist die 186er REZEPT-Kategorie (Basisrezept-Taxonomie); am Gericht heisst die
     * Klassifikation `speisen_klasse` und läuft über eine eigene Achse — deshalb steht
     * `category` bewusst nicht in `SCHRITTE_VK` (s. Kommentar dort). Ungeschützt war nur die
     * Richtung Basisschritt → Gericht: der Rezept-Browser listet BEIDE Ebenen
     * (`RecipeService::paginateBrowser` filtert nicht) und startete für die ganze Auswahl
     * `SCHRITTE`. Ein markiertes Gericht bekam damit eine Basisrezept-Kategorie vorgeschlagen.
     */
    private const NUR_BASIS = ['category', 'regeneration'];

    /**
     * GP-Bulk-Autopilot-Schritte (Feld-KIs mit vorhandenem Accept-Pfad).
     *
     * Spec 50 · B-7/B-9: `anker` (Kern-Anker = Aroma-Identität, macht den GP im Pairing-Graph
     * sichtbar) ist Standard. `allergene` und `naehrwerte` sind KEIN Standard mehr — Allergene,
     * Nährwerte und Zusatzstoffe kommen aus den Lieferantenartikeln (GL-01 §4.3); die KI-Schätzung
     * bleibt als explizit gewählter Schritt für LA-lose GPs (`SCHRITTE_GP_EXPLIZIT`).
     */
    public const SCHRITTE_GP = ['condition', 'tags', 'anker'];

    /** Nur auf ausdrückliche Wahl (LA-lose GPs) — nie im „alles anreichern"-Lauf. */
    public const SCHRITTE_GP_EXPLIZIT = ['allergene', 'naehrwerte'];

    /**
     * Spec 03 L7: WAS schreibt ein Schritt? Bisher stand diese Zuordnung nur
     * implizit im `match`-Block von `uebernehmen()`. Der One-Shot braucht sie
     * explizit, um die Schrittfolge auf echte LÜCKEN zu schneiden (ein schon
     * gefülltes Feld nicht erneut bezahlen). `source` ist null bei Feldern ohne
     * Lineage-Trio (`taste_direction` = Auto-Apply-Ausnahme, GL-07 §4.3).
     */
    public const ZIELFELDER = [
        'description' => ['feld' => 'description', 'source' => 'description_source'],
        'category' => ['feld' => 'category_id', 'source' => 'category_source'],
        'geschmack' => ['feld' => 'taste_direction', 'source' => null],
        'wording' => ['feld' => 'sales_wording_standard', 'source' => 'sales_wording_source'],
        'plating' => ['feld' => 'plating_text', 'source' => 'plating_source'],
        'speisen_klasse' => ['feld' => 'dish_class_id', 'source' => 'dish_class_source'],
        'dichteklasse' => ['feld' => 'dichteklasse', 'source' => 'dichteklasse_source'],
        // B-1/B-2 (Spec 50): Schritte OHNE Spalte am Rezept — ihr Ziel ist eine Zeile in einer
        // Nebentabelle. `feld => null` heißt: die Lücke entscheidet allein {@see relationaleLuecke()}.
        'servier_vehikel' => ['feld' => 'serving_vehicle_vocab_id', 'source' => 'serving_vehicle_source'],
        'rollen' => ['feld' => null, 'source' => null],                 // recipe_ingredients.role
        'regeneration' => ['feld' => null, 'source' => null],           // recipe_regenerations, Gesamt-Zeile
        'garverlust' => ['feld' => null, 'source' => null],             // recipe_ingredients.cooking_loss_pct
    ];

    /** Skalierungs-Vokabular der Behälter-Zeile (Spiegel des Editor-Selects). */
    private const SKALIERUNG = ['tiefer_fuellbar', 'hoehe_gebunden', 'lagenware'];

    public function __construct(private AiGatewayService $ki)
    {
    }

    /**
     * Spec 03 L7: welche Schritte füllen an diesem Rezept noch eine Lücke?
     *
     * Ein Schritt fällt raus, wenn sein Ziel-Feld schon einen Wert trägt — egal
     * ob von Hand, vom Generator oder aus einem früheren Lauf. Das ist der
     * Unterschied zwischen „✨ Alles anreichern" (der Mensch will alle Felder neu
     * vorgeschlagen bekommen und entscheidet je Zeile) und der One-Shot-Kaskade
     * (die übernimmt selbst und darf darum nur Leerstellen anfassen). Ein
     * unbekannter Schritt bleibt drin — er soll in `proposeFeld()` laut scheitern,
     * nicht hier still verschwinden.
     *
     * @param  list<string>  $schritte
     * @return list<string>
     */
    public function luecken(FoodAlchemistRecipe $r, array $schritte): array
    {
        return array_values(array_filter($schritte, function (string $s) use ($r) {
            $ziel = self::ZIELFELDER[$s] ?? null;
            if ($ziel === null) {
                return true;
            }
            if ($ziel['feld'] === null) {
                return $this->relationaleLuecke($r, $s);
            }
            $wert = $r->getAttribute($ziel['feld']);

            return $wert === null || $wert === '' || $this->relationaleLuecke($r, $s);
        }));
    }

    /**
     * B-10: Schritte, deren Ziel nicht nur EINE Spalte ist. `dichteklasse` gilt erst als erfüllt, wenn
     * auch ein Behälter je Zweck steht — ohne Behälter-Zeile rechnet `BehaelterBedarfService` weiter
     * nichts, die Klasse allein wäre ein gefülltes Feld ohne Wirkung. Manuelle Behälter-Zeilen zählen
     * genauso wie KI-gesetzte: die Lücke ist „kein Behälter", nicht „kein KI-Behälter".
     */
    private function relationaleLuecke(FoodAlchemistRecipe $r, string $schritt): bool
    {
        return match ($schritt) {
            'dichteklasse' => ! DB::table('foodalchemist_recipe_containers')
                ->where('recipe_id', $r->id)->whereNull('deleted_at')->whereNotNull('container_vocab_id')->exists(),
            // Spec 51-Vertrag: `device_vocab_id NULL` = kalt servieren (Entscheidung), KEINE
            // Gesamt-Zeile (`ingredient_id IS NULL`) = Lücke. Overrides je Komponente zählen nicht.
            'regeneration' => ! DB::table('foodalchemist_recipe_regenerations')
                ->where('recipe_id', $r->id)->whereNull('deleted_at')->whereNull('ingredient_id')->exists(),
            // Eine lebende Zutat ohne Wert reicht — der Accept füllt genau diese Zeilen.
            'garverlust' => $this->zutatenOhne($r, 'cooking_loss_pct')->exists(),
            'rollen' => $this->zutatenOhne($r, 'role')->exists(),
            default => false,
        };
    }

    /** Lebende Zutat-Zeilen des Rezepts, deren Spalte `$spalte` noch leer ist. */
    private function zutatenOhne(FoodAlchemistRecipe $r, string $spalte): \Illuminate\Database\Query\Builder
    {
        return DB::table('foodalchemist_recipe_ingredients')
            ->where('recipe_id', $r->id)->whereNull('deleted_at')->whereNull($spalte);
    }

    /**
     * #4: wie {@see luecken()}, aber für den BEWUSSTEN „Alles anreichern"-Refresh: nimmt auch
     * bereits GEFÜLLTE Felder mit — sofern sie nicht manuell gepflegt sind (`*_source === 'manual'`).
     * So werden die abgeleiteten Textfelder (Beschreibung/Geschmack/Wording/…) nach Zutaten-/
     * Rezeptänderungen neu erzeugt statt stehenzubleiben; manuelle Pflege bleibt geschützt
     * (Override-First — `uebernehmen()` zieht dieselbe Grenze zusätzlich hart). Felder ohne
     * `source`-Spalte (z.B. Geschmack) tragen keinen Manual-Schutz und werden mit-aufgefrischt.
     */
    public function zuAktualisieren(FoodAlchemistRecipe $r, array $schritte): array
    {
        return array_values(array_filter($schritte, function (string $s) use ($r) {
            $ziel = self::ZIELFELDER[$s] ?? null;
            if ($ziel === null) {
                return true;
            }
            if ($ziel['feld'] === null) {
                // Zeilen-Schritte (Rollen/Regeneration/Garverlust) kennen kein „auffrischen":
                // ihr Accept füllt nur LEERE Zeilen (jede gefüllte ist eine Entscheidung, egal
                // welcher Herkunft) — ohne Lücke gäbe es nichts zu schreiben.
                return $this->relationaleLuecke($r, $s);
            }
            $wert = $r->getAttribute($ziel['feld']);
            if ($wert === null || $wert === '' || $this->relationaleLuecke($r, $s)) {
                return true;                                        // leer → immer
            }
            $sourceFeld = $ziel['source'] ?? null;                 // gefüllt: nur auffrischen, wenn nicht manuell
            return $sourceFeld === null || (string) $r->getAttribute($sourceFeld) !== 'manual';
        }));
    }

    /**
     * Lauf-Zeile anlegen (Fortschritts-Anker für Polling + Review-Queue).
     * Herausgezogen für Spec 03 L7: die One-Shot-Kaskade läuft synchron im ohnehin
     * asynchronen Generier-Job und darf deshalb keinen zweiten Job dispatchen —
     * sie braucht die Lauf-Zeile ohne `starte()`. Ein Insert, eine Wahrheit.
     */
    public function laufAnlegen(Team $team, int $total, BulkRunType $type = BulkRunType::Enrich, array $context = []): int
    {
        return (int) FoodAlchemistBulkRun::starte($team->id, $type, $total, $context, Auth::id())->id;
    }

    /** Startet einen Run (Job ist queued; Sandbox/Tests: sync). */
    public function starte(Team $team, array $recipeIds, array $schritte = self::SCHRITTE, BulkRunType $type = BulkRunType::Enrich): int
    {
        $ids = FoodAlchemistRecipe::visibleToTeam($team)->whereIn('id', $recipeIds)->pluck('id')->all();
        // V-047: woran der Lauf arbeitete — die Schrittfolge ist der Gegenstand der
        // Anreicherung, die Arbeitsmenge ihr Umfang. Ohne beides sagt die Zeile nur
        // „enrich, 12 done" und ein zweiter Lauf mit anderer Schrittfolge sieht gleich aus.
        $runId = $this->laufAnlegen($team, count($ids), $type, ['schritte' => array_values($schritte)]);

        \Platform\FoodAlchemist\Jobs\BulkEnrichJob::dispatch($runId, $team->id, $ids, $schritte);

        return $runId;
    }

    /**
     * Spec 03 L1b: Anreicherungs-Lauf am GERICHT — gleicher Vorschlags-Speicher und
     * gleiche Review-Mechanik, nur andere Schrittfolge. Die Arbeitsmenge wird hier
     * auf Verkaufsrezepte geschnitten: ein mitgegebenes Basisrezept fällt raus,
     * statt VK-Schritte auf der falschen Ebene zu fahren.
     */
    public function starteVk(Team $team, array $recipeIds, array $schritte = self::SCHRITTE_VK): int
    {
        $ids = FoodAlchemistRecipe::visibleToTeam($team)->verkauf()->whereIn('id', $recipeIds)->pluck('id')->all();

        return $this->starte($team, $ids, $schritte, BulkRunType::EnrichVk);
    }

    /** Job-Kern: ein Rezept × Schritte → Vorschläge (kein Fach-Write). */
    public function verarbeiteRezept(Team $team, int $runId, int $recipeId, array $schritte): void
    {
        $r = FoodAlchemistRecipe::visibleToTeam($team)->find($recipeId);
        $fehler = false;
        foreach ($r === null ? [] : $schritte as $feld) {
            try {
                $vorschlag = $this->proposeFeld($team, $r, $feld);
                FoodAlchemistBulkProposal::create([
                    'team_id' => $team->id, 'run_id' => $runId, 'recipe_id' => $r->id, 'field' => $feld,
                    'value' => $vorschlag['value'],
                    'confidence' => $vorschlag['confidence'],
                    'reasoning' => $vorschlag['reasoning'],
                    'call_log_id' => $vorschlag['call_log_id'],
                    // V-072: der GP-Zwilling wertet zusätzlich `[]` als leer. Die Abweichung
                    // ist bekannt und eingefroren — hier NICHT im Vorbeigehen angeglichen.
                    'status' => $vorschlag['value'] === null || $vorschlag['value'] === ''
                        ? BulkProposalStatus::Leer : BulkProposalStatus::Offen,
                ]);
            } catch (\Throwable $e) {
                $fehler = true;
                FoodAlchemistBulkProposal::create([
                    'team_id' => $team->id, 'run_id' => $runId, 'recipe_id' => $recipeId, 'field' => $feld,
                    'status' => BulkProposalStatus::Leer, 'error' => mb_strimwidth($e->getMessage(), 0, 500),
                ]);
            }
        }

        $this->zaehleFortschritt($runId, $fehler || $r === null);
    }

    /** @return array{wert: mixed, confidence: ?float, reasoning: ?string, call_log_id: ?int} */
    private function proposeFeld(Team $team, FoodAlchemistRecipe $r, string $feld): array
    {
        if (in_array($feld, self::NUR_GERICHT, true) && ! $r->is_sales_recipe) {
            throw new \RuntimeException("Bulk-Schritt [{$feld}] gilt nur fuer Verkaufsgerichte.");
        }
        if (in_array($feld, self::NUR_BASIS, true) && $r->is_sales_recipe) {
            throw new \RuntimeException("Bulk-Schritt [{$feld}] gilt nur fuer Basisrezepte (am Gericht: speisen_klasse).");
        }
        if ($feld === 'speisen_klasse') {
            // Eine Wahrheit: Taxonomie-Aufbau, Aktiv-Filter und Klassen-Validierung
            // stehen im SpeisenKlassenService (Detail-Panel fährt denselben Weg).
            $c = app(SpeisenKlassenService::class)->classify($team, $r->id);

            return [
                'value' => $c['klasse_id'] === null ? null
                    : ['dish_class_id' => $c['klasse_id'], 'klasse_name' => $c['klasse_name']],
                'confidence' => $c['confidence'], 'reasoning' => $c['reasoning'], 'call_log_id' => $c['call_log_id'],
            ];
        }

        if ($feld === 'dichteklasse') {
            return $this->proposeDichteklasse($team, $r);
        }
        if ($feld === 'rollen') {
            // Eine Wahrheit: Vokabular, Zeilen-Validierung und Gesamt-Gericht-Sicht stehen im
            // SpeisenKlassenService (Detail-Panel fährt denselben Weg).
            $v = app(SpeisenKlassenService::class)->verteileRollen($team, $r->id);

            return [
                'value' => $v['rollen'] === [] ? null : ['rollen' => $v['rollen']],
                'confidence' => $v['confidence'], 'reasoning' => $v['reasoning'], 'call_log_id' => $v['call_log_id'],
            ];
        }
        if ($feld === 'regeneration') {
            return $this->proposeRegeneration($team, $r);
        }
        if ($feld === 'garverlust') {
            return $this->proposeGarverlust($team, $r);
        }

        [$key, $kontext, $extract] = match ($feld) {
            'description' => ['recipe.description',
                ['name' => $r->name, 'description' => $r->description, 'zutaten' => $r->ingredients()->whereNull('deleted_at')->pluck('display_name')->all()],
                fn (array $w) => $w['description'] ?? null],
            'category' => ['recipe.category',
                ['name' => $r->name, 'category_id' => $r->category_id,
                    'kategorien' => FoodAlchemistRecipeCategory::orderBy('id')->limit(200)->pluck('label', 'id')->all()],
                fn (array $w) => $w['category_id'] ?? null],
            'geschmack' => ['recipe.geschmack',
                ['name' => $r->name, 'taste_direction' => $r->taste_direction],
                fn (array $w) => $w['taste_direction'] ?? null],
            // L1b: VK-Texte — derselbe Kontext-Zuschnitt wie die ✨-Einzelknöpfe im VkModal
            'wording' => ['vk.wording', $this->gerichtKontext($r),
                fn (array $w) => $w['sales_wording_standard'] ?? null],
            'plating' => ['vk.plating', $this->gerichtKontext($r) + ['portion_g' => $r->sales_quantity_per_unit_g],
                fn (array $w) => $w['preparation'] ?? $w['plating_text'] ?? null],   // Registry-Schema: {preparation}
            // B-1: derselbe Zuschnitt wie VkModal::kiVehikel — die KI wählt aus dem, was das Team hat.
            // Kontext-Key `vehikel` (nicht `vokabular`, das ist der Rollen-Schritt; nicht `portion_g`,
            // das ist der Plating-Marker der Test-Fakes): Struktur trägt die Bedeutung, nicht Wortlaut.
            'servier_vehikel' => ['vk.servier_vehikel',
                $this->gerichtKontext($r) + ['grammatur_g' => $r->sales_quantity_per_unit_g,
                    'vehikel' => $this->vehikelKatalog($team)->pluck('name', 'id')->all()],
                fn (array $w) => is_numeric($w['servier_vehikel_id'] ?? null)
                    && $this->vehikelKatalog($team)->contains(fn ($v) => (int) $v->id === (int) $w['servier_vehikel_id'])
                    ? (int) $w['servier_vehikel_id'] : null],
            default => throw new \RuntimeException("Unbekannter Bulk-Schritt [{$feld}]."),
        };

        $p = $this->ki->propose($key, $kontext, ['target_table' => 'foodalchemist_recipes', 'target_id' => $r->id]);

        return [
            'value' => $extract($p->werte),
            'confidence' => $p->confidence,
            'reasoning' => $p->reasoning,
            'call_log_id' => $p->callLogId,
        ];
    }

    /** Gericht-Kontext für die VK-Schritte (Name · Wording · Komponenten · Klasse). */
    private function gerichtKontext(FoodAlchemistRecipe $r): array
    {
        return [
            'name' => $r->name,
            'sales_wording_standard' => $r->sales_wording_standard,
            'komponenten' => $r->ingredients()->whereNull('deleted_at')
                ->with(['referencedRecipe:id,name', 'gp:id,name'])->get()
                ->map(fn ($z) => $z->referencedRecipe?->name ?? $z->gp?->name ?? $z->display_name)->all(),
            'speisen_klasse' => $r->dishClass?->label,
        ];
    }

    /**
     * B-10 · Vorschlag Dichteklasse + Behälter je Zweck — derselbe Zuschnitt wie
     * {@see \Platform\FoodAlchemist\Livewire\Recipes\RecipeModal::kiDichteklasse}: der sichtbare
     * Behälter-Katalog geht MIT in den Prompt (die KI wählt eine id aus dem, was das Team hat, nicht
     * aus Weltwissen über Gastronorm), und das Füllmengen-Dossier kommt aus dem Wissensmodul
     * (`recipe.eigenschaften`), damit die Referenzmenge angewendet statt geraten wird.
     *
     * Der Vorschlagswert ist das ganze `werte`-Objekt — die Review-Liste zeigt es als JSON, der
     * Accept zerlegt es. Ungültige Klasse UND kein verwertbarer Behälter → null (Status Leer).
     *
     * @return array{value: mixed, confidence: ?float, reasoning: ?string, call_log_id: ?int}
     */
    private function proposeDichteklasse(Team $team, FoodAlchemistRecipe $r): array
    {
        $katalog = $this->behaelterKatalog($team);
        $wissen = app(KnowledgeContextService::class)->contextFor(
            $team, 'recipe.dichteklasse', trim($r->name.' Behälter Füllmenge Füllgrad Dichte')
        );
        // Spec 52/B3: Messfelder über den Helfer, sonst bleibt `dropped` hier blind.
        $opts = KnowledgeContextService::proposeOptionen($wissen)
            + ['target_table' => 'foodalchemist_recipes', 'target_id' => $r->id];

        $p = $this->ki->propose('recipe.dichteklasse', [
            'name' => $r->name,
            'kategorie' => $r->category?->label,
            'zutaten' => $r->ingredients()->whereNull('deleted_at')->with('gp:id,name')->limit(15)->get()
                ->map(fn ($z) => $z->display_name ?: ($z->gp?->name ?? $z->raw_text))->filter()->values()->all(),
            'ausbeute_kg' => $r->yield_kg !== null ? round((float) $r->yield_kg, 2) : null,
            'behaelter' => $katalog->map(fn ($b) => [
                'id' => (int) $b->id,
                'name' => $b->name,
                'familie' => $b->familie,
                'liter' => $b->volumen_l !== null ? (float) $b->volumen_l : null,
                'tiefe_mm' => $b->tiefe_mm !== null ? (int) $b->tiefe_mm : null,
                'freigegeben_fuer' => $b->eignung !== null ? json_decode((string) $b->eignung, true) : null,
            ])->values()->all(),
        ], $opts);

        $klasse = $p->werte['dichteklasse'] ?? null;
        $klasse = is_string($klasse) && array_key_exists($klasse, BehaelterRechner::DICHTE) ? $klasse : null;
        $behaelter = is_array($p->werte['behaelter_je_zweck'] ?? null) ? $p->werte['behaelter_je_zweck'] : [];
        $behaelter = array_filter($behaelter, fn ($id) => is_numeric($id) && $katalog->contains(fn ($b) => (int) $b->id === (int) $id));

        return [
            'value' => $klasse === null && $behaelter === [] ? null : [
                'dichteklasse' => $klasse,
                'skalierung' => in_array($p->werte['skalierung'] ?? null, self::SKALIERUNG, true) ? $p->werte['skalierung'] : null,
                'behaelter_je_zweck' => $behaelter,
                'referenz_menge_kg_je_zweck' => is_array($p->werte['referenz_menge_kg_je_zweck'] ?? null) ? $p->werte['referenz_menge_kg_je_zweck'] : [],
            ],
            'confidence' => $p->confidence,
            'reasoning' => $p->reasoning,
            'call_log_id' => $p->callLogId,
        ];
    }

    /**
     * B-1 · Vorschlag Regeneration als Gesamt-Zeile des Basisrezepts (Spec 51: der Default, den
     * Gerichte erben). Ein Programm; `kalt` ist eine Entscheidung (Gerät null), kein Leer-Ergebnis.
     * Verwertbar = kalt ODER gültige Geräte-id aus dem sichtbaren Vokabular — sonst null (Status Leer).
     *
     * @return array{value: mixed, confidence: ?float, reasoning: ?string, call_log_id: ?int}
     */
    private function proposeRegeneration(Team $team, FoodAlchemistRecipe $r): array
    {
        $geraete = $this->geraeteKatalog($team);
        $p = $this->ki->propose('recipe.regeneration', [
            'name' => $r->name,
            'kategorie' => $r->category?->label,
            'zutaten' => $r->ingredients()->whereNull('deleted_at')->with('gp:id,name')->limit(15)->get()
                ->map(fn ($z) => $z->display_name ?: ($z->gp?->name ?? $z->raw_text))->filter()->values()->all(),
            'geraete' => $geraete->pluck('name', 'id')->all(),
        ], ['target_table' => 'foodalchemist_recipes', 'target_id' => $r->id]);

        $kalt = ($p->werte['kalt'] ?? null) === true;
        $geraetId = $p->werte['geraet_id'] ?? null;
        $geraetId = ! $kalt && is_numeric($geraetId) && $geraete->has((int) $geraetId) ? (int) $geraetId : null;
        $int = fn ($v) => is_numeric($v) ? (int) $v : null;

        return [
            'value' => ! $kalt && $geraetId === null ? null : [
                'kalt' => $kalt,
                'device_vocab_id' => $geraetId,
                'temp_c' => $kalt ? null : $int($p->werte['temp_c'] ?? null),
                'duration_min' => $kalt ? null : $int($p->werte['duration_min'] ?? null),
                'core_temp_c' => $kalt ? null : $int($p->werte['core_temp_c'] ?? null),
                'note' => is_string($p->werte['note'] ?? null) && trim($p->werte['note']) !== '' ? trim($p->werte['note']) : null,
            ],
            'confidence' => $p->confidence,
            'reasoning' => $p->reasoning,
            'call_log_id' => $p->callLogId,
        ];
    }

    /**
     * B-2 · Vorschlag Garverlust je Zutat (Regelwerk §6 F6.5, 0–100). Nur Zeilen OHNE Wert gehen
     * in den Vorschlag — eine gefüllte Zeile ist eine Entscheidung (manual/ki/auto), egal welcher
     * Herkunft. Der Editor-Vorschlag klemmt weiter auf 0–60 (Einzelzeile, andere Skala bewusst).
     *
     * @return array{value: mixed, confidence: ?float, reasoning: ?string, call_log_id: ?int}
     */
    private function proposeGarverlust(Team $team, FoodAlchemistRecipe $r): array
    {
        $offen = $this->zutatenOhne($r, 'cooking_loss_pct')->orderBy('position')->orderBy('id')
            ->get(['id', 'display_name', 'raw_text', 'gp_id']);
        $gpNamen = FoodAlchemistGp::whereIn('id', $offen->pluck('gp_id')->filter()->all())->pluck('name', 'id');
        $zutaten = $offen->mapWithKeys(fn ($z) => [
            (int) $z->id => $z->display_name ?: ($gpNamen[$z->gp_id] ?? $z->raw_text ?? ''),
        ])->all();
        if ($zutaten === []) {
            return ['value' => null, 'confidence' => null, 'reasoning' => null, 'call_log_id' => null];
        }

        $p = $this->ki->propose('recipe.garverlust', [
            'name' => $r->name,
            'kategorie' => $r->category?->label,
            'zutaten' => $zutaten,
            'verluste' => DB::table('foodalchemist_recipe_ingredients')->where('recipe_id', $r->id)
                ->whereNull('deleted_at')->whereNotNull('cooking_loss_pct')->pluck('cooking_loss_pct', 'id')->all(),   // Kontext-Echo
        ], ['target_table' => 'foodalchemist_recipes', 'target_id' => $r->id]);

        $verluste = [];
        foreach ((array) ($p->werte['verluste'] ?? []) as $id => $pct) {
            if (array_key_exists((int) $id, $zutaten) && is_numeric($pct) && (float) $pct >= 0.0 && (float) $pct <= 100.0) {
                $verluste[(int) $id] = round((float) $pct, 1);
            }
        }

        return [
            'value' => $verluste === [] ? null : ['verluste' => $verluste],
            'confidence' => $p->confidence,
            'reasoning' => $p->reasoning,
            'call_log_id' => $p->callLogId,
        ];
    }

    /** Sichtbare Regenerations-Geräte, nach id — dieselbe Auswahl wie das Editor-Select. */
    private function geraeteKatalog(Team $team): \Illuminate\Support\Collection
    {
        return TeamScope::applyVisible(
            DB::table('foodalchemist_vocab_regeneration_devices')->whereNull('deleted_at')->where('is_inactive', false),
            'team_id', $team
        )->orderBy('name')->get()->keyBy(fn ($g) => (int) $g->id);
    }

    /** Sichtbare Servier-Vehikel (Typ-Ebene) — dieselbe Auswahl wie das Editor-Select. */
    private function vehikelKatalog(Team $team): \Illuminate\Support\Collection
    {
        return TeamScope::applyVisible(
            DB::table('foodalchemist_vocab_serving_vehicles')->whereNull('deleted_at')->where('is_inactive', false),
            'team_id', $team
        )->orderBy('name')->get();
    }

    /** Sichtbarer Behälter-Katalog des Teams — dieselbe Auswahl, die das Editor-Formular anbietet. */
    private function behaelterKatalog(Team $team): \Illuminate\Support\Collection
    {
        return TeamScope::applyVisible(
            DB::table('foodalchemist_vocab_containers')->whereNull('deleted_at')->where('is_inactive', false),
            'team_id', $team
        )->orderBy('name')->get();
    }

    /** Review: EIN Vorschlag übernehmen (Override-First, Lineage ki, Stempel). */
    public function uebernehmen(Team $team, int $proposalId): bool
    {
        $prop = FoodAlchemistBulkProposal::whereKey($proposalId)->where('status', BulkProposalStatus::Offen)->first();
        if ($prop === null) {
            return false;
        }
        $r = FoodAlchemistRecipe::visibleToTeam($team)->find($prop->recipe_id);
        if ($r === null) {
            return false;
        }
        $wert = $prop->value;                                        // `array`-Cast = das bisherige json_decode

        if ($prop->field === 'speisen_klasse') {
            return $this->uebernehmeSpeisenKlasse($team, $r->id, $wert, $prop);
        }
        if ($prop->field === 'dichteklasse') {
            return $this->uebernehmeDichteklasse($team, $r, is_array($wert) ? $wert : [], $prop);
        }
        if (in_array($prop->field, ['rollen', 'regeneration', 'garverlust'], true)) {
            return $this->uebernehmeZeilen($team, $r, is_array($wert) ? $wert : [], $prop);
        }
        $text = fn () => is_string($wert) && trim($wert) !== '' ? trim($wert) : null;

        $update = match ($prop->field) {
            'description' => $r->description_source === 'manual' ? null
                : ['description' => (string) $wert, 'description_source' => 'ki', 'description_ai_confidence' => $prop->confidence],
            // L1b: VK-Texte mit eigenem Lineage-Paar; Gericht-Guard, damit ein
            // fehlgeleiteter Vorschlag nicht am Basisrezept landet.
            'wording' => $r->sales_wording_source === 'manual' || ! $r->is_sales_recipe || $text() === null ? null
                : ['sales_wording_standard' => $text(), 'sales_wording_source' => 'ki', 'sales_wording_ai_confidence' => $prop->confidence],
            'plating' => $r->plating_source === 'manual' || ! $r->is_sales_recipe || $text() === null ? null
                : ['plating_text' => $text(), 'plating_source' => 'ki', 'plating_ai_confidence' => $prop->confidence],
            'category' => $r->category_source === 'manual' || FoodAlchemistRecipeCategory::find((int) $wert) === null ? null
                : ['category_id' => (int) $wert, 'category_source' => 'ki', 'category_ai_confidence' => $prop->confidence],
            'geschmack' => in_array($wert, ['suess', 'herzhaft', 'neutral'], true)
                ? ['taste_direction' => $wert] : null,             // Auto-Apply-Ausnahme-Feld (GL-07 §4.3), kein Lineage-Trio
            // B-1: nur Gericht, nur sichtbare Typ-Vehikel, manual gewinnt (dieselben Riegel wie VkModal::uebernehmeVehikel)
            'servier_vehikel' => $r->serving_vehicle_source === 'manual' || ! $r->is_sales_recipe || ! is_numeric($wert)
                || ! $this->vehikelKatalog($team)->contains(fn ($v) => (int) $v->id === (int) $wert) ? null
                : ['serving_vehicle_vocab_id' => (int) $wert, 'serving_vehicle_source' => 'ki', 'serving_vehicle_ai_confidence' => $prop->confidence],
            default => null,
        };
        if ($update === null) {
            return false;                                            // Override-First / ungültig — Vorschlag bleibt offen
        }

        $r->update($update);

        // B-1: das Vehikel lebt doppelt (Rezept-Spalte + Standard-Darreichung) — `updateVk` spiegelt
        // es, dieser Weg muss es auch, sonst zeigt die Ausgabe etwas anderes als der Editor.
        if ($prop->field === 'servier_vehikel') {
            app(SalesRecipeService::class)->spiegeleStandardDarreichung($team, $r->refresh(), $update);
        }

        // 2026-09-04: `plating_text` ist der Spiegel der ANRICHTE-Schritte (Regelwerk §3.3).
        // Die anderen Schreibwege (Editor, MCP, updateVk) parsen ankommenden Markdown in
        // Schritte — ohne diesen Zweig setzte allein die Anreicherung einen Text OHNE Schritte,
        // und der Anrichten-Tab stünde leer, obwohl das Feld gefüllt ist.
        if ($prop->field === 'plating' && ($update['plating_text'] ?? null) !== null) {
            $svc = app(RecipeStepService::class);
            $ebene = \Platform\FoodAlchemist\Models\FoodAlchemistRecipeStep::EBENE_ANRICHTEN;
            if ($svc->ausMarkdown($r, (string) $update['plating_text'], ebene: $ebene) === 0) {
                $svc->spiegele($r);   // Ebene hatte schon Schritte → sie gewinnen
            }
        }

        $this->ki->stempleAccepted($prop->call_log_id !== null ? (int) $prop->call_log_id : null);
        $prop->update(['status' => BulkProposalStatus::Uebernommen]);

        return true;
    }

    /**
     * B-1/B-2 · Accept der Zeilen-Schritte. Gemeinsame Regel: geschrieben wird NUR in leere Zeilen
     * (Override-First auf Zeilenebene — eine gefüllte Rolle, ein gesetzter Verlust, eine lebende
     * Gesamt-Zeile sind Entscheidungen). Übernommen gilt der Vorschlag, wenn mindestens EINE Zeile
     * gelandet ist; sonst bleibt er offen und die Review-Liste zeigt ihn weiter.
     */
    private function uebernehmeZeilen(Team $team, FoodAlchemistRecipe $r, array $wert, FoodAlchemistBulkProposal $prop): bool
    {
        $gelandet = 0;
        switch ($prop->field) {
            case 'rollen':
                $offen = $this->zutatenOhne($r, 'role')->pluck('id')->map(fn ($id) => (int) $id)->flip();
                $rollen = array_filter((array) ($wert['rollen'] ?? []), fn ($role, $id) => $offen->has((int) $id), ARRAY_FILTER_USE_BOTH);
                $gelandet = $rollen === [] ? 0 : app(SpeisenKlassenService::class)->acceptRollen($team, $r->id, $rollen);
                break;

            case 'regeneration':
                if (! $this->relationaleLuecke($r, 'regeneration')) {
                    break;                                              // Gesamt-Zeile steht (kalt oder Gerät) — Entscheidung
                }
                $kalt = ($wert['kalt'] ?? false) === true;
                $geraetId = $wert['device_vocab_id'] ?? null;
                if (! $kalt && (! is_numeric($geraetId) || ! $this->geraeteKatalog($team)->has((int) $geraetId))) {
                    break;                                              // weder Entscheidung „kalt" noch gültiges Gerät
                }
                app(SalesRecipeService::class)->upsertRegeneration($team, $r->id, [
                    'component_label' => 'Gesamt', 'ingredient_id' => null,
                    'device_vocab_id' => $kalt ? null : (int) $geraetId,
                    'temp_c' => $kalt ? null : ($wert['temp_c'] ?? null),
                    'duration_min' => $kalt ? null : ($wert['duration_min'] ?? null),
                    'core_temp_c' => $kalt ? null : ($wert['core_temp_c'] ?? null),
                    'note' => $wert['note'] ?? null,
                    'source' => 'ki', 'ai_confidence' => $prop->confidence, 'ai_reasoning' => $prop->reasoning,
                ]);
                $gelandet = 1;
                break;

            case 'garverlust':
                foreach ((array) ($wert['verluste'] ?? []) as $id => $pct) {
                    if (! is_numeric($pct) || (float) $pct < 0.0 || (float) $pct > 100.0) {
                        continue;
                    }
                    $gelandet += $this->zutatenOhne($r, 'cooking_loss_pct')->where('id', (int) $id)
                        ->update(['cooking_loss_pct' => round((float) $pct, 1), 'cooking_loss_source' => 'ki', 'updated_at' => now()]);
                }
                if ($gelandet > 0) {
                    // Der Verlust ändert Ausbeute und EK/kg — der Recompute zieht Rezept + Nutzer nach.
                    app(RecipeRecomputeService::class)->recomputeAndPropagate($r->id);
                }
                break;
        }

        if ($gelandet === 0) {
            return false;                                                          // nichts Leeres getroffen — bleibt offen
        }
        $this->ki->stempleAccepted($prop->call_log_id !== null ? (int) $prop->call_log_id : null);
        $prop->update(['status' => BulkProposalStatus::Uebernommen]);

        return true;
    }

    /**
     * L1b-Accept der Speisen-Klasse: geht durch `SpeisenKlassenService::acceptKlasse`,
     * weil dort Override-First, Besitzer-Regel (D1) und Taxonomie-Validierung schon
     * stehen — und der Accept-Stempel mit. Ein Veto kommt dort als Exception; hier
     * bleibt der Vorschlag dann offen (Review kann später entscheiden).
     */
    private function uebernehmeSpeisenKlasse(Team $team, int $recipeId, mixed $wert, FoodAlchemistBulkProposal $prop): bool
    {
        $klasseId = is_array($wert) ? ($wert['dish_class_id'] ?? null) : $wert;
        if (! is_numeric($klasseId)) {
            return false;
        }
        try {
            app(SpeisenKlassenService::class)->acceptKlasse(
                $team, $recipeId, (int) $klasseId, (float) $prop->confidence, $prop->reasoning,
                $prop->call_log_id !== null ? (int) $prop->call_log_id : null,
            );
        } catch (\Throwable) {
            return false;                                            // manual / geerbt / ungültige Klasse
        }
        $prop->update(['status' => BulkProposalStatus::Uebernommen]);

        return true;
    }

    /**
     * B-10 · Accept Dichteklasse + Behälter je Zweck. Dieselben drei Riegel wie im Editor
     * ({@see \Platform\FoodAlchemist\Livewire\Recipes\RecipeModal::uebernehmeKiBehaelter}):
     *  1. nur ids aus dem sichtbaren Katalog · 2. nur wo `eignung` den Zweck trägt · 3. nur in leere
     * Felder (Override-First — eine gepflegte Behälter-Zeile ist eine Entscheidung). Die Referenzmenge
     * wird nur zu einem gesetzten Behälter geschrieben und trägt `source='ki'` (rechnet dann mit
     * Konfidenz „mittel", nicht „hoch"); eine bestehende Zeile bekommt nur ihre LEEREN Felder gefüllt —
     * `upsertContainer` ersetzt sonst die ganze Zeile.
     *
     * Die Klasse selbst: `dichteklasse_source='manual'` gewinnt. Übernommen gilt der Vorschlag, wenn
     * mindestens EIN Teil gelandet ist — sonst bleibt er offen.
     */
    private function uebernehmeDichteklasse(Team $team, FoodAlchemistRecipe $r, array $wert, FoodAlchemistBulkProposal $prop): bool
    {
        $gelandet = false;

        $klasse = $wert['dichteklasse'] ?? null;
        if (is_string($klasse) && array_key_exists($klasse, BehaelterRechner::DICHTE) && $r->dichteklasse_source !== 'manual') {
            $r->update(['dichteklasse' => $klasse, 'dichteklasse_source' => 'ki',
                'dichteklasse_ai_confidence' => $prop->confidence, 'dichteklasse_ai_reasoning' => $prop->reasoning]);
            $gelandet = true;
        }

        $katalog = $this->behaelterKatalog($team)->keyBy(fn ($b) => (int) $b->id);
        $skalierung = in_array($wert['skalierung'] ?? null, self::SKALIERUNG, true) ? $wert['skalierung'] : null;
        $mengen = is_array($wert['referenz_menge_kg_je_zweck'] ?? null) ? $wert['referenz_menge_kg_je_zweck'] : [];
        $behaelter = is_array($wert['behaelter_je_zweck'] ?? null) ? $wert['behaelter_je_zweck'] : [];
        $vorhanden = DB::table('foodalchemist_recipe_containers')
            ->where('recipe_id', $r->id)->whereNull('deleted_at')->get()->keyBy('zweck');

        foreach (FoodAlchemistVocabContainer::ZWECKE as $zweck) {
            $zeile = $vorhanden->get($zweck);
            $in = [
                'container_vocab_id' => $zeile?->container_vocab_id,
                'referenz_menge_kg' => $zeile?->referenz_menge_kg,
                'skalierung' => $zeile?->skalierung,
                'max_schichthoehe_mm' => $zeile?->max_schichthoehe_mm,
                'stueck_je_behaelter' => $zeile?->stueck_je_behaelter,
                'note' => $zeile?->note,
                'source' => $zeile?->source ?? 'ki',
            ];
            $geaendert = false;

            $id = $behaelter[$zweck] ?? null;
            if ($in['container_vocab_id'] === null && is_numeric($id) && ($b = $katalog->get((int) $id)) !== null) {
                $eignung = $b->eignung !== null ? (array) json_decode((string) $b->eignung, true) : null;
                if ($eignung === null || in_array($zweck, $eignung, true)) {
                    $in['container_vocab_id'] = (int) $b->id;                      // Riegel 1–3 bestanden
                    $geaendert = true;
                }
            }
            if ($in['container_vocab_id'] === null) {
                continue;                                                          // ohne Behälter ist der Rest bedeutungslos
            }
            if ($in['skalierung'] === null && $skalierung !== null) {
                $in['skalierung'] = $skalierung;
                $geaendert = true;
            }
            $kg = $mengen[$zweck] ?? null;
            if ($in['referenz_menge_kg'] === null && is_numeric($kg) && (float) $kg > 0.0) {
                $in['referenz_menge_kg'] = round((float) $kg, 2);
                $in['source'] = 'ki';                                              // hergeleitet, nicht gewogen
                $geaendert = true;
            }
            if (! $geaendert) {
                continue;
            }
            $in['ai_confidence'] = $prop->confidence;
            $in['ai_reasoning'] = $prop->reasoning;
            app(SalesRecipeService::class)->upsertContainer($team, $r->id, $zweck, $in);
            $gelandet = true;
        }

        if (! $gelandet) {
            return false;                                                          // Override-First / nichts Gültiges — bleibt offen
        }
        $this->ki->stempleAccepted($prop->call_log_id !== null ? (int) $prop->call_log_id : null);
        $prop->update(['status' => BulkProposalStatus::Uebernommen]);

        return true;
    }

    /** Review: »Alle übernehmen« eines Runs — Override-First gilt je Zeile. */
    public function alleUebernehmen(Team $team, int $runId): int
    {
        $n = 0;
        foreach (FoodAlchemistBulkProposal::where('run_id', $runId)->where('status', BulkProposalStatus::Offen)->orderBy('id')->pluck('id') as $id) {
            $n += $this->uebernehmen($team, (int) $id) ? 1 : 0;
        }

        return $n;
    }

    public function verwerfen(Team $team, int $proposalId): void
    {
        $prop = FoodAlchemistBulkProposal::find($proposalId);
        if ($prop !== null && FoodAlchemistRecipe::visibleToTeam($team)->whereKey($prop->recipe_id)->exists()) {
            $prop->update(['status' => BulkProposalStatus::Verworfen]);
            $this->ki->stempleRejected($prop->call_log_id !== null ? (int) $prop->call_log_id : null);
        }
    }

    /** Fortschritts-Polling (Browser-Pill). */
    public function status(Team $team, int $runId): ?FoodAlchemistBulkRun
    {
        return FoodAlchemistBulkRun::where('id', $runId)->where('team_id', $team->id)->first();
    }

    /**
     * Öffentliche Hülle um {@see zaehleFortschritt()} für Läufe, die außerhalb dieses Services
     * abgearbeitet werden ({@see ConceptOneShotService} · Spec 50 C-4): ein Element, ein Zähler,
     * derselbe Schließ-Mechanismus — statt einer Kopie des Doppel-Updates im Aufrufer.
     */
    public function laufZaehlen(int $runId, bool $fehler = false): void
    {
        $this->zaehleFortschritt($runId, $fehler);
    }

    /**
     * Ein abgearbeitetes Element zählen und den Lauf schließen, sobald `done` das Soll
     * erreicht. V-032: bis hier stand derselbe Doppel-Update zweimal im selben Service
     * (Rezept- und GP-Pfad), jede neue Lauf-Art hätte ihn ein drittes Mal kopiert.
     * Die Zähler bleiben bewusst SQL-seitig (`done + 1`) — zwei parallele Job-Worker
     * dürfen sich nicht gegenseitig überschreiben.
     */
    private function zaehleFortschritt(int $runId, bool $fehler): void
    {
        FoodAlchemistBulkRun::whereKey($runId)->update([
            'done' => DB::raw('done + 1'),
            'failed' => DB::raw('failed + ' . ($fehler ? 1 : 0)),
            'updated_at' => now(),
        ]);
        FoodAlchemistBulkRun::whereKey($runId)->whereColumn('done', '>=', 'total')
            ->update(['status' => BulkRunStatus::Done->value, 'updated_at' => now()]);
    }

    public function offeneVorschlaege(Team $team, int $runId): int
    {
        return FoodAlchemistBulkProposal::where('run_id', $runId)->where('team_id', $team->id)
            ->where('status', BulkProposalStatus::Offen)->count();
    }

    // ── GP-Bulk-Autopilot (Pendant zum Rezept-Pfad, eigener Vorschlags-Speicher) ──

    /** Startet einen GP-Anreicherungs-Lauf (Job queued; Sandbox/Tests: sync). */
    public function starteGp(Team $team, array $gpIds, array $schritte = self::SCHRITTE_GP): int
    {
        $ids = FoodAlchemistGp::visibleToTeam($team)->whereIn('id', $gpIds)->pluck('id')->all();
        $runId = $this->laufAnlegen($team, count($ids), BulkRunType::EnrichGp, ['schritte' => array_values($schritte)]);

        \Platform\FoodAlchemist\Jobs\BulkEnrichGpJob::dispatch($runId, $team->id, $ids, $schritte);

        return $runId;
    }

    /**
     * Spec 50 · B-3: Kern-Anker für frisch geminteten GPs SYNCHRON nachziehen — derselbe Weg wie der
     * Rezept-One-Shot (Lauf-Zeile als Audit-Spur, Vorschlag, sofortige Übernahme), aber nur für GPs,
     * die noch kein lebendes Anker-Mapping haben. Ein GP ohne Anker ist im Pairing-Graph unsichtbar;
     * `pairingGlied` sprang bisher still darüber hinweg. Fail-soft: wirft nie.
     *
     * @param  list<int>  $gpIds
     * @return array{run_id: ?int, geprueft: int, uebernommen: int, offen: int, fehler?: string}
     */
    public function ankerNachziehen(Team $team, array $gpIds): array
    {
        $ohne = FoodAlchemistGp::visibleToTeam($team)->whereIn('id', $gpIds)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('foodalchemist_gp_anchor_mappings as m')
                ->whereColumn('m.gp_id', 'foodalchemist_gps.id')->whereNull('m.deleted_at'))
            ->pluck('id')->map(fn ($v) => (int) $v)->all();
        if ($ohne === []) {
            return ['run_id' => null, 'geprueft' => 0, 'uebernommen' => 0, 'offen' => 0];
        }
        try {
            $runId = $this->laufAnlegen($team, count($ohne), BulkRunType::EnrichGp,
                ['schritte' => ['anker'], 'quelle' => 'gp_mint']);
            foreach ($ohne as $gpId) {
                $this->verarbeiteGp($team, $runId, $gpId, ['anker']);
            }

            return ['run_id' => $runId, 'geprueft' => count($ohne),
                'uebernommen' => $this->alleUebernehmenGp($team, $runId), 'offen' => $this->offeneGpVorschlaege($team, $runId)];
        } catch (\Throwable $e) {
            return ['run_id' => null, 'geprueft' => count($ohne), 'uebernommen' => 0, 'offen' => 0,
                'fehler' => mb_strimwidth($e->getMessage(), 0, 300)];
        }
    }

    /** Job-Kern: ein GP × Schritte → Vorschläge (kein Fach-Write). */
    public function verarbeiteGp(Team $team, int $runId, int $gpId, array $schritte): void
    {
        $gp = FoodAlchemistGp::visibleToTeam($team)->with('commodity_group')->find($gpId);
        $fehler = false;
        foreach ($gp === null ? [] : $schritte as $feld) {
            try {
                $vorschlag = $this->proposeGpFeld($team, $gp, $feld);
                // V-072: hier zählt zusätzlich `[]` als leer — der Rezept-Zwilling kennt
                // den dritten Fall nicht. Bekannte Abweichung, eingefroren, nicht angeglichen.
                $leer = $vorschlag['value'] === null || $vorschlag['value'] === '' || $vorschlag['value'] === [];
                FoodAlchemistBulkGpProposal::create([
                    'team_id' => $team->id, 'run_id' => $runId, 'gp_id' => $gp->id, 'field' => $feld,
                    'value' => $vorschlag['value'],
                    'confidence' => $vorschlag['confidence'],
                    'reasoning' => $vorschlag['reasoning'],
                    'call_log_id' => $vorschlag['call_log_id'],
                    'status' => $leer ? BulkProposalStatus::Leer : BulkProposalStatus::Offen,
                ]);
            } catch (\Throwable $e) {
                $fehler = true;
                FoodAlchemistBulkGpProposal::create([
                    'team_id' => $team->id, 'run_id' => $runId, 'gp_id' => $gpId, 'field' => $feld,
                    'status' => BulkProposalStatus::Leer, 'error' => mb_strimwidth($e->getMessage(), 0, 500),
                ]);
            }
        }

        $this->zaehleFortschritt($runId, $fehler || $gp === null);
    }

    /** @return array{wert: mixed, confidence: ?float, reasoning: ?string, call_log_id: ?int} */
    private function proposeGpFeld(Team $team, FoodAlchemistGp $gp, string $feld): array
    {
        $basis = ['name' => $gp->name, 'condition' => $gp->condition, 'commodity_group' => $gp->commodity_group?->name];
        $vokabular = $feld === 'anker' ? $this->gpAnkerKandidaten($gp) : [];

        [$key, $kontext, $extract] = match ($feld) {
            'condition' => ['gp.condition', ['name' => $gp->name, 'condition' => $gp->condition ?: null],
                fn (array $w) => $w['condition'] ?? null],
            'tags' => ['gp.tags', ['name' => $gp->name,
                'tags' => collect(FoodAlchemistGp::TAG_FIELDS)->mapWithKeys(fn ($t) => [$t => $gp->getAttribute("tag_{$t}")])->filter(fn ($v) => $v !== null)->all()],
                fn (array $w) => $w['tags'] ?? null],
            'allergene' => ['gp.allergene', $basis,
                fn (array $w) => $w['allergene'] ?? null],
            'naehrwerte' => ['gp.naehrwerte', $basis,
                fn (array $w) => array_intersect_key($w, array_flip(['kcal', 'protein_g', 'fat_g', 'carbs_g', 'salt_g'])) ?: null],
            // B-7: die KI wählt aus einer begründeten Vorauswahl (lexikalisch + semantisch + neutral);
            // nur ein Slug aus dem mitgegebenen Vokabular ist ein gültiger Wert.
            'anker' => ['gp.anker', $basis + ['vokabular' => $vokabular],
                fn (array $w) => is_string($w['anchor_slug'] ?? null) && in_array($w['anchor_slug'], $vokabular, true)
                    ? ['anchor_slug' => $w['anchor_slug']] : null],
            default => throw new \RuntimeException("Unbekannter GP-Bulk-Schritt [{$feld}]."),
        };

        $p = $this->ki->propose($key, $kontext, ['target_table' => 'foodalchemist_gps', 'target_id' => $gp->id]);

        return [
            'value' => $extract($p->werte),
            'confidence' => $p->confidence,
            'reasoning' => $p->reasoning,
            'call_log_id' => $p->callLogId,
        ];
    }

    /**
     * B-7: Anker-Vokabular für EIN GP — dieselbe Idee wie `RecipeOneShotService::ankerKandidaten`:
     * (a) lexikalische Wort-Treffer im GP-Namen, (b) semantischer Recall (Beigabe, nie Bedingung),
     * (c) `neutral` immer. Kein (a) und kein (b) → Vollvokabular statt geratener Teilmenge (eine
     * falsche Whitelist macht die richtige Antwort unerreichbar; der teure Call ist das kleinere Übel).
     *
     * @return list<string>
     */
    private function gpAnkerKandidaten(FoodAlchemistGp $gp): array
    {
        $ids = app(PairingService::class)->lexicalAnkerKandidaten((string) $gp->name);
        $slugs = $ids === [] ? [] : DB::table('foodalchemist_vocab_pairing_anchors')
            ->whereIn('id', $ids)->whereNull('deleted_at')->pluck('slug')->all();
        try {
            $semantisch = app(\Platform\FoodAlchemist\Services\Ai\KnowledgeEmbeddingService::class)
                ->searchAnkerSlugs((string) $gp->name, 40);
        } catch (\Throwable) {
            $semantisch = [];
        }
        $vereint = array_values(array_unique(array_merge($slugs, $semantisch)));
        if ($vereint === []) {
            $vereint = DB::table('foodalchemist_vocab_pairing_anchors')->whereNull('deleted_at')
                ->where('slug', '!=', 'neutral')->orderBy('slug')->pluck('slug')->all();
        }

        return array_values(array_unique([...array_slice($vereint, 0, 120), 'neutral']));
    }

    /** Review: EIN GP-Vorschlag übernehmen (Override-First, Lineage ki, Stempel). */
    public function uebernehmenGp(Team $team, int $proposalId): bool
    {
        $prop = FoodAlchemistBulkGpProposal::whereKey($proposalId)->where('status', BulkProposalStatus::Offen)->first();
        if ($prop === null) {
            return false;
        }
        $gp = FoodAlchemistGp::visibleToTeam($team)->find($prop->gp_id);
        if ($gp === null || ! $gp->isOwnedBy($team)) {                 // D1: nur eigene GPs
            return false;
        }
        $wert = $prop->value;                                        // `array`-Cast = das bisherige json_decode
        $ok = false;

        if ($prop->field === 'condition') {
            $z = app(GpNamingService::class)->normalisiereZustand(is_array($wert) ? ($wert['condition'] ?? null) : $wert);
            if ($z !== null && in_array($z, GpNamingService::ZUSTAND_VOCAB, true) && $gp->condition_source !== 'manual') {
                $gp->update(['condition' => $z, 'condition_source' => 'ki', 'condition_ai_confidence' => $prop->confidence, 'condition_ai_reasoning' => $prop->reasoning]);
                $ok = true;
            }
        } elseif ($prop->field === 'tags' && is_array($wert) && $gp->tag_source !== 'manual') {
            $tagWerte = $wert['tags'] ?? $wert;
            $update = [];
            foreach (FoodAlchemistGp::TAG_FIELDS as $tag) {
                if (array_key_exists($tag, $tagWerte)) {
                    $update["tag_{$tag}"] = (bool) $tagWerte[$tag];
                }
            }
            if ($update !== []) {
                $gp->update([...$update, 'tag_source' => 'ki', 'tag_ai_confidence' => $prop->confidence, 'tag_ai_reasoning' => $prop->reasoning, 'tag_aggregated_at' => now()]);
                $ok = true;
            }
        } elseif ($prop->field === 'allergene' && is_array($wert)) {
            // Spec 50 · A8: derselbe Wächter wie im Panel — wo ein LA-Profil existiert, gilt
            // die LA-Kette (GL-01 §4.3). Der Bulk-Pfad setzt zwar kein `allergens_source`,
            // schreibt aber ebenfalls in die Override-Ebene und würde die Messung verdecken.
            if (app(GpAggregateService::class)->hatLaAllergenProfil($gp)) {
                return false;
            }
            $update = [];
            foreach (FoodAlchemistGp::ALLERGEN_FIELDS as $feld) {
                $v = $wert['allergene'][$feld] ?? $wert[$feld] ?? null;
                // Override-First: nur setzen, wenn noch KEIN Override existiert (manuelle Werte bleiben)
                if (in_array($v, ['enthalten', 'spuren', 'nicht_enthalten'], true) && $gp->getAttribute("allergen_{$feld}") === null) {
                    $update["allergen_{$feld}"] = $v;
                }
            }
            if ($update !== []) {
                $gp->update([...$update, 'allergens_confidence' => $prop->confidence]);
                $ok = true;
            }
        } elseif ($prop->field === 'anker' && is_array($wert) && is_string($wert['anchor_slug'] ?? null)) {
            // Override-First: ein manuell gesetzter Kern-Anker (Inv. 3) blockt die KI-Inferenz komplett.
            $manuell = DB::table('foodalchemist_gp_anchor_mappings')->where('gp_id', $gp->id)
                ->where('source', 'manual')->whereNull('deleted_at')->exists();
            $ankerId = DB::table('foodalchemist_vocab_pairing_anchors')->where('slug', $wert['anchor_slug'])
                ->whereNull('deleted_at')->value('id');
            if (! $manuell && $ankerId !== null) {
                app(PairingService::class)->setGpAnkerInference($team, $gp->id, (int) $ankerId,
                    (float) ($prop->confidence ?? 0.0), (string) ($prop->reasoning ?: 'Bulk-Anreicherung'));
                $ok = true;
            }
        } elseif ($prop->field === 'naehrwerte' && is_array($wert) && $gp->nutri_source !== 'manual') {
            $num = fn ($v) => is_numeric($v) && (float) $v >= 0 ? round((float) $v, 2) : null;
            if ($num($wert['kcal'] ?? null) !== null) {                // kcal = Leit-Indikator (GL-08)
                $gp->update([
                    'nutri_kcal_per_100g' => $num($wert['kcal'] ?? null),
                    'nutri_protein_g_per_100g' => $num($wert['protein_g'] ?? null),
                    'nutri_fat_g_per_100g' => $num($wert['fat_g'] ?? null),
                    'nutri_carbs_g_per_100g' => $num($wert['carbs_g'] ?? null),
                    'nutri_salt_g_per_100g' => $num($wert['salt_g'] ?? null),
                    'nutri_source' => 'ki', 'nutri_ai_confidence' => $prop->confidence,
                ]);
                $ok = true;
            }
        }

        if (! $ok) {
            return false;                                              // Override-First / ungültig — Vorschlag bleibt offen
        }
        $this->ki->stempleAccepted($prop->call_log_id !== null ? (int) $prop->call_log_id : null);
        $prop->update(['status' => BulkProposalStatus::Uebernommen]);

        return true;
    }

    /** Review: »Alle übernehmen« eines GP-Runs — Override-First je Zeile. */
    public function alleUebernehmenGp(Team $team, int $runId): int
    {
        $n = 0;
        foreach (FoodAlchemistBulkGpProposal::where('run_id', $runId)->where('status', BulkProposalStatus::Offen)->orderBy('id')->pluck('id') as $id) {
            $n += $this->uebernehmenGp($team, (int) $id) ? 1 : 0;
        }

        return $n;
    }

    public function verwerfenGp(Team $team, int $proposalId): void
    {
        $prop = FoodAlchemistBulkGpProposal::find($proposalId);
        if ($prop !== null && FoodAlchemistGp::visibleToTeam($team)->whereKey($prop->gp_id)->exists()) {
            $prop->update(['status' => BulkProposalStatus::Verworfen]);
            $this->ki->stempleRejected($prop->call_log_id !== null ? (int) $prop->call_log_id : null);
        }
    }

    /** Offene GP-Vorschläge eines Runs (Review-Zähler). */
    public function offeneGpVorschlaege(Team $team, int $runId): int
    {
        return FoodAlchemistBulkGpProposal::where('run_id', $runId)->where('team_id', $team->id)
            ->where('status', BulkProposalStatus::Offen)->count();
    }

    /** GP-Vorschläge eines Runs fürs Review-Panel (mit Feld + Wert-Vorschau). */
    public function gpVorschlaege(Team $team, int $runId): \Illuminate\Support\Collection
    {
        return FoodAlchemistBulkGpProposal::where('run_id', $runId)->where('team_id', $team->id)
            ->whereIn('status', [BulkProposalStatus::Offen, BulkProposalStatus::Uebernommen])
            ->orderBy('field')->get();
    }
}
