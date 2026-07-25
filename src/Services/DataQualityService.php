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
        return ['key' => $key, 'label' => $label, 'wert' => $wert, 'severity' => 'info', 'signal' => null];
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

        return ['key' => $key, 'label' => $label, 'wert' => $wert, 'severity' => $severity, 'signal' => $signal];
    }
}
