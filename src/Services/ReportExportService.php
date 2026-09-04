<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Collection;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistFormat;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarte;
use Platform\FoodAlchemist\Models\FoodAlchemistGeschirrSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;

/**
 * Zentrale Datenaufbereitung für druckbare/PDF-fähige Reports.
 *
 * Der Service rendert keine PDFs. Er liefert ein stabiles Datenpaket für HTML
 * und DomPDF. Vollkaskade bedeutet: Rezept/Gericht/Concept → Sub-Rezepte →
 * GP → Lead-Lieferantenartikel → aktueller Preis.
 */
class ReportExportService
{
    /** @var array<int, ?Team> Team je ID — die Zeilen-EK-Abfrage braucht das Team je Rezept. */
    private array $teamCache = [];

    /** @return array<string, bool|string> */
    public function optionen(array $query, string $scope): array
    {
        $profil = (string) ($query['profil'] ?? 'produktion');
        if (! in_array($profil, ['kurz', 'produktion', 'kalkulation', 'voll'], true)) {
            $profil = 'produktion';
        }

        $defaults = match ($profil) {
            'kurz' => [
                'stammdaten' => true, 'zutaten' => true, 'steps' => false, 'sensorik' => false,
                'produktion' => false, 'preise' => false, 'lieferanten' => false, 'kaskade' => false,
                'bilder' => false, 'deklaration' => false, 'naehrwerte' => false, 'notizen' => false, 'intern' => false, 'simulation' => false,
                'regeneration' => false, 'anrichten' => false, 'behaelter' => false,
            ],
            'kalkulation' => [
                'stammdaten' => true, 'zutaten' => true, 'steps' => false, 'sensorik' => false,
                'produktion' => false, 'preise' => true, 'lieferanten' => true, 'kaskade' => true,
                'bilder' => false, 'deklaration' => false, 'naehrwerte' => true, 'notizen' => false, 'intern' => true, 'simulation' => false,
                'regeneration' => false, 'anrichten' => false, 'behaelter' => false,
            ],
            'voll' => [
                'stammdaten' => true, 'zutaten' => true, 'steps' => true, 'sensorik' => true,
                'produktion' => true, 'preise' => true, 'lieferanten' => true, 'kaskade' => true,
                'bilder' => false, 'deklaration' => true, 'naehrwerte' => true, 'notizen' => true, 'intern' => true, 'simulation' => false,
                'regeneration' => true, 'anrichten' => true, 'behaelter' => true,
            ],
            default => [
                'stammdaten' => true, 'zutaten' => true, 'steps' => true, 'sensorik' => false,
                'produktion' => true, 'preise' => false, 'lieferanten' => false, 'kaskade' => true,
                'bilder' => false, 'deklaration' => false, 'naehrwerte' => false, 'notizen' => false, 'intern' => false, 'simulation' => false,
                'regeneration' => true, 'anrichten' => false, 'behaelter' => true,
            ],
        };

        // Format + Foodbook = symmetrisch zum Concept (jede Edition/jedes Kapitel-Concept IST ein
        // Concept): gleiche Profile, gleicher Filter-Satz, gleicher Preis-Default im Produktions-Profil.
        if (in_array($scope, ['concept', 'format', 'foodbook', 'speisekarte'], true) && $profil === 'produktion') {
            $defaults['preise'] = true;
        }

        foreach (array_keys($defaults) as $key) {
            if (array_key_exists($key, $query)) {
                $defaults[$key] = filter_var($query[$key], FILTER_VALIDATE_BOOLEAN);
            }
        }

        $optionen = ['profil' => $profil, ...$defaults];
        if ($scope === 'concept') {
            $optionen['pax'] = min(1_000_000, max(0, (int) ($query['pax'] ?? 0)));
        }
        if ($scope === 'recipe') {
            // E (Dominique, 2026-09-04): Bedarfs-Hochrechnung wie die Concept-Auftrags-
            // simulation, aber am Rezept. Zwei Eingabe-Sichten auf EINE Mechanik:
            // Basisrezept in Ziel-Kilo, Gericht in „N × Darreichung" (deren Portionsgewicht
            // die Zielmasse ergibt). Entscheid: Basisrezept nur kg, Gericht mit Umschalter.
            $optionen['ziel_kg'] = max(0.0, (float) str_replace(',', '.', (string) ($query['ziel_kg'] ?? 0)));
            $optionen['ziel_menge'] = min(1_000_000, max(0, (int) ($query['ziel_menge'] ?? 0)));
            $optionen['darreichung'] = max(0, (int) ($query['darreichung'] ?? 0));
        }

        return $optionen;
    }

    /** @return array<string, mixed> */
    public function rezeptDaten(Team $team, int $id, array $optionen): array
    {
        $recipe = FoodAlchemistRecipe::visibleToTeam($team)
            ->with($this->recipeRelations())
            ->findOrFail($id);

        $hoch = $this->hochrechnung($recipe, $optionen);
        $optionen['faktor'] = $hoch['faktor'];
        $baum = $this->recipeNode($recipe, $optionen, 0, []);

        return [
            'typ' => $recipe->is_sales_recipe ? 'gericht' : 'basisrezept',
            'titel' => $recipe->is_sales_recipe ? 'Gericht' : 'Basisrezept',
            'name' => $recipe->name,
            'optionen' => $optionen,
            'recipe' => $baum,
            'concept' => null,
            'hochrechnung' => $hoch,
        ];
    }

    /**
     * E: Bedarfs-Faktor für den Report. EINE Mechanik — Zielmasse ÷ Ansatz-Ausbeute —
     * mit zwei Eingabe-Sichten:
     *   · Basisrezept: Ziel in kg direkt („50 kg Linsensalat")
     *   · Gericht:     N × Darreichung; die Zielmasse kommt aus dem Portionsgewicht der
     *                  gewählten Darreichung (Standard vorgewählt, umschaltbar). Damit
     *                  stimmt „je nach Verkaufseinheit" von selbst: Teller, Platte und
     *                  Stück tragen je ein eigenes Gramm-Gewicht.
     *
     * Ohne Ausbeute (yield_kg leer/0) gibt es keinen Faktor — dann bleibt der Report der
     * Ansatz, mit Hinweis. Nichts wird geschätzt.
     *
     * @return array<string, mixed>
     */
    private function hochrechnung(FoodAlchemistRecipe $recipe, array $optionen): array
    {
        $darreichungen = $recipe->is_sales_recipe
            ? $recipe->darreichungen()->with('servingForm')->get()
                ->map(fn ($d) => [
                    'id' => (int) $d->id,
                    'label' => $d->servingForm?->label ?? 'Darreichung #'.$d->id,
                    'gramm' => $d->quantity_per_unit_g !== null ? (float) $d->quantity_per_unit_g : null,
                    'is_standard' => (bool) $d->is_standard,
                ])->values()->all()
            : [];

        $aus = [
            'aktiv' => false, 'faktor' => null, 'ziel_kg' => null, 'ziel_menge' => null,
            'darreichungen' => $darreichungen, 'darreichung' => null, 'hinweis' => null,
        ];

        $yield = $recipe->yield_kg !== null ? (float) $recipe->yield_kg : 0.0;
        $zielKg = (float) ($optionen['ziel_kg'] ?? 0);
        $zielMenge = (int) ($optionen['ziel_menge'] ?? 0);

        if ($zielKg <= 0 && $zielMenge <= 0) {
            return $aus;
        }
        if ($yield <= 0) {
            return ['hinweis' => 'Keine Ausbeute hinterlegt — ohne Ansatz-Menge lässt sich der Bedarf nicht hochrechnen.'] + $aus;
        }

        if ($zielMenge > 0 && $darreichungen !== []) {
            $gewaehlt = collect($darreichungen)->firstWhere('id', (int) ($optionen['darreichung'] ?? 0))
                ?? collect($darreichungen)->firstWhere('is_standard', true)
                ?? $darreichungen[0];
            if (($gewaehlt['gramm'] ?? null) === null || $gewaehlt['gramm'] <= 0) {
                return ['hinweis' => 'Für „'.$gewaehlt['label'].'" ist kein Portionsgewicht hinterlegt — '
                    .'ohne das ist die Zielmasse unbekannt.', 'darreichung' => $gewaehlt] + $aus;
            }
            $zielKg = $zielMenge * $gewaehlt['gramm'] / 1000;
            $aus['darreichung'] = $gewaehlt;
            $aus['ziel_menge'] = $zielMenge;
        }

        if ($zielKg <= 0) {
            return ['hinweis' => 'Zielmenge unklar — bitte Kilo oder eine Darreichung mit Portionsgewicht angeben.'] + $aus;
        }

        return array_merge($aus, [
            'aktiv' => true,
            'faktor' => $zielKg / $yield,
            'ziel_kg' => round($zielKg, 3),
            'ansatz_kg' => round($yield, 3),
        ]);
    }

    /** @return array<string, mixed> */
    public function conceptDaten(Team $team, int $id, array $optionen): array
    {
        $concept = FoodAlchemistConcept::visibleToTeam($team)
            ->with([
                'category:id,name',
                'eventType:id,name',
                'servingForm:id,label',
                'serviceMoments:id,name',
                'seasons:id,name',
                'slots' => fn ($q) => $q->orderBy('position'),
                'slots.unit:id,slug,display_de',
                'slots.dish' => fn ($q) => $q->with($this->recipeRelations()),
                'slots.package.dishes.unit:id,slug,display_de',
                'slots.package.dishes.dish' => fn ($q) => $q->with($this->recipeRelations()),
            ])
            ->findOrFail($id);

        $slots = $concept->slots->map(function ($slot) use ($optionen) {
            $gerichte = collect();
            if ($slot->dish !== null) {
                $gerichte->push([
                    'quelle' => 'gericht',
                    'menge' => $this->mengeText($slot->quantity, $slot->unit),
                    'recipe' => $this->recipeNode($slot->dish, $optionen, 0, []),
                ]);
            }
            if ($slot->package !== null) {
                foreach ($slot->package->dishes as $paketGericht) {
                    if ($paketGericht->dish === null) {
                        continue;
                    }
                    $gerichte->push([
                        'quelle' => 'paket',
                        'paket' => $slot->package->name,
                        'menge' => $this->mengeText($paketGericht->quantity, $paketGericht->unit),
                        'recipe' => $this->recipeNode($paketGericht->dish, $optionen, 0, []),
                    ]);
                }
            }

            return [
                'id' => (int) $slot->id,
                'position' => (int) $slot->position,
                'role' => $slot->role,
                'title' => $slot->title,
                'type' => $slot->package_id !== null ? 'paket' : ($slot->sales_recipe_id !== null ? 'gericht' : 'leer'),
                'package' => $slot->package ? [
                    'id' => (int) $slot->package->id,
                    'name' => $slot->package->name,
                    'price_per_person' => $slot->package->price_per_person,
                    'ek_per_person' => $slot->package->ek_per_person,
                    'food_cost_percent' => $slot->package->food_cost_percent,
                ] : null,
                'gerichte' => $gerichte->values()->all(),
            ];
        })->values();

        $auftragsSimulation = null;
        $pax = (int) ($optionen['pax'] ?? 0);
        if ($pax > 0 && ($optionen['simulation'] ?? false)) {
            // Ebene 2: ein via $optionen durchgereichter Betrieb rechnet die Sim mit dessen Kosten (sonst Team-Baseline).
            $simOutlet = ($optionen['outlet'] ?? null) instanceof \Platform\FoodAlchemist\Models\FoodAlchemistOutlet ? $optionen['outlet'] : null;
            $auftragsSimulation = app(OrderCostingService::class)->costConcept($team, $concept, $pax, $simOutlet);
        }

        return [
            'typ' => 'concept',
            'titel' => 'Concept',
            'name' => $concept->name,
            'optionen' => $optionen,
            'recipe' => null,
            'concept' => [
                'id' => (int) $concept->id,
                'name' => $concept->name,
                'consumer_name' => $concept->consumer_name,
                'occasion' => $concept->occasion,
                'level' => $concept->level,
                'status' => $concept->status,
                'description' => $concept->description,
                'price_per_person_cache' => $concept->price_per_person_cache,
                'ek_per_person_cache' => $concept->ek_per_person_cache,
                'work_time_min_cache' => $concept->work_time_min_cache,
                'category' => $concept->category?->name,
                'event_type' => $concept->eventType?->name,
                'serving_form' => $concept->servingForm?->label,
                'moments' => $concept->serviceMoments->pluck('name')->values()->all(),
                'seasons' => $concept->seasons->pluck('name')->values()->all(),
                'slots' => $slots->all(),
                'order_simulation' => $auftragsSimulation,
            ],
        ];
    }

    /**
     * F3b: Technischer Format-Report — spiegelt {@see conceptDaten}, aber eine Ebene höher.
     * Ein Format hat keine eigene Gericht-Produktion; jede type=concept-Position IST eine
     * Edition (= ein Concept) und wird über die IDENTISCHE Concept-Report-Auflösung gebaut,
     * damit derselbe Filter-Satz (Preise/Lieferanten/Anleitung/Bilder/Deklaration/Nährwerte/
     * Sensorik/Produktion/Notizen/Kaskade) je Edition greift. header/text/spacer bleiben als
     * Struktur in Reihenfolge erhalten. Der Diskriminator `format` schaltet den Report-Zweig.
     *
     * @return array<string, mixed>
     */
    public function formatDaten(Team $team, int $formatId, array $optionen): array
    {
        $format = FoodAlchemistFormat::visibleToTeam($team)
            ->with([
                'slots' => fn ($q) => $q->orderBy('position'),
                'slots.concept:id,name,consumer_name,claim,description,status,price_per_person_cache',
                'heroImage',
                // F2e: priceRange liest ausschließlich slots.concept (Alt-Editionen-Fallback entfernt).
                'servingForm:id,label',
                'eventType:id,name',
                'serviceMoments:id,name',
                'seasons:id,name',
            ])
            ->findOrFail($formatId);

        $positionen = [];
        foreach ($format->slots as $slot) {
            if ($slot->type === 'concept') {
                $concept = $slot->concept;
                if ($concept === null) {
                    continue; // referenziertes Concept nicht mehr sichtbar → Position auslassen
                }
                try {
                    // Volle, filter-identische Concept-Report-Auflösung je Edition wiederverwenden.
                    $positionen[] = [
                        'kind' => 'edition',
                        'concept' => $this->conceptDaten($team, (int) $concept->id, $optionen)['concept'],
                    ];
                } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
                    continue; // Edition nicht (mehr) team-sichtbar → auslassen
                }
            } elseif ($slot->type === 'header') {
                $text = trim((string) $slot->title);
                if ($text !== '') {
                    $positionen[] = ['kind' => 'header', 'text' => $text];
                }
            } elseif ($slot->type === 'text') {
                $text = trim((string) $slot->text_content);
                if ($text !== '') {
                    $positionen[] = ['kind' => 'text', 'text' => $text];
                }
            } elseif ($slot->type === 'spacer') {
                $positionen[] = ['kind' => 'spacer', 'height' => $slot->height ?: 'mittel'];
            }
        }

        return [
            'typ' => 'format',
            'titel' => 'Format',
            'name' => (string) $format->name,
            'optionen' => $optionen,
            'recipe' => null,
            'concept' => null,
            'format' => [
                'id' => (int) $format->id,
                'name' => (string) $format->name,
                'consumer_name' => $format->consumer_name,
                'claim' => $format->claim,
                'story' => $format->story,
                'origin' => $format->origin,
                'status' => $format->status,
                'price_range' => $format->priceRange(),
                'serving_form' => $format->servingForm?->label,
                'event_type' => $format->eventType?->name,
                'moments' => $format->serviceMoments->pluck('name')->values()->all(),
                'seasons' => $format->seasons->pluck('name')->values()->all(),
                // Hero nur wenn Bilder-Filter an — spiegelt die Bilder-Gate-Logik der Rezept-Nodes.
                'hero' => ($optionen['bilder'] ?? false) ? $format->heroImage?->dataUri() : null,
                'positionen' => $positionen,
            ],
        ];
    }

    /**
     * #5a: Foodbook-Report — der TECHNISCHE Report mit Profilen + Filtern (wie Concept/Format),
     * nur eine Ebene höher: pro Kapitel drillen die concept_ref-/recipe_ref-Positionen filter-
     * identisch wie ein Concept-/Rezept-Report (inkl. Produktions-Kaskade — die lebt jetzt HIER,
     * nicht mehr im schönen Dokument). `?kapitel[]` filtert die Kapitel wie beim Dokument.
     *
     * @param  list<int>  $kapitelFilter
     * @return array<string, mixed>
     */
    public function foodbookDaten(Team $team, int $foodbookId, array $optionen, array $kapitelFilter = []): array
    {
        $fb = FoodAlchemistFoodbook::visibleToTeam($team)
            ->with([
                'chapters' => fn ($q) => $q->orderBy('position'),
                'chapters.blocks' => fn ($q) => $q->where('visible', true)->orderBy('position'),
                'chapters.blocks.dish:id,name',
                'crmCompany',
            ])
            ->findOrFail($foodbookId);

        $chapters = $fb->chapters;
        if ($kapitelFilter !== []) {
            $erlaubt = array_flip(array_map('intval', $kapitelFilter));
            $chapters = $chapters->filter(fn ($k) => isset($erlaubt[(int) $k->id]))->values();
            $vorhanden = array_flip($chapters->pluck('id')->map(fn ($v) => (int) $v)->all());
            $byParent = $chapters->groupBy(fn ($k) => ($k->parent_id !== null && isset($vorhanden[(int) $k->parent_id])) ? (int) $k->parent_id : 0);
        } else {
            $byParent = $chapters->groupBy(fn ($k) => $k->parent_id ?? 0);
        }

        $kapitelRows = [];
        $walk = function ($parentId, int $depth) use (&$walk, $byParent, &$kapitelRows, $team, $optionen) {
            foreach ($byParent[$parentId] ?? [] as $k) {
                $positionen = [];
                foreach ($k->blocks as $b) {
                    if ($b->type === 'concept_ref' && $b->concept_id !== null) {
                        try {
                            $positionen[] = ['kind' => 'concept', 'concept' => $this->conceptDaten($team, (int) $b->concept_id, $optionen)['concept']];
                        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
                            continue; // Concept nicht (mehr) sichtbar → auslassen
                        }
                    } elseif ($b->type === 'recipe_ref' && $b->sales_recipe_id !== null) {
                        try {
                            $positionen[] = ['kind' => 'recipe', 'name' => $b->dish?->name, 'recipe' => $this->rezeptDaten($team, (int) $b->sales_recipe_id, $optionen)['recipe']];
                        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
                            continue;
                        }
                    } elseif (str_starts_with((string) $b->type, 'header')) {
                        $t = trim((string) $b->label);
                        if ($t !== '') {
                            $positionen[] = ['kind' => 'header', 'text' => $t];
                        }
                    } elseif ($b->type === 'text') {
                        $t = trim((string) $b->customer_text);
                        if ($t !== '') {
                            $positionen[] = ['kind' => 'text', 'text' => $t];
                        }
                    }
                }
                $kapitelRows[] = ['title' => trim((string) ($k->consumer_title ?: $k->title)), 'depth' => $depth, 'positionen' => $positionen];
                $walk((int) $k->id, $depth + 1);
            }
        };
        $walk(0, 0);

        return [
            'typ' => 'foodbook',
            'titel' => 'Foodbook',
            'name' => (string) $fb->label,
            'optionen' => $optionen,
            'recipe' => null,
            'concept' => null,
            'format' => null,
            'foodbook' => [
                'id' => (int) $fb->id,
                'name' => (string) $fb->label,
                'customer' => $fb->crmCompany?->display_name,
                'kapitel' => $kapitelRows,
            ],
        ];
    }

    /**
     * Technischer Speisekarte-Report (Dominique 2026-08-27, Parität zum Foodbook-Report): Rubrik-Baum ×
     * Positionen, jede über den GETEILTEN Concept-/Rezept-Körper (Filter LITERAL dieselben wie Concept/
     * Format/Foodbook). menue_ref (Konzept ODER Paket) läuft über die Concept-Auflösung, gericht_ref über
     * den Rezept-Körper; header/text bleiben als Struktur in Reihenfolge. Die Produktions-Kaskade lebt HIER.
     *
     * @return array<string, mixed>
     */
    public function speisekarteDaten(Team $team, int $karteId, array $optionen, array $rubrikFilter = []): array
    {
        $karte = FoodAlchemistSpeisekarte::visibleToTeam($team)
            ->with([
                'sections' => fn ($q) => $q->orderBy('position'),
                'sections.items' => fn ($q) => $q->orderBy('position'),
                'sections.items.dish:id,name',
                'crmCompany',
            ])
            ->findOrFail($karteId);

        $sections = $karte->sections;
        if ($rubrikFilter !== []) {
            $erlaubt = array_flip(array_map('intval', $rubrikFilter));
            $sections = $sections->filter(fn ($r) => isset($erlaubt[(int) $r->id]))->values();
            $vorhanden = array_flip($sections->pluck('id')->map(fn ($v) => (int) $v)->all());
            $byParent = $sections->groupBy(fn ($r) => ($r->parent_id !== null && isset($vorhanden[(int) $r->parent_id])) ? (int) $r->parent_id : 0);
        } else {
            $byParent = $sections->groupBy(fn ($r) => $r->parent_id ?? 0);
        }

        $rubrikRows = [];
        $walk = function ($parentId, int $depth) use (&$walk, $byParent, &$rubrikRows, $team, $optionen) {
            foreach ($byParent[$parentId] ?? [] as $r) {
                $positionen = [];
                foreach ($r->items as $pos) {
                    if ($pos->type === 'menue_ref' && $pos->concept_id !== null) {
                        try {
                            $positionen[] = ['kind' => 'concept', 'concept' => $this->conceptDaten($team, (int) $pos->concept_id, $optionen)['concept']];
                        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
                            continue; // Concept nicht (mehr) sichtbar → auslassen
                        }
                    } elseif ($pos->type === 'gericht_ref' && $pos->sales_recipe_id !== null) {
                        try {
                            $positionen[] = ['kind' => 'recipe', 'name' => $pos->dish?->name, 'recipe' => $this->rezeptDaten($team, (int) $pos->sales_recipe_id, $optionen)['recipe']];
                        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
                            continue;
                        }
                    } elseif ($pos->type === 'header') {
                        $t = trim((string) $pos->label);
                        if ($t !== '') {
                            $positionen[] = ['kind' => 'header', 'text' => $t];
                        }
                    } elseif ($pos->type === 'text') {
                        $t = trim((string) $pos->consumer_text);
                        if ($t !== '') {
                            $positionen[] = ['kind' => 'text', 'text' => $t];
                        }
                    }
                }
                $rubrikRows[] = ['title' => trim((string) ($r->consumer_title ?: $r->title)), 'depth' => $depth, 'positionen' => $positionen];
                $walk((int) $r->id, $depth + 1);
            }
        };
        $walk(0, 0);

        return [
            'typ' => 'speisekarte',
            'titel' => 'Speisekarte',
            'name' => (string) $karte->name,
            'optionen' => $optionen,
            'recipe' => null,
            'concept' => null,
            'format' => null,
            'foodbook' => null,
            'speisekarte' => [
                'id' => (int) $karte->id,
                'name' => (string) $karte->name,
                'customer' => $karte->crmCompany?->display_name,
                'rubriken' => $rubrikRows,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function gpDaten(Team $team, int $id, array $optionen): array
    {
        $gp = FoodAlchemistGp::visibleToTeam($team)
            ->with([
                'commodity_group',
                'leadLa.supplier:id,name',
                'leadLa.prices' => fn ($q) => $q->orderByDesc('change_date')->orderByDesc('id')->limit(1),
                'structures.item.supplier:id,name',
                'recipeIngredients.recipe:id,name,is_sales_recipe,ek_total_eur,sales_net',
            ])
            ->findOrFail($id);

        $lead = $gp->leadLa;
        $price = $lead?->prices instanceof Collection ? $lead->prices->first() : null;

        return [
            'typ' => 'gp',
            'titel' => 'Grundprodukt',
            'name' => $gp->name,
            'optionen' => $optionen,
            'recipe' => null,
            'concept' => null,
            'report' => [
                'kind' => 'gp',
                'gp' => [
                    'id' => (int) $gp->id,
                    'name' => $gp->name,
                    'status' => $gp->status?->value ?? (string) $gp->status,
                    'warengruppe' => $gp->commodity_group?->name ?? $gp->commodity_group_code,
                    'sub_category' => $gp->sub_category,
                    'lead_la' => $lead ? [
                        'supplier' => $lead->supplier?->name,
                        'article_number' => $lead->article_number,
                        'designation' => $lead->designation,
                        'packaging_unit' => $lead->packaging_unit,
                        'qty' => $lead->qty,
                        'unit_code' => $lead->unit_code,
                        'price' => $price?->price,
                    ] : null,
                    'tags' => $gp->setTags(),
                    'deklaration' => $this->gpDeklaration($gp),
                    'naehrwerte' => $this->gpNaehrwerte($gp),
                    'strukturen' => $gp->structures->map(fn ($s) => [
                        'supplier' => $s->item?->supplier?->name,
                        'article_number' => $s->item?->article_number,
                        'designation' => $s->item?->designation,
                        'needs_review' => (bool) $s->needs_review,
                    ])->values()->all(),
                    'verwendung' => $gp->recipeIngredients->map(fn ($ri) => [
                        'recipe' => $ri->recipe?->name,
                        'typ' => $ri->recipe?->is_sales_recipe ? 'Gericht' : 'Basisrezept',
                        'quantity' => $ri->quantity,
                        'raw_text' => $ri->raw_text,
                    ])->values()->all(),
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function supplierDaten(Team $team, int $id, array $optionen): array
    {
        $supplier = FoodAlchemistSupplier::visibleToTeam($team)
            ->with(['items' => fn ($q) => $q->whereNull('deleted_at')->orderBy('designation')])
            ->findOrFail($id);

        $items = $supplier->items()->whereNull('deleted_at')
            ->with(['structure.gp:id,name,lead_la_supplier_item_id', 'prices' => fn ($q) => $q->orderByDesc('change_date')->orderByDesc('id')->limit(1)])
            ->orderBy('designation')->get();

        return [
            'typ' => 'lieferant',
            'titel' => 'Lieferant',
            'name' => $supplier->name,
            'optionen' => $optionen,
            'recipe' => null,
            'concept' => null,
            'report' => [
                'kind' => 'supplier',
                'supplier' => [
                    'id' => (int) $supplier->id,
                    'name' => $supplier->name,
                    'status' => $supplier->status?->value ?? null,
                    'city' => $supplier->city,
                    'email_order' => $supplier->email_order,
                    'homepage' => $supplier->homepage,
                    'is_inactive' => (bool) $supplier->is_inactive,
                    'items' => $items->map(fn ($item) => [
                        'article_number' => $item->article_number,
                        'designation' => $item->designation,
                        'packaging_unit' => $item->packaging_unit,
                        'qty' => $item->qty,
                        'unit_code' => $item->unit_code,
                        'is_discontinued' => (bool) $item->is_discontinued,
                        'price' => $item->prices->first()?->price,
                        'gp' => $item->structure?->gp?->name,
                        'is_lead' => $item->structure?->gp !== null
                            && (int) $item->structure->gp->lead_la_supplier_item_id === (int) $item->id,
                    ])->values()->all(),
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function geschirrDaten(Team $team, int $id, array $optionen): array
    {
        $supplier = FoodAlchemistGeschirrSupplier::visibleToTeam($team)
            ->with(['items' => fn ($q) => $q->whereNull('deleted_at')->orderBy('label')])
            ->findOrFail($id);

        return [
            'typ' => 'geschirr',
            'titel' => 'Geschirr',
            'name' => $supplier->name,
            'optionen' => $optionen,
            'recipe' => null,
            'concept' => null,
            'report' => [
                'kind' => 'geschirr',
                'supplier' => [
                    'id' => (int) $supplier->id,
                    'name' => $supplier->name,
                    'city' => $supplier->city,
                    'email_order' => $supplier->email_order,
                    'homepage' => $supplier->homepage,
                    'is_inactive' => (bool) $supplier->is_inactive,
                    'items' => $supplier->items->map(fn ($item) => [
                        'artikel_nr' => $item->artikel_nr,
                        'label' => $item->label,
                        'category' => $item->category,
                        'material' => $item->material,
                        'form' => $item->form,
                        'color' => $item->color,
                        'masse' => $item->masse_label,
                        'rental_price' => $item->rental_price,
                        'pfand' => $item->pfand,
                        'unit' => $item->unit,
                        'is_inactive' => (bool) $item->is_inactive,
                    ])->values()->all(),
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function favoritenDaten(Team $team, array $optionen, int $limit = 300): array
    {
        $items = app(FavoriteGpService::class)->suggest($team, $limit, null, false);

        return [
            'typ' => 'favoriten',
            'titel' => 'Favoriten',
            'name' => 'Favoriten-Grundprodukte',
            'optionen' => $optionen,
            'recipe' => null,
            'concept' => null,
            'report' => [
                'kind' => 'favoriten',
                'items' => $items->values()->all(),
                'n_favoriten' => $items->where('is_favorite', true)->count(),
            ],
        ];
    }

    /** @return list<string> */
    private function recipeRelations(): array
    {
        return [
            'category:id,label',
            'dishClass:id,label',
            'dishMainGroup:id,label',
            'markupClass:id,label,code',
            'salesUnit:id,slug,display_de',
            'defaultStation:id,name,slug,group_name',
            'equipment:id,slug,name',
            // §3.2: das Regenerations-Programm je Komponente. Bis 2026-09-04 las es KEIN
            // Druckpfad — gepflegt wurde es im Editor, auf dem Blatt stand nur der
            // Ein-Zeiler der Standard-Darreichung (den niemand füllte).
            'regenerations' => fn ($q) => $q->whereNull('deleted_at')->orderBy('sort_order'),
            'regenerations.device:id,name',
            'steps',
            'steps.photos',
            // §3.3: die Anrichte-Ebene liegt in derselben Tabelle — eigene Relation, damit
            // die beiden Nummerierungen nicht in einer Liste landen.
            'platingSteps',
            'platingSteps.photos',
            'ingredients' => fn ($q) => $q->whereNull('deleted_at')->orderBy('position'),
            'ingredients.unit:id,slug,display_de,dimension,default_in_g,default_in_ml',
            'ingredients.gp.leadLa.supplier:id,name',
            'ingredients.gp.leadLa.prices' => fn ($q) => $q->orderByDesc('change_date')->orderByDesc('id')->limit(1),
            'ingredients.referencedRecipe',
        ];
    }

    /**
     * @param  array<int, true>  $visited
     * @return array<string, mixed>
     */
    /**
     * Behälter-Bedarf für ein Rezept im Bericht — je Zweck, auf die Zielmenge gerechnet.
     *
     * Bewusst NUR das eigene Rezept: die Komponenten stehen im Bericht ohnehin als eigene Knoten
     * und bringen ihren Bedarf dort selbst mit. Sie hier zusätzlich zu summieren hiesse, dasselbe
     * Geschirr zweimal aufs Blatt zu schreiben.
     *
     * @return array<int, array{zweck:string, kurz:?string}>|null
     */
    private function behaelterBedarf(FoodAlchemistRecipe $recipe, mixed $zielKg): ?array
    {
        $team = $recipe->teamRelation ?? \Platform\Core\Models\Team::find($recipe->team_id);
        if ($team === null) {
            return null;
        }

        $svc = app(\Platform\FoodAlchemist\Services\BehaelterBedarfService::class);
        $menge = $zielKg !== null ? (float) $zielKg : null;

        $ergebnisse = array_filter([
            $svc->abfuellen($team, $recipe, $menge),
            ...$svc->jeKomponente($team, [[
                'recipe' => $recipe, 'label' => $recipe->name, 'menge_kg' => (float) ($menge ?? 0),
            ]]),
        ]);

        $raus = [];
        foreach ($ergebnisse as $e) {
            $kurz = \Platform\FoodAlchemist\Services\BehaelterBedarfService::varianteKurz($e);
            if ($kurz !== null) {
                $raus[] = ['zweck' => $e['zweck'], 'kurz' => $kurz];
            }
        }

        return $raus === [] ? null : $raus;
    }

    private function recipeNode(
        FoodAlchemistRecipe $recipe,
        array $optionen,
        int $tiefe,
        array $visited,
        string $adresse = '',
        ?array $eltern = null,
    ): array {
        if (isset($visited[$recipe->id])) {
            return [
                'id' => (int) $recipe->id,
                'name' => $recipe->name,
                'zyklus' => true,
                'tiefe' => $tiefe,
                'adresse' => $adresse,
                'eltern' => $eltern,
            ];
        }
        $visited[$recipe->id] = true;

        if (! $recipe->relationLoaded('ingredients')) {
            $recipe->load($this->recipeRelations());
        }

        $sensorik = null;
        if ($optionen['sensorik'] ?? false) {
            try {
                $sensorik = app(SensorikService::class)->fuerRezept((int) $recipe->id);
            } catch (\Throwable) {
                $sensorik = ['leer' => true, 'fehler' => 'Sensorik konnte nicht gelesen werden.'];
            }
        }

        // Zeilen-EK aus der EINEN Kosten-Wahrheit (dieselbe T3-Kaskade, die
        // `ek_total_eur` erzeugt) — nicht im Report nachgerechnet. Σ Zeilen = EK gesamt.
        // UNGERUNDET (zeilenKostenUndMassen), weil der abgeleitete €/kg-Preis sonst bei
        // Amuse-Mengen auf 0 zusammenfällt: 0,008 kg × 0,18 €/kg = 0,0014 € → gerundet 0,00.
        $zeilenKosten = $this->zeilenKosten($recipe);

        return [
            'id' => (int) $recipe->id,
            'name' => $recipe->name,
            'adresse' => $adresse,
            'eltern' => $eltern,
            'is_sales_recipe' => (bool) $recipe->is_sales_recipe,
            'status' => $recipe->status?->value ?? (string) $recipe->status,
            'tiefe' => $tiefe,
            'zyklus' => false,
            'description' => $recipe->description,
            'preparation' => $recipe->preparation,
            'notes_manual' => $recipe->notes_manual,
            // E: Hochrechnung. Skaliert wird, was eine MENGE ist — Ausbeute, Stückzahl,
            // EK-Summe. Verhältniszahlen (€/kg, Wareneinsatz-%) bleiben, sie ändern sich
            // beim Skalieren nicht. Die Arbeitszeit bleibt bewusst UNSKALIERT: sie ist
            // nicht linear (Rüstzeit fällt einmal an, Batch-Grenzen und Standzeit rechnet
            // der Produktionsplaner) — sie hier mit dem Faktor zu multiplizieren wäre eine
            // erfundene Zahl. Der Report weist das aus.
            'yield_kg' => $this->skaliere($recipe->yield_kg, $optionen),
            'yield_pieces' => $this->skaliere($recipe->yield_pieces, $optionen),
            'ek_total_eur' => $this->skaliere($recipe->ek_total_eur, $optionen),
            'ek_per_kg_eur' => $recipe->ek_per_kg_eur,
            'sales_net' => $recipe->sales_net,
            'food_cost_percent' => ((float) ($recipe->sales_net ?? 0) > 0 && $recipe->ek_total_eur !== null)
                ? round(((float) $recipe->ek_total_eur / (float) $recipe->sales_net) * 100, 2)
                : null,
            'sales_wording_standard' => $recipe->sales_wording_standard,
            'plating_text' => $recipe->plating_text,
            'category' => $recipe->category?->label,
            'dish_class' => $recipe->dishClass?->label,
            'dish_main_group' => $recipe->dishMainGroup?->label,
            'markup_class' => $recipe->markupClass?->label ?? $recipe->markupClass?->code,
            'sales_unit' => $recipe->salesUnit?->display_de ?? $recipe->salesUnit?->slug,
            'produktion' => [
                'production_depth' => $recipe->production_depth,
                'work_time_min' => $recipe->work_time_min,
                'temperature' => $recipe->temperature,
                'function' => $recipe->function,
                'default_station' => $recipe->defaultStation?->name,
                'setup_time_min' => $recipe->setup_time_min,
                'max_vorlauf_tage' => $recipe->max_vorlauf_tage,
                'batch_max_kg' => $recipe->batch_max_kg,
                'batch_max_pieces' => $recipe->batch_max_pieces,
                // Fehlten hier, obwohl der Editor sie pflegt (Auto-Planer rechnet mit ihnen).
                'variable_work_time_min' => $recipe->variable_work_time_min,
                'variable_work_time_basis' => $recipe->variable_work_time_basis,
                'standzeit_min' => $recipe->standzeit_min,
                'equipment' => $recipe->equipment->pluck('name')->values()->all(),
            ],
            // §3.3 Anrichten als SCHRITTE (nicht mehr nur der Spiegel-Text): der Teller-Aufbau
            // ist der visuellste Arbeitsgang — die Fotos gehören mit aufs Blatt.
            'anrichte_schritte' => $recipe->platingSteps->sortBy('position')->values()->map(fn ($s) => [
                'position' => (int) $s->position,
                'phase' => $s->phase,
                'text' => $s->text,
                'photos' => ($optionen['bilder'] ?? false)
                    ? $s->photos->map(fn ($foto) => [
                        'id' => (int) $foto->id,
                        'caption' => $foto->caption,
                        'url' => $foto->url(),
                        'src' => $this->photoDataUri($foto->pfad) ?? $foto->url(),
                    ])->values()->all()
                    : [],
            ])->all(),
            // §3.2 Regeneration — eigene Ebene, eigener Schalter (`opt['regeneration']`).
            // Spec 51: aus der HOCHGERECHNETEN Ausbeute — der Bericht skaliert auf ein Ziel,
            // und der Behaelterbedarf muss mitskalieren, sonst zeigt er die Ansatzgroesse.
            'behaelter' => ($optionen['behaelter'] ?? false)
                ? $this->behaelterBedarf($recipe, $this->skaliere($recipe->yield_kg, $optionen))
                : null,
            'regenerationen' => $recipe->regenerations->map(fn ($r) => [
                'komponente' => $r->component_label,
                'geraet' => $r->device?->name,
                'temp_c' => $r->temp_c,
                'duration_min' => $r->duration_min,
                'core_temp_c' => $r->core_temp_c,
                'note' => $r->note,
            ])->values()->all(),
            'steps' => $recipe->steps->sortBy('position')->values()->map(fn ($s) => [
                'position' => (int) $s->position,
                'phase' => $s->phase,
                'text' => $s->text,
                'photos' => ($optionen['bilder'] ?? false)
                    ? $s->photos->map(fn ($foto) => [
                        'id' => (int) $foto->id,
                        'caption' => $foto->caption,
                        'url' => $foto->url(),
                        'src' => $this->photoDataUri($foto->context_file_id, $foto->pfad) ?? $foto->url(),
                    ])->values()->all()
                    : [],
            ])->all(),
            'deklaration' => $this->recipeDeklaration($recipe),
            'naehrwerte' => $this->recipeNaehrwerte($recipe),
            'sensorik' => $sensorik,
            'ingredients' => $recipe->ingredients->values()
                ->map(fn ($z, $i) => $this->ingredientNode(
                    $z,
                    $optionen,
                    $tiefe,
                    $visited,
                    $i + 1,
                    $recipe->ingredients->count(),
                    $adresse,
                    ['id' => (int) $recipe->id, 'name' => $recipe->name, 'adresse' => $adresse],
                    $zeilenKosten[$z->id] ?? null,
                ))->all(),
        ];
    }

    /**
     * E: Menge × Bedarfs-Faktor. Ohne aktive Hochrechnung unverändert (identity), damit
     * der Normal-Report byte-gleich bleibt und die Hochrechnung keine zweite Render-Route
     * aufmacht. NULL bleibt NULL — eine fehlende Zahl wird durch Skalieren nicht besser.
     */
    private function skaliere(mixed $wert, array $optionen): mixed
    {
        $faktor = $optionen['faktor'] ?? null;
        if ($faktor === null || $wert === null) {
            return $wert;
        }

        return round((float) $wert * (float) $faktor, 4);
    }

    /**
     * Zeilen-EK je Zutat-ID über {@see RecipeRecomputeService::zeilenKosten} — dieselbe
     * Quelle wie der Recompute, damit der Report keine zweite Preis-Wahrheit aufmacht.
     * Fail-soft: ohne Team/bei Fehlern bleibt die Spalte leer statt den Report zu kippen.
     *
     * @return array<int, ?float>
     */
    private function zeilenKosten(FoodAlchemistRecipe $recipe): array
    {
        try {
            $team = $recipe->team_id !== null
                ? ($this->teamCache[$recipe->team_id] ??= Team::find($recipe->team_id))
                : null;

            $zeilen = app(RecipeRecomputeService::class)->zeilenKostenUndMassen($recipe, $team);

            return array_map(fn ($z) => $z['kosten'], $zeilen);
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array<string, mixed> */
    private function ingredientNode(
        $z,
        array $optionen,
        int $tiefe,
        array $visited,
        int $nr = 1,
        int $von = 1,
        string $elternAdresse = '',
        ?array $elternInfo = null,
        ?float $zeilenEk = null,
    ): array {
        $gp = $z->gp;
        $sub = $z->referencedRecipe;
        $lead = $gp?->leadLa;
        $price = $lead?->prices instanceof Collection ? $lead->prices->first() : null;

        // Kaskaden-Adresse: Komponente 3 des Gerichts = „K3", ihre 2. Komponente = „K3.2".
        // Sie macht die Zugehörigkeit über Seitenumbrüche hinweg lesbar (Einrückung allein
        // reicht in DomPDF nicht — dort gab es sie bisher gar nicht).
        $kindAdresse = $sub !== null
            ? ($elternAdresse === '' ? 'K' . $nr : $elternAdresse . '.' . $nr)
            : null;

        [$proEinheit, $proEinheitLabel] = $this->preisProEinsatzEinheit($z, $zeilenEk);

        return [
            'id' => (int) $z->id,
            'nr' => $nr,
            'position' => (int) $z->position,
            'adresse' => $kindAdresse,
            'name' => $sub?->name ?? $gp?->name ?? $z->display_name ?? $z->raw_text,
            'raw_text' => $z->raw_text,
            'menge' => $this->mengeText($this->skaliere($z->quantity, $optionen), $z->unit),
            'quantity' => $this->skaliere($z->quantity, $optionen),
            'unit' => $z->unit?->display_de ?? $z->unit?->slug,
            'role' => $z->role,
            'type' => $sub !== null ? 'basisrezept' : ($gp !== null ? 'gp' : 'offen'),
            'ek_anteil_eur' => $this->skaliere($zeilenEk, $optionen),
            'ek_pro_einheit_eur' => $proEinheit,                   // Bezugspreis — skaliert NICHT
            'ek_pro_einheit_label' => $proEinheitLabel,
            'gp' => $gp ? $this->gpNode($gp, $lead, $price) : null,
            'subrecipe' => ($sub !== null && ($optionen['kaskade'] ?? false))
                ? $this->recipeNode(
                    $sub,
                    $optionen,
                    $tiefe + 1,
                    $visited,
                    (string) $kindAdresse,
                    ($elternInfo ?? []) + [
                        'nr' => $nr,
                        'von' => $von,
                        'einsatz' => $this->mengeText($this->skaliere($z->quantity, $optionen), $z->unit),
                    ],
                )
                : null,
        ];
    }

    /**
     * Bezugspreis in der Einsatz-Einheit, abgeleitet aus dem Zeilen-EK (`Anteil ÷ Menge`) —
     * also aus derselben Kosten-Wahrheit, kein zweiter Preis-Pfad. Masse wird auf €/kg,
     * Volumen auf €/l normalisiert (€/g wäre unlesbar klein), alles andere bleibt
     * €/Einsatzeinheit. Bei Mengen-Bereichen zählt der Mittelwert (wie I6/F6.4).
     *
     * @return array{0: ?float, 1: ?string}
     */
    private function preisProEinsatzEinheit($z, ?float $zeilenEk): array
    {
        if ($zeilenEk === null) {
            return [null, null];
        }

        $menge = $z->quantity_max !== null
            ? ((float) $z->quantity + (float) $z->quantity_max) / 2
            : (float) $z->quantity;
        if ($menge <= 0) {
            return [null, null];
        }

        $proEinheit = $zeilenEk / $menge;
        $unit = $z->unit;

        if ($unit?->dimension === 'mass' && (float) ($unit->default_in_g ?? 0) > 0) {
            return [$proEinheit * (1000 / (float) $unit->default_in_g), '€/kg'];
        }
        if ($unit?->dimension === 'volume' && (float) ($unit->default_in_ml ?? 0) > 0) {
            return [$proEinheit * (1000 / (float) $unit->default_in_ml), '€/l'];
        }

        $label = $unit?->display_de ?? $unit?->slug;

        return [$proEinheit, $label ? '€/' . $label : null];
    }

    /** @return array<string, mixed> */
    private function gpNode(FoodAlchemistGp $gp, $lead, $price): array
    {
        return [
            'id' => (int) $gp->id,
            'name' => $gp->name,
            'commodity_group_code' => $gp->commodity_group_code,
            'lead_la' => $lead ? [
                'id' => (int) $lead->id,
                'designation' => $lead->designation ?? $lead->name ?? null,
                'article_number' => $lead->article_number ?? null,
                'packaging_unit' => $lead->packaging_unit ?? null,
                'qty' => $lead->qty,
                'unit_code' => $lead->unit_code,
                'supplier' => $lead->supplier?->name,
                'price' => $price?->price,
                'price_partial' => $price?->price_partial,
            ] : null,
        ];
    }

    private function mengeText($quantity, $unit): ?string
    {
        if ($quantity === null || $quantity === '') {
            return null;
        }

        return rtrim(rtrim(number_format((float) $quantity, 3, ',', '.'), '0'), ',')
            . ($unit ? ' ' . ($unit->display_de ?? $unit->slug) : '');
    }

    /** @return array<string, mixed> */
    private function recipeDeklaration(FoodAlchemistRecipe $recipe): array
    {
        $allergene = [];
        foreach (\Platform\FoodAlchemist\Models\FoodAlchemistItemAllergen::ALLERGENE as $feld => $label) {
            $allergene[] = ['key' => $feld, 'label' => $label, 'wert' => $recipe->{"allergen_{$feld}"} ?? 'unbekannt'];
        }

        $zusatzstoffe = [];
        foreach (\Platform\FoodAlchemist\Models\FoodAlchemistItemDeclaration::STOFFE as $feld => $label) {
            $zusatzstoffe[] = ['key' => $feld, 'label' => $label, 'wert' => $recipe->{"additive_{$feld}"}];
        }

        return [
            'specs' => [
                'Vegan' => $recipe->spec_is_vegan,
                'Vegetarisch' => $recipe->spec_is_vegetarian,
                'Halal' => $recipe->spec_is_halal,
                'Glutenfrei' => $recipe->spec_is_gluten_free,
                'Laktosefrei' => $recipe->spec_is_lactose_free,
                'Enthält Schwein' => $recipe->spec_contains_pork,
                'Enthält Rind' => $recipe->spec_contains_beef,
            ],
            'allergene' => $allergene,
            'allergens_confidence' => $recipe->allergens_confidence,
            'zusatzstoffe' => $zusatzstoffe,
        ];
    }

    /** @return array<string, mixed> */
    private function recipeNaehrwerte(FoodAlchemistRecipe $recipe): array
    {
        return [
            'kcal' => $recipe->nutri_kcal_per_100g,
            'protein_g' => $recipe->nutri_protein_g_per_100g,
            'fat_g' => $recipe->nutri_fat_g_per_100g,
            'saturated_fat_g' => $recipe->nutri_saturated_fat_g_per_100g,
            'carbs_g' => $recipe->nutri_carbs_g_per_100g,
            'sugar_g' => $recipe->nutri_sugar_g_per_100g,
            'salt_g' => $recipe->nutri_salt_g_per_100g,
            'confidence' => $recipe->nutri_confidence,
            'mapped' => $recipe->nutri_n_ingredients_mapped,
            'total' => $recipe->nutri_n_ingredients_total,
        ];
    }

    /** @return array<string, mixed> */
    private function gpDeklaration(FoodAlchemistGp $gp): array
    {
        $allergene = [];
        foreach (\Platform\FoodAlchemist\Models\FoodAlchemistItemAllergen::ALLERGENE as $feld => $label) {
            $allergene[] = ['key' => $feld, 'label' => $label, 'wert' => $gp->{"allergen_{$feld}"} ?? 'unbekannt'];
        }

        return [
            'specs' => [
                'Vegan' => $gp->tag_is_vegan,
                'Vegetarisch' => $gp->tag_is_vegetarian,
                'Halal' => $gp->tag_is_halal,
                'Glutenfrei' => $gp->tag_is_gluten_free,
                'Laktosefrei' => $gp->tag_is_lactose_free,
                'Enthält Schwein' => $gp->tag_contains_pork,
                'Enthält Rind' => $gp->tag_contains_beef,
            ],
            'allergene' => $allergene,
            'allergens_confidence' => $gp->allergens_confidence,
            'zusatzstoffe' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function gpNaehrwerte(FoodAlchemistGp $gp): array
    {
        return [
            'kcal' => $gp->nutri_kcal_per_100g,
            'protein_g' => $gp->nutri_protein_g_per_100g,
            'fat_g' => $gp->nutri_fat_g_per_100g,
            'saturated_fat_g' => null,
            'carbs_g' => $gp->nutri_carbs_g_per_100g,
            'sugar_g' => null,
            'salt_g' => $gp->nutri_salt_g_per_100g,
            'confidence' => $gp->nutri_ai_confidence,
            'source' => $gp->nutri_source,
            'mapped' => null,
            'total' => null,
        ];
    }

    /**
     * Foto als base64-dataUri fürs PDF. Die Quelle ist die ContextFile (eigener `disk` —
     * auf demo `local`/`hetzner`, NICHT `public`), erst danach der Legacy-`pfad`. Vorher
     * prüfte diese Methode nur den public-Disk und fiel sonst auf `$foto->url()` zurück:
     * eine SIGNIERTE Core-Route mit TTL, die DomPDF nie laden kann ⇒ Schrittfotos fehlten
     * in jeder Report-PDF. Die Fallback-Kette gehört genau einmal ins Modul, deshalb hier
     * über {@see FoodAlchemistMediaService::dataUri}.
     */
    private function photoDataUri(?int $contextFileId, ?string $pfad): ?string
    {
        try {
            return app(FoodAlchemistMediaService::class)->dataUri($contextFileId, $pfad);
        } catch (\Throwable) {
            return null;
        }
    }
}
