<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistPriceChangeAudit;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichungDelta;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichungZubehoer;

/**
 * Darreichungs-CRUD + Preisrechnung (Umbau-Spec Darreichungen, Phase 5).
 *
 * Invarianten:
 *  - genau EINE Standard-Darreichung pro Gericht (setzeStandard swappt transaktional)
 *  - eine Servierform höchstens einmal pro Gericht (DB-Unique)
 *  - Deltas nur auf Zutatenzeilen des Kernrezepts (FK) — Grenzregel E5:
 *    weglassen/umgewichten ja, neue Zutaten strukturell unmöglich
 *  - FA-native Anlagen tragen created_via (F12; WaWi-Importe haben legacy_id)
 *
 * Preise (spiegelt WaWi-Recompute 206 Stufe 4):
 *  - Stufe 1 (keine Deltas): ek_portion = EK/g des Rezepts × Grammatur × Anzahl
 *  - Stufe 2 (Deltas): Misch-Preis/g über Komponenten NACH Delta (omitted raus,
 *    Kosten skalieren linear mit der Masse), dann × Grammatur × Anzahl
 *  - auto: dynamischer Vorschlag aus Unternehmens-Basissatz × relativem Klassenfaktor
 *  - fixed: Live-VK bleibt, der Vergleichsvorschlag rechnet weiter; Begründung ist Pflicht
 *  - MwSt kommt als Profil-Schlüssel aus Darreichung → Preisklasse → Team
 *  - Standard-Darreichung spiegelt sales_net nach recipes.sales_net (Anzeige-Cache)
 */
class DarreichungService
{
    public function __construct(
        private RecipeRecomputeService $recompute,
        private CatalogPricingService $catalogPricing,
        private TeamSettingsService $settings,
    ) {}

    public function anlegen(Team $team, int $recipeId, int $servierformId, array $attrs = [], string $createdVia = 'fa_ui'): FoodAlchemistRecipeDarreichung
    {
        $recipe = FoodAlchemistRecipe::visibleToTeam($team)->findOrFail($recipeId);
        if (! $recipe->is_sales_recipe) {
            // Darreichung = Verkaufsform. Ein Basisrezept wird über ein Gericht verkauft, das es als Komponente trägt.
            throw new \RuntimeException('„' . $recipe->name . '" ist ein Basisrezept — Darreichungen gibt es nur an Gerichten.');
        }
        if ($recipe->presentations()->where('serving_form_id', $servierformId)->exists()) {
            throw new \RuntimeException('Diese Servierform existiert schon an diesem Gericht.');
        }

        // Soft-gelöschte Zeile derselben Form blockiert den DB-Unique → reaktivieren statt einfügen
        $trashed = FoodAlchemistRecipeDarreichung::onlyTrashed()
            ->where('recipe_id', $recipe->id)->where('serving_form_id', $servierformId)->first();
        if ($trashed !== null) {
            $trashed->forceDelete();
        }

        $standard = $recipe->standardPresentation;
        $darreichung = FoodAlchemistRecipeDarreichung::create([
            'team_id' => $recipe->team_id,
            'recipe_id' => $recipe->id,
            'serving_form_id' => $servierformId,
            'is_standard' => $standard === null,               // erste Form = Standard
            // Vorbefüllung aus der Standard-Form (F2: KEIN Pauschal-Faktor — User passt an)
            'quantity_per_unit_g' => $attrs['quantity_per_unit_g'] ?? $standard?->quantity_per_unit_g,
            'unit_vocab_id' => $attrs['unit_vocab_id'] ?? $standard?->unit_vocab_id,
            'unit_count' => $attrs['unit_count'] ?? $standard?->unit_count,
            'markup_class_id' => $attrs['markup_class_id'] ?? $standard?->markup_class_id
                ?? $recipe->markup_class_id ?? $this->settings->defaultMarkupClassId($team),
            'price_mode' => $attrs['price_mode'] ?? 'auto',
            'sales_net' => $attrs['sales_net'] ?? null,
            'note' => $attrs['note'] ?? null,
            // Spec 83 B3i/B3l: NICHT aus der Standard-Form vorbefüllen — die Einheit hängt an der Form (s je Teller ≠ min je Platte)
            'anrichten_wert' => isset($attrs['anrichten_wert']) && is_numeric(str_replace(',', '.', (string) $attrs['anrichten_wert']))
                ? (float) str_replace(',', '.', (string) $attrs['anrichten_wert']) : null,
            'anrichten_pauschal_min' => isset($attrs['anrichten_pauschal_min']) && is_numeric(str_replace(',', '.', (string) $attrs['anrichten_pauschal_min']))
                ? (float) str_replace(',', '.', (string) $attrs['anrichten_pauschal_min']) : null,
            'created_via' => $createdVia,
        ]);

        $this->recomputePreise($darreichung);

        return $darreichung->refresh();
    }

    /**
     * Stellt sicher, dass ein (VK-)Gericht eine Standard-Darreichung hat. Legt sonst
     * eine auf der Form „unbestimmt" an (Review-Queue, kein teller-Default — Servierform-
     * Regel der Umbau-Spec) und übernimmt die Legacy-VK-Felder als Startwerte. Idempotent.
     * Gibt die Standard-Darreichung zurück (oder null, wenn die unbestimmt-Form fehlt).
     */
    public function ensureStandard(Team $team, int $recipeId, string $createdVia = 'mcp'): ?FoodAlchemistRecipeDarreichung
    {
        $recipe = FoodAlchemistRecipe::visibleToTeam($team)->findOrFail($recipeId);
        if (! $recipe->is_sales_recipe) {
            return null; // Basisrezept: keine Verkaufsform (s. anlegen())
        }

        $standard = $recipe->standardPresentation()->first();
        if ($standard !== null) {
            return $standard;
        }
        if ($recipe->presentations()->exists()) {
            return null; // Varianten ohne Standard-Flag: nichts raten (wie syncStandardDarreichung)
        }

        $unbestimmt = $this->unbestimmtId($team);

        $quantityPerUnitG = $recipe->sales_quantity_per_unit_g;
        // Weg-B (2026-10-12): wie grammJeEinheit — ohne `sales_unit_count` ist der Ansatz EINE
        // Verkaufseinheit, die Grammatur also der ganze Ertrag. Vorher blieb sie bei leerem
        // Zähler NULL, obwohl der Ertrag bekannt war (Gericht 3920: Editor 476 g, Report „kein Gewicht").
        if ($quantityPerUnitG === null && $recipe->yield_kg !== null && (float) $recipe->yield_kg > 0) {
            $quantityPerUnitG = round((float) $recipe->yield_kg * 1000 / max(1, (int) ($recipe->sales_unit_count ?? 0)), 1);
        }

        return $this->anlegen($team, $recipe->id, $unbestimmt, [
            'quantity_per_unit_g' => $quantityPerUnitG,
            'unit_vocab_id' => $recipe->sales_unit_vocab_id,
            // recipes.sales_unit_count ist die Ausbeute des Rezeptlaufs (Nenner für
            // Yield/Anzahl), nicht die Zahl verkaufter Einheiten IN einer Darreichung.
            // Der Standard repräsentiert genau eine Verkaufseinheit.
            'unit_count' => 1,
            'markup_class_id' => $recipe->markup_class_id,
        ], $createdVia);
    }

    private const FELDER = [
        'quantity_per_unit_g', 'unit_vocab_id', 'unit_count',
        'markup_class_id', 'price_mode', 'sales_net',
        'vat_profile_key', 'price_override_reason', 'price_override_expires_at',
        'container_warm_vocab_id', 'container_cold_vocab_id',
        // Spec 51: die vier Regenerations-Skalare sind entfallen. Sie waren eine zweite Wahrheit
        // neben `recipe_regenerations`, die kein Schreibpfad je fuellte — eine Servierform aendert
        // nichts an 140 °C. Behaelter und Vehikel bleiben: dort greift die Form legitim ein.
        'serving_vehicle_vocab_id',
        'work_time_surcharge_min', 'offer_text_override', 'note',
        'anrichten_wert', 'anrichten_pauschal_min',   // Spec 83 B3i/B3l: Anrichte-Zeit je Darreichung (Einheit aus der Form)
        'vessel_gp_id',      // Gefäß der Form: Non-Food-GP (WG 16); der Servier-Typ kommt daraus
    ];

    private const DEZIMAL_FELDER = [
        'quantity_per_unit_g' => 'Grammatur',
        'unit_count' => 'Anzahl',
        'anrichten_wert' => 'Anrichten je Einheit',
        'anrichten_pauschal_min' => 'Anrichten-Pauschale',
        'sales_net' => 'VK netto',
    ];

    public function aktualisieren(Team $team, int $darreichungId, array $attrs): FoodAlchemistRecipeDarreichung
    {
        $darreichung = $this->find($team, $darreichungId);
        $update = array_intersect_key($attrs, array_flip(self::FELDER));
        foreach ($update as $k => $v) {
            if ($v === '') {
                $update[$k] = null;
            }
        }
        foreach (self::DEZIMAL_FELDER as $feld => $bezeichnung) {
            if (! array_key_exists($feld, $update) || $update[$feld] === null) {
                continue;
            }
            $wert = str_replace(',', '.', trim((string) $update[$feld]));
            if (! is_numeric($wert)) {
                throw new \RuntimeException($bezeichnung . ' muss eine gültige Zahl sein.');
            }
            $update[$feld] = (float) $wert;
        }
        if (array_key_exists('vat_profile_key', $update) && $update['vat_profile_key'] !== null
            && ! in_array($update['vat_profile_key'], ['regulaer', 'ermaessigt'], true)) {
            throw new \RuntimeException('Unbekanntes MwSt-Profil.');
        }
        $legacyManual = ($update['price_mode'] ?? null) === 'manuell';
        if (array_key_exists('price_mode', $update)) {
            $update['price_mode'] = $legacyManual ? 'fixed' : $update['price_mode'];
            if (! in_array($update['price_mode'], ['auto', 'fixed'], true)) {
                throw new \RuntimeException('Unbekannter Preismodus.');
            }
        }
        // Gefäß: nur ein sichtbares Non-Food-GP (WG 16)
        if (array_key_exists('vessel_gp_id', $update) && $update['vessel_gp_id'] !== null) {
            $gefaess = \Platform\FoodAlchemist\Models\FoodAlchemistGp::visibleToTeam($team)->find((int) $update['vessel_gp_id']);
            if ($gefaess === null) {
                throw new \RuntimeException('Gefäß (Grundprodukt) nicht sichtbar oder nicht vorhanden.');
            }
            if (! $gefaess->istNonFood()) {
                throw new \RuntimeException('Als Gefäß nimmt die Darreichung nur Non-Food-Grundprodukte (Warengruppe 16).');
            }
            $update['vessel_gp_id'] = (int) $gefaess->id;
        }
        // Eine Übersteuerung des Servier-Typs gibt es nur, wenn sie vom Typ des Gefäßes ABWEICHT: ein leerer oder gleicher Wert wird geleert,
        // sonst übersteuerte ein Bestandswert still für immer den Typ des Gefäßes (Etikett lügt).
        if (array_key_exists('vessel_gp_id', $update) || array_key_exists('serving_vehicle_vocab_id', $update)) {
            $gefaessId = array_key_exists('vessel_gp_id', $update) ? $update['vessel_gp_id'] : $darreichung->vessel_gp_id;
            if ($gefaessId !== null) {
                $typ = array_key_exists('serving_vehicle_vocab_id', $update) ? $update['serving_vehicle_vocab_id'] : $darreichung->serving_vehicle_vocab_id;
                $gefaessTyp = \Platform\FoodAlchemist\Models\FoodAlchemistGp::query()->whereKey($gefaessId)->value('serving_vehicle_vocab_id');
                if ($typ !== null && $gefaessTyp !== null && (int) $typ === (int) $gefaessTyp) {
                    $update['serving_vehicle_vocab_id'] = null;
                }
            }
        }
        $wirdFixiert = ($update['price_mode'] ?? $darreichung->price_mode) === 'fixed';
        if ($wirdFixiert) {
            $reason = trim((string) ($update['price_override_reason'] ?? $darreichung->price_override_reason ?? ''));
            if ($reason === '' && $legacyManual) {
                $reason = 'Legacy-Übernahme aus Preismodus manuell';
            }
            $fixedPrice = $update['sales_net'] ?? $darreichung->sales_net;
            if ($reason === '' || $fixedPrice === null) {
                throw new \RuntimeException('Ein fixierter Preis benötigt einen VK und eine Begründung.');
            }
            $update['price_override_reason'] = $reason;
            $update['price_override_user_id'] = Auth::id();
            $update['price_override_at'] = now();
        } elseif (($update['price_mode'] ?? null) === 'auto') {
            $update = array_merge($update, [
                'price_override_reason' => null,
                'price_override_user_id' => null,
                'price_override_at' => null,
                'price_override_expires_at' => null,
            ]);
        }
        $darreichung->update($update);
        $this->recomputePreise($darreichung);

        return $darreichung->refresh();
    }

    /**
     * Servierform einer bestehenden Darreichung wechseln (Entscheid Dominique 2026-09-04).
     *
     * `unbestimmt` ist ein REVIEW-ZUSTAND, kein Label. Steht fest, wie das Gericht
     * ausgegeben wird, wird die FORM der Zeile gesetzt — das Vokabular wird nie
     * umbenannt (es ist WaWi-Master und an allen Gerichten dasselbe). Darum ist
     * `serving_form_id` bewusst kein Eintrag in {@see self::FELDER}: der Formwechsel
     * ist ein eigener Übergang mit Kollisionsprüfung, kein Attribut-Update.
     *
     * Preisneutral: die Aufschlagsklasse bleibt eigenständig (Umbau-Spec F7, kein
     * Servierform→Aufschlag-Mapping), deshalb kein Recompute.
     */
    public function setzeServierform(Team $team, int $darreichungId, int $servierformId): FoodAlchemistRecipeDarreichung
    {
        $darreichung = $this->find($team, $darreichungId);
        if ((int) $darreichung->serving_form_id === $servierformId) {
            return $darreichung;                                  // nichts zu tun
        }

        // Sichtbarkeit statt Eigentum: geerbte und globale Formen müssen am eigenen
        // Gericht wählbar bleiben (Master-Vererbung, wie TeamScope::referenz()).
        $form = \Platform\FoodAlchemist\Models\FoodAlchemistServierform::visibleToTeam($team)
            ->whereKey($servierformId)->first();
        if ($form === null) {
            throw new \RuntimeException('Unbekannte Servierform.');
        }
        if ($form->is_inactive) {
            throw new \RuntimeException('Diese Servierform ist deaktiviert.');
        }
        if ($darreichung->recipe->presentations()
            ->where('serving_form_id', $servierformId)
            ->whereKeyNot($darreichung->id)->exists()) {
            throw new \RuntimeException('Diese Servierform existiert schon an diesem Gericht.');
        }

        // Soft-gelöschte Zeile derselben Form blockiert den DB-Unique (wie in anlegen()).
        FoodAlchemistRecipeDarreichung::onlyTrashed()
            ->where('recipe_id', $darreichung->recipe_id)
            ->where('serving_form_id', $servierformId)
            ->get()->each->forceDelete();

        $darreichung->update(['serving_form_id' => $servierformId]);

        return $darreichung->refresh();
    }

    public function loeschen(Team $team, int $darreichungId): void
    {
        $darreichung = $this->find($team, $darreichungId);
        // Die Standard-Darreichung ist der Anker der Preis-Wahrheit und der Slot-Auflösung.
        // Sie darf nie verschwinden — vorher (2026-09-04) griff der Guard nur bei >1 Zeile,
        // die EINZIGE Darreichung war über den MCP-Pfad löschbar (die UI versteckt den
        // Knopf unabhängig von der Anzahl, deshalb fiel es nie auf).
        if ($darreichung->is_standard) {
            throw new \RuntimeException($darreichung->recipe->presentations()->count() > 1
                ? 'Standard-Darreichung zuerst auf eine andere Form übertragen.'
                : 'Die einzige Darreichung eines Gerichts kann nicht gelöscht werden — stattdessen die Servierform wechseln.');
        }
        $darreichung->delete();
    }

    public function setzeStandard(Team $team, int $darreichungId): void
    {
        $darreichung = $this->find($team, $darreichungId);
        DB::transaction(function () use ($darreichung) {
            // Reihenfolge wichtig: partieller Unique-Index erlaubt nur EIN is_standard=1
            $darreichung->recipe->presentations()
                ->where('id', '!=', $darreichung->id)
                ->update(['is_standard' => false]);
            $darreichung->update(['is_standard' => true]);
        });
        $this->spiegleStandardVk($darreichung->recipe->fresh());
    }

    /** Delta setzen/ändern — nur Zutatenzeilen des eigenen Rezepts (E5 strukturell). */
    public function setzeDelta(Team $team, int $darreichungId, int $recipeIngredientId, ?float $mengeOverrideG, bool $omitted): void
    {
        $darreichung = $this->find($team, $darreichungId);
        $gehoertZumRezept = $darreichung->recipe->ingredients()
            ->where('id', $recipeIngredientId)->exists();
        if (! $gehoertZumRezept) {
            throw new \RuntimeException('Zutat gehört nicht zum Kernrezept (E5: keine neuen Zutaten).');
        }
        if ($mengeOverrideG === null && ! $omitted) {
            $this->entferneDelta($team, $darreichungId, $recipeIngredientId);

            return;
        }
        FoodAlchemistRecipeDarreichungDelta::withTrashed()->updateOrCreate(
            ['presentation_id' => $darreichung->id, 'recipe_ingredient_id' => $recipeIngredientId],
            ['team_id' => $darreichung->team_id, 'quantity_override_g' => $mengeOverrideG,
                'omitted' => $omitted, 'deleted_at' => null],
        );
        $this->recomputePreise($darreichung);
    }

    public function entferneDelta(Team $team, int $darreichungId, int $recipeIngredientId): void
    {
        $darreichung = $this->find($team, $darreichungId);
        $darreichung->deltas()->where('recipe_ingredient_id', $recipeIngredientId)->forceDelete();
        $this->recomputePreise($darreichung);
    }

    /**
     * Zubehör & Verpackung setzen/ändern (ein Non-Food-GP je Form). Nur Grundprodukte der Warengruppe 16: Lebensmittel gehören in die
     * Zutaten (Gegenstück zum Zutaten-Guard). Wirkt nur auf den EK der Darreichung, nie auf Allergene, Nährwerte, Profil oder Yield.
     */
    public function setzeZubehoer(Team $team, int $darreichungId, int $gpId, float $menge, ?string $note = null): FoodAlchemistRecipeDarreichungZubehoer
    {
        $darreichung = $this->find($team, $darreichungId);
        if ($menge <= 0) {
            throw new \RuntimeException('Die Menge muss größer als 0 sein.');
        }
        $gp = \Platform\FoodAlchemist\Models\FoodAlchemistGp::visibleToTeam($team)->find($gpId);
        if ($gp === null) {
            throw new \RuntimeException('Grundprodukt nicht sichtbar/vorhanden.');
        }
        if (! $gp->istNonFood()) {
            throw new \RuntimeException('Zubehör & Verpackung nimmt nur Non-Food-Grundprodukte (Warengruppe 16). Lebensmittel gehören in die Zutaten.');
        }
        $zeile = FoodAlchemistRecipeDarreichungZubehoer::withTrashed()->updateOrCreate(
            ['presentation_id' => $darreichung->id, 'gp_id' => $gp->id],
            ['team_id' => $darreichung->team_id, 'quantity' => $menge, 'note' => $note !== null && trim($note) !== '' ? trim($note) : null, 'deleted_at' => null],
        );
        if ($zeile->wasRecentlyCreated || $zeile->position === 0) {
            $zeile->update(['position' => (int) $darreichung->zubehoer()->max('position') + 1]);
        }
        $this->recomputePreise($darreichung);

        return $zeile;
    }

    public function entferneZubehoer(Team $team, int $darreichungId, int $gpId): void
    {
        $darreichung = $this->find($team, $darreichungId);
        $darreichung->zubehoer()->where('gp_id', $gpId)->forceDelete();
        $this->recomputePreise($darreichung);
    }

    /**
     * Kosten des Zubehörs je Einheit der Darreichung (Lead-LA-Preis je Stück wie überall).
     *
     * @return array{summe: float, vollstaendig: bool, zeilen: list<array{gp_id:int, name:string, menge:float, preis_stk:?float, kosten:?float, note:?string}>}
     */
    public function zubehoerKosten(FoodAlchemistRecipeDarreichung $darreichung, ?Team $team = null): array
    {
        $team ??= Team::find($darreichung->team_id);
        $summe = 0.0;
        $vollstaendig = true;
        $zeilen = [];
        foreach ($darreichung->zubehoer()->with('gp:id,name,team_id,commodity_group_code,lead_la_supplier_item_id,piece_default_g')->get() as $z) {
            // Doppelzählung vermeiden: dasselbe GP als Gefäß zählt dort (Gefäß gewinnt). Miete (Lieferant der Gruppe VERLEIH): nur Hinweis, nie je Portion.
            $doppelt = $darreichung->vessel_gp_id !== null && (int) $darreichung->vessel_gp_id === (int) $z->gp_id;
            $miete = ! $doppelt && $z->gp !== null && $this->istVerleihGp($z->gp);
            if ($doppelt || $miete) {
                $zeilen[] = ['gp_id' => (int) $z->gp_id, 'name' => (string) $z->gp?->name, 'menge' => (float) $z->quantity, 'preis_stk' => null, 'kosten' => null, 'note' => $z->note, 'doppelt' => $doppelt, 'miete' => $miete];

                continue;
            }
            $preis = $z->gp !== null ? $this->recompute->preisProStueckPublic($z->gp, $team) : null;
            $kosten = $preis !== null ? round($preis * (float) $z->quantity, 4) : null;
            if ($kosten === null) {
                $vollstaendig = false;
            } else {
                $summe += $kosten;
            }
            $zeilen[] = ['gp_id' => (int) $z->gp_id, 'name' => (string) $z->gp?->name, 'menge' => (float) $z->quantity, 'preis_stk' => $preis, 'kosten' => $kosten, 'note' => $z->note, 'doppelt' => false, 'miete' => false];
        }

        return ['summe' => round($summe, 4), 'vollstaendig' => $vollstaendig, 'zeilen' => $zeilen];
    }

    /**
     * Wirksamer Servier-Typ der Darreichung — EINE Lese-Stelle: die ausdrückliche Übersteuerung an der Darreichung, sonst der Typ des Gefäß-GP.
     * Kein Kopieren, kein Auseinanderlaufen: ändert sich der Typ am GP, ziehen alle Darreichungen mit.
     */
    public function vehikelId(FoodAlchemistRecipeDarreichung $d): ?int
    {
        if ($d->serving_vehicle_vocab_id !== null) {
            return (int) $d->serving_vehicle_vocab_id;
        }
        $typ = $d->vessel_gp_id !== null ? ($d->relationLoaded('vessel') ? $d->vessel?->serving_vehicle_vocab_id : \Platform\FoodAlchemist\Models\FoodAlchemistGp::query()->whereKey($d->vessel_gp_id)->value('serving_vehicle_vocab_id')) : null;

        return $typ !== null ? (int) $typ : null;
    }

    /** Hat die Darreichung einen Servier-Typ, der vom Typ ihres Gefäßes abweicht (ausdrückliche Übersteuerung)? */
    public function uebersteuert(FoodAlchemistRecipeDarreichung $d): bool
    {
        if ($d->serving_vehicle_vocab_id === null || $d->vessel_gp_id === null) {
            return false;
        }
        $gefaessTyp = \Platform\FoodAlchemist\Models\FoodAlchemistGp::query()->whereKey($d->vessel_gp_id)->value('serving_vehicle_vocab_id');

        return $gefaessTyp !== null && (int) $gefaessTyp !== (int) $d->serving_vehicle_vocab_id;
    }

    /** Kommt der Lead-Artikel des GP von einem Lieferanten der Gruppe VERLEIH (Miete je Tag/Veranstaltung)? Dann zählt er nicht je Portion. */
    public function istVerleihGp(\Platform\FoodAlchemist\Models\FoodAlchemistGp $gp): bool
    {
        if ($gp->lead_la_supplier_item_id === null || ! \Illuminate\Support\Facades\Schema::hasTable('foodalchemist_supplier_groups')) {
            return false;
        }

        return DB::table('foodalchemist_supplier_items as i')
            ->join('foodalchemist_suppliers as s', 's.id', '=', 'i.supplier_id')
            ->join('foodalchemist_supplier_groups as g', 'g.id', '=', 's.group_id')
            ->where('i.id', $gp->lead_la_supplier_item_id)->where('g.code', \Platform\FoodAlchemist\Support\LieferantenGruppen::VERLEIH)->exists();
    }

    /**
     * Gefäß der Darreichung als Kostenposten: 1 Stück je Einheit zum Lead-Preis je Stück, AUSSER Miete (Lieferant der Gruppe VERLEIH):
     * dann keine Kosten je Portion, nur der Hinweis.
     *
     * @return array{gp:?\Platform\FoodAlchemist\Models\FoodAlchemistGp, kosten:?float, miete:bool, preis_fehlt:bool}
     */
    public function gefaessKosten(FoodAlchemistRecipeDarreichung $d, ?Team $team = null): array
    {
        $gp = $d->vessel_gp_id !== null ? \Platform\FoodAlchemist\Models\FoodAlchemistGp::query()->find($d->vessel_gp_id) : null;
        if ($gp === null) {
            return ['gp' => null, 'kosten' => null, 'miete' => false, 'preis_fehlt' => false];
        }
        if ($this->istVerleihGp($gp)) {
            return ['gp' => $gp, 'kosten' => null, 'miete' => true, 'preis_fehlt' => false];
        }
        $team ??= Team::find($d->team_id);
        $preis = $this->recompute->preisProStueckPublic($gp, $team);

        return ['gp' => $gp, 'kosten' => $preis !== null ? round($preis, 4) : null, 'miete' => false, 'preis_fehlt' => $preis === null];
    }

    /**
     * Alles, was auf den EK der Darreichung kommt, ohne die Zutaten: Zubehör & Verpackung plus Gefäß (je Einheit).
     *
     * @return array{summe: float, vollstaendig: bool, zubehoer: array<string,mixed>, gefaess: array<string,mixed>}
     */
    public function beigabenKosten(FoodAlchemistRecipeDarreichung $d, ?Team $team = null): array
    {
        $zub = $this->zubehoerKosten($d, $team);
        $gef = $this->gefaessKosten($d, $team);

        return ['summe' => round($zub['summe'] + ($gef['kosten'] ?? 0.0), 4), 'vollstaendig' => $zub['vollstaendig'] && ! $gef['preis_fehlt'], 'zubehoer' => $zub, 'gefaess' => $gef];
    }

    /**
     * Anzeige-Info für Gefäß, Typ und Hinweise (VK-Modal, Concepter „Anrichten“, Paket) — berechnet, nichts gespeichert.
     *
     * @return array<string,mixed> `leer` = weder Gefäß noch Typ
     */
    public function gefaessInfo(FoodAlchemistRecipeDarreichung $d, ?Team $team = null): array
    {
        $typId = $this->vehikelId($d);
        $name = fn (?int $id) => $id !== null ? (string) DB::table('foodalchemist_vocab_serving_vehicles')->where('id', $id)->value('name') : null;
        $gef = $this->gefaessKosten($d, $team);
        $gp = $gef['gp'];
        $warnung = null;
        $gramm = (float) ($d->quantity_per_unit_g ?? 0);
        $volumen = $gp?->volume_ml !== null ? (float) $gp->volume_ml : null;
        if ($gp !== null && $volumen !== null && $volumen > 0 && $gramm > $volumen) {
            $warnung = 'Füllmenge '.rtrim(rtrim(number_format($gramm, 1, ',', '.'), '0'), ',').' g passt nicht in '.\Platform\FoodAlchemist\Support\NonFoodMasse::volumen($volumen).' (Volumen des Gefäßes).';
        }
        $gefaessTypId = $gp?->serving_vehicle_vocab_id !== null ? (int) $gp->serving_vehicle_vocab_id : null;

        return [
            'leer' => $d->vessel_gp_id === null && $typId === null,
            'gefaess_id' => $gp?->id, 'gefaess_name' => $gp?->name, 'typ_id' => $typId, 'typ_name' => $name($typId),
            'uebersteuert' => $this->uebersteuert($d), 'gefaess_typ_name' => $name($gefaessTypId),
            'warnung' => $warnung, 'miete' => $gef['miete'], 'kosten' => $gef['kosten'], 'preis_fehlt' => $gef['preis_fehlt'],
        ];
    }

    /** EK/VK einer Darreichung neu rechnen (Stufe 1 + Stufe-2-Deltas). */
    public function recomputePreise(FoodAlchemistRecipeDarreichung $darreichung, ?FoodAlchemistRecipe $recipe = null): void
    {
        $recipe ??= $darreichung->recipe()->with('ingredients.unit', 'ingredients.gp', 'ingredients.referencedRecipe')->first();
        $team = Team::findOrFail($darreichung->team_id);
        $deltas = $darreichung->deltas()->get();

        if ($deltas->isEmpty()) {
            // Stufe 1: proportional — EK/g des Rezepts × Grammatur × Anzahl
            $ekProG = $recipe->ek_per_kg_eur !== null ? (float) $recipe->ek_per_kg_eur / 1000.0 : null;
            $grammatur = $this->grammJeEinheit($darreichung, $recipe) ?? 0.0;
            $ekPortion = ($ekProG !== null && $grammatur > 0)
                ? round($ekProG * $grammatur
                    * (float) ($darreichung->unit_count ?: 1), 4)
                : null;
        } else {
            // Stufe 2: Overrides sind ECHTE Gramm je Einheit dieser Form (User-Entscheid
            // 2026-07-03) — die Grammatur der Form ergibt sich aus der Komponenten-Summe,
            // der EK direkt aus Σ (Preis/g × Gramm). Kein Verhältnis-Umweg mehr.
            $proEinheit = $this->standardProEinheit($recipe, $team);
            $deltaMap = $deltas->keyBy('recipe_ingredient_id');
            $kosten = 0.0;
            $masse = 0.0;
            $nBepreist = 0;
            foreach ($proEinheit as $ingId => $zeile) {
                $delta = $deltaMap->get($ingId);
                if ($delta !== null && $delta->omitted) {
                    continue;
                }
                $m = $delta?->quantity_override_g !== null ? (float) $delta->quantity_override_g : $zeile['masse_g'];
                if ($zeile['kosten_pro_g'] !== null) {
                    $nBepreist++;
                    $kosten += $zeile['kosten_pro_g'] * $m;
                }
                $masse += $m;
            }
            // Auto-Grammatur: g/Einheit = Summe der Komponenten dieser Form
            $darreichung->quantity_per_unit_g = $masse > 0 ? round($masse, 1) : $darreichung->quantity_per_unit_g;
            $ekPortion = $nBepreist > 0
                ? round($kosten * (float) ($darreichung->unit_count ?: 1), 4)
                : ($recipe->ek_per_kg_eur !== null && $masse > 0
                    ? round((float) $recipe->ek_per_kg_eur / 1000.0 * $masse
                        * (float) ($darreichung->unit_count ?: 1), 4)
                    : null);
        }

        // Zubehör & Verpackung (Non-Food, je Einheit) kommt auf den EK der Darreichung — NUR dorthin. Ist der Lebensmittel-EK unbepreist,
        // bleibt ek_portion null: das Zubehör allein täuscht keinen Preis vor.
        if ($ekPortion !== null && ($darreichung->vessel_gp_id !== null || $darreichung->zubehoer()->exists())) {
            $ekPortion = round($ekPortion + $this->beigabenKosten($darreichung, $team)['summe'] * (float) ($darreichung->unit_count ?: 1), 4);
        }

        $oldCalculated = $darreichung->calculated_sales_net;
        $oldEffective = $darreichung->sales_net;
        $darreichung->forceFill(['quantity_per_unit_g' => $darreichung->quantity_per_unit_g, 'ek_portion' => $ekPortion]);
        $price = $this->catalogPricing->catalogPrice($team, $darreichung);
        $darreichung->update([
            'quantity_per_unit_g' => $darreichung->quantity_per_unit_g,
            'ek_portion' => $ekPortion,
            'price_mode' => $price['price_mode'],
            'calculated_sales_net' => $price['calculated_sales_net'],
            'sales_net' => $price['sales_net'],
            'sales_gross' => $price['sales_gross'],
            'vat_profile_key' => $price['vat_profile_key'],
            'price_calculation_source' => $price['base_source'],
            'price_calculation_version' => $price['calculation_version'],
            'price_calculated_at' => now(),
            ...($price['override_expired'] ? [
                'price_override_reason' => null,
                'price_override_user_id' => null,
                'price_override_at' => null,
                'price_override_expires_at' => null,
            ] : []),
        ]);

        if ($oldCalculated !== $darreichung->calculated_sales_net || $oldEffective !== $darreichung->sales_net) {
            FoodAlchemistPriceChangeAudit::create([
                'team_id' => $darreichung->team_id,
                'entity_type' => 'presentation',
                'entity_id' => $darreichung->id,
                'old_calculated_net' => $oldCalculated,
                'new_calculated_net' => $darreichung->calculated_sales_net,
                'old_effective_net' => $oldEffective,
                'new_effective_net' => $darreichung->sales_net,
                'price_mode' => $darreichung->price_mode,
                'source' => $price['base_source'],
                'reason' => $darreichung->price_override_reason,
                'user_id' => $darreichung->price_override_user_id,
                'metadata' => [
                    'base_factor' => $price['base_factor'],
                    'class_factor_pct' => $price['class_factor_pct'],
                    'calculation_version' => $price['calculation_version'],
                ],
            ]);
        }

        if ($darreichung->is_standard) {
            $this->spiegleStandardVk($recipe);
        }
    }

    /**
     * Preis-Anstoß fürs Zubehör: ändert sich der (Lead-)Preis eines Non-Food-GP, rechnen NUR die Darreichungen neu, die es als Zubehör
     * führen — gezielt über den Index auf gp_id, kein Massen-Recompute. Ohne Zubehör-Zeile kostet der Aufruf eine Abfrage.
     *
     * @param  list<int>  $gpIds
     * @return int Anzahl neu gerechneter Darreichungen
     */
    public function recomputeFuerZubehoerGps(array $gpIds): int
    {
        $gpIds = array_values(array_unique(array_map('intval', $gpIds)));
        if ($gpIds === []) {
            return 0;
        }
        $ids = DB::table('foodalchemist_recipe_presentation_zubehoer')->whereIn('gp_id', $gpIds)->whereNull('deleted_at')->distinct()->pluck('presentation_id')
            ->merge(DB::table('foodalchemist_recipe_presentations')->whereIn('vessel_gp_id', $gpIds)->whereNull('deleted_at')->pluck('id'))->unique()->values();
        if ($ids->isEmpty()) {
            return 0;
        }
        $n = 0;
        foreach (FoodAlchemistRecipeDarreichung::whereIn('id', $ids)->get() as $d) {
            $this->recomputePreise($d);
            $n++;
        }

        return $n;
    }

    /** Wie {@see recomputeFuerZubehoerGps}, ausgehend von einem Lieferantenartikel (Preiszeile geändert): die GPs kommen aus der Struktur. */
    public function nachLaPreisAenderung(int $supplierItemId): int
    {
        $gpIds = DB::table('foodalchemist_supplier_item_structures')->where('supplier_item_id', $supplierItemId)->whereNull('deleted_at')
            ->whereNotNull('gp_id')->pluck('gp_id')->map(fn ($v) => (int) $v)->all();

        return $this->recomputeFuerZubehoerGps($gpIds);
    }

    /** Alle Darreichungen eines Gerichts neu rechnen (nach Zutaten-/EK-Änderung). */
    public function recomputeFuerRezept(int $recipeId): void
    {
        $darreichungen = FoodAlchemistRecipeDarreichung::where('recipe_id', $recipeId)->get();
        if ($darreichungen->isEmpty()) {
            return;
        }
        // Rezept + Zutaten-Relationen EINMAL laden statt je Darreichung neu (N+1).
        $recipe = FoodAlchemistRecipe::with('ingredients.unit', 'ingredients.gp', 'ingredients.referencedRecipe')->find($recipeId);
        foreach ($darreichungen as $d) {
            $this->recomputePreise($d, $recipe);
        }
    }

    /**
     * Grammatur EINER Einheit dieser Darreichung (ohne Deltas) — eine Regel für Preis UND
     * Produktion: eingetragene Grammatur; fehlt sie an der STANDARD-Form, die Rezept-Grammatur,
     * sonst Ertrag ÷ Portionen (ohne Portionszahl = eine Portion). Andere Formen ohne
     * Grammatur: null (kein Preis, keine Produktionsmenge).
     */
    public function grammJeEinheit(FoodAlchemistRecipeDarreichung $darreichung, FoodAlchemistRecipe $recipe): ?float
    {
        $grammatur = (float) ($darreichung->quantity_per_unit_g ?? 0);
        if ($grammatur > 0) {
            return $grammatur;
        }
        if (! $darreichung->is_standard) {
            return null;
        }
        $legacyGrammatur = (float) ($recipe->sales_quantity_per_unit_g ?? 0);
        if ($legacyGrammatur > 0) {
            return $legacyGrammatur;
        }
        if ($recipe->yield_kg !== null && (float) $recipe->yield_kg > 0) {
            return (float) $recipe->yield_kg * 1000 / max(1, (int) ($recipe->sales_unit_count ?? 0));
        }

        return null;
    }

    /**
     * Produktionsmenge für N verkaufte Portionen eines Gerichts in einer Darreichung —
     * gerechnet wie der Portions-EK der Darreichung (recomputePreise), nur mit Menge statt
     * Preis: Grammatur × Anzahl je Portion bzw. bei Deltas die Gramm je Komponente
     * (weggelassen = 0, Override = echte Gramm, Rest = Standard-Komposition je Einheit).
     *
     * Ergebnis in Rezept-Ansätzen (Explosion skaliert jede Zutatenzeile damit):
     *  - `ansaetze`: Mengenäquivalent (Σ Gramm ÷ Ansatz-Masse) für Zeit, Behälter, Anzeige
     *  - `je_zutat`: nur bei Deltas — Ansätze je recipe_ingredient_id (weicht vom Mittel ab)
     *
     * @return array{ansaetze: float, je_zutat: ?array<int, float>, gramm_je_portion: ?float, warnung: ?string}
     */
    public function produktionsAnteil(FoodAlchemistRecipe $recipe, ?FoodAlchemistRecipeDarreichung $darreichung, float $portionen, ?Team $team = null): array
    {
        $einheiten = $portionen * (float) ($darreichung?->unit_count ?: 1);
        // Ansatz-Masse wie beim Preis: ek_per_kg bezieht sich auf den Kalkulations-Yield
        // (COALESCE(yield_kg_manual, yield_kg), RecipeRecomputeService A-3).
        $yieldKg = $recipe->yield_kg_manual !== null ? (float) $recipe->yield_kg_manual : (float) ($recipe->yield_kg ?? 0);
        $ansatzG = $yieldKg > 0 ? $yieldKg * 1000 : 0.0;

        if ($darreichung !== null && $darreichung->deltas()->exists()) {
            $proEinheit = $this->standardProEinheit($recipe, $team);
            $batchMasse = array_sum(array_map(fn ($z) => $z['masse_g'], $this->recompute->zeilenKostenUndMassen($recipe, $team)));
            $faktorStd = ($batchMasse > 0 && array_sum(array_column($proEinheit, 'masse_g')) > 0)
                ? array_sum(array_column($proEinheit, 'masse_g')) / $batchMasse : null;
            $deltaMap = $darreichung->deltas()->get()->keyBy('recipe_ingredient_id');
            $jeZutat = [];
            $summeG = 0.0;
            foreach ($proEinheit as $ingId => $zeile) {
                $delta = $deltaMap->get($ingId);
                $g = $delta?->omitted ? 0.0 : ($delta?->quantity_override_g !== null ? (float) $delta->quantity_override_g : $zeile['masse_g']);
                $summeG += $g;
                // Ansätze dieser Zutat = gebrauchte Gramm ÷ Gramm je Ansatz (Standard-Komposition ist bereits je Einheit skaliert).
                $jeAnsatz = $faktorStd !== null && $faktorStd > 0 ? $zeile['masse_g'] / $faktorStd : 0.0;
                $jeZutat[(int) $ingId] = $jeAnsatz > 0 ? $einheiten * $g / $jeAnsatz : 0.0;
            }
            $basis = $ansatzG > 0 ? $ansatzG : $batchMasse;

            return [
                'ansaetze' => $basis > 0 ? $einheiten * $summeG / $basis : 0.0,
                'je_zutat' => $jeZutat,
                'gramm_je_portion' => round($summeG * (float) ($darreichung->unit_count ?: 1), 1),
                'warnung' => null,
            ];
        }

        $gramm = (float) ($darreichung?->quantity_per_unit_g ?? 0);
        if ($gramm <= 0 || $ansatzG <= 0) {
            // Form ohne eingetragene Grammatur (oder Gericht ohne Ertrag): Menge wie bisher über die
            // Ausbeute des Ansatzes — Stück (yield_pieces) bzw. Portionen (sales_unit_count).
            $anzahl = ($recipe->yield_pieces !== null && (float) $recipe->yield_pieces > 0)
                ? (float) $recipe->yield_pieces
                : max(1, (int) ($recipe->sales_unit_count ?? 1));

            return [
                'ansaetze' => $einheiten / $anzahl,
                'je_zutat' => null,
                'gramm_je_portion' => null,
                'warnung' => ($darreichung !== null && ! $darreichung->is_standard)
                    ? "Gericht „{$recipe->name}“: Darreichung „".($darreichung->servingForm?->label ?? '?')."“ ohne Grammatur — Menge wie eine Standard-Portion gerechnet."
                    : null,
            ];
        }
        $warnung = null;

        return [
            'ansaetze' => $einheiten * $gramm / $ansatzG,
            'je_zutat' => null,
            'gramm_je_portion' => round($gramm * (float) ($darreichung?->unit_count ?: 1), 1),
            'warnung' => $warnung,
        ];
    }

    /**
     * Standard-Komposition JE EINHEIT: Batch-Massen der Zutaten skaliert auf die
     * Grammatur der Standard-Darreichung (Fallback: ganze Charge = eine Einheit).
     * Referenz für Delta-Editor (Anzeige „Standard (g)") und Delta-Preisrechnung.
     *
     * @return array<int, array{masse_g: float, kosten_pro_g: ?float}>
     */
    public function standardProEinheit(FoodAlchemistRecipe $recipe, ?Team $team = null): array
    {
        $zeilen = $this->recompute->zeilenKostenUndMassen($recipe, $team);
        $batchG = array_sum(array_map(fn ($z) => $z['masse_g'], $zeilen));
        // Referenz = Grammatur der Standard-Form — aber nur, wenn diese selbst delta-frei
        // ist (sonst wäre die Referenz zirkulär, weil ihre Grammatur aus Deltas entsteht).
        $standard = $recipe->standardPresentation()->first();
        $stdG = ($standard !== null && ! $standard->deltas()->exists()) ? $standard->quantity_per_unit_g : null;
        $faktor = ($batchG > 0 && $stdG !== null && (float) $stdG > 0) ? (float) $stdG / $batchG : 1.0;

        $out = [];
        foreach ($zeilen as $ingId => $z) {
            $out[$ingId] = [
                'masse_g' => $z['masse_g'] * $faktor,
                'kosten_pro_g' => ($z['kosten'] !== null && $z['masse_g'] > 0) ? $z['kosten'] / $z['masse_g'] : null,
            ];
        }

        return $out;
    }

    /** recipes.sales_net = Anzeige-Cache der Standard-Darreichung (Preis-Wahrheit = Darreichung). */
    private function spiegleStandardVk(FoodAlchemistRecipe $recipe): void
    {
        $standard = $recipe->standardPresentation()->first();
        if ($standard !== null) {
            $team = Team::find($recipe->team_id);
            $vatDefaults = $team !== null ? app(TeamSettingsService::class)->mwst($team) : TeamSettingsService::MWST_DEFAULTS;
            $vatKey = in_array($standard->vat_profile_key, ['regulaer', 'ermaessigt'], true)
                ? $standard->vat_profile_key : $vatDefaults['default_satz'];
            DB::table('foodalchemist_recipes')->where('id', $recipe->id)
                ->update([
                    'sales_net' => $standard->sales_net,
                    'sales_gross' => $standard->sales_gross,
                    'vat_rate' => (float) ($vatDefaults[$vatKey] ?? 0),
                ]);
        }
    }

    /** Technische Standardform für alte Gerichte ohne Darreichung selbstheilend bereitstellen. */
    private function unbestimmtId(Team $team): int
    {
        $model = \Platform\FoodAlchemist\Models\FoodAlchemistServierform::visibleToTeam($team)
            ->where('code', 'unbestimmt')->first();
        if ($model === null) {
            $model = \Platform\FoodAlchemist\Models\FoodAlchemistServierform::create([
                'team_id' => $team->id,
                'code' => 'unbestimmt',
                'label' => 'Unbestimmt',
            ]);
        }

        return (int) $model->id;
    }

    private function find(Team $team, int $darreichungId): FoodAlchemistRecipeDarreichung
    {
        $darreichung = FoodAlchemistRecipeDarreichung::with('recipe')->findOrFail($darreichungId);
        FoodAlchemistRecipe::visibleToTeam($team)->findOrFail($darreichung->recipe_id);

        return $darreichung;
    }
}
