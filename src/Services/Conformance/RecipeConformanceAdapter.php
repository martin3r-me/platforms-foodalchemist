<?php

namespace Platform\FoodAlchemist\Services\Conformance;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistDishClass;
use Platform\FoodAlchemist\Models\FoodAlchemistDishMainGroup;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeCategory;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\RecipeReviseService;
use Platform\FoodAlchemist\Services\RecipeService;
use Platform\FoodAlchemist\Services\RecipeStepService;

/**
 * Konformitäts-Adapter für Rezepte — deckt BEIDE Sichten ab (Basisrezept UND
 * Verkaufsgericht), weil beide dieselbe Tabelle sind; `is_sales_recipe` wählt
 * die Regelwerke. Die Kontext-Felder spiegeln bewusst {@see RecipeReviewService::kontext}
 * (bewährte Accessor-Kette), erweitert um Grounding-/Sub-Rezept-Marker, die für
 * die §-Prüfung (Naming, Sub-Rezept-Regel, Default-GPs) tragen.
 */
class RecipeConformanceAdapter implements ConformanceAdapter
{
    public function artifactType(): string
    {
        return 'recipe';                                              // Basisrezept UND VK — dieselbe Tabelle
    }

    public function unterstuetztHeilung(): bool
    {
        return true;                                                 // Freitext-Revise via recipe.ueberarbeiten
    }

    /**
     * KONZENTRATIONS-MARKER: Zutaten-Formen, deren Masse durch Wasserentzug oder Einkochen
     * bereits reduziert ist. Sie sind Würz- und Aromakomponenten, nie die Hauptmasse eines
     * Gerichts — 1 kg Trockentomate entspricht grob 8–14 kg Frischware.
     *
     * Geprüft wird gegen NAME + `processing` + `form`, nicht gegen `condition`: `condition`
     * trägt nur die vier §9-Zustände (frisch|TK|trocken|konserviert), und „getrocknet" steckt
     * in der Verarbeitung. GP 13757 („Tomaten: TK, getrocknet") hat sogar `condition = NULL` —
     * ein zustand-basierter Check wäre dort blind.
     */
    private const KONZENTRAT_MARKER = [
        'getrocknet', 'trockentomate', 'konzentrat', 'pulver', 'granulat', 'extrakt',
        'reduktion', 'demi-glace', 'instant',
    ];

    /**
     * Anteil an der Einsatzmasse, ab dem eine Konzentrat-Zutat als Hauptmasse gilt.
     *
     * FACHLICHE SETZUNG, nicht gemessen — darum konfigurierbar und als `hart`, aber NICHT
     * blockierend. 20 % ist bewusst hoch angesetzt: legitime Fälle (eine Pfeffer-Reduktion in
     * einer Sauce, Tomatenmark als Röstbasis) liegen deutlich darunter, der Fall aus Lauf 65
     * lag bei 57,9 %. Wer den Wert senkt, bekommt Befunde an Saucen; wer ihn hebt, verliert den
     * Fall. Er gehört Dominique, nicht dem Code.
     */
    private const KONZENTRAT_ANTEIL_MAX = 0.20;

    public function deterministischeBefunde(Team $team, int $id): array
    {
        $r = app(RecipeService::class)->detailAnySicht($team, $id);
        if ($r === null) {
            return [];
        }
        $schwelle = (float) config('foodalchemist.conformance.konzentrat_anteil_max', self::KONZENTRAT_ANTEIL_MAX);
        if ($schwelle <= 0.0 || $schwelle >= 1.0) {
            return [];
        }

        // Einsatzmasse nur aus Zeilen mit MASSEN-Einheit bilden. Eine Stück-/Bund-Zeile trägt
        // hier 0 g bei und würde den Anteil künstlich hochrechnen — dieselbe Falle, die im
        // Yield-Check von DataQualityService dokumentiert ist. Ist die Masse nicht sauber
        // bestimmbar, wird NICHT geraten und der Check schweigt.
        $gewichte = [];
        $summe = 0.0;
        $unklar = false;
        foreach ($r->ingredients as $z) {
            $menge = (float) $z->quantity;
            $slug = (string) ($z->unit?->slug ?? '');
            $gramm = match ($slug) {
                'g', 'ml' => $menge,                    // ml ≈ g: für einen Anteils-Check genügt das
                'kg', 'l' => $menge * 1000.0,
                default => null,
            };
            if ($gramm === null || $menge <= 0.0) {
                if ($menge > 0.0) {
                    $unklar = true;
                }

                continue;
            }
            $summe += $gramm;
            $gewichte[] = [$z, $gramm];
        }
        if ($unklar || $summe <= 0.0 || $gewichte === []) {
            return [];
        }

        $befunde = [];
        foreach ($gewichte as [$z, $gramm]) {
            $gp = $z->gp;
            if ($gp === null) {
                continue;                               // ungeerdete Zeile: andere Baustelle
            }
            $text = mb_strtolower(trim(
                (string) $gp->name.' '.(string) ($gp->processing ?? '').' '.(string) ($gp->form ?? '')
            ));
            $marker = null;
            foreach (self::KONZENTRAT_MARKER as $m) {
                if (str_contains($text, $m)) {
                    $marker = $m;

                    break;
                }
            }
            if ($marker === null) {
                continue;
            }
            $anteil = $gramm / $summe;
            if ($anteil <= $schwelle) {
                continue;
            }
            $befunde[] = [
                'paragraph' => '§6',                    // Mengen, Einheiten & Yield
                'schweregrad' => 'hart',
                'feld' => 'zutaten',
                'begruendung' => sprintf(
                    '»%s« ist eine Konzentrat-/Trockenform (Marker »%s») und trägt %s %% der '
                    .'Einsatzmasse (%s g von %s g). Solche Formen sind Würz- und Aromakomponenten, '
                    .'nicht die Hauptmasse — als Hauptzutat ist die Menge um etwa eine '
                    .'Größenordnung zu hoch.',
                    (string) $gp->name,
                    $marker,
                    number_format($anteil * 100, 1, ',', '.'),
                    number_format($gramm, 0, ',', '.'),
                    number_format($summe, 0, ',', '.'),
                ),
                'vorschlag' => 'Entweder die Menge auf eine Würz-Dosierung senken, oder auf die '
                    .'passende Grundform wechseln (frisch / konserviert / passiert).',
                'konfidenz' => 1.0,                     // deterministisch gerechnet, nicht geschätzt
            ];
        }

        return $befunde;
    }

    public function pruefauftrag(Team $team, int $id): array
    {
        $r = app(RecipeService::class)->detailAnySicht($team, $id);
        if ($r === null) {
            throw new \RuntimeException('Rezept nicht gefunden oder nicht sichtbar.');
        }
        $vk = (bool) $r->is_sales_recipe;

        $kontext = [
            'artefakt_typ' => $vk ? 'Verkaufsgericht (VK)' : 'Basisrezept/Komponente',
            'name' => $r->name,
            'kategorie' => $r->kategorie?->label,
            'beschreibung' => $r->description,
            'zutaten' => $r->ingredients->map(fn ($z) => [
                'text' => $z->gp?->name ?? $z->referencedRecipe?->name ?? $z->display_name ?? $z->raw_text,
                'menge' => (float) $z->quantity,
                'einheit_slug' => $z->unit?->slug,
                'geerdet' => $z->gp_id !== null || $z->referenced_recipe_id !== null,
                'ist_sub_rezept' => $z->referenced_recipe_id !== null,
            ])->values()->all(),
        ];

        if ($vk) {
            // Verkaufs-Facetten sind Prüf-MASSSTAB (passt Name/Klasse zur §-Regel?),
            // gespiegelt aus dem Review-Kontext — kein Schreibziel.
            $kontext['speisen_klasse'] = $r->dishClass?->label;
            $kontext['diaetform'] = $r->dishClass?->diet_form;
            $kontext['portion_g'] = $r->sales_quantity_per_unit_g;
            $kontext['verkaufseinheiten'] = $r->sales_unit_count;
        } else {
            $kontext['ansatz_kg'] = $r->yield_kg_manual ?? $r->yield_kg;
            $kontext['ansatz_stueck'] = $r->yield_pieces;
        }

        // Basisrezept → Basisrezepte-Regelwerk (§-Dossiers). VK → zusätzlich das
        // Verkaufsgerichte-Regelwerk (Einzel-Dossier, kein §-Split). Volle §-Texte
        // lädt der ConformanceService anhand dieser Slug-Präfixe.
        $praefixe = ['regelwerk-basisrezepte-'];
        if ($vk) {
            $praefixe[] = 'regelwerk.regelwerk_verkaufsgerichte';
        }

        return [
            // Stabiler Domaenen-Schluessel — NICHT der Anzeigetext aus dem Kontext.
            'artefakt' => $vk ? 'vk' : 'basisrezept',
            'kontext' => $kontext,
            'regelwerk_praefixe' => $praefixe,
            'target_table' => 'foodalchemist_recipes',
        ];
    }

    /**
     * Selbstheil-Runde: EIN Freitext-Revise (`recipe.ueberarbeiten`) nach der Verstoß-
     * Direktive, angewendet über DIESELBE Strecke wie der manuelle „✨ KI-Überarbeiten"
     * (syncZeilen → syncIngredients mit #508-Grounding; Texte mit Lineage ki, Override-
     * First). Gespiegelt aus {@see \Platform\FoodAlchemist\Livewire\Recipes\RecipeModal::ueberarbeitungUebernehmen}
     * — ohne den Livewire-Zustand. Best-effort: liefert die KI nichts Verwertbares,
     * bleibt das Rezept unangetastet und der Verstoß steht danach als Hinweis.
     */
    public function revise(Team $team, int $id, string $direktive, array $befunde = []): void
    {
        $r = app(RecipeService::class)->detailAnySicht($team, $id);
        if ($r === null) {
            return;
        }

        $vorschlag = app(AiGatewayService::class)->propose($r->is_sales_recipe ? 'vk.ueberarbeiten' : 'recipe.ueberarbeiten', [
            'anweisung' => $direktive,
            'name' => $r->name,
            'kategorie' => $r->is_sales_recipe ? $r->dishMainGroup?->code : $r->kategorie?->label,
            'diaetform' => $r->is_sales_recipe ? $r->dishClass?->diet_form : null,
            'description' => $r->description,
            'preparation' => $r->preparation,
            'zutaten' => $r->ingredients->map(fn ($z) => [
                'id' => $z->id,
                'text' => $z->gp?->name ?? $z->referencedRecipe?->name ?? $z->display_name ?? $z->raw_text,
                'quantity' => (float) $z->quantity,
                'einheit_slug' => $z->unit?->slug,
            ])->values()->all(),
        ]);

        $werte = $vorschlag->werte;
        $conf = max(0.0, min(1.0, $vorschlag->confidence));

        // Zutaten: Voll-Ersatz über den geteilten Revise-Pfad (#508-Grounding hängt dran).
        if (! empty($werte['zutaten']) && is_array($werte['zutaten'])) {
            $zeilen = app(RecipeReviseService::class)->syncZeilen($r, $werte['zutaten']);
            if ($zeilen !== []) {
                app(RecipeService::class)->syncIngredients($team, $id, $zeilen);
            }
        }

        // Texte: direkter Write mit Lineage ki — manuell gepflegte Felder bleiben unangetastet.
        $frisch = $r->fresh();
        $name = trim((string) ($werte['name'] ?? ''));
        if ($name === '') {
            // Der Critic hat bereits einen kontrollierten Zielwert geliefert. Falls der freie
            // Revise-Call das neue `name`-Feld auslässt, darf der erkannte harte Naming-Verstoß
            // nicht wirkungslos bleiben.
            $name = $this->findingVorschlag($befunde, 'name', nurHart: true);
        }
        if ($name !== '' && $name !== (string) $frisch->name) {
            $frisch->update(['name' => $name]);
        }
        if (is_string($werte['description'] ?? null) && trim($werte['description']) !== '' && $frisch->description_source !== 'manual') {
            $frisch->update([
                'description' => $werte['description'],
                'description_source' => 'ki',
                'description_ai_confidence' => $conf,
            ]);
        }
        if (is_string($werte['preparation'] ?? null) && trim($werte['preparation']) !== '' && $frisch->preparation_source !== 'manual') {
            $frisch->update(['preparation_source' => 'ki', 'preparation_ai_confidence' => $conf]);
            app(RecipeStepService::class)->ausMarkdown($frisch, $werte['preparation'], ueberschreiben: true);
        }

        $this->applyControlledSuggestions($team, $frisch->fresh(), $befunde);
    }

    /** Kritiker-Vorschlag für ein exakt benanntes Feld; leere/unsichere Vorschläge bleiben No-op. */
    private function findingVorschlag(array $befunde, string $feld, bool $nurHart = false): string
    {
        foreach ($befunde as $befund) {
            if (! is_array($befund) || (string) ($befund['feld'] ?? '') !== $feld) {
                continue;
            }
            if ($nurHart && (string) ($befund['schweregrad'] ?? '') !== 'hart') {
                continue;
            }
            $vorschlag = trim((string) ($befund['vorschlag'] ?? ''));
            if ($vorschlag !== '') {
                return $vorschlag;
            }
        }

        return '';
    }

    /**
     * Kontrollierte Facetten nicht als Freitext speichern: ein Critic-Vorschlag wird nur
     * übernommen, wenn er im sichtbaren DB-Vokabular exakt auflösbar ist.
     */
    private function applyControlledSuggestions(Team $team, $recipe, array $befunde): void
    {
        $kategorie = $this->findingVorschlag($befunde, 'kategorie', nurHart: true);
        if ($kategorie !== '') {
            if ($recipe->is_sales_recipe) {
                $gruppe = FoodAlchemistDishMainGroup::visibleToTeam($team)
                    ->where(fn ($q) => $q->whereRaw('LOWER(code) = ?', [mb_strtolower($kategorie)])
                        ->orWhereRaw('LOWER(label) = ?', [mb_strtolower($kategorie)]))
                    ->first();
                if ($gruppe !== null) {
                    $recipe->update(['dish_main_group_id' => (int) $gruppe->id]);
                }
            } else {
                $category = FoodAlchemistRecipeCategory::visibleToTeam($team)
                    ->whereRaw('LOWER(label) = ?', [mb_strtolower($kategorie)])->first();
                if ($category !== null) {
                    $recipe->update(['category_id' => (int) $category->id]);
                }
            }
        }

        if (! $recipe->is_sales_recipe) {
            return;
        }
        $diaet = $this->findingVorschlag($befunde, 'diaetform', nurHart: true);
        $hauptgruppeId = (int) ($recipe->fresh()->dish_main_group_id ?? 0);
        if ($diaet === '' || $hauptgruppeId <= 0) {
            return;
        }
        $klasse = FoodAlchemistDishClass::visibleToTeam($team)
            ->where('dish_main_group_id', $hauptgruppeId)
            ->whereRaw('LOWER(diet_form) = ?', [mb_strtolower($diaet)])
            ->first();
        if ($klasse !== null) {
            $recipe->update(['dish_class_id' => (int) $klasse->id]);
        }
    }
}
