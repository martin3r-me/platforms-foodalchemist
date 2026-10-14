<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\Core\Models\User;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistConceptSlot;
use Platform\FoodAlchemist\Models\FoodAlchemistPaket;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Support\MasterTeam;

/**
 * Spec 82 Abo für Konzepte/Pakete/Formate, PR 4: Veröffentlichen eines MASTER-Konzepts als Fassung (Entwurf D1: Zeilen-Fassung).
 * Gilt für kind=concept UND kind=paket (D6: beide Paket-Wege). Spiegelt {@see PaketFassungService}.
 *
 * Ein Slot zeigt auf GENAU eines von: Paket (`package_id`), eingebettetes Paket-Konzept (`embedded_concept_id`, kind=paket),
 * Gericht/Basisrezept (`sales_recipe_id`). Jedes Ziel braucht eine veröffentlichte Fassung (D2/D11), sonst bricht das
 * Veröffentlichen mit einer Liste ab; die Fassung zeigt danach auf die jüngste Fassung des Ziels (Baum eingefroren, §12 B 2).
 * D10: unveröffentlichte Änderungen an einem Rezept sind nur ein Hinweis. D9: eine am Slot gewählte nicht-Standard-Darreichung
 * wird an die Rezept-Fassung angehängt. Leck-Sperre: keine Master-Preise (Konzept-Cache, manueller Preis, Slot-Preise der
 * Layout-Blöcke); der Mandant rechnet selbst über {@see ConceptPreisService}.
 */
class ConceptFassungService
{
    /** Spalten, die eine Konzept-Fassung nie vom Arbeitsstand erbt. */
    private const NIE_KOPIEREN = [
        'uuid', 'legacy_id', 'created_at', 'updated_at', 'deleted_at', 'offer_id', 'kopie_von_id', 'kopie_stand_at', 'template_source_id',
        'price_per_person_cache', 'calculated_price_per_person', 'price_per_person_manual', 'ek_per_person_cache', 'price_calculated_at',
        'price_calculation_source', 'price_calculation_version', 'price_override_reason', 'price_override_user_id',
        'price_override_at', 'price_override_expires_at', 'nutrition_cache', 'work_time_min_cache',
        'ist_fassung', 'fassung_von_id', 'fassung_nr', 'inhalt_hash', 'veroeffentlicht_at', 'veroeffentlicht_von',
        'aenderungsnotiz', 'fassung_allergen_geaendert',
    ];

    /** Slot-Spalten, die nicht in Hash und Kopie gehören bzw. umgehängt werden. */
    private const SLOT_NIE = ['id', 'uuid', 'team_id', 'concept_id', 'package_id', 'embedded_concept_id', 'sales_recipe_id', 'presentation_id',
        'price_value', 'price_basis', 'created_at', 'updated_at', 'deleted_at'];

    public function __construct(
        private FaRechte $rechte,
        private AboService $abos,
        private FassungService $rezeptFassungen,
        private PaketFassungService $paketFassungen,
        private PlattformProtokoll $protokoll,
    ) {}

    /**
     * @return array{fassung_id:int, neu:bool, fassung_nr:int, hinweise:list<string>}
     */
    public function veroeffentlichen(Team $master, int $conceptId, string $notiz, ?User $actor): array
    {
        if (! MasterTeam::istMaster($master)) {
            throw new \RuntimeException('Veröffentlichen gibt es nur im Master-Team (Spec 82 §12 B).');
        }
        $this->rechte->pruefe($actor, $master, FaRolle::Freigeben, 'Konzept veröffentlichen');
        $notiz = trim($notiz);
        if ($notiz === '') {
            throw new \RuntimeException('Beim Veröffentlichen ist eine Änderungsnotiz Pflicht (Änderungsprotokoll der Abonnenten).');
        }
        $concept = FoodAlchemistConcept::where('team_id', $master->id)->findOrFail($conceptId);   // Scope: nie eine Fassung
        $slots = $concept->slots()->get();

        [$ziele, $hinweise] = $this->zieleSammeln($slots);
        $hash = $this->inhaltHash($concept, $slots, $ziele);
        $vorher = FoodAlchemistConcept::mitFassungen()->where('fassung_von_id', $concept->id)->where('ist_fassung', true)
            ->whereNull('deleted_at')->orderByDesc('fassung_nr')->orderByDesc('id')->first();
        if ($vorher !== null && $vorher->inhalt_hash === $hash) {
            return ['fassung_id' => (int) $vorher->id, 'neu' => false, 'fassung_nr' => (int) $vorher->fassung_nr, 'hinweise' => $hinweise];
        }

        $fassung = DB::transaction(function () use ($concept, $slots, $ziele, $hash, $notiz, $actor, $vorher) {
            $nr = (int) FoodAlchemistConcept::mitFassungen()->withTrashed()->where('fassung_von_id', $concept->id)->where('ist_fassung', true)->max('fassung_nr') + 1;

            $neu = $this->kopiere($concept, $slots, $ziele, $nr, $hash, $notiz, $actor);
            $this->markiereAllergenAenderung($neu, $vorher);

            return $neu;
        });

        $this->abos->neuBerechnenAlle();
        $this->protokoll->schreibe('konzept_fassung_veroeffentlicht', ['concept_id' => $concept->id, 'kind' => $concept->kind, 'fassung_id' => $fassung->id,
            'fassung_nr' => (int) $fassung->fassung_nr, 'hinweise' => $hinweise, 'notiz' => $notiz], $actor, $fassung);

        try {   // Signale und Folgen: nie ein Grund, die Veröffentlichung scheitern zu lassen
            app(FassungSignalService::class)->nachBausteinVeroeffentlichung('concept', [(int) $fassung->id]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Konzept-Fassung veröffentlicht, aber die Signal-Erzeugung ist fehlgeschlagen: '.$e->getMessage(), ['exception' => $e, 'concept_id' => $concept->id]);
        }

        return ['fassung_id' => (int) $fassung->id, 'neu' => true, 'fassung_nr' => (int) $fassung->fassung_nr, 'hinweise' => $hinweise];
    }

    /** Allergen-/Zusatzstoff-Erbe über die Kette: Flag an der Fassung, wenn sich das Bild gegenüber der Vorgängerin ändert. */
    private function markiereAllergenAenderung(FoodAlchemistConcept $fassung, ?FoodAlchemistConcept $vorher): void
    {
        if ($vorher === null) {
            return;
        }
        $v = app(FassungsVergleich::class);
        if ($v->bilderDiff($v->kettenBild('concept', (int) $vorher->id), $v->kettenBild('concept', (int) $fassung->id)) !== []) {
            $fassung->forceFill(['fassung_allergen_geaendert' => true])->save();
        }
    }

    /**
     * Lese-Status für die Oberfläche „Veröffentlichen…“ — rechnet nichts um und schreibt nichts (Spec 82 §13 B6, PR 8).
     *
     * @return array{letzte: ?array{id:int, nr:int, veroeffentlicht_at:?string, notiz:?string}, weicht_ab: bool, blockiert: ?string, hinweise: list<string>, allergen_aenderung: bool, allergen_felder: list<string>}
     */
    public function status(FoodAlchemistConcept $concept): array
    {
        $letzte = FoodAlchemistConcept::mitFassungen()->where('fassung_von_id', $concept->id)->where('ist_fassung', true)->whereNull('deleted_at')
            ->orderByDesc('fassung_nr')->orderByDesc('id')->first();
        $out = ['letzte' => $letzte === null ? null : ['id' => (int) $letzte->id, 'nr' => (int) $letzte->fassung_nr, 'veroeffentlicht_at' => $letzte->veroeffentlicht_at?->format('d.m.Y H:i'),
            'notiz' => $letzte->aenderungsnotiz], 'weicht_ab' => true, 'blockiert' => null, 'hinweise' => [], 'allergen_aenderung' => false, 'allergen_felder' => []];
        try {
            $slots = $concept->slots()->get();
            [$ziele, $hinweise] = $this->zieleSammeln($slots);
        } catch (\RuntimeException $e) {
            $out['blockiert'] = $e->getMessage();

            return $out;
        }
        $out['hinweise'] = $hinweise;
        $out['weicht_ab'] = $letzte === null || $letzte->inhalt_hash !== $this->inhaltHash($concept, $slots, $ziele);
        if ($letzte !== null) {
            $v = app(FassungsVergleich::class);
            $ids = array_map(fn ($r) => (int) $r->id, array_values($ziele['recipe']));
            foreach ($ziele['paket'] as $p) {
                array_push($ids, ...$v->rezeptIdsDerKette('paket', (int) $p->id));
            }
            foreach ($ziele['concept'] as $c) {
                array_push($ids, ...$v->rezeptIdsDerKette('concept', (int) $c->id));
            }
            $diff = $v->bilderDiff($v->kettenBild('concept', (int) $letzte->id), $v->kettenBildAus(array_values(array_unique($ids))));
            $out['allergen_aenderung'] = $diff !== [];
            $out['allergen_felder'] = array_keys($diff);
        }

        return $out;
    }

    /**
     * Je Slot-Ziel die jüngste Fassung. Fehlt eine, bricht es mit der Liste ab (D2/D11).
     *
     * @param  \Illuminate\Support\Collection<int, FoodAlchemistConceptSlot>  $slots
     * @return array{0: array{recipe: array<int, FoodAlchemistRecipe>, paket: array<int, FoodAlchemistPaket>, concept: array<int, FoodAlchemistConcept>}, 1: list<string>}
     */
    private function zieleSammeln($slots): array
    {
        $ziele = ['recipe' => [], 'paket' => [], 'concept' => []];
        $fehlt = [];
        $hinweise = [];
        foreach ($slots as $s) {
            if ($s->package_id !== null && ! isset($ziele['paket'][(int) $s->package_id])) {
                $p = FoodAlchemistPaket::mitFassungen()->find($s->package_id);
                $f = $p === null ? null : FoodAlchemistPaket::mitFassungen()->where('fassung_von_id', $p->id)->where('ist_fassung', true)
                    ->whereNull('deleted_at')->orderByDesc('fassung_nr')->orderByDesc('id')->first();
                $f === null ? ($p !== null ? $fehlt[] = "Paket „{$p->name}“" : null) : $ziele['paket'][(int) $s->package_id] = $f;
            }
            if ($s->embedded_concept_id !== null && ! isset($ziele['concept'][(int) $s->embedded_concept_id])) {
                $c = FoodAlchemistConcept::mitFassungen()->find($s->embedded_concept_id);
                $f = $c === null ? null : FoodAlchemistConcept::mitFassungen()->where('fassung_von_id', $c->id)->where('ist_fassung', true)
                    ->whereNull('deleted_at')->orderByDesc('fassung_nr')->orderByDesc('id')->first();
                $f === null ? ($c !== null ? $fehlt[] = "Paket-Konzept „{$c->name}“" : null) : $ziele['concept'][(int) $s->embedded_concept_id] = $f;
            }
            if ($s->sales_recipe_id !== null && ! isset($ziele['recipe'][(int) $s->sales_recipe_id])) {
                $r = FoodAlchemistRecipe::mitFassungen()->find($s->sales_recipe_id);
                $f = $r === null ? null : FoodAlchemistRecipe::mitFassungen()->where('fassung_von_id', $r->id)->where('ist_fassung', true)
                    ->whereNull('deleted_at')->orderByDesc('fassung_nr')->orderByDesc('id')->first();
                if ($f === null) {
                    $r !== null ? $fehlt[] = "Rezept „{$r->name}“" : null;
                } else {
                    $ziele['recipe'][(int) $s->sales_recipe_id] = $f;
                    if ($this->rezeptFassungen->status($r)['weicht_ab']) {
                        $hinweise[] = "Rezept „{$r->name}“ hat unveröffentlichte Änderungen";
                    }
                }
            }
        }
        if ($fehlt !== []) {
            $fehlt = array_values(array_unique($fehlt));
            throw new \RuntimeException('Noch nicht veröffentlichbar: Diese Bestandteile haben keine veröffentlichte Fassung: '
                . implode(', ', array_slice($fehlt, 0, 10)) . (count($fehlt) > 10 ? ' …' : '')
                . '. Zuerst veröffentlichen (Spec 82 Abo-Entwurf D2/D11).');
        }

        return [$ziele, array_values(array_unique($hinweise))];
    }

    private function inhaltHash(FoodAlchemistConcept $concept, $slots, array $ziele): string
    {
        $kopf = collect($concept->getAttributes())
            ->except(array_merge(['id', 'team_id', 'price_mode', 'is_template'], self::NIE_KOPIEREN))
            ->reject(fn ($v, $k) => str_ends_with($k, '_at'))
            ->sortKeys()->all();
        $zeilen = $slots->filter(fn ($s) => $this->hatZiel($s, $ziele))->map(function (FoodAlchemistConceptSlot $s) use ($ziele) {
            $felder = collect($s->getAttributes())->except(self::SLOT_NIE)->sortKeys()->all();
            $quelle = $s->presentation_id ? FoodAlchemistRecipeDarreichung::find($s->presentation_id) : null;

            return [$felder,
                $s->package_id !== null ? (int) ($ziele['paket'][(int) $s->package_id]->id ?? 0) : null,
                $s->embedded_concept_id !== null ? (int) ($ziele['concept'][(int) $s->embedded_concept_id]->id ?? 0) : null,
                $s->sales_recipe_id !== null ? (int) ($ziele['recipe'][(int) $s->sales_recipe_id]->id ?? 0) : null,
                $quelle === null ? null : [$quelle->only(['serving_form_id', 'quantity_per_unit_g', 'unit_vocab_id', 'unit_count']), (bool) $quelle->is_standard],
            ];
        })->values()->all();
        $pivots = [
            $concept->serviceMoments()->allRelatedIds()->sort()->values()->all(),
            $concept->seasons()->allRelatedIds()->sort()->values()->all(),
            $concept->targetGroups()->allRelatedIds()->sort()->values()->all(),
        ];

        return hash('sha256', json_encode([$kopf, $zeilen, $pivots], JSON_UNESCAPED_UNICODE));
    }

    /** Ein Slot ohne Ziel (Struktur-Block, leerer Slot) bleibt in der Fassung; einer mit gelöschtem Ziel fällt weg. */
    private function hatZiel(FoodAlchemistConceptSlot $s, array $ziele): bool
    {
        if ($s->package_id !== null) {
            return isset($ziele['paket'][(int) $s->package_id]);
        }
        if ($s->embedded_concept_id !== null) {
            return isset($ziele['concept'][(int) $s->embedded_concept_id]);
        }
        if ($s->sales_recipe_id !== null) {
            return isset($ziele['recipe'][(int) $s->sales_recipe_id]);
        }

        return true;
    }

    private function kopiere(FoodAlchemistConcept $concept, $slots, array $ziele, int $nr, string $hash, string $notiz, ?User $actor): FoodAlchemistConcept
    {
        $fassung = $concept->replicate(self::NIE_KOPIEREN);
        $fassung->forceFill([
            'price_mode' => 'auto', 'is_template' => false,
            'ist_fassung' => true, 'fassung_von_id' => $concept->id, 'fassung_nr' => $nr, 'inhalt_hash' => $hash,
            'veroeffentlicht_at' => now(), 'veroeffentlicht_von' => $actor?->id, 'aenderungsnotiz' => $notiz,
        ])->save();

        foreach ($slots as $s) {
            if (! $this->hatZiel($s, $ziele)) {
                continue;
            }
            $rezept = $s->sales_recipe_id !== null ? $ziele['recipe'][(int) $s->sales_recipe_id] : null;
            $quelle = $s->presentation_id ? FoodAlchemistRecipeDarreichung::find($s->presentation_id) : null;
            $zeile = $s->replicate(['uuid', 'created_at', 'updated_at', 'deleted_at']);
            $zeile->forceFill([
                'concept_id' => $fassung->id,
                'package_id' => $s->package_id !== null ? $ziele['paket'][(int) $s->package_id]->id : null,
                'embedded_concept_id' => $s->embedded_concept_id !== null ? $ziele['concept'][(int) $s->embedded_concept_id]->id : null,
                'sales_recipe_id' => $rezept?->id,
                'presentation_id' => $rezept !== null && $quelle !== null ? $this->paketFassungen->fassungsDarreichung($rezept, $quelle)?->id : null,
                'price_value' => null, 'price_basis' => null,   // Preise der Layout-Blöcke gehören dem Master
            ])->save();
        }
        $fassung->serviceMoments()->sync($concept->serviceMoments()->allRelatedIds()->all());
        $fassung->seasons()->sync($concept->seasons()->allRelatedIds()->all());
        $fassung->targetGroups()->sync($concept->targetGroups()->allRelatedIds()->all());
        foreach ($concept->sectorSuitabilities()->get() as $e) {   // Sektor-Eignung = Eigenschaft des Konzepts, keine Preise
            $e->replicate(['uuid'])->forceFill(['concept_id' => $fassung->id])->save();
        }
        foreach ($concept->images()->get() as $img) {   // Bilder bleiben Verweise auf die Master-Datei (D5)
            $img->replicate(['uuid'])->forceFill(['concept_id' => $fassung->id])->save();
        }

        return $fassung;
    }
}
