<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\Core\Models\User;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Models\FoodAlchemistPaket;
use Platform\FoodAlchemist\Models\FoodAlchemistPaketGericht;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Support\MasterTeam;

/**
 * Spec 82 Abo für Konzepte/Pakete/Formate, PR 2: Veröffentlichen eines MASTER-Pakets als Fassung (Entwurf D1: Zeilen-Fassung).
 *
 * - Nur im Master-Team, Rolle Freigeben, Änderungsnotiz Pflicht (wie FassungService für Rezepte).
 * - D2/D11 Blockieren: Jedes Rezept im Paket (Gericht UND Basisrezept-Posten) braucht eine veröffentlichte Rezept-Fassung,
 *   sonst bricht das Veröffentlichen mit einer Liste ab. Genommen wird die jüngste Rezept-Fassung (wie die Abo-Hülle).
 * - D10: Hat die Arbeitszeile eines Rezepts unveröffentlichte Änderungen, bleibt das Paket veröffentlichbar; die Antwort trägt
 *   den Hinweis „Rezept X hat unveröffentlichte Änderungen“.
 * - D9: Eine referenzierte Darreichung, die nicht die Standard-Darreichung ist, wird an die Rezept-Fassung ANGEHÄNGT
 *   (deren inhalt_hash deckt nur die Standard-Form und bleibt gleich); das Paket-Gericht zeigt auf diese Kopie.
 * - Leck-Sperre: die Fassung trägt keine Master-Preise (price_*, ek_*, food_cost_percent, Overrides) und keinen Angebots-Bezug;
 *   der Mandant rechnet selbst (PR 3).
 * - Ein Paket mit gleichem Inhalt wie die letzte Fassung erzeugt keine neue Zeile.
 */
class PaketFassungService
{
    /** Spalten, die eine Paket-Fassung nie vom Arbeitsstand erbt. */
    private const NIE_KOPIEREN = [
        'uuid', 'created_at', 'updated_at', 'deleted_at', 'offer_id', 'kopie_von_id', 'kopie_stand_at',
        'price_per_person', 'calculated_price_per_person', 'ek_per_person', 'food_cost_percent', 'price_calculated_at', 'price_stale',
        'price_calculation_source', 'price_calculation_version', 'price_override_reason', 'price_override_user_id',
        'price_override_at', 'price_override_expires_at', 'nutrition_cache', 'work_time_min_cache',
        'ist_fassung', 'fassung_von_id', 'fassung_nr', 'inhalt_hash', 'veroeffentlicht_at', 'veroeffentlicht_von',
        'aenderungsnotiz', 'fassung_allergen_geaendert',
    ];

    /** Spalten der Gerichts-Zeile, die nicht in den Inhalt (Hash) bzw. die Kopie gehören. */
    private const GERICHT_NIE = ['id', 'uuid', 'team_id', 'package_id', 'sales_recipe_id', 'presentation_id', 'created_at', 'updated_at', 'deleted_at'];

    /** Eingaben einer Darreichung, die ihren Inhalt bestimmen (wie FassungService). */
    private const DARREICHUNG_FELDER = ['serving_form_id', 'quantity_per_unit_g', 'unit_vocab_id', 'unit_count', 'container_warm_vocab_id',
        'container_cold_vocab_id', 'serving_vehicle_vocab_id', 'tableware_item_id', 'vessel_gp_id'];

    private const DARREICHUNG_NIE = ['uuid', 'legacy_id', 'recipe_id', 'ek_portion', 'markup_class_id', 'sales_net', 'calculated_sales_net',
        'sales_gross', 'price_calculation_source', 'price_calculation_version', 'price_calculated_at', 'price_override_reason',
        'price_override_user_id', 'price_override_at', 'price_override_expires_at', 'created_at', 'updated_at', 'deleted_at'];

    public function __construct(
        private FaRechte $rechte,
        private AboService $abos,
        private FassungService $rezeptFassungen,
        private PlattformProtokoll $protokoll,
    ) {}

    /**
     * @return array{fassung_id:int, neu:bool, fassung_nr:int, hinweise:list<string>}
     */
    public function veroeffentlichen(Team $master, int $paketId, string $notiz, ?User $actor): array
    {
        if (! MasterTeam::istMaster($master)) {
            throw new \RuntimeException('Veröffentlichen gibt es nur im Master-Team (Spec 82 §12 B).');
        }
        $this->rechte->pruefe($actor, $master, FaRolle::Freigeben, 'Paket veröffentlichen');
        $notiz = trim($notiz);
        if ($notiz === '') {
            throw new \RuntimeException('Beim Veröffentlichen ist eine Änderungsnotiz Pflicht (Änderungsprotokoll der Abonnenten).');
        }
        $paket = FoodAlchemistPaket::where('team_id', $master->id)->findOrFail($paketId);   // Scope: nie eine Fassung
        $gerichte = $paket->dishes()->get();

        [$rezeptFassung, $hinweise] = $this->rezeptFassungenSammeln($gerichte);

        $hash = $this->inhaltHash($paket, $gerichte, $rezeptFassung);
        $vorher = FoodAlchemistPaket::mitFassungen()->where('fassung_von_id', $paket->id)->where('ist_fassung', true)
            ->whereNull('deleted_at')->orderByDesc('fassung_nr')->orderByDesc('id')->first();
        if ($vorher !== null && $vorher->inhalt_hash === $hash) {
            return ['fassung_id' => (int) $vorher->id, 'neu' => false, 'fassung_nr' => (int) $vorher->fassung_nr, 'hinweise' => $hinweise];
        }

        $fassung = DB::transaction(function () use ($paket, $gerichte, $rezeptFassung, $hash, $notiz, $actor, $vorher) {
            // Nummer über ALLE Fassungen der Familie, auch soft-gelöschte (wie beim Rezept)
            $nr = (int) FoodAlchemistPaket::mitFassungen()->withTrashed()->where('fassung_von_id', $paket->id)->where('ist_fassung', true)->max('fassung_nr') + 1;

            $neu = $this->kopiere($paket, $gerichte, $rezeptFassung, $nr, $hash, $notiz, $actor);
            $this->markiereAllergenAenderung($neu, $vorher);

            return $neu;
        });

        $this->abos->neuBerechnenAlle();   // Bibliothek der Abonnenten zieht auf die neueste Fassung nach
        $this->protokoll->schreibe('paket_fassung_veroeffentlicht', ['paket_id' => $paket->id, 'fassung_id' => $fassung->id,
            'fassung_nr' => (int) $fassung->fassung_nr, 'hinweise' => $hinweise, 'notiz' => $notiz], $actor, $fassung);

        try {   // Signale und Folgen: nie ein Grund, die Veröffentlichung scheitern zu lassen
            app(FassungSignalService::class)->nachBausteinVeroeffentlichung('paket', [(int) $fassung->id]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Paket-Fassung veröffentlicht, aber die Signal-Erzeugung ist fehlgeschlagen: '.$e->getMessage(), ['exception' => $e, 'paket_id' => $paket->id]);
        }

        return ['fassung_id' => (int) $fassung->id, 'neu' => true, 'fassung_nr' => (int) $fassung->fassung_nr, 'hinweise' => $hinweise];
    }

    /** Allergen-/Zusatzstoff-Erbe über die Kette: Flag an der Fassung, wenn sich das Bild gegenüber der Vorgängerin ändert. */
    private function markiereAllergenAenderung(FoodAlchemistPaket $fassung, ?FoodAlchemistPaket $vorher): void
    {
        if ($vorher === null) {
            return;
        }
        $v = app(FassungsVergleich::class);
        if ($v->bilderDiff($v->kettenBild('paket', (int) $vorher->id), $v->kettenBild('paket', (int) $fassung->id)) !== []) {
            $fassung->forceFill(['fassung_allergen_geaendert' => true])->save();
        }
    }

    /**
     * Lese-Status für die Oberfläche „Veröffentlichen…“ — rechnet nichts um und schreibt nichts (Spec 82 §13 B6, PR 8). `blockiert` = Meldung, warum das
     * Veröffentlichen gerade nicht geht (Rezept ohne Fassung, D2/D11); `weicht_ab` gilt nur, wenn nicht blockiert.
     *
     * @return array{letzte: ?array{id:int, nr:int, veroeffentlicht_at:?string, notiz:?string}, weicht_ab: bool, blockiert: ?string, hinweise: list<string>, allergen_aenderung: bool, allergen_felder: list<string>}
     */
    public function status(FoodAlchemistPaket $paket): array
    {
        $letzte = FoodAlchemistPaket::mitFassungen()->where('fassung_von_id', $paket->id)->where('ist_fassung', true)->whereNull('deleted_at')
            ->orderByDesc('fassung_nr')->orderByDesc('id')->first();
        $out = ['letzte' => $letzte === null ? null : ['id' => (int) $letzte->id, 'nr' => (int) $letzte->fassung_nr, 'veroeffentlicht_at' => $letzte->veroeffentlicht_at?->format('d.m.Y H:i'),
            'notiz' => $letzte->aenderungsnotiz], 'weicht_ab' => true, 'blockiert' => null, 'hinweise' => [], 'allergen_aenderung' => false, 'allergen_felder' => []];
        try {
            $gerichte = $paket->dishes()->get();
            [$rezeptFassung, $hinweise] = $this->rezeptFassungenSammeln($gerichte);
        } catch (\RuntimeException $e) {
            $out['blockiert'] = $e->getMessage();

            return $out;
        }
        $out['hinweise'] = $hinweise;
        $out['weicht_ab'] = $letzte === null || $letzte->inhalt_hash !== $this->inhaltHash($paket, $gerichte, $rezeptFassung);
        if ($letzte !== null) {
            $v = app(FassungsVergleich::class);
            $diff = $v->bilderDiff($v->kettenBild('paket', (int) $letzte->id), $v->kettenBildAus(array_map(fn ($r) => (int) $r->id, array_values($rezeptFassung))));
            $out['allergen_aenderung'] = $diff !== [];
            $out['allergen_felder'] = array_keys($diff);
        }

        return $out;
    }

    /**
     * Je Gericht die jüngste Rezept-Fassung. Fehlt eine, bricht es mit der Liste ab (D2/D11); unveröffentlichte Änderungen
     * an der Arbeitszeile sind nur ein Hinweis (D10).
     *
     * @param  \Illuminate\Support\Collection<int, FoodAlchemistPaketGericht>  $gerichte
     * @return array{0: array<int, FoodAlchemistRecipe>, 1: list<string>}  [Rezept-Zeile (Arbeitszeile) id → jüngste Fassung, Hinweise]
     */
    private function rezeptFassungenSammeln($gerichte): array
    {
        $fassungen = [];
        $fehlt = [];
        $hinweise = [];
        foreach ($gerichte as $g) {
            $rezeptId = (int) $g->sales_recipe_id;
            if (isset($fassungen[$rezeptId])) {
                continue;
            }
            $rezept = FoodAlchemistRecipe::mitFassungen()->find($rezeptId);
            if ($rezept === null) {
                continue;   // gelöschtes Rezept: Zeile fällt in der Fassung weg, wie im Arbeitsstand
            }
            $letzte = FoodAlchemistRecipe::mitFassungen()->where('fassung_von_id', $rezept->id)->where('ist_fassung', true)
                ->whereNull('deleted_at')->orderByDesc('fassung_nr')->orderByDesc('id')->first();
            if ($letzte === null) {
                $fehlt[$rezeptId] = (string) $rezept->name;

                continue;
            }
            $fassungen[$rezeptId] = $letzte;
            if ($this->rezeptFassungen->status($rezept)['weicht_ab']) {
                $hinweise[] = "Rezept „{$rezept->name}“ hat unveröffentlichte Änderungen";
            }
        }
        if ($fehlt !== []) {
            $namen = array_values($fehlt);
            throw new \RuntimeException('Noch nicht veröffentlichbar: Diese Rezepte im Paket haben keine veröffentlichte Fassung: '
                . implode(', ', array_slice($namen, 0, 10)) . (count($namen) > 10 ? ' …' : '')
                . '. Zuerst die Rezepte veröffentlichen (Spec 82 Abo-Entwurf D2/D11).');
        }

        return [$fassungen, $hinweise];
    }

    /** @param array<int, FoodAlchemistRecipe> $rezeptFassung */
    private function inhaltHash(FoodAlchemistPaket $paket, $gerichte, array $rezeptFassung): string
    {
        $kopf = collect($paket->getAttributes())
            ->except(array_merge(['id', 'team_id', 'price_mode', 'status'], self::NIE_KOPIEREN))
            ->reject(fn ($v, $k) => str_ends_with($k, '_at'))
            ->sortKeys()->all();
        $zeilen = $gerichte->filter(fn ($g) => isset($rezeptFassung[(int) $g->sales_recipe_id]))->map(function (FoodAlchemistPaketGericht $g) use ($rezeptFassung) {
            $felder = collect($g->getAttributes())->except(self::GERICHT_NIE)->sortKeys()->all();
            $quelle = $g->presentation_id ? FoodAlchemistRecipeDarreichung::find($g->presentation_id) : null;

            return [$felder, (int) $rezeptFassung[(int) $g->sales_recipe_id]->id, $quelle === null ? null : $this->darreichungsInhalt($quelle)];
        })->values()->all();

        return hash('sha256', json_encode([$kopf, $zeilen], JSON_UNESCAPED_UNICODE));
    }

    /** Inhalt einer Darreichung (Form + Deltas über Zutaten-Position) für Hash und Wiederfinden. */
    private function darreichungsInhalt(FoodAlchemistRecipeDarreichung $d): array
    {
        $positionen = DB::table('foodalchemist_recipe_ingredients')->where('recipe_id', $d->recipe_id)->pluck('position', 'id');
        $deltas = $d->deltas()->get(['recipe_ingredient_id', 'quantity_override_g', 'omitted'])
            ->map(fn ($x) => [$positionen[(int) $x->recipe_ingredient_id] ?? null, $x->quantity_override_g, (bool) $x->omitted])
            ->sortBy(fn ($x) => (string) $x[0])->values()->all();

        $zubehoer = $d->zubehoer()->reorder()->orderBy('gp_id')->get(['gp_id', 'quantity'])->map(fn ($z) => [(int) $z->gp_id, (float) $z->quantity])->all();

        return [$d->only(self::DARREICHUNG_FELDER), $deltas, (bool) $d->is_standard, $zubehoer];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, FoodAlchemistPaketGericht>  $gerichte
     * @param  array<int, FoodAlchemistRecipe>  $rezeptFassung
     */
    private function kopiere(FoodAlchemistPaket $paket, $gerichte, array $rezeptFassung, int $nr, string $hash, string $notiz, ?User $actor): FoodAlchemistPaket
    {
        $fassung = $paket->replicate(self::NIE_KOPIEREN);
        $fassung->forceFill([
            'price_mode' => 'auto', 'price_stale' => false,
            'ist_fassung' => true, 'fassung_von_id' => $paket->id, 'fassung_nr' => $nr, 'inhalt_hash' => $hash,
            'veroeffentlicht_at' => now(), 'veroeffentlicht_von' => $actor?->id, 'aenderungsnotiz' => $notiz,
        ])->save();

        foreach ($gerichte as $g) {
            $rezept = $rezeptFassung[(int) $g->sales_recipe_id] ?? null;
            if ($rezept === null) {
                continue;
            }
            $quelle = $g->presentation_id ? FoodAlchemistRecipeDarreichung::find($g->presentation_id) : null;
            $zeile = $g->replicate(['uuid', 'created_at', 'updated_at', 'deleted_at']);
            $zeile->forceFill([
                'package_id' => $fassung->id,
                'sales_recipe_id' => $rezept->id,
                'presentation_id' => $quelle === null ? null : $this->fassungsDarreichung($rezept, $quelle)?->id,
            ])->save();
        }

        return $fassung;
    }

    /**
     * D9: Die Darreichung der Rezept-Fassung, die der Quell-Darreichung des Arbeitsstands entspricht. Standard → die Standard-
     * Darreichung der Fassung; sonst eine bereits angehängte gleiche Kopie, sonst wird sie angehängt (Deltas über Zutaten-Position).
     */
    public function fassungsDarreichung(FoodAlchemistRecipe $fassung, FoodAlchemistRecipeDarreichung $quelle): ?FoodAlchemistRecipeDarreichung
    {
        $vorhandene = FoodAlchemistRecipeDarreichung::where('recipe_id', $fassung->id)->orderByDesc('is_standard')->orderBy('id')->get();
        if ($quelle->is_standard) {
            return $vorhandene->first(fn ($d) => (bool) $d->is_standard) ?? $vorhandene->first();
        }
        $gesucht = $this->darreichungsInhalt($quelle);
        foreach ($vorhandene as $d) {
            if (! $d->is_standard && $this->gleich($this->darreichungsInhalt($d), $gesucht)) {
                return $d;
            }
        }
        $kopie = $quelle->replicate(self::DARREICHUNG_NIE);
        $kopie->forceFill(['recipe_id' => $fassung->id, 'is_standard' => false, 'price_mode' => 'auto'])->save();

        // Deltas: Zutat der Arbeitszeile → Zutat der Fassung über (Position, gp_id, Unter-Rezept) — passt nichts, entfällt das Delta
        $arbeit = DB::table('foodalchemist_recipe_ingredients')->where('recipe_id', $quelle->recipe_id)->whereNull('deleted_at')->get(['id', 'position', 'gp_id'])->keyBy('id');
        $ziel = DB::table('foodalchemist_recipe_ingredients')->where('recipe_id', $fassung->id)->whereNull('deleted_at')->get(['id', 'position', 'gp_id']);
        foreach ($quelle->deltas()->get() as $d) {
            $z = $arbeit[(int) $d->recipe_ingredient_id] ?? null;
            $treffer = $z === null ? null : $ziel->first(fn ($t) => (int) $t->position === (int) $z->position && (int) $t->gp_id === (int) $z->gp_id);
            if ($treffer === null) {
                continue;
            }
            $d->replicate(['uuid', 'legacy_id'])->forceFill(['presentation_id' => $kopie->id, 'recipe_ingredient_id' => $treffer->id])->save();
        }
        foreach ($quelle->zubehoer()->get() as $z) {
            $z->replicate(['uuid'])->forceFill(['presentation_id' => $kopie->id])->save();   // Zubehör & Verpackung geht mit (EK der Darreichung)
        }

        return $kopie;
    }

    private function gleich(array $a, array $b): bool
    {
        return json_encode($a) === json_encode($b);
    }
}
