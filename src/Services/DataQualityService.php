<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\SignalSeverity;
use Platform\FoodAlchemist\Enums\SignalTyp;
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
    public function __construct(private SignalService $signals)
    {
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
        ];
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
            if ($this->namingVerstoss($r, $basisZaehler)) {
                $ids[] = $r['id'];
            }
        }

        return $ids;
    }

    /** @param array{id:int,name:string,vk:bool,achse:string} $r */
    private function grammaturBasis(array $r): string
    {
        return $r['achse'] . '|' . $this->normalisierterName(
            preg_replace(self::GRAMMATUR_MUSTER, ' ', $r['name']) ?? $r['name']
        );
    }

    /**
     * @param array{id:int,name:string,vk:bool,achse:string} $r
     * @param array<string,int> $basisZaehler
     */
    private function namingVerstoss(array $r, array $basisZaehler): bool
    {
        $name = $r['name'];

        if (preg_match('/\s{2,}/u', $name) === 1 || preg_match(self::NAME_TRENNER_RAND, $name) === 1) {
            return true;
        }

        if (preg_match(self::GRAMMATUR_MUSTER, $name) === 1
            && ($basisZaehler[$this->grammaturBasis($r)] ?? 0) < 2) {
            return true;
        }

        if (! $r['vk']) {
            return false;                                   // §1-Präfix/Marker sind VK-Regeln
        }

        foreach (self::VK_MARKER as $marker) {
            if (mb_stripos($name, $marker) !== false) {
                return true;
            }
        }

        // Pipe-Skelett: führendes Hauptgruppen-Kürzel in eckigen Klammern (§1.1).
        return preg_match('/^\[[A-Z]{2,5}\]\s/u', $name) !== 1;
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

        return $kind === 'gp' ? $this->gpItems($q, $limit) : $this->recipeItems($q, $limit);
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
     * teilen sich diese. Rückgabe [?Builder, kind:'gp'|'recipe']; null = keine Objekte.
     *
     * @return array{0: \Illuminate\Database\Eloquent\Builder|null, 1: string}
     */
    private function queryFor(Team $team, string $metrik): array
    {
        // Tranche A (Spec 21) definiert ihr Prädikat im Check-Register — hier nur nachschlagen,
        // nicht nachbauen. Der switch unten ist der Altbestand der Kaskaden-Ebenen (s. V-005).
        $checks = $this->rezeptQualitaetChecks();
        if (isset($checks[$metrik])) {
            return [($checks[$metrik]['q'])($team), 'recipe'];
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
