<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\SignalSeverity;
use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;

/**
 * Datenqualitäts-Ampel für die Kaskade LA → GP → Basisrezept → VK-Gericht.
 *
 * Rein MESSEND (read-only, keine Daten-Mutation) + optionale Signal-Emission über
 * den SignalService (Dedup, kein Dauerfeuer) → die Befunde landen damit in der
 * bestehenden „Signale"-Inbox (ReviewQueue) und sind über MCP `signale.SEARCH`
 * sichtbar, statt in einer Wegwerf-Report-Datei. Idempotent + team-gescoped
 * (visibleToTeam) + schedulebar (foodalchemist:data-quality --signals).
 *
 * Ergänzt SignalDetektorService::datenqualitaetGpLa (GP ohne Lead) um die restlichen
 * Kaskaden-Dimensionen: Preis-Auflösung, Allergen-Metadaten, Anker-Erdung,
 * unbestimmte Servierform, unvollständige EK-Ketten.
 */
class DataQualityService
{
    /**
     * `CoverageService` kommt ab Spec 21 · S4b dazu (frame-gestützte Konzept-Checks),
     * `PairingService` ab S4b-2 (Anker-Graph), `VkSnapshotService` ab S4c-2 (freigegebener
     * VK vs. live). Kein Container-Zyklus: die Abhängigkeiten aller drei (PlanningFrame/
     * Concept/Foodbook bzw. keine bzw. TeamSettings) kennen diesen Service nicht — anders
     * als beim `SignalObjectService`, der genau deshalb ausgelagert wurde.
     */
    public function __construct(
        private SignalService $signals,
        private CoverageService $coverage,
        private PairingService $pairing,
        private VkSnapshotService $vkSnapshots,
    ) {
    }

    /** Ab dieser Anzahl gilt eine Lücke als kritisch (rot) statt Warnung (gelb). */
    private const ROT_SCHWELLE = 100;

    /**
     * Metrik-Art. Nötig, weil `severity='info'` beide Arten treffen kann (ein als
     * Info deklarierter Lücken-Check wird nie rot) — die Zeitreihe (Spec 21 · E1)
     * braucht aber genau die Lücken und nicht die Bestands-Totale („GPs approved").
     */
    public const KIND_GAP = 'gap';

    public const KIND_INFO = 'info';

    /** Zubereitungs-Text unter dieser Länge gilt als nicht vorhanden (Spec 21 §2). */
    private const MIN_ZUBEREITUNG_ZEICHEN = 20;

    /** So lange unberührt + unreferenziert ⇒ Pflege-Kandidat. */
    private const VERWAIST_TAGE = 180;

    /**
     * „Produktiv" = das Rezept beansprucht, benutzbar zu sein — nur diese Menge wird
     * gegen Rezeptur-Vollständigkeit geprüft. `draft`/`stub`/`deprecated` sind bewusst
     * ausgenommen: ein Auto-Stub (RecipeService setzt `status=stub`) HAT per Definition
     * keine Zubereitung und keine Zutaten — den fängt `rezept_sub_stub_offen`; ein
     * `deprecated`-Rezept ist ausgemustert. Spec 21 §2 schreibt „status != draft", was
     * vor `stub`/`deprecated` formuliert wurde; die engere Menge verhindert Über-Flaggen
     * (Nicht-Ziel: kein Rauschen).
     */
    private const PRODUKTIV_STATUS = ['review', 'approved'];

    /**
     * Alle Flächen, auf denen ein Rezept „benutzt" ist — eine Referenz genügt ⇒ nicht verwaist.
     * Sub-Rezept-Nutzung zählt mit: ein Basisrezept in einem anderen Rezept ist in Gebrauch,
     * auch wenn es in keinem Foodbook steht.
     */
    private const NUTZUNGS_REFERENZEN = [
        ['foodalchemist_recipe_ingredients', 'referenced_recipe_id'],
        ['foodalchemist_concept_slots', 'sales_recipe_id'],
        ['foodalchemist_foodbook_blocks', 'sales_recipe_id'],
        ['foodalchemist_menu_plan_entries', 'sales_recipe_id'],
        ['foodalchemist_package_dishes', 'sales_recipe_id'],
    ];

    /**
     * Teilmenge von NUTZUNGS_REFERENZEN, die den Gast erreicht — nur hier ist eine
     * belastbare Allergen-Auskunft Pflicht. Sub-Rezept-Nutzung ist bewusst NICHT dabei:
     * sie macht ein Rezept „in Gebrauch", aber nicht kundenexponiert; die Exposition
     * erbt es über sein Eltern-Gericht (s. kundenExponiert()).
     */
    private const KUNDEN_REFERENZEN = [
        ['foodalchemist_concept_slots', 'sales_recipe_id'],
        ['foodalchemist_foodbook_blocks', 'sales_recipe_id'],
        ['foodalchemist_menu_plan_entries', 'sales_recipe_id'],
        ['foodalchemist_package_dishes', 'sales_recipe_id'],
    ];

    /** Die 14 EU-Allergen-Spalten am Rezept (GL-01) — `unbekannt` ist der Default nach dem Insert. */
    private const ALLERGEN_SPALTEN = [
        'gluten', 'crustaceans', 'eggs', 'fish', 'peanuts', 'soy', 'milk',
        'tree_nuts', 'celery', 'mustard', 'sesame', 'sulphites', 'lupin', 'molluscs',
    ];

    /**
     * Parenthesierte Grammatur-/Maßangabe im Namen — `(65g)`, `(17 g)`, `(80 ml)`, `(5x6cm)`.
     * Genau die Formen aus Regelwerk_Verkaufsgerichte §1.2; freistehende Zahlen bleiben
     * bewusst unangetastet (`Sauce 2000er`, `Cuvée 1er` sind keine Grammaturen).
     */
    private const GRAMMATUR_MUSTER = '/\(\s*\d+(?:[.,]\d+)?\s*(?:[x×]\s*\d+(?:[.,]\d+)?\s*)?(?:g|kg|mg|ml|cl|l|cm|mm|stk|st)\s*\)/iu';

    /** Katalog-/Marker-Codes, die laut Regelwerk_Verkaufsgerichte §1.2 ersatzlos aus dem VK-Namen raus. */
    private const VK_MARKER = ['CC:', 'STF:', 'MS:', '(SG)', '(BOX)', 'ADD ON', '[FC]'];

    /** Trennzeichen, die am Namensanfang/-ende immer ein Rest aus Import/Split sind. */
    private const NAME_TRENNER_RAND = '/(^[\s|,;:\-\x{2013}]|[\s|,;:\-\x{2013}]$)/u';

    /**
     * Voll-Messung aller Kaskaden-Ebenen.
     *
     * @return array<string,array{label:string,metriken:list<array<string,mixed>>}>
     */
    public function messeAlleEbenen(Team $team): array
    {
        return [
            'la' => ['label' => 'Lieferantenartikel', 'metriken' => $this->la($team)],
            'gp' => ['label' => 'Grundprodukte', 'metriken' => $this->gp($team)],
            'basisrezept' => ['label' => 'Basisrezepte', 'metriken' => $this->basisrezepte($team)],
            'gericht' => ['label' => 'VK-Gerichte', 'metriken' => $this->gerichte($team)],
            'rezeptqualitaet' => ['label' => 'Rezept-Qualität', 'metriken' => $this->rezeptqualitaet($team)],
            'konzept' => ['label' => 'Konzepte', 'metriken' => $this->konzept($team)],
            'foodbook' => ['label' => 'Foodbooks', 'metriken' => $this->foodbook($team)],
            'quer' => ['label' => 'Querschnitt', 'metriken' => $this->quer($team)],
        ];
    }

    /**
     * Emittiert für jede Lücken-Metrik (wert > 0) mit Signal-Deskriptor ein Signal.
     * Idempotent über dedup_key (SignalService dedupt offene Signale je Team+Typ+Key).
     *
     * @return int Anzahl erzeugter/aktualisierter Signale
     */
    public function emittiereSignale(Team $team): int
    {
        $n = 0;
        foreach ($this->messeAlleEbenen($team) as $ebene) {
            foreach ($ebene['metriken'] as $m) {
                if (($m['signal'] ?? null) === null || (int) $m['wert'] === 0) {
                    continue;
                }
                $wert = (int) $m['wert'];
                $this->signals->erzeuge(
                    $team,
                    $m['signal']['typ'],
                    // Fester Schweregrad je Check schlägt die reine Mengen-Heuristik: „ohne Zubereitung"
                    // ist auch bei 3 Treffern kritisch, „verwaist" auch bei 300 nur Info (Spec 21 §2).
                    $m['signal']['sev'] ?? ($wert > self::ROT_SCHWELLE ? SignalSeverity::Kritisch : SignalSeverity::Warnung),
                    $wert . ' — ' . $m['label'],
                    [
                        'dedup_key' => $m['signal']['dedup'],
                        'description' => $m['signal']['desc'] ?? $m['label'],
                        'payload' => ['anzahl' => $wert, 'metrik' => $m['key'], 'ebene' => $ebene['label']],
                        'source' => 'data-quality',
                    ]
                );
                $n++;
            }
        }

        return $n;
    }

    // ---- Ebenen-Messungen -------------------------------------------------

    /** @return list<array<string,mixed>> */
    private function la(Team $team): array
    {
        // Arbeitsmenge = strukturierte LAs (die 264k Roh-Katalog sind nicht das Ziel).
        $strukturiert = DB::table('foodalchemist_supplier_item_structures')->count();
        $gemappt = DB::table('foodalchemist_supplier_item_structures')->whereNotNull('gp_id')->count();
        $needsReview = DB::table('foodalchemist_supplier_item_structures')->where('needs_review', true)->count();

        return [
            $this->info('la_strukturiert', 'Strukturierte LAs (Arbeitsmenge)', $strukturiert),
            $this->info('la_gemappt', 'davon GP-gemappt', $gemappt),
            $this->gap('la_needs_review', 'LAs in Review-Queue', $needsReview),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function gp(Team $team): array
    {
        $approved = FoodAlchemistGp::visibleToTeam($team)->where('status', 'approved')->count();
        $tentative = FoodAlchemistGp::visibleToTeam($team)->where('status', 'tentative')->count();

        // approved, requires_la, ohne Lead-LA (bzw. keine LAs)
        $ohneLead = FoodAlchemistGp::visibleToTeam($team)
            ->where('status', 'approved')->where('requires_la', true)
            ->where(fn ($w) => $w->whereNull('lead_la_supplier_item_id')->orWhere('n_las_total', 0))
            ->count();

        // approved, requires_la, Lead gesetzt, aber Lead-LA hat keinen gültigen Preis
        $leadOhnePreis = FoodAlchemistGp::visibleToTeam($team)
            ->where('status', 'approved')->where('requires_la', true)
            ->whereNotNull('lead_la_supplier_item_id')
            ->whereNotExists($this->aktivPreisFuerLead())
            ->count();

        // Allergen-Metadaten nie aggregiert (allergens_confidence NULL)
        $allergenKonfidenzFehlt = FoodAlchemistGp::visibleToTeam($team)
            ->where('status', 'approved')->whereNull('allergens_confidence')->count();

        // genutzte approved-GPs ohne Anker (Flavor-Graph-Erdung)
        $ankerFehlt = FoodAlchemistGp::visibleToTeam($team)
            ->where('status', 'approved')
            ->whereExists($this->gpGenutzt())
            ->whereNotExists($this->gpHatAnker())
            ->count();

        // tentative GPs, die (regelwidrig) schon in Rezepten hängen
        $tentativeGenutzt = FoodAlchemistGp::visibleToTeam($team)
            ->where('status', 'tentative')->whereExists($this->gpGenutzt())->count();

        return [
            $this->info('gp_approved', 'GPs approved', $approved),
            $this->info('gp_tentative', 'GPs tentative (Review-Queue)', $tentative),
            // Signal-Emission bewusst ohne Deskriptor: SignalDetektorService::datenqualitaetGpLa
            // besitzt diesen Befund bereits (kein Doppel-Signal im Scheduler). Bleibt Info-Metrik.
            $this->gap('gp_ohne_lead', 'approved-GPs ohne Lead-LA', $ohneLead),
            $this->gap('gp_lead_ohne_preis', 'approved-GPs: Lead-LA ohne gültigen Preis', $leadOhnePreis, SignalTyp::DatenqualitaetGpLa, 'dq-gp-lead-ohne-preis',
                'Lead-LA gesetzt, aber ohne aktiven Preis (>0, nicht gesperrt) → GP löst nicht auf einen EK auf.'),
            $this->gap('gp_allergen_konfidenz', 'approved-GPs ohne Allergen-Konfidenz', $allergenKonfidenzFehlt, SignalTyp::DatenqualitaetGpLa, 'dq-gp-allergen-konfidenz',
                'Allergen-Aggregation (ALL-MAXIMAL + Konfidenz) nie auf GP-Ebene persistiert.'),
            $this->gap('gp_anker_fehlt', 'genutzte approved-GPs ohne Flavor-Anker', $ankerFehlt, SignalTyp::AnkerFehlt, 'dq-gp-anker-fehlt',
                'Genutzte GPs ohne Anker-Mapping sind für den Pairing-Graph unsichtbar.'),
            $this->gap('gp_tentative_genutzt', 'tentative GPs in Rezepten genutzt', $tentativeGenutzt, SignalTyp::DatenqualitaetGpLa, 'dq-gp-tentative-genutzt',
                'Tentative (unkuratierte) GPs sollten nicht in Rezepten hängen — approven oder ersetzen.'),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function basisrezepte(Team $team): array
    {
        $ekNull = $this->rezepte($team, false)->whereNull('ek_total_eur')->count();
        $ekTeil = $this->rezepte($team, false)->whereNotNull('ek_total_eur')
            ->whereColumn('ek_n_ingredients_priced', '<', 'ek_n_ingredients_total')->count();
        $ankerFehlt = $this->rezepte($team, false)->whereNotExists($this->rezeptHatAnker())->count();

        return [
            $this->gap('br_ek_null', 'Basisrezepte ohne EK', $ekNull, SignalTyp::EkKetteUnvollstaendig, 'dq-br-ek-null',
                'Basisrezepte, deren Zutaten-Kette auf keinen Preis auflöst.'),
            $this->gap('br_ek_teil', 'Basisrezepte teil-unbepreist', $ekTeil, SignalTyp::EkKetteUnvollstaendig, 'dq-br-ek-teil',
                'Nur ein Teil der Zutaten hat einen Preis → EK unterschätzt.'),
            $this->gap('br_anker_fehlt', 'Basisrezepte ohne Flavor-Anker', $ankerFehlt, SignalTyp::AnkerFehlt, 'dq-br-anker-fehlt'),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function gerichte(Team $team): array
    {
        $ekNull = $this->rezepte($team, true)->whereNull('ek_total_eur')->count();
        $ekTeil = $this->rezepte($team, true)->whereNotNull('ek_total_eur')
            ->whereColumn('ek_n_ingredients_priced', '<', 'ek_n_ingredients_total')->count();
        $ankerFehlt = $this->rezepte($team, true)->whereNotExists($this->rezeptHatAnker())->count();
        $unbestimmt = $this->unbestimmteServierform($team);

        return [
            $this->gap('vk_ek_null', 'VK-Gerichte ohne EK', $ekNull, SignalTyp::EkKetteUnvollstaendig, 'dq-vk-ek-null'),
            $this->gap('vk_ek_teil', 'VK-Gerichte teil-unbepreist', $ekTeil, SignalTyp::EkKetteUnvollstaendig, 'dq-vk-ek-teil'),
            $this->gap('vk_anker_fehlt', 'VK-Gerichte ohne Flavor-Anker (graph-blind)', $ankerFehlt, SignalTyp::AnkerFehlt, 'dq-vk-anker-fehlt'),
            $this->gap('vk_servierform_unbestimmt', 'VK-Gerichte mit Servierform „unbestimmt"', $unbestimmt, SignalTyp::ServierformUnbestimmt, 'dq-vk-servierform-unbestimmt',
                'Standard-Darreichung steht auf „unbestimmt" (Review) — Servierform kuratieren.'),
        ];
    }

    /**
     * Spec 21 Tranche A — Inhalts-Qualität der Rezeptur selbst (deterministisch, 0-Egress).
     *
     * Abgrenzung: die Ebenen `basisrezept`/`gericht` messen Geld (EK-Kette), Erdung (Anker)
     * und Servierform — also ob ein Rezept *auflöst*. Diese Sektion misst, ob es *taugt*:
     * Zubereitung, Mengen, Ausbeute, Benennung, Kategorie, Allergen-Belastbarkeit.
     * Sie spannt bewusst über beide Rezept-Arten (Basisrezept + VK-Gericht), weil die
     * Rezeptur-Regeln (Regelwerk_Basisrezepte) für beide gelten.
     *
     * @return list<array<string,mixed>>
     */
    private function rezeptqualitaet(Team $team): array
    {
        $out = [];
        foreach ($this->rezeptQualitaetChecks() as $key => $c) {
            $out[] = $this->gap($key, $c['label'], ($c['q'])($team)->count(), $c['typ'], $c['dedup'], $c['desc'], $c['sev']);
        }

        return $out;
    }

    /**
     * Tranche-A-Register: Label, Signal-Deskriptor und **das Prädikat** je Check —
     * EINMAL definiert. Zähl-Seite (`rezeptqualitaet`), Objekt-Liste (`betroffene`) und
     * Fixer-Lifecycle (`countFor`) ziehen alle aus derselben Closure; ein Regel-Wechsel
     * kann damit nicht auf halber Strecke driften.
     *
     * Reihenfolge = Lese-Reihenfolge im Cockpit: erst was das Rezept unbrauchbar macht
     * (kritisch), dann Struktur-/Pflege-Befunde.
     *
     * @return array<string,array{label:string,typ:SignalTyp,dedup:string,sev:SignalSeverity,desc:string,q:\Closure}>
     */
    private function rezeptQualitaetChecks(): array
    {
        return [
            'rezept_ohne_zubereitung' => [
                'label' => 'Rezepte ohne Zubereitung',
                'typ' => SignalTyp::RezeptOhneZubereitung,
                'dedup' => 'dq-rezept-ohne-zubereitung',
                'sev' => SignalSeverity::Kritisch,
                'desc' => 'Ohne Zubereitungstext ist das Rezept nicht produzierbar — Küche kann es nicht ausführen, '
                    . 'Regeneration/Prozessanker hängen daran. Prüfen: steht der Text noch unübernommen in '
                    . '`excel_raw_preparation` (Import-Rest)?',
                // LENGTH: SQLite zählt Zeichen, MySQL Bytes — bei Schwelle 20 fachlich belanglos
                // (Umlaute machen MySQL nur minimal nachsichtiger), dafür ohne Dialekt-Fallunterscheidung.
                'q' => fn (Team $t) => $this->produktiveRezepte($t)
                    ->whereRaw("LENGTH(TRIM(COALESCE(preparation, ''))) < " . self::MIN_ZUBEREITUNG_ZEICHEN),
            ],
            'rezept_mengen_luecke' => [
                'label' => 'Rezepte mit Mengen-Lücke (Zutat ohne Menge)',
                'typ' => SignalTyp::RezeptMengenLuecke,
                'dedup' => 'dq-rezept-mengen-luecke',
                'sev' => SignalSeverity::Kritisch,
                'desc' => 'Mindestens eine Zutat steht auf Menge 0 (die Spalte ist NOT NULL, 0 ist der Marker für '
                    . '„nicht bekannt"). Solche Zutaten fallen aus EK, Yield und Nährwerten heraus — die Kalkulation '
                    . 'des Rezepts ist zu niedrig, ohne dass man es sieht.',
                'q' => fn (Team $t) => $this->alleRezepte($t)->whereExists(fn ($q) => $q->select(DB::raw(1))
                    ->from('foodalchemist_recipe_ingredients as ri')
                    ->whereColumn('ri.recipe_id', 'foodalchemist_recipes.id')
                    ->whereNull('ri.deleted_at')->where('ri.quantity', 0)),
            ],
            'rezept_allergen_unbelastbar' => [
                'label' => 'Kundenexponierte Rezepte mit unbelastbaren Allergenen',
                'typ' => SignalTyp::RezeptAllergenUnbelastbar,
                'dedup' => 'dq-rezept-allergen-unbelastbar',
                'sev' => SignalSeverity::Kritisch,
                'desc' => 'Das Rezept erreicht über Konzept, Foodbook, Speiseplan oder Paket den Gast, aber seine '
                    . 'Allergen-Auskunft ist nicht belastbar: Konfidenz `unknown` oder mindestens ein Allergen steht '
                    . 'auf `unbekannt`. Kritisch, weil daraus eine Kunden-Auskunft wird. Nicht exponierte Rezepte '
                    . 'bleiben bewusst außen vor — dort ist die Lücke Pflege, keine Haftung.',
                'q' => fn (Team $t) => $this->kundenExponiert($this->alleRezepte($t))->where(function ($w) {
                    $w->where('allergens_confidence', 'unknown')->orWhereNull('allergens_confidence');
                    foreach (self::ALLERGEN_SPALTEN as $spalte) {
                        $w->orWhere('allergen_' . $spalte, 'unbekannt');
                    }
                }),
            ],
            'rezept_zutaten_ungemappt' => [
                'label' => 'Rezepte mit ungemappten Zutaten',
                'typ' => SignalTyp::RezeptZutatenUngemappt,
                'dedup' => 'dq-rezept-zutaten-ungemappt',
                'sev' => SignalSeverity::Warnung,
                'desc' => 'Zutaten ohne GP-Mapping zählen weder in EK noch in Allergene/Nährwerte — jede Aggregation '
                    . 'am Rezept ist damit systematisch unvollständig.',
                'q' => fn (Team $t) => $this->alleRezepte($t)->where('n_ingredients_unmapped', '>', 0),
            ],
            'rezept_ein_zutat' => [
                'label' => 'Rezepte mit höchstens einer Zutat',
                'typ' => SignalTyp::RezeptEinZutat,
                'dedup' => 'dq-rezept-ein-zutat',
                'sev' => SignalSeverity::Warnung,
                'desc' => 'Ein „Rezept" mit einer oder keiner Zutat ist nach Regelwerk_Basisrezepte §10 meist gar '
                    . 'keins: entweder ein Grundprodukt, das als Rezept angelegt wurde, oder ein Fehl-Import, dessen '
                    . 'Zutatenliste nie ankam.',
                'q' => fn (Team $t) => $this->produktiveRezepte($t)->where('n_ingredients_total', '<=', 1),
            ],
            'rezept_kategorie_problem' => [
                'label' => 'Rezepte ohne/mit stillgelegter Kategorie',
                'typ' => SignalTyp::RezeptKategorieProblem,
                'dedup' => 'dq-rezept-kategorie-problem',
                'sev' => SignalSeverity::Warnung,
                'desc' => 'Kategorie fehlt, oder das Gericht hängt an einer stillgelegten Speisen-Hauptgruppe '
                    . '(Taxonomie-Neutralisierung: APE/SNK/ALC/BVK/ALL sind inaktiv). Ohne gültige Kategorie ist das '
                    . 'Rezept in Browser, Slot-Filtern und Gericht-Pickern unauffindbar.',
                // Zwei getrennte Taxonomien, deshalb zwei Zweige (verifiziert an den Migrationen):
                //  · VK-Gericht  → `dish_main_group_id` direkt am Rezept (Modell A), Ziel `dish_main_groups`
                //    — DIESE Tabelle trägt `is_inactive` (269er-Neutralisierung).
                //  · Basisrezept → `category_id` → `recipe_categories.main_group_id` → `recipe_main_groups`
                //    — Produktions-Taxonomie, hat KEIN Stillgelegt-Flag; hier bleibt nur „Kategorie fehlt".
                'q' => fn (Team $t) => $this->alleRezepte($t)->where(fn ($w) => $w
                    ->where(fn ($vk) => $vk->where('is_sales_recipe', true)
                        ->where(fn ($x) => $x->whereNull('dish_main_group_id')
                            ->orWhereExists($this->inaktiveSpeisenHauptgruppe())))
                    ->orWhere(fn ($br) => $br->where('is_sales_recipe', false)->whereNull('category_id'))),
            ],
            'rezept_yield_implausibel' => [
                'label' => 'Rezepte mit fehlender/unmöglicher Ausbeute',
                'typ' => SignalTyp::RezeptYieldImplausibel,
                'dedup' => 'dq-rezept-yield-implausibel',
                'sev' => SignalSeverity::Warnung,
                'desc' => 'Entweder fehlt die Ausbeute trotz vorhandener Zutaten (dann rechnet nichts pro kg/Portion), '
                    . 'oder sie übersteigt die Roh-Einsatzmasse — physikalisch unmöglich, weil Putz- und Garverlust '
                    . 'die Masse nur senken können. Zweiter Fall heißt fast immer: Ausbeute ist manuell übersteuert '
                    . 'oder stammt aus einem Stand vor der letzten Zutaten-Änderung → Neuberechnung anstoßen.',
                // Bewusst NICHT gebaut: der Spec-Zweig „yield < 0,3 × Einsatzmasse = >70 % Verlust".
                // Er ist deterministisch nicht bewertbar, weil genau dieser Wert das REGULÄRE Ergebnis
                // des Recomputes ist, sobald Putz-/Garverlust deklariert sind (Reduktionen, Fonds,
                // Jus erreichen 70–90 %) — und die Verlust-Kaskade zieht Defaults von GP und Team-WG,
                // steht also nicht an der Zutat. Der Check würde jede Reduktion flaggen. Der belastbare
                // Zwilling wäre „Ausbeute weicht von der Neuberechnung ab" (Recompute-Drift) — anderer
                // Mechanismus, eigenes Signal (Backlog).
                'q' => fn (Team $t) => $this->produktiveRezepte($t)->where(fn ($w) => $w
                    ->where(fn ($fehlt) => $fehlt->whereNull('yield_kg')->whereNull('yield_kg_manual')
                        ->where('n_ingredients_total', '>', 0))
                    ->orWhere(fn ($zuHoch) => $zuHoch
                        ->where(fn ($da) => $da->whereNotNull('yield_kg')->orWhereNotNull('yield_kg_manual'))
                        // Ratio nur bewerten, wenn JEDE beitragende Zutat eine Massen-Einheit trägt —
                        // sonst zählt eine Stück-/Bund-Zutat mit 0 g in die Summe und die Ausbeute wirkt
                        // fälschlich zu hoch (die Falle dieses Checks).
                        ->whereExists($this->beitragendeZutat(false))
                        ->whereNotExists($this->beitragendeZutat(true))
                        ->whereRaw('COALESCE(foodalchemist_recipes.yield_kg_manual, foodalchemist_recipes.yield_kg) * 1000 > ('
                            . $this->einsatzmasseGrammSql() . ')'))),
            ],
            'rezept_naming_regelwerk' => [
                'label' => 'Rezepte mit Naming-Verstoß',
                'typ' => SignalTyp::RezeptNamingRegelwerk,
                'dedup' => 'dq-rezept-naming-regelwerk',
                'sev' => SignalSeverity::Warnung,
                'desc' => 'Deterministisch prüfbare Verstöße gegen das Naming: doppelte Leerzeichen, führende/'
                    . 'schließende Trennzeichen, Grammatur im Namen ohne unterscheidende Funktion — bei VK-Gerichten '
                    . 'zusätzlich fehlendes [HG]-Präfix (Pipe-Skelett, Regelwerk_Verkaufsgerichte §1) und '
                    . 'Katalog-Marker (CC:, STF:, (BOX) …). Namen sind die Suchoberfläche des Bestands: was falsch '
                    . 'heißt, wird doppelt angelegt.',
                'q' => fn (Team $t) => $this->alleRezepte($t)->whereIn('id', $this->namingVerstossIds($t)),
            ],
            'rezept_dublette' => [
                'label' => 'Rezepte mit Namens-Dublette',
                'typ' => SignalTyp::RezeptDublette,
                'dedup' => 'dq-rezept-dublette',
                'sev' => SignalSeverity::Warnung,
                'desc' => 'Zwei oder mehr Rezepte derselben Art und Kategorie tragen denselben Namen (normalisiert: '
                    . 'Groß-/Kleinschreibung, Satzzeichen und Mehrfach-Leerzeichen ignoriert). Beide Seiten werden '
                    . 'gelistet — zusammenführen oder über die Kategorie/einen Zusatz unterscheiden. Eine andere '
                    . 'Kategorie ist laut Regelwerk_Basisrezepte §1 ein zulässiger Diskriminator und zählt deshalb '
                    . 'nicht als Dublette.',
                'q' => fn (Team $t) => $this->alleRezepte($t)->whereIn('id', $this->dubletteIds($t)),
            ],
            'rezept_sub_stub_offen' => [
                'label' => 'Referenzierte Sub-Rezept-Stubs ohne Inhalt',
                'typ' => SignalTyp::RezeptSubStubOffen,
                'dedup' => 'dq-rezept-sub-stub-offen',
                'sev' => SignalSeverity::Warnung,
                'desc' => 'Ein anderes Rezept verweist auf dieses als Sub-Rezept, aber es ist ein leerer Auto-Stub '
                    . '(Regelwerk_Basisrezepte §4). Solange der Stub leer ist, fehlen dem Eltern-Rezept dessen Masse, '
                    . 'Kosten und Allergene — die Aggregation des Eltern-Rezepts ist damit still unvollständig.',
                // Spec 21 §2 schreibt `status=draft`; `RecipeService::createSubRecipeStub()` setzt real
                // `status=stub` (ein per Hand angelegter Sub-Verweis kann als draft entstehen) → beide
                // Status zählen, sonst geht der Regelfall durch. 0 Zutaten ist die eigentliche Aussage.
                'q' => fn (Team $t) => $this->alleRezepte($t)
                    ->whereIn('status', ['stub', 'draft'])
                    ->where('n_ingredients_total', 0)
                    ->whereExists(fn ($q) => $q->select(DB::raw(1))
                        ->from('foodalchemist_recipe_ingredients as ri')
                        ->whereColumn('ri.referenced_recipe_id', 'foodalchemist_recipes.id')
                        ->whereNull('ri.deleted_at')),
            ],
            'rezept_verwaist' => [
                'label' => 'Rezepte verwaist (unreferenziert + unberührt)',
                'typ' => SignalTyp::RezeptVerwaist,
                'dedup' => 'dq-rezept-verwaist',
                'sev' => SignalSeverity::Info,
                'desc' => 'Seit über ' . self::VERWAIST_TAGE . ' Tagen unberührt und in keinem Gericht, Konzept, '
                    . 'Foodbook, Speiseplan oder Paket referenziert — Pflege-Kandidat (aktualisieren, ausmustern '
                    . 'oder bewusst behalten). Bewusst nur Info: Bestand ist kein Fehler.',
                'q' => fn (Team $t) => $this->unreferenziert(
                    $this->produktiveRezepte($t)->where('updated_at', '<', now()->subDays(self::VERWAIST_TAGE))
                ),
            ],
            // Tranche B (S5b) — die zwei Zeilen dieses Registers mit KI-Urteil im Rücken.
            // Sie prüfen trotzdem nichts selbst: der Egress lag im Batch
            // (`foodalchemist:recipe-findings`), hier wird nur der abgelegte Bestand
            // gelesen. Die Auswahl-Regel kommt ungeteilt aus `offeneUeberSchwelle()` —
            // eine eigene Schwelle hier hieße, dass ein Befund in der Rezept-Ansicht
            // erledigt aussieht und im Cockpit noch zählt.
            'rezept_plausi_ki' => [
                'label' => 'Rezepte mit offenem KI-Befund',
                'typ' => SignalTyp::RezeptPlausiKi,
                'dedup' => 'dq-rezept-plausi-ki',
                'sev' => SignalSeverity::Warnung,
                'desc' => 'Der Rezept-Copilot hat am Rezept mindestens einen unentschiedenen Befund mit Konfidenz ≥ '
                    . RecipeFindingService::KONFIDENZ_SCHWELLE . ' hinterlassen (falsche Menge, unpassende Zutat, '
                    . 'fehlende Schlüsselkomponente). Das ist die Fehlerklasse Pfefferkörner→Pfefferrahm-Sauce, die '
                    . 'bisher nur einmalig per Skript gefunden wurde. Aufgelöst wird sie im Rezept selbst — je '
                    . 'Befund übernehmen oder verwerfen; ein verworfener Befund kommt nicht wieder.',
                // `whereIn` mit Sub-Builder statt eigenem EXISTS: die Befund-Zeilen sind
                // bewusst NICHT team-hierarchisch (Messreihen-Ausnahme aus S5a), ihr Scope
                // steckt im Service. Nachgebaut wäre er hier eine zweite Wahrheit.
                // Die Arten-Grenze ist ab S5b-2 Pflicht: sonst zählte diese Zeile auch die
                // Bauart-Befunde mit und dasselbe Rezept stünde in zwei Signalen für einen
                // Sachverhalt, den nur eines von beiden beschreibt.
                'q' => fn (Team $t) => $this->alleRezepte($t)->whereIn(
                    'foodalchemist_recipes.id',
                    app(RecipeFindingService::class)
                        ->offeneUeberSchwelle($t, null, RecipeReviewService::ARTEN_COPILOT)->select('recipe_id')
                ),
            ],
            'rezept_gericht_vs_komponente' => [
                'label' => 'Rezepte mit Bauart-Zweifel (Gericht oder Komponente?)',
                'typ' => SignalTyp::RezeptGerichtVsKomponente,
                'dedup' => 'dq-rezept-gericht-vs-komponente',
                'sev' => SignalSeverity::Warnung,
                'desc' => 'Der Bauart-Pass widerspricht der bestehenden Einordnung: was hier als Gericht geführt '
                    . 'wird, ist nach Bauart eine Komponente — oder umgekehrt. Maßstab ist die 269er-Regel '
                    . '„Wie ist es gebaut?", nie „Wo wird es eingesetzt?". Die Folgen einer falschen Einordnung '
                    . 'sind still, aber breit: eine als Gericht geführte Sauce taucht in Gericht-Pickern und '
                    . 'Slot-Vorschlägen auf, ein als Komponente geführtes Gericht bekommt weder Verkaufs-Facetten '
                    . 'noch Darreichungen. Aufgelöst wird das im Rezept selbst — die Umstellung ist eine '
                    . 'Struktur-Entscheidung und bewusst kein Knopf.',
                'q' => fn (Team $t) => $this->alleRezepte($t)->whereIn(
                    'foodalchemist_recipes.id',
                    app(RecipeFindingService::class)
                        ->offeneUeberSchwelle($t, null, RecipeReviewService::ARTEN_STRUKTUR)->select('recipe_id')
                ),
            ],
        ];
    }

    /**
     * Spec 21 Tranche C — Qualität der Komposition (Konzept-Ebene, deterministisch, 0-Egress).
     *
     * Bis hierher endete die Kaskade am Gericht: LA → GP → Basisrezept → VK-Gericht. Das
     * Konzept ist aber die Einheit, die der Kunde kauft — ein vollständiges Gericht in einem
     * halb gefüllten Menü ist trotzdem ein Mangel, und er fällt heute erst im Angebot auf.
     *
     * **Arbeitsmenge = nur was in Gebrauch ist** (s. konzepteInGebrauch). Das ist die
     * tragende Abgrenzung dieser Tranche: anders als ein Rezept ist ein Konzept über
     * längere Zeit *bewusst* unfertig (der Entwurf IST der Arbeitsstand). Würde man alle
     * Entwürfe messen, zählte der Check die normale Arbeit als Fehler — genau das
     * Rauschen, das Spec 21 §9 ausschließt.
     *
     * @return list<array<string,mixed>>
     */
    private function konzept(Team $team): array
    {
        $out = [];
        foreach ($this->konzeptChecks() as $key => $c) {
            $out[] = $this->gap($key, $c['label'], ($c['q'])($team)->count(), $c['typ'], $c['dedup'], $c['desc'], $c['sev']);
        }

        return $out;
    }

    /**
     * Tranche-C-Register — gleiche Bauart wie {@see rezeptQualitaetChecks}: Label,
     * Signal-Deskriptor und Prädikat je Check an EINER Stelle, aus der Zähl-Seite,
     * Objekt-Liste (`betroffene`) und Lifecycle (`countFor`) gemeinsam ziehen.
     *
     * @return array<string,array{label:string,typ:SignalTyp,dedup:string,sev:SignalSeverity,desc:string,q:\Closure}>
     */
    private function konzeptChecks(): array
    {
        return [
            'konzept_slot_luecke' => [
                'label' => 'Konzepte in Gebrauch mit unbesetztem Pflicht-Slot',
                'typ' => SignalTyp::KonzeptSlotLuecke,
                'dedup' => 'dq-konzept-slot-luecke',
                'sev' => SignalSeverity::Warnung,
                'desc' => 'Das Konzept ist in Gebrauch (aktiv, an einem Angebot oder in einem Foodbook), aber '
                    . 'mindestens ein als Pflicht markierter Slot ist weder mit einem Gericht noch mit einem Paket '
                    . 'belegt — oder es hat überhaupt keinen belegten Inhalts-Slot. Solche Lücken schlagen bis in '
                    . 'Angebot und Kundendokument durch: der Preis pro Person rechnet ohne die fehlende Position, '
                    . 'die Zeile fehlt im Menü.',
                'q' => fn (Team $t) => $this->konzepteInGebrauch($t)->where(fn ($w) => $w
                    ->whereExists($this->offenerPflichtSlot())
                    ->orWhereNotExists($this->belegterInhaltsSlot())),
            ],
            'konzept_ohne_wording' => [
                'label' => 'Konzepte in Gebrauch mit Gericht ohne Kunden-Wording',
                'typ' => SignalTyp::KonzeptOhneWording,
                'dedup' => 'dq-konzept-ohne-wording',
                'sev' => SignalSeverity::Warnung,
                'desc' => 'Mindestens eine Gericht-Zeile dieses Konzepts hat keine kundenfähige Bezeichnung: weder '
                    . 'am Slot (`wording`) noch am Gericht (`sales_wording_standard`). Die Wording-Kette fällt dort '
                    . 'auf den INTERNEN Pipe-Namen zurück ([HG]-Präfix, Bausteine mit | getrennt) — genau der Text, '
                    . 'der nie beim Kunden landen darf. Der Foodbook-Override ist bewusst nicht mitgeprüft: er hängt '
                    . 'am Buch, nicht am Konzept, und würde die Lücke nur an einer von n Stellen kaschieren.',
                'q' => fn (Team $t) => $this->konzepteInGebrauch($t)->whereExists($this->slotOhneWording()),
            ],
            'konzept_preisband_verletzt' => [
                'label' => 'Konzepte in Gebrauch außerhalb des Preisbands',
                'typ' => SignalTyp::KonzeptPreisbandVerletzt,
                'dedup' => 'dq-konzept-preisband-verletzt',
                'sev' => SignalSeverity::Warnung,
                'desc' => 'Der Ist-Preis pro Person liegt außerhalb der im Planungs-Gerüst gesetzten Spanne '
                    . '(`price_min_pp`/`price_max_pp`) — oder ein Slot-Preisrahmen ist gerissen. Gemeldet wird nur '
                    . 'die rote Lage: eine Abweichung vom Zielpreis INNERHALB der Spanne ist gelb und bleibt eine '
                    . 'Kalkulations-Frage, kein Signal. Ohne Gerüst gibt es kein Soll und damit keinen Befund.',
                'q' => fn (Team $t) => $this->konzepteMitFrameBefund($t, 'preis'),
            ],
            'konzept_regel_verletzt' => [
                'label' => 'Konzepte in Gebrauch mit verletzter Gerüst-Regel',
                'typ' => SignalTyp::KonzeptRegelVerletzt,
                'dedup' => 'dq-konzept-regel-verletzt',
                'sev' => SignalSeverity::Warnung,
                'desc' => 'Mindestens eine Kunden-Politik aus dem Planungs-Gerüst ist gerissen: Diät-Quote nicht '
                    . 'erreicht, eine No-Go-Zutat kommt vor, ein No-Go-Allergen ist enthalten, oder eine geforderte '
                    . 'Saison fehlt. Das sind Zusagen an den Kunden — anders als eine Struktur-Lücke fällt eine '
                    . 'verletzte Zusage nicht beim Lesen auf. Weiche Regeln (`severity=weich`) sind gelb und zählen '
                    . 'hier nicht mit; die Allergen-Linie ist Freitext und maschinell nicht messbar.',
                'q' => fn (Team $t) => $this->konzepteMitFrameBefund($t, 'regel'),
            ],
            'konzept_dramaturgie' => [
                'label' => 'Konzepte in Gebrauch mit wiederholter Hauptzutat',
                'typ' => SignalTyp::KonzeptDramaturgie,
                'dedup' => 'dq-konzept-dramaturgie',
                'sev' => SignalSeverity::Info,
                'desc' => 'Zwei oder mehr Gänge dieses Konzepts tragen dieselbe Hauptzutat — als Hauptzutat gilt '
                    . 'die mengenmäßig dominierende Zutat des Gerichts, ihr Aroma-Anker identifiziert sie; '
                    . 'Sorten-Varianten zählen mit (lachs / lachs_wild). Das ist bewusst nur ein Hinweis und keine '
                    . 'Warnung: ein Themen-Menü darf sich wiederholen. Gerichte ohne massen-vergleichbare Zutaten '
                    . 'oder ohne Anker bleiben unbewertet — fehlende Erdung ist keine Aussage über das Menü.',
                'q' => fn (Team $t) => $this->konzepteMitWiederholung($t),
            ],
        ];
    }

    /**
     * Befund-Dimensionen aus {@see CoverageService}, die als Regel-Verletzung zählen.
     * Bewusst NUR die Regel-Dimensionen (`RULE_TYPES`): `menge`/`dramaturgie` kommen aus
     * den Gerüst-Slots, nicht aus einer Regel — sie gehören begrifflich zur Slot-Lücke
     * und würden hier zwei verschiedene Sachverhalte in einem Signal vermischen.
     */
    private const FRAME_REGEL_DIMENSIONEN = ['diaet', 'nogo', 'saison'];

    /**
     * Spec 21 · S4b — die frame-gestützte Hälfte von Tranche C.
     *
     * Beide Checks fallen aus EINEM `CoverageService::coverage()`-Aufruf je Konzept
     * (Preis-Kopf bzw. Regel-Befunde), darum EIN Helfer statt zwei: die Lade- und
     * Auflöse-Logik existiert genau einmal, und wer sie ändert, ändert beide Checks.
     *
     * Anders als die S4a-Checks ist das **kein Builder-Prädikat** — `coverage()` lädt je
     * Konzept Slots, Regeln, Gerichte und ruft `preisCockpit()`. Muster ist deshalb der
     * PHP-Pass aus S1b (`namingVerstossIds`): erst die IDs bestimmen, dann `whereIn`,
     * damit `betroffene()`/`countFor()`/`trifftObjekt()` unverändert weiterfunktionieren.
     *
     * Zwei Dinge halten die Kosten klein:
     *  · Die Arbeitsmenge ist doppelt geschnitten — in Gebrauch (S4a) UND mit Gerüst.
     *    Ohne Gerüst gibt es kein Soll; ein Konzept ohne gesetzte Preis-/Regel-Erwartung
     *    kann sie nicht verletzen (`coverage()` gäbe dort `hat_geruest=false` zurück).
     *  · Bewusst OHNE Memoisierung, aus demselben Grund wie {@see nameScan}: der Service
     *    wird auch NACH einem Fix erneut gefragt (`countFor` ⇒ Signal schließen), ein
     *    Cache meldete dort den alten Stand.
     *
     * Gemeldet wird ausschließlich `ampel='verletzt'` — also genau das, was das Concepter-
     * Cockpit rot zeigt. `teilerfuellt` bleibt draußen: „noch keine bepreisten Positionen"
     * oder „>10 % vom Zielpreis, aber in der Spanne" sind Arbeitsstände, keine Fehler.
     * Damit sagen Ampel und Signal dasselbe — sonst gäbe es zwei Wahrheiten über dasselbe
     * Konzept.
     *
     * @param  'preis'|'regel'  $art
     */
    private function konzepteMitFrameBefund(Team $team, string $art): \Illuminate\Database\Eloquent\Builder
    {
        $kandidaten = $this->konzepteInGebrauch($team)
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('foodalchemist_planning_frames as pf')
                ->where('pf.owner_type', 'concept')
                ->whereColumn('pf.owner_id', 'foodalchemist_concepts.id')
                ->whereNull('pf.deleted_at'))
            ->pluck('id')->all();

        $treffer = [];
        foreach ($kandidaten as $id) {
            foreach ($this->coverage->coverage($team, 'concept', (int) $id)['befunde'] as $b) {
                if ($b['ampel'] !== 'verletzt') {
                    continue;
                }
                $passt = $art === 'preis'
                    ? $b['dimension'] === 'preis'
                    : in_array($b['dimension'], self::FRAME_REGEL_DIMENSIONEN, true);
                if ($passt) {
                    $treffer[] = (int) $id;
                    break;
                }
            }
        }

        return $this->konzepteInGebrauch($team)->whereIn('id', $treffer);
    }

    /**
     * Spec 21 · S4b-2 — die Anker-Graph-Hälfte von Tranche C.
     *
     * Anders als {@see konzepteMitFrameBefund} braucht dieser Check **kein Planungs-Gerüst**:
     * er misst das Konzept gegen sich selbst (welche Gänge tragen dieselbe Hauptzutat) und
     * greift damit auch dort, wo niemand ein Soll gesetzt hat. Die Arbeitsmenge bleibt die
     * aus S4a (in Gebrauch), enger geschnitten wird nur über die Sache selbst: unter zwei
     * Gerichten gibt es keine Menüfolge.
     *
     * Der Befund kommt aus {@see PairingService::menuRepetitions} — bewusst NICHT aus
     * `menuCohesion`, obwohl der Fahrplan das zuerst so vorsah: dessen Score liest einen
     * geteilten Anker als maximale Nähe (1,0), was für den Teller stimmt und für die
     * Menüfolge die Diagnose umdreht; und seine Anker-Union je Gericht enthält jede
     * Nebenzutat, „zweimal Butter" wäre dort dasselbe Signal wie „zweimal Lachs".
     * Begründung im Detail am Service.
     *
     * `severity=info`: eine Wiederholung ist kein Fehler, sondern eine Frage an die
     * Dramaturgie — ein Themen-Menü (Kürbis-Menü) wiederholt bewusst. Als Warnung wäre
     * das genau das Über-Flaggen, das Spec 21 §9 ausschließt.
     */
    private function konzepteMitWiederholung(Team $team): \Illuminate\Database\Eloquent\Builder
    {
        $treffer = [];
        foreach ($this->konzepteInGebrauch($team)->pluck('id') as $id) {
            if ($this->pairing->menuRepetitions($this->konzeptGerichtIds((int) $id)) !== []) {
                $treffer[] = (int) $id;
            }
        }

        return $this->konzepteInGebrauch($team)->whereIn('id', $treffer);
    }

    /**
     * Die Gerichte EINES Konzepts über beide Befüllungs-Arten — Gericht-Slot und
     * Paket-Slot (dieselben zwei Wege wie {@see slotOhneWording}; ein Paket bringt
     * seine Gerichte in die Menüfolge ein, auch wenn sie an keinem eigenen Slot hängen).
     *
     * @return list<int>
     */
    private function konzeptGerichtIds(int $conceptId): array
    {
        $direkt = DB::table('foodalchemist_concept_slots')
            ->where('concept_id', $conceptId)
            ->whereNull('deleted_at')
            ->whereNotNull('sales_recipe_id')
            ->pluck('sales_recipe_id')->all();

        $ausPaket = DB::table('foodalchemist_package_dishes as pd')
            ->join('foodalchemist_concept_slots as cs', 'cs.package_id', '=', 'pd.package_id')
            ->where('cs.concept_id', $conceptId)
            ->whereNull('cs.deleted_at')
            ->whereNull('pd.deleted_at')
            ->whereNotNull('pd.sales_recipe_id')
            ->pluck('pd.sales_recipe_id')->all();

        return array_values(array_unique(array_map('intval', array_merge($direkt, $ausPaket))));
    }

    /**
     * Konzepte, die real benutzt werden — nur hier ist Unvollständigkeit ein Mangel.
     * Vier Wege in den Gebrauch: Status `aktiv`, an einem Angebot (direkt oder über die
     * Angebots-Zuordnung), oder in einem Foodbook-Block referenziert. Vorlagen
     * (`is_template`) sind per Definition Gerüste und bleiben draußen, `archiviert` ist
     * ausgemustert.
     */
    private function konzepteInGebrauch(Team $team): \Illuminate\Database\Eloquent\Builder
    {
        return FoodAlchemistConcept::visibleToTeam($team)
            ->where('is_template', false)
            ->where('status', '!=', 'archiviert')
            ->where(fn ($w) => $w
                ->where('status', 'aktiv')
                ->orWhereNotNull('offer_id')
                ->orWhereExists(fn ($q) => $q->select(DB::raw(1))->from('foodalchemist_offer_concept as oc')
                    ->whereColumn('oc.concept_id', 'foodalchemist_concepts.id'))
                ->orWhereExists(fn ($q) => $q->select(DB::raw(1))->from('foodalchemist_foodbook_blocks as fb')
                    ->whereColumn('fb.concept_id', 'foodalchemist_concepts.id')
                    ->whereNull('fb.deleted_at')));
    }

    /**
     * EXISTS: ein Pflicht-Slot ohne Befüllung. „Genau eines von Paket/Gericht" erzwingt
     * der ConceptService — hier zählt nur, dass keines von beiden steht. Struktur-Slots
     * (Header/Text/Leerzeile) sind bewusst ausgenommen: sie tragen nie Inhalt
     * (ConceptService::STRUKTUR_TYPEN).
     */
    private function offenerPflichtSlot(): \Closure
    {
        return fn ($q) => $q->select(DB::raw(1))->from('foodalchemist_concept_slots as cs')
            ->whereColumn('cs.concept_id', 'foodalchemist_concepts.id')
            ->whereNull('cs.deleted_at')
            ->whereNotIn('cs.type', ConceptService::STRUKTUR_TYPEN)
            ->where('cs.is_pflicht', true)
            ->whereNull('cs.package_id')
            ->whereNull('cs.sales_recipe_id');
    }

    /** EXISTS: mindestens ein Inhalts-Slot ist belegt (Gericht oder Paket). */
    private function belegterInhaltsSlot(): \Closure
    {
        return fn ($q) => $q->select(DB::raw(1))->from('foodalchemist_concept_slots as cs')
            ->whereColumn('cs.concept_id', 'foodalchemist_concepts.id')
            ->whereNull('cs.deleted_at')
            ->whereNotIn('cs.type', ConceptService::STRUKTUR_TYPEN)
            ->where(fn ($w) => $w->whereNotNull('cs.package_id')->orWhereNotNull('cs.sales_recipe_id'));
    }

    /**
     * EXISTS: eine Gericht-Zeile dieses Konzepts würde den internen Namen drucken.
     *
     * Spiegelt {@see WordingResolver} — beide Befüllungs-Arten, weil beide im
     * Kundendokument als Zeile erscheinen:
     *   · Gericht-Slot → `slot.wording` → `dish.sales_wording_standard`
     *   · Paket-Slot   → Paket-Gerichte gehen direkt auf `sales_wording_standard`
     *     (ein Slot-Override existiert für sie nicht, s. WordingResolver::gerichtZeilen)
     */
    private function slotOhneWording(): \Closure
    {
        $ohneStandard = fn ($spalte) => fn ($q) => $q->select(DB::raw(1))->from('foodalchemist_recipes as wr')
            ->whereColumn('wr.id', $spalte)
            ->whereNull('wr.deleted_at')
            ->whereRaw("LENGTH(TRIM(COALESCE(wr.sales_wording_standard, ''))) = 0");

        return function ($q) use ($ohneStandard) {
            return $q->select(DB::raw(1))->from('foodalchemist_concept_slots as cs')
                ->whereColumn('cs.concept_id', 'foodalchemist_concepts.id')
                ->whereNull('cs.deleted_at')
                ->where(fn ($w) => $w
                    ->where(fn ($gericht) => $gericht
                        ->whereNotNull('cs.sales_recipe_id')
                        ->whereRaw("LENGTH(TRIM(COALESCE(cs.wording, ''))) = 0")
                        ->whereExists($ohneStandard('cs.sales_recipe_id')))
                    ->orWhere(fn ($paket) => $paket
                        ->whereNotNull('cs.package_id')
                        ->whereExists(fn ($p) => $p->select(DB::raw(1))->from('foodalchemist_package_dishes as pd')
                            ->whereColumn('pd.package_id', 'cs.package_id')
                            ->whereNull('pd.deleted_at')
                            ->whereExists($ohneStandard('pd.sales_recipe_id')))));
        };
    }

    /**
     * Spec 21 Tranche D — Qualität des Kundendokuments (Foodbook-Ebene, deterministisch, 0-Egress).
     *
     * Letzte Stufe der Kaskade: LA → GP → Basisrezept → VK-Gericht → Konzept → **Foodbook**.
     * Das Buch ist das Artefakt, das der Kunde in die Hand bekommt — ein leeres Kapitel darin
     * fällt heute erst beim Lesen des PDF auf, und dann steht es schon beim Kunden.
     *
     * **Drei Arbeitsmengen, nicht eine** — das ist die tragende Abgrenzung dieser Tranche und
     * der Unterschied zu Tranche C (dort genügte `konzepteInGebrauch` für alle Checks):
     *  · `foodbook_kapitel_leer` + `foodbook_ziel_verfehlt` messen nur BENUTZTE Bücher
     *    ({@see foodbooksInGebrauch}) — ein Entwurf hat leere Kapitel, weil er ein Entwurf ist.
     *  · `foodbook_skizze_ungeerdet` hängt am **Kapitel-Go**: dort hat ein Mensch „Anlegen"
     *    gedrückt, und damit ist der Arbeitsstand erklärt — unabhängig vom Buch-Status.
     *  · `foodbook_stale` misst nur FREIGEGEBENE Bücher ({@see foodbooksFreigegeben}) — in der
     *    Kalkulation sollen Preise sich bewegen, „überholt" gibt es erst draußen.
     *
     * @return list<array<string,mixed>>
     */
    private function foodbook(Team $team): array
    {
        $out = [];
        foreach ($this->foodbookChecks() as $key => $c) {
            $out[] = $this->gap($key, $c['label'], ($c['q'])($team)->count(), $c['typ'], $c['dedup'], $c['desc'], $c['sev']);
        }

        return $out;
    }

    /**
     * Tranche-D-Register — gleiche Bauart wie {@see rezeptQualitaetChecks} und
     * {@see konzeptChecks}: Label, Signal-Deskriptor und Prädikat je Check an EINER
     * Stelle, aus der Zähl-Seite, Objekt-Liste (`betroffene`) und Lifecycle (`countFor`)
     * gemeinsam ziehen. Objekt ist immer das **Foodbook**, nicht das Kapitel: es ist das,
     * was man öffnet und was einen Namen trägt — dieselbe Wahl wie in Tranche C, wo das
     * Konzept und nicht der Slot das Objekt ist.
     *
     * @return array<string,array{label:string,typ:SignalTyp,dedup:string,sev:SignalSeverity,desc:string,q:\Closure}>
     */
    private function foodbookChecks(): array
    {
        return [
            'foodbook_kapitel_leer' => [
                'label' => 'Foodbooks in Gebrauch mit leerem Kapitel oder ohne jeden Inhalt',
                'typ' => SignalTyp::FoodbookKapitelLeer,
                'dedup' => 'dq-foodbook-kapitel-leer',
                'sev' => SignalSeverity::Warnung,
                'desc' => 'Mindestens ein Kapitel dieses Foodbooks trägt keine Inhalts-Zeile: weder einen '
                    . 'Paket-/Konzept-Block (`concept_ref`) noch ein einzelnes Gericht (`recipe_ref`). Kopfzeilen, '
                    . 'Text, Abstand und Bild zählen nicht als Inhalt — sie beschreiben ihn. Gemessen werden nur '
                    . 'Kapitel OHNE Unterkapitel: ein Eltern-Kapitel ist eine Klammer, sein Inhalt steht darunter. '
                    . 'Ein unsichtbar geschalteter Block zählt ebenfalls nicht: im Kundendokument druckt das '
                    . 'Kapitel dann leer, und genau das ist der Befund. Zweiter Zweig: ein Buch mit überhaupt '
                    . 'keinem befüllten Kapitel — es hat kein LEERES Kapitel und käme sonst unauffällig durch.',
                'q' => fn (Team $t) => $this->foodbooksInGebrauch($t)->where(fn ($w) => $w
                    ->whereExists($this->kapitelOhneInhalt())
                    ->orWhereNotExists($this->kapitelMitInhalt())),
            ],
            'foodbook_skizze_ungeerdet' => [
                'label' => 'Foodbooks mit Kreativ-Skizze, die nach dem Go nicht geerdet wurde',
                'typ' => SignalTyp::FoodbookSkizzeUngeerdet,
                'dedup' => 'dq-foodbook-skizze-ungeerdet',
                'sev' => SignalSeverity::Warnung,
                'desc' => 'Beim Kapitel-Go wurde eine Freitext-Skizze in die KI-Queue gestellt '
                    . '(`generation_status=queued`), aber es ist nie ein Gericht daraus geworden. Ohne '
                    . 'LLM-Provider bleibt eine Skizze bewusst queued und retrybar — deshalb greift der Befund '
                    . 'erst ' . self::SKIZZE_STUCK_STUNDEN . ' Stunden nach dem Go: davor ist sie in Arbeit, '
                    . 'danach steckt sie. Es geht um verlorene Kreativarbeit, nicht um einen Datenfehler: die Idee '
                    . 'steht im Buch-Entwurf, im Sortiment steht sie nicht.',
                'q' => fn (Team $t) => $this->foodbooksNichtArchiviert($t)->whereExists($this->ungeerdeteSkizze()),
            ],
            'foodbook_ziel_verfehlt' => [
                'label' => 'Foodbooks in Gebrauch mit verfehltem Kapitel-Ziel',
                'typ' => SignalTyp::FoodbookZielVerfehlt,
                'dedup' => 'dq-foodbook-ziel-verfehlt',
                'sev' => SignalSeverity::Warnung,
                'desc' => 'Mindestens ein Kapitel dieses Foodbooks reißt sein gesetztes SOLL: das Mengengerüst '
                    . '(`target_count`) ist mit NULL Gerichten unbesetzt, oder der Ø-VK der Gerichte im Kapitel '
                    . 'liegt außerhalb der Kapitel-Preisspanne. Das Ziel wird n-tief vererbt (Kapitel → Eltern), '
                    . 'ein Unterkapitel erbt also die Vorgabe der Klammer darüber; der Ist-Bezug rollt umgekehrt '
                    . 'über alle Nachfahren hoch. Gemeldet wird nur die ROTE Lage — „3 von 5 Gerichten da" oder '
                    . '„weicht >15 % vom Preis-Anker ab" ist gelb und bleibt Arbeitsstand. Ohne Planungs-Gerüst '
                    . 'gibt es keinen Befund: die Kapitel-Ziele werden nur innerhalb der Coverage ausgewertet.',
                'q' => fn (Team $t) => $this->foodbooksMitKapitelZielBefund($t),
            ],
            'foodbook_stale' => [
                'label' => 'Freigegebene Foodbooks mit überholtem Preis',
                'typ' => SignalTyp::FoodbookStale,
                'dedup' => 'dq-foodbook-stale',
                'sev' => SignalSeverity::Warnung,
                'desc' => 'Das Buch ist freigegeben bzw. beim Kunden, aber mindestens eines seiner Gerichte hat '
                    . 'seinen freigegebenen VK-Snapshot (R2.5) verlassen: der live gerechnete Preis weicht über die '
                    . 'Team-Leitplanke (`max_vk_delta_pct`) hinaus ab. Das Dokument nennt damit einen Preis, den das '
                    . 'System nicht mehr rechnet. Bücher in der Kalkulation zählen bewusst nicht mit — dort SOLLEN '
                    . 'Preise sich bewegen. Gerichte ohne je freigegebenen Snapshot ebenfalls nicht: ohne Freigabe '
                    . 'gibt es keinen Kundenpreis, von dem etwas abweichen könnte.',
                'q' => fn (Team $t) => $this->foodbooksMitPreisDrift($t),
            ],
            // Spec 03 · L2b hat den Fixer nachgeliefert (Kapitel-Textfeld + KI-Knopf je Kapitel),
            // damit ist der §9-Vorbehalt erledigt und der Check darf scharf sein.
            'foodbook_kapitel_ohne_text' => [
                'label' => 'Foodbooks in Gebrauch mit Kapitel ohne Hinführung',
                'typ' => SignalTyp::FoodbookKapitelOhneText,
                'dedup' => 'dq-foodbook-kapitel-ohne-text',
                // Bewusst `info`, nicht `warnung`: das Kapitel ist druckbar und inhaltlich
                // vollständig, es ist nur nicht ausformuliert. Als Warnung stünde eine
                // Formulierungs-Aufgabe neben einem falschen Kundenpreis.
                'sev' => SignalSeverity::Info,
                'desc' => 'Mindestens ein befülltes Kapitel dieses Foodbooks hat keinen Kundentext '
                    . '(`description`) — im Dokument folgt auf die Kapitel-Überschrift direkt die Liste, '
                    . 'ohne Hinführung. Gemessen werden nur Kapitel, die auch Inhalt TRAGEN: ein leeres '
                    . 'Kapitel ist bereits `foodbook_kapitel_leer` und braucht keinen Text, sondern '
                    . 'Gerichte — zwei Signale auf denselben Sachverhalt wären Rauschen. Auflösen im '
                    . 'Kapitel-Kopf der Leitstelle: das Feld „Hinführung" mit ✨ KI-Text (Spec 03 L2b).',
                'q' => fn (Team $t) => $this->foodbooksInGebrauch($t)->whereExists($this->kapitelOhneText()),
            ],
        ];
    }

    /**
     * Spec 21 · S4c-2 — die frame-gestützte Hälfte von Tranche D. Baugleich zu
     * {@see konzepteMitFrameBefund} (PHP-Pass → `whereIn`, keine Memoisierung), aber auf
     * die **Kapitel-Befunde** geschnitten: `CoverageService::pruefeKapitel` stempelt genau
     * diesen Befunden eine `chapter_id` auf, alles ohne ist ein Frame-Kopf- oder Slot-Befund
     * und damit ein anderer Sachverhalt (der wäre das Foodbook-Gegenstück zu
     * `konzept_preisband_verletzt`, nicht das Kapitel-Ziel).
     *
     * **Die Ziel-Vererbung n-tief steckt in `kapitelKettenZiel`** (self + Eltern hoch, erstes
     * gesetztes gewinnt) und der Ist-Rollup über alle Nachfahren in `istFoodbook` — beides
     * wird hier bewusst NICHT nachgebaut. Eine eigene Auflösung wäre die zweite Wahrheit,
     * an der Signal und Planungs-Rail auseinanderlaufen.
     *
     * Bekannte Grenze, bewusst so: ohne Planungs-Gerüst am Buch gibt `coverage()` früh
     * `hat_geruest=false` zurück, ein von Hand gesetztes Kapitel-Ziel wird dann nirgends
     * ausgewertet — auch nicht in der UI. Der Signal-Layer richtet das nicht einseitig
     * (→ Verbesserungs-Backlog), sonst meldete er einen Befund, den keine Fläche zeigt.
     */
    private function foodbooksMitKapitelZielBefund(Team $team): \Illuminate\Database\Eloquent\Builder
    {
        $kandidaten = $this->foodbooksInGebrauch($team)
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('foodalchemist_planning_frames as pf')
                ->where('pf.owner_type', 'foodbook')
                ->whereColumn('pf.owner_id', 'foodalchemist_foodbooks.id')
                ->whereNull('pf.deleted_at'))
            ->pluck('id')->all();

        $treffer = [];
        foreach ($kandidaten as $id) {
            foreach ($this->coverage->coverage($team, 'foodbook', (int) $id)['befunde'] as $b) {
                if ($b['ampel'] === 'verletzt' && ($b['chapter_id'] ?? null) !== null) {
                    $treffer[] = (int) $id;
                    break;
                }
            }
        }

        return $this->foodbooksInGebrauch($team)->whereIn('id', $treffer);
    }

    /**
     * Spec 21 · S4c-2 — die Snapshot-Hälfte von Tranche D.
     *
     * „Stale" heißt hier **der freigegebene Kundenpreis ist überholt**, und diese Aussage
     * hat im System genau eine Quelle: {@see VkSnapshotService::pending} vergleicht den
     * eingefrorenen VK gegen den live gerechneten, mit der Team-Leitplanke als Schwelle.
     * Dieselbe Liste füttert das Signal `vk_anpassung_empfohlen` — dort je Gericht, hier je
     * Buch. Zwei Objekte, eine Wahrheit: eine eigene Schwelle im Foodbook-Layer hieße, dass
     * ein Preis am Gericht schon driftet und am Buch noch nicht.
     *
     * Das Buch-Signal ist trotzdem kein Duplikat, sondern die andere Frage: nicht „welcher
     * Preis muss nachgezogen werden", sondern „welches Dokument steht mit einem falschen
     * Preis beim Kunden" — nur die zweite Frage hat einen Adressaten außerhalb der Küche.
     *
     * Ein Team-weiter `pending()`-Aufruf statt einer Rechnung je Buch: die Kandidaten-Menge
     * ist klein (nur Gerichte MIT Freigabe) und hängt nicht am Buch.
     */
    private function foodbooksMitPreisDrift(Team $team): \Illuminate\Database\Eloquent\Builder
    {
        $driftend = array_flip(array_map(
            static fn (array $p): int => (int) $p['recipe_id'],
            $this->vkSnapshots->pending($team)
        ));

        $treffer = [];
        if ($driftend !== []) {
            foreach ($this->foodbooksFreigegeben($team)->pluck('id') as $id) {
                foreach ($this->foodbookGerichtIds((int) $id) as $recipeId) {
                    if (isset($driftend[$recipeId])) {
                        $treffer[] = (int) $id;
                        break;
                    }
                }
            }
        }

        return $this->foodbooksFreigegeben($team)->whereIn('id', $treffer);
    }

    /**
     * Dritte Arbeitsmenge der Tranche (nach `foodbooksInGebrauch` und der Go-Menge):
     * Bücher, die **draußen** sind. Gegenüber `foodbooksInGebrauch` fällt genau die Phase
     * `kalkulation` weg — dort ist das Verschieben von Preisen die Arbeit selbst, und ein
     * Signal darauf wäre exakt das Über-Flaggen, das Spec 21 §9 ausschließt. Der
     * Versand-Snapshot am Kapitel bleibt drin: was versendet wurde, ist beim Kunden.
     */
    private function foodbooksFreigegeben(Team $team): \Illuminate\Database\Eloquent\Builder
    {
        return $this->foodbooksNichtArchiviert($team)
            ->where(fn ($w) => $w
                ->whereIn('status', self::FOODBOOK_STATUS_IN_GEBRAUCH)
                ->orWhere('phase', 'freigabe')
                ->orWhereExists(fn ($q) => $q->select(DB::raw(1))->from('foodalchemist_foodbook_chapters as sk')
                    ->whereColumn('sk.foodbook_id', 'foodalchemist_foodbooks.id')
                    ->whereNull('sk.deleted_at')
                    ->whereNotNull('sk.snapshot_at')));
    }

    /**
     * Die Gerichte EINES Foodbooks über beide Befüllungs-Arten der Spec-19-Duality:
     * `recipe_ref`-Block (Einzelgericht) und `concept_ref`-Block (Paket/Konzept, dessen
     * Gerichte über {@see konzeptGerichtIds} kommen — inklusive Paket-Slots).
     *
     * **Nur sichtbare Blöcke**, dieselbe Grenze wie bei {@see kapitelOhneInhalt}: beurteilt
     * wird, was im Kundendokument LANDET. Ein ausgeschalteter Block trägt keinen Preis nach
     * draußen und kann deshalb auch keinen überholten zeigen.
     *
     * @return list<int>
     */
    private function foodbookGerichtIds(int $foodbookId): array
    {
        $bloecke = DB::table('foodalchemist_foodbook_blocks as fb')
            ->join('foodalchemist_foodbook_chapters as k', 'k.id', '=', 'fb.chapter_id')
            ->where('k.foodbook_id', $foodbookId)
            ->whereNull('k.deleted_at')
            ->whereNull('fb.deleted_at')
            ->where('fb.visible', true)
            ->get(['fb.sales_recipe_id', 'fb.concept_id']);

        $ids = [];
        foreach ($bloecke as $b) {
            if ($b->sales_recipe_id !== null) {
                $ids[] = (int) $b->sales_recipe_id;
            }
            if ($b->concept_id !== null) {
                $ids = array_merge($ids, $this->konzeptGerichtIds((int) $b->concept_id));
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Foodbooks, die real benutzt werden — nur hier ist ein leeres Kapitel ein Mangel.
     * Drei Wege in den Gebrauch, jeder für sich ausreichend:
     *  · `status` — das Buch ist live bzw. schon beim Kunden (s. FOODBOOK_STATUS_IN_GEBRAUCH).
     *  · `phase` ab `kalkulation` — im Planungs-Workflow ist die Befüllung erklärt-fertig
     *    (PhaseService::PHASEN: kontext → struktur → befuellung → **kalkulation** → freigabe).
     *    Das ist der belastbarere Marker als der Status: die Phase führt die Leitstelle
     *    selbst, den Status setzt jemand von Hand (im Bestand steht er durchweg auf `draft`).
     *  · ein Kapitel mit `snapshot_at` — der Versand hat es eingefroren, es war beim Kunden.
     */
    private function foodbooksInGebrauch(Team $team): \Illuminate\Database\Eloquent\Builder
    {
        return $this->foodbooksNichtArchiviert($team)
            ->where(fn ($w) => $w
                ->whereIn('status', self::FOODBOOK_STATUS_IN_GEBRAUCH)
                ->orWhereIn('phase', self::FOODBOOK_PHASEN_IN_GEBRAUCH)
                ->orWhereExists(fn ($q) => $q->select(DB::raw(1))->from('foodalchemist_foodbook_chapters as sk')
                    ->whereColumn('sk.foodbook_id', 'foodalchemist_foodbooks.id')
                    ->whereNull('sk.deleted_at')
                    ->whereNotNull('sk.snapshot_at')));
    }

    /**
     * Basismenge beider Tranche-D-Checks: alles außer `archiviert`. Ein archiviertes Buch
     * ist ausgemustert — dieselbe Grenze wie bei den Konzepten.
     */
    private function foodbooksNichtArchiviert(Team $team): \Illuminate\Database\Eloquent\Builder
    {
        return FoodAlchemistFoodbook::visibleToTeam($team)->where('status', '!=', 'archiviert');
    }

    /** Ab dieser Phase gilt die Befüllung als erklärt-fertig (s. PhaseService::PHASEN). */
    private const FOODBOOK_PHASEN_IN_GEBRAUCH = ['kalkulation', 'freigabe'];

    /**
     * Status-Werte, die „nicht mehr Arbeitsstand" bedeuten. `versendet` ist eindeutig.
     *
     * **`aktiv` UND `active`, weil das Vokabular im Bestand widersprüchlich ist:** die
     * Migration schreibt `draft|aktiv|versendet|archiviert` (2026_06_13_000045), das
     * Status-Dropdown der Leitstelle schreibt aber `active`
     * (`livewire/foodbooks/index.blade.php:135`) — dasselbe Auseinanderlaufen wie bei den
     * Konzepten (`ConceptService::setStatus` validiert `active`, die Migration kommentiert
     * `aktiv`). Welche Schreibweise kanonisch ist, entscheidet nicht dieser Check; bis dahin
     * misst er BEIDE, statt ein live geschaltetes Buch stillschweigend zu verfehlen. Als Bug
     * gemeldet — sobald der Kanon steht, fällt hier ein Wert weg (und `konzepteInGebrauch`
     * braucht dieselbe Korrektur).
     */
    private const FOODBOOK_STATUS_IN_GEBRAUCH = ['aktiv', 'active', 'versendet'];

    /**
     * Block-Typen, die Inhalt TRAGEN. Der Rest des Vokabulars (`header`/`text`/`spacer`/
     * `image`) beschreibt oder gliedert ihn — dieselbe Unterscheidung wie
     * `ConceptService::STRUKTUR_TYPEN` auf der Konzept-Ebene.
     */
    private const INHALTS_BLOCK_TYPEN = ['concept_ref', 'recipe_ref'];

    /**
     * So lange nach dem Kapitel-Go darf eine Skizze queued bleiben, ohne aufzufallen.
     * Der Go dispatcht die Jobs sofort (`verarbeiteFreitextQueue`); wer nach zwei Tagen
     * noch nichts erzeugt hat, wartet nicht, sondern steckt. Kürzer wäre Rauschen (ein
     * Provider-Ausfall über Nacht), länger würde verlorene Kreativarbeit verschweigen.
     */
    private const SKIZZE_STUCK_STUNDEN = 48;

    /**
     * EXISTS: ein Kapitel dieses Foodbooks ohne eine einzige Inhalts-Zeile.
     *
     * Drei Einschränkungen, jede mit eigenem Grund:
     *  · **Blattkapitel only** — ein Kapitel mit Unterkapiteln ist eine Klammer; sein
     *    Inhalt hängt an den Kindern (Spec 19: Kapitel-Scope = Kapitel + Nachfahren).
     *    Ohne diese Grenze wäre jede saubere Baum-Gliederung ein Befund.
     *  · **`archived`-Kapitel draußen** — dieselbe Logik wie beim Buch-Status.
     *  · **`visible=true`** am Inhalts-Block: das Kapitel wird danach beurteilt, was im
     *    Kundendokument LANDET. Ein ausgeschalteter Block ist im Export nicht da.
     */
    private function kapitelOhneInhalt(): \Closure
    {
        return fn ($q) => $q->select(DB::raw(1))->from('foodalchemist_foodbook_chapters as k')
            ->whereColumn('k.foodbook_id', 'foodalchemist_foodbooks.id')
            ->whereNull('k.deleted_at')
            ->where('k.status', '!=', 'archived')
            ->whereNotExists(fn ($kind) => $kind->select(DB::raw(1))->from('foodalchemist_foodbook_chapters as kk')
                ->whereColumn('kk.parent_id', 'k.id')
                ->whereNull('kk.deleted_at'))
            ->whereNotExists(fn ($b) => $b->select(DB::raw(1))->from('foodalchemist_foodbook_blocks as fb')
                ->whereColumn('fb.chapter_id', 'k.id')
                ->whereNull('fb.deleted_at')
                ->where('fb.visible', true)
                ->whereIn('fb.type', self::INHALTS_BLOCK_TYPEN));
    }

    /**
     * EXISTS: irgendein Kapitel dieses Foodbooks trägt Inhalt. Gegenstück zum zweiten
     * Zweig von `foodbook_kapitel_leer` — dieselbe Lücke, die auf der Konzept-Ebene
     * `belegterInhaltsSlot` schließt: ohne diesen Zweig wäre ein Buch mit NULL Kapiteln
     * unauffällig, weil es kein leeres Kapitel hat.
     */
    private function kapitelMitInhalt(): \Closure
    {
        return fn ($q) => $q->select(DB::raw(1))->from('foodalchemist_foodbook_chapters as k')
            ->whereColumn('k.foodbook_id', 'foodalchemist_foodbooks.id')
            ->whereNull('k.deleted_at')
            ->where('k.status', '!=', 'archived')
            ->whereExists(fn ($b) => $b->select(DB::raw(1))->from('foodalchemist_foodbook_blocks as fb')
                ->whereColumn('fb.chapter_id', 'k.id')
                ->whereNull('fb.deleted_at')
                ->where('fb.visible', true)
                ->whereIn('fb.type', self::INHALTS_BLOCK_TYPEN));
    }

    /**
     * EXISTS: ein Kapitel dieses Foodbooks, das Inhalt trägt, aber keinen Kundentext.
     *
     * Die tragende Grenze ist **„trägt Inhalt"** und damit die Umkehrung von
     * {@see kapitelOhneInhalt}: beide Checks teilen dieselbe Inhalts-Definition (sichtbarer
     * `concept_ref`/`recipe_ref`-Block), aber sie schließen sich aus. Ein leeres Kapitel
     * braucht keine Hinführung, sondern Gerichte — es dort zusätzlich zu melden hieße,
     * einen Sachverhalt zweimal zu zählen (Spec 21 §9).
     *
     * Anders als beim Inhalts-Check ist **kein Blatt-Filter** nötig: geprüft wird, was
     * Inhalt trägt, und das kann auch ein Eltern-Kapitel mit eigenen Blöcken sein. Eine
     * reine Klammer ohne eigene Blöcke fällt über dieselbe Bedingung heraus.
     *
     * `visible` wird bewusst NICHT am Text geprüft — das Feld hat kein Sichtbarkeits-Flag:
     * `dokumentDaten` gibt `description` als `text` je Kapitel heraus, sobald das Kapitel
     * gedruckt wird (Projektion mit L2b nachgezogen; vorher las sie niemand).
     */
    private function kapitelOhneText(): \Closure
    {
        return fn ($q) => $q->select(DB::raw(1))->from('foodalchemist_foodbook_chapters as k')
            ->whereColumn('k.foodbook_id', 'foodalchemist_foodbooks.id')
            ->whereNull('k.deleted_at')
            ->where('k.status', '!=', 'archived')
            // Haus-Muster der Wording-Checks: NULL, leer und „nur Leerzeichen" sind dasselbe
            // (das Formular schreibt '' statt NULL, ein versehentliches Space wäre kein Text).
            ->whereRaw("LENGTH(TRIM(COALESCE(k.description, ''))) = 0")
            ->whereExists(fn ($b) => $b->select(DB::raw(1))->from('foodalchemist_foodbook_blocks as fb')
                ->whereColumn('fb.chapter_id', 'k.id')
                ->whereNull('fb.deleted_at')
                ->where('fb.visible', true)
                ->whereIn('fb.type', self::INHALTS_BLOCK_TYPEN));
    }

    /**
     * EXISTS: eine Freitext-Skizze, die der Kapitel-Go in die KI-Queue gestellt hat und
     * die dort hängen geblieben ist.
     *
     * Der Zustand ist exakt der, den `materialisiereFreitextIdee` als „noch offen" liest
     * (`sales_recipe_id` null · `generation_status='queued'` · `materialized_at` null) —
     * gespiegelt statt neu formuliert, damit Signal und Queue nicht auseinanderlaufen.
     * Dazu `generated_recipe_id` null: ein erzeugtes Rezept ist das Ergebnis, auch wenn
     * der Rest-Stempel fehlen sollte.
     *
     * `fehlgeschlagen` zählt bewusst NICHT: dort ist die KI gelaufen und hat verloren, das
     * meldet die Leitstelle am Kapitel selbst. Hier geht es um den stillen Fall — niemand
     * bekommt mit, dass nichts passiert ist.
     */
    private function ungeerdeteSkizze(): \Closure
    {
        return fn ($q) => $q->select(DB::raw(1))->from('foodalchemist_foodbook_chapters as k')
            ->whereColumn('k.foodbook_id', 'foodalchemist_foodbooks.id')
            ->whereNull('k.deleted_at')
            ->whereNotNull('k.released_at')
            ->where('k.released_at', '<', now()->subHours(self::SKIZZE_STUCK_STUNDEN))
            ->whereExists(fn ($i) => $i->select(DB::raw(1))->from('foodalchemist_dish_ideas as di')
                ->whereColumn('di.chapter_id', 'k.id')
                ->whereNull('di.deleted_at')
                ->where('di.generation_status', 'queued')
                ->whereNull('di.sales_recipe_id')
                ->whereNull('di.generated_recipe_id')
                ->whereNull('di.materialized_at')
                ->where('di.status', '!=', 'verworfen'));
    }

    /** @return list<array<string,mixed>> */
    private function quer(Team $team): array
    {
        // Zutat-Mappings aus KI-Vorschlag, noch nicht menschlich verifiziert.
        $geminiUnverifiziert = DB::table('foodalchemist_recipe_ingredients as ri')
            ->where('ri.match_method', 'gemini_proposed')
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('foodalchemist_recipes as r')
                ->whereColumn('r.id', 'ri.recipe_id')->whereNull('r.deleted_at'))
            ->count();

        return [
            $this->gap('ri_gemini_unverifiziert', 'Zutat-Mappings (KI-Vorschlag, unverifiziert)', $geminiUnverifiziert, null, null),
        ];
    }

    // ---- Wiederverwendete Sub-Queries ------------------------------------

    /** Basis-Query der Rezepte einer Sicht (VK = true, Basisrezept = false). */
    private function rezepte(Team $team, bool $vk): \Illuminate\Database\Eloquent\Builder
    {
        return FoodAlchemistRecipe::visibleToTeam($team)->where('is_sales_recipe', $vk);
    }

    /** Basis-Query über BEIDE Rezept-Arten — Tranche-A-Regeln gelten für Basisrezept wie VK-Gericht. */
    private function alleRezepte(Team $team): \Illuminate\Database\Eloquent\Builder
    {
        return FoodAlchemistRecipe::visibleToTeam($team);
    }

    /** Rezepte, die benutzbar sein wollen (s. PRODUKTIV_STATUS) — Stubs/Entwürfe/Ausgemusterte raus. */
    private function produktiveRezepte(Team $team): \Illuminate\Database\Eloquent\Builder
    {
        return $this->alleRezepte($team)->whereIn('status', self::PRODUKTIV_STATUS);
    }

    /** EXISTS: die Speisen-Hauptgruppe des VK-Gerichts ist stillgelegt (269er-Neutralisierung). */
    private function inaktiveSpeisenHauptgruppe(): \Closure
    {
        return fn ($q) => $q->select(DB::raw(1))->from('foodalchemist_dish_main_groups as hg')
            ->whereColumn('hg.id', 'foodalchemist_recipes.dish_main_group_id')
            ->where('hg.is_inactive', true)->whereNull('hg.deleted_at');
    }

    /**
     * EXISTS-Closure auf die *beitragenden* Zutaten eines Rezepts — dieselbe Auswahl, die
     * RecipeRecomputeService::yieldUndZaehler in den Yield rechnet (optional und Einheit „qs"
     * tragen dort 0 bei, also auch hier nicht).
     *
     * @param bool $ohneMasse true ⇒ nur die beitragenden Zutaten, die NICHT in Gramm
     *                        umrechenbar sind (Zähl-/Volumen-Einheit ohne Gramm-Default).
     */
    private function beitragendeZutat(bool $ohneMasse): \Closure
    {
        return function ($q) use ($ohneMasse) {
            $q->select(DB::raw(1))
                ->from('foodalchemist_recipe_ingredients as ri')
                ->join('foodalchemist_vocab_units as u', 'u.id', '=', 'ri.unit_vocab_id')
                ->whereColumn('ri.recipe_id', 'foodalchemist_recipes.id')
                ->whereNull('ri.deleted_at')
                ->where('ri.is_optional', false)
                ->where('u.slug', '!=', 'qs');

            if ($ohneMasse) {
                $q->where(fn ($w) => $w->where('u.dimension', '!=', 'mass')
                    ->orWhereNull('u.dimension')
                    ->orWhereNull('u.default_in_g')
                    ->orWhere('u.default_in_g', '<=', 0));
            }

            return $q;
        };
    }

    /**
     * Korrelierte Summe der Roh-Einsatzmasse in Gramm (vor Verlust) — spiegelt
     * `mengeAvg × default_in_g` aus RecipeRecomputeService (Mengen-Bereich = Mittelwert,
     * §6.4). Nur aufgerufen, wenn alle beitragenden Zutaten Massen-Einheiten tragen.
     */
    private function einsatzmasseGrammSql(): string
    {
        return 'SELECT SUM(((ri.quantity + COALESCE(ri.quantity_max, ri.quantity)) / 2) * u.default_in_g)'
            . ' FROM foodalchemist_recipe_ingredients ri'
            . ' JOIN foodalchemist_vocab_units u ON u.id = ri.unit_vocab_id'
            . ' WHERE ri.recipe_id = foodalchemist_recipes.id AND ri.deleted_at IS NULL'
            . " AND ri.is_optional = 0 AND u.slug != 'qs'";
    }

    /**
     * Schränkt auf Rezepte ein, die den Gast erreichen: entweder direkt auf einer
     * Kunden-Fläche (Konzept/Foodbook/Speiseplan/Paket) oder als Sub-Rezept eines Rezepts,
     * das dort hängt. Bewusst EINE Ebene tief — tiefer wäre rekursiv (max. 3 Ebenen laut
     * Regelwerk §4) und für die Trefferliste unverhältnismäßig; die Eltern-Ebene ist der Fall,
     * der zählt (Sauce im verkauften Gericht).
     */
    private function kundenExponiert(\Illuminate\Database\Eloquent\Builder $q): \Illuminate\Database\Eloquent\Builder
    {
        return $q->where(function ($w) {
            foreach (self::KUNDEN_REFERENZEN as [$tabelle, $spalte]) {
                $w->orWhereExists(fn ($s) => $s->select(DB::raw(1))->from($tabelle)
                    ->whereColumn($tabelle . '.' . $spalte, 'foodalchemist_recipes.id')
                    ->whereNull($tabelle . '.deleted_at'));
            }
            $w->orWhereExists(fn ($s) => $s->select(DB::raw(1))
                ->from('foodalchemist_recipe_ingredients as ri')
                ->whereColumn('ri.referenced_recipe_id', 'foodalchemist_recipes.id')
                ->whereNull('ri.deleted_at')
                ->where(function ($p) {
                    foreach (self::KUNDEN_REFERENZEN as [$tabelle, $spalte]) {
                        $p->orWhereExists(fn ($x) => $x->select(DB::raw(1))->from($tabelle)
                            ->whereColumn($tabelle . '.' . $spalte, 'ri.recipe_id')
                            ->whereNull($tabelle . '.deleted_at'));
                    }
                }));
        });
    }

    // ---- Namens-Checks (PHP statt SQL, bewusst) ---------------------------
    //
    // Naming und Dubletten brauchen Normalisierung + Muster-Erkennung. In SQL wäre das
    // entweder dialekt-gebunden (REGEXP kennt MySQL, SQLite nicht) oder eine Kette
    // verschachtelter REPLACE/LOWER-Aufrufe, die auf beiden Dialekten unterschiedlich mit
    // Umlauten umgeht. Ein Pass über (id, name) ist bei Bestandsgrößen im Tausender-Bereich
    // billig und auf jedem Dialekt bit-identisch. Bewusst ohne Memoisierung: der Service
    // wird auch NACH einem Fix erneut gefragt (countFor ⇒ Signal schließen) — ein Cache
    // würde dort den alten Stand melden.

    /** @return list<array{id:int,name:string,vk:bool,achse:string}> */
    private function nameScan(Team $team): array
    {
        return $this->alleRezepte($team)
            ->get(['id', 'name', 'is_sales_recipe', 'category_id', 'dish_main_group_id'])
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'name' => (string) $r->name,
                'vk' => (bool) $r->is_sales_recipe,
                // Gruppen-Achse = Rezept-Art + ihre jeweilige Taxonomie. Getrennte Bäume:
                // VK-Gericht hängt an der Speisen-Hauptgruppe, Basisrezept an der Produktions-
                // Kategorie. Gleicher Name in anderer Kategorie ist ein zulässiger Diskriminator.
                'achse' => $r->is_sales_recipe
                    ? 'vk:' . ($r->dish_main_group_id ?? '-')
                    : 'br:' . ($r->category_id ?? '-'),
            ])->all();
    }

    /** Vergleichsform eines Namens: klein, ohne Satzzeichen/Trenner, Leerraum normalisiert. */
    private function normalisierterName(string $name): string
    {
        $n = mb_strtolower($name);
        $n = preg_replace('/[.,;:|\/\-\x{2013}()\[\]"\x{201C}\x{201E}\']+/u', ' ', $n) ?? $n;

        return trim(preg_replace('/\s+/u', ' ', $n) ?? $n);
    }

    /**
     * Rezepte mit deterministisch belegbarem Naming-Verstoß.
     *
     * Grammatur im Namen ist NICHT pauschal ein Verstoß: Regelwerk_Verkaufsgerichte §1.2a
     * (und Basisrezepte §1.8) erlauben sie ausdrücklich, wenn sie zwei sonst gleichnamige
     * Gerichte unterscheidet („(17g)" vs. „(65g)"). Deshalb zählt sie nur, wenn es diesen
     * Zwilling gar nicht gibt — dann ist sie reine Steckbrief-Angabe und gehört ins Datenfeld.
     *
     * @return list<int>
     */
    private function namingVerstossIds(Team $team): array
    {
        $rows = $this->nameScan($team);

        $basisZaehler = [];
        foreach ($rows as $r) {
            $basis = $this->grammaturBasis($r);
            $basisZaehler[$basis] = ($basisZaehler[$basis] ?? 0) + 1;
        }

        $ids = [];
        foreach ($rows as $r) {
            if ($this->namingBefunde($r, $basisZaehler) !== []) {
                $ids[] = $r['id'];
            }
        }

        return $ids;
    }

    /**
     * Spec 21 · S3b-3 (Ursachen-Kette): WELCHE Regel ein einzelnes Rezept verletzt.
     *
     * Der Check selbst (`namingVerstossIds`) beantwortet nur „ja/nein" — für den
     * Deep-Link auf das verletzte § braucht das Panel den konkreten Fall. Die Regel-
     * Prüfung bleibt hier (eine Stelle, dasselbe `nameScan`); die Zuordnung
     * Fall → §/Dokument liegt bewusst NICHT hier, sondern im `SignalCauseService`:
     * dieser Service misst, er formuliert nicht.
     *
     * Der Grammatur-Fall braucht den Zwillings-Zähler über den ganzen Bestand
     * (§1.2a — sie ist nur ohne Diskriminator-Funktion ein Verstoß), darum wird der
     * volle Scan gefahren und danach auf das eine Rezept gefiltert.
     *
     * @return list<string> Fall-Schlüssel aus {@see self::NAMING_FAELLE}, leer = regelkonform
     */
    public function namingBefundeFuer(Team $team, int $recipeId): array
    {
        $rows = $this->nameScan($team);

        $basisZaehler = [];
        foreach ($rows as $r) {
            $basis = $this->grammaturBasis($r);
            $basisZaehler[$basis] = ($basisZaehler[$basis] ?? 0) + 1;
        }

        foreach ($rows as $r) {
            if ($r['id'] === $recipeId) {
                return $this->namingBefunde($r, $basisZaehler);
            }
        }

        return [];
    }

    /** Alle Fall-Schlüssel, die {@see namingBefundeFuer} liefern kann (Vertrag für den Aufrufer). */
    public const NAMING_FAELLE = ['leerraum', 'trenner_rand', 'grammatur', 'vk_marker', 'vk_praefix'];

    /** @param array{id:int,name:string,vk:bool,achse:string} $r */
    private function grammaturBasis(array $r): string
    {
        return $r['achse'] . '|' . $this->normalisierterName(
            preg_replace(self::GRAMMATUR_MUSTER, ' ', $r['name']) ?? $r['name']
        );
    }

    /**
     * Alle verletzten Regel-Fälle eines Namens. Bewusst eine LISTE statt eines bool:
     * der Zähl-Pfad braucht nur „nicht leer", die Ursachen-Kette (S3b-3) den Fall.
     * Es wird nicht beim ersten Treffer abgebrochen — ein Name kann mehrere Regeln
     * reißen, und wer ihn korrigiert, soll alle auf einmal sehen.
     *
     * @param array{id:int,name:string,vk:bool,achse:string} $r
     * @param array<string,int> $basisZaehler
     * @return list<string>
     */
    private function namingBefunde(array $r, array $basisZaehler): array
    {
        $name = $r['name'];
        $faelle = [];

        if (preg_match('/\s{2,}/u', $name) === 1) {
            $faelle[] = 'leerraum';
        }
        if (preg_match(self::NAME_TRENNER_RAND, $name) === 1) {
            $faelle[] = 'trenner_rand';
        }
        if (preg_match(self::GRAMMATUR_MUSTER, $name) === 1
            && ($basisZaehler[$this->grammaturBasis($r)] ?? 0) < 2) {
            $faelle[] = 'grammatur';
        }

        if (! $r['vk']) {
            return $faelle;                                 // §1-Präfix/Marker sind VK-Regeln
        }

        foreach (self::VK_MARKER as $marker) {
            if (mb_stripos($name, $marker) !== false) {
                $faelle[] = 'vk_marker';
                break;
            }
        }

        // Pipe-Skelett: führendes Hauptgruppen-Kürzel in eckigen Klammern (§1.1).
        if (preg_match('/^\[[A-Z]{2,5}\]\s/u', $name) !== 1) {
            $faelle[] = 'vk_praefix';
        }

        return $faelle;
    }

    /**
     * Rezepte, deren normalisierter Name innerhalb derselben Art+Kategorie mehrfach vorkommt.
     * Es werden ALLE Mitglieder der Gruppe geflaggt — man muss beide Seiten ansehen, um zu
     * entscheiden, welche bleibt.
     *
     * @return list<int>
     */
    private function dubletteIds(Team $team): array
    {
        $gruppen = [];
        foreach ($this->nameScan($team) as $r) {
            $gruppen[$r['achse'] . '|' . $this->normalisierterName($r['name'])][] = $r['id'];
        }

        $ids = [];
        foreach ($gruppen as $gruppe) {
            if (count($gruppe) > 1) {
                array_push($ids, ...$gruppe);
            }
        }

        return $ids;
    }

    /** Schränkt auf Rezepte ein, die auf KEINER Nutzungs-Fläche referenziert werden. */
    private function unreferenziert(\Illuminate\Database\Eloquent\Builder $q): \Illuminate\Database\Eloquent\Builder
    {
        foreach (self::NUTZUNGS_REFERENZEN as [$tabelle, $spalte]) {
            $q->whereNotExists(fn ($s) => $s->select(DB::raw(1))->from($tabelle)
                ->whereColumn($tabelle . '.' . $spalte, 'foodalchemist_recipes.id')
                ->whereNull($tabelle . '.deleted_at'));
        }

        return $q;
    }

    /** EXISTS: GP ist in mindestens einer Rezept-Zutat genutzt. */
    private function gpGenutzt(): \Closure
    {
        return fn ($q) => $q->select(DB::raw(1))->from('foodalchemist_recipe_ingredients as ri')
            ->whereColumn('ri.gp_id', 'foodalchemist_gps.id');
    }

    /** EXISTS: GP hat ein Anker-Mapping. */
    private function gpHatAnker(): \Closure
    {
        return fn ($q) => $q->select(DB::raw(1))->from('foodalchemist_gp_anchor_mappings as m')
            ->whereColumn('m.gp_id', 'foodalchemist_gps.id');
    }

    /** EXISTS: Lead-LA des GP hat einen aktiven Preis (>0, nicht gesperrt). */
    private function aktivPreisFuerLead(): \Closure
    {
        return fn ($q) => $q->select(DB::raw(1))->from('foodalchemist_prices as p')
            ->whereColumn('p.supplier_item_id', 'foodalchemist_gps.lead_la_supplier_item_id')
            ->where('p.price', '>', 0)->where('p.is_blocked', false)->whereNull('p.deleted_at');
    }

    /** EXISTS: Rezept hat ein Anker-Mapping. */
    private function rezeptHatAnker(): \Closure
    {
        return fn ($q) => $q->select(DB::raw(1))->from('foodalchemist_recipe_anchor_mappings as m')
            ->whereColumn('m.recipe_id', 'foodalchemist_recipes.id');
    }

    /** VK-Gerichte, deren Standard-Darreichung auf der Servierform „unbestimmt" steht. */
    private function unbestimmteServierform(Team $team): int
    {
        $unbId = FoodAlchemistServierform::where('code', 'unbestimmt')->value('id');
        if ($unbId === null) {
            return 0;
        }

        return $this->rezepte($team, true)
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('foodalchemist_recipe_presentations as p')
                ->whereColumn('p.recipe_id', 'foodalchemist_recipes.id')
                ->where('p.serving_form_id', $unbId)->where('p.is_standard', true)->whereNull('p.deleted_at'))
            ->count();
    }

    // ---- „Reinschauen": betroffene Objekte je Metrik (read-only) ---------

    /**
     * Listet die konkreten Objekte hinter einer Lücken-Metrik — dieselben Prädikate
     * wie die Zähl-Query oben (eine Regel-Stelle, kein Drift), nur SELECT statt COUNT.
     * Für das Signal-Cockpit („reinschauen"): der Detektor speichert im Payload nur
     * die Anzahl, die Objekte werden hier on-demand aufgelöst.
     *
     * @return list<array{kind:string,id:int,name:string,is_sales_recipe:bool}>
     */
    public function betroffene(Team $team, string $metrik, int $limit = 50): array
    {
        [$q, $kind] = $this->queryFor($team, $metrik);
        if ($q === null) {
            return [];
        }

        return match ($kind) {
            'gp' => $this->gpItems($q, $limit),
            'concept' => $this->conceptItems($q, $limit),
            'foodbook' => $this->foodbookItems($q, $limit),
            default => $this->recipeItems($q, $limit),
        };
    }

    /**
     * Umgekehrte Frage (Spec 21 · P·2, objekt-zentrische Sicht): trifft das Prädikat
     * dieser Metrik ein KONKRETES Objekt? Dasselbe `queryFor` wie Zählung und Liste —
     * nur mit `whereKey`, damit „was hat dieses Rezept noch?" nicht die volle
     * Trefferliste jeder Metrik laden muss (ein EXISTS je Metrik statt n Zeilen).
     *
     * $kind muss zur Metrik passen ('gp'|'recipe'|'concept'|'foodbook') — eine GP-Metrik
     * trifft nie ein Rezept, auch wenn die IDs zufällig gleich sind.
     */
    public function trifftObjekt(Team $team, string $metrik, string $kind, int $id): bool
    {
        [$q, $metrikKind] = $this->queryFor($team, $metrik);
        if ($q === null || $metrikKind !== $kind) {
            return false;
        }

        return $q->whereKey($id)->exists();
    }

    /**
     * Re-Count einer einzelnen Metrik (nach einem Fix). Dieselbe Query wie betroffene()
     * (queryFor), nur COUNT — für die Lifecycle-Entscheidung „Signal schließen" (count 0).
     */
    public function countFor(Team $team, string $metrik): int
    {
        [$q] = $this->queryFor($team, $metrik);

        return $q?->count() ?? 0;
    }

    /**
     * EINE Query-Definition je Metrik (kein Drift): betroffene() (SELECT) + countFor() (COUNT)
     * teilen sich diese. Rückgabe [?Builder, kind:'gp'|'recipe'|'concept'|'foodbook'];
     * null = keine Objekte.
     *
     * @return array{0: \Illuminate\Database\Eloquent\Builder|null, 1: string}
     */
    private function queryFor(Team $team, string $metrik): array
    {
        // Die Register (Tranche A = Rezept, C = Konzept, D = Foodbook) definieren ihr
        // Prädikat selbst — hier nur nachschlagen, nicht nachbauen. Der `kind` gehört ans
        // Register, nicht an den Aufrufer: er entscheidet, WELCHES Objekt die Liste zeigt.
        // Der switch unten ist der Altbestand der Kaskaden-Ebenen (s. V-005).
        foreach ([
            ['recipe', $this->rezeptQualitaetChecks()],
            ['concept', $this->konzeptChecks()],
            ['foodbook', $this->foodbookChecks()],
        ] as [$kind, $checks]) {
            if (isset($checks[$metrik])) {
                return [($checks[$metrik]['q'])($team), $kind];
            }
        }

        switch ($metrik) {
            case 'gp_ohne_lead':
                return [FoodAlchemistGp::visibleToTeam($team)->where('status', 'approved')->where('requires_la', true)
                    ->where(fn ($w) => $w->whereNull('lead_la_supplier_item_id')->orWhere('n_las_total', 0)), 'gp'];
            case 'gp_lead_ohne_preis':
                return [FoodAlchemistGp::visibleToTeam($team)->where('status', 'approved')->where('requires_la', true)
                    ->whereNotNull('lead_la_supplier_item_id')->whereNotExists($this->aktivPreisFuerLead()), 'gp'];
            case 'gp_allergen_konfidenz':
                return [FoodAlchemistGp::visibleToTeam($team)->where('status', 'approved')->whereNull('allergens_confidence'), 'gp'];
            case 'gp_anker_fehlt':
                return [FoodAlchemistGp::visibleToTeam($team)->where('status', 'approved')->whereExists($this->gpGenutzt())->whereNotExists($this->gpHatAnker()), 'gp'];
            case 'gp_tentative_genutzt':
                return [FoodAlchemistGp::visibleToTeam($team)->where('status', 'tentative')->whereExists($this->gpGenutzt()), 'gp'];
            case 'br_ek_null':
                return [$this->rezepte($team, false)->whereNull('ek_total_eur'), 'recipe'];
            case 'br_ek_teil':
                return [$this->rezepte($team, false)->whereNotNull('ek_total_eur')->whereColumn('ek_n_ingredients_priced', '<', 'ek_n_ingredients_total'), 'recipe'];
            case 'br_anker_fehlt':
                return [$this->rezepte($team, false)->whereNotExists($this->rezeptHatAnker()), 'recipe'];
            case 'vk_ek_null':
                return [$this->rezepte($team, true)->whereNull('ek_total_eur'), 'recipe'];
            case 'vk_ek_teil':
                return [$this->rezepte($team, true)->whereNotNull('ek_total_eur')->whereColumn('ek_n_ingredients_priced', '<', 'ek_n_ingredients_total'), 'recipe'];
            case 'vk_anker_fehlt':
                return [$this->rezepte($team, true)->whereNotExists($this->rezeptHatAnker()), 'recipe'];
            case 'vk_servierform_unbestimmt':
                $unbId = FoodAlchemistServierform::where('code', 'unbestimmt')->value('id');
                if ($unbId === null) {
                    return [null, 'recipe'];
                }

                return [$this->rezepte($team, true)->whereExists(fn ($x) => $x->select(DB::raw(1))
                    ->from('foodalchemist_recipe_presentations as p')->whereColumn('p.recipe_id', 'foodalchemist_recipes.id')
                    ->where('p.serving_form_id', $unbId)->where('p.is_standard', true)->whereNull('p.deleted_at')), 'recipe'];
            case 'ri_gemini_unverifiziert':
                return [FoodAlchemistRecipe::visibleToTeam($team)->whereExists(fn ($q) => $q->select(DB::raw(1))
                    ->from('foodalchemist_recipe_ingredients as ri')->whereColumn('ri.recipe_id', 'foodalchemist_recipes.id')
                    ->where('ri.match_method', 'gemini_proposed')), 'recipe'];
            default:
                return [null, 'gp'];
        }
    }

    /** @return list<array{kind:string,id:int,name:string,is_sales_recipe:bool}> */
    private function gpItems(\Illuminate\Database\Eloquent\Builder $q, int $limit): array
    {
        return $q->orderBy('name')->limit($limit)->get(['id', 'name'])
            ->map(fn ($g) => ['kind' => 'gp', 'id' => (int) $g->id, 'name' => (string) $g->name, 'is_sales_recipe' => false])->all();
    }

    /**
     * Konzept-Objekte (Tranche C). `is_sales_recipe` ist hier immer false — das Feld
     * unterscheidet im Panel nur Basisrezept von VK-Gericht und hat für ein Konzept
     * keine Bedeutung; die Fallunterscheidung im Panel läuft über `kind`.
     *
     * @return list<array{kind:string,id:int,name:string,is_sales_recipe:bool}>
     */
    private function conceptItems(\Illuminate\Database\Eloquent\Builder $q, int $limit): array
    {
        return $q->orderBy('name')->limit($limit)->get(['id', 'name'])
            ->map(fn ($c) => ['kind' => 'concept', 'id' => (int) $c->id, 'name' => (string) $c->name, 'is_sales_recipe' => false])->all();
    }

    /**
     * Foodbook-Objekte (Tranche D). Das Buch trägt seinen Namen in `label`, nicht in
     * `name` — die Panel-Zeile erwartet `name`, also wird hier umbenannt (statt das Panel
     * um einen zweiten Feldnamen zu erweitern). `is_sales_recipe` ist wie beim Konzept
     * ohne Bedeutung; die Fallunterscheidung im Panel läuft über `kind`.
     *
     * @return list<array{kind:string,id:int,name:string,is_sales_recipe:bool}>
     */
    private function foodbookItems(\Illuminate\Database\Eloquent\Builder $q, int $limit): array
    {
        return $q->orderBy('label')->limit($limit)->get(['id', 'label'])
            ->map(fn ($f) => ['kind' => 'foodbook', 'id' => (int) $f->id, 'name' => (string) $f->label, 'is_sales_recipe' => false])->all();
    }

    /** @return list<array{kind:string,id:int,name:string,is_sales_recipe:bool}> */
    private function recipeItems(\Illuminate\Database\Eloquent\Builder $q, int $limit): array
    {
        return $q->orderBy('name')->limit($limit)->get(['id', 'name', 'is_sales_recipe'])
            ->map(fn ($r) => ['kind' => 'recipe', 'id' => (int) $r->id, 'name' => (string) $r->name, 'is_sales_recipe' => (bool) $r->is_sales_recipe])->all();
    }

    // ---- Metrik-Konstruktoren --------------------------------------------

    /** Informations-Metrik (Total o. ä.) — nie ampel-relevant. */
    private function info(string $key, string $label, int $wert): array
    {
        return ['key' => $key, 'kind' => self::KIND_INFO, 'label' => $label, 'wert' => $wert, 'severity' => 'info', 'signal' => null];
    }

    /**
     * Lücken-Metrik: grün bei 0, gelb bis Schwelle, rot darüber. Optionaler
     * Signal-Deskriptor (Typ + dedup_key + Beschreibung) für die --signals-Emission.
     *
     * $sev setzt den Signal-Schweregrad fix, statt ihn aus der Trefferzahl abzuleiten —
     * die Ampel-Farbe der Metrik zeigt weiter die Menge. Einzige Ausnahme: ein als
     * `Info` deklarierter Check wird NIE rot/gelb — eine Pflege-Liste („verwaiste
     * Rezepte") ist kein Alarm, und 300 davon sind kein roter Zustand.
     */
    private function gap(string $key, string $label, int $wert, ?SignalTyp $typ = null, ?string $dedup = null, ?string $desc = null, ?SignalSeverity $sev = null): array
    {
        $severity = $wert === 0 ? 'gruen' : ($sev === SignalSeverity::Info ? 'info' : ($wert > self::ROT_SCHWELLE ? 'rot' : 'gelb'));
        $signal = ($typ !== null && $dedup !== null)
            ? ['typ' => $typ, 'dedup' => $dedup, 'desc' => $desc, 'sev' => $sev]
            : null;

        return ['key' => $key, 'kind' => self::KIND_GAP, 'label' => $label, 'wert' => $wert, 'severity' => $severity, 'signal' => $signal];
    }
}
