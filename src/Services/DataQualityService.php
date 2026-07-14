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
                    $wert > self::ROT_SCHWELLE ? SignalSeverity::Kritisch : SignalSeverity::Warnung,
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
            $this->gap('gp_ohne_lead', 'approved-GPs ohne Lead-LA', $ohneLead, SignalTyp::DatenqualitaetGpLa, 'dq-gp-ohne-lead',
                'GPs, die einen Lieferantenartikel brauchen, aber keinen Lead-LA/keine LAs haben — Kalkulation bleibt unvollständig.'),
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

    // ---- Metrik-Konstruktoren --------------------------------------------

    /** Informations-Metrik (Total o. ä.) — nie ampel-relevant. */
    private function info(string $key, string $label, int $wert): array
    {
        return ['key' => $key, 'label' => $label, 'wert' => $wert, 'severity' => 'info', 'signal' => null];
    }

    /**
     * Lücken-Metrik: grün bei 0, gelb bis Schwelle, rot darüber. Optionaler
     * Signal-Deskriptor (Typ + dedup_key + Beschreibung) für die --signals-Emission.
     */
    private function gap(string $key, string $label, int $wert, ?SignalTyp $typ = null, ?string $dedup = null, ?string $desc = null): array
    {
        $severity = $wert === 0 ? 'gruen' : ($wert > self::ROT_SCHWELLE ? 'rot' : 'gelb');
        $signal = ($typ !== null && $dedup !== null)
            ? ['typ' => $typ, 'dedup' => $dedup, 'desc' => $desc]
            : null;

        return ['key' => $key, 'label' => $label, 'wert' => $wert, 'severity' => $severity, 'signal' => $signal];
    }
}
