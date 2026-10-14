<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistConceptSlot;
use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;

/**
 * Spec 82 Abo für Konzepte, PR 4 (Preis im Mandantenkontext, §12 C): Konzeptpreis je (Konzept-Fassung × Team).
 *
 * Gleiche Leitplanken wie {@see PaketPreisService}: live, nichts gespeichert, keine Master-Spalte. Pakete laufen über
 * PaketPreisService, eingebettete Paket-Konzepte rekursiv hierüber, Gerichte über KostenCacheService/TeamVkService (mit der am
 * Slot gewählten Darreichung). Unvollständiger EK/VK eines Postens = „kein Preis" fürs ganze Konzept (null + Grund + fehlende
 * Bestandteile) — nie 0 €, nie Teilsumme (Auflage 4).
 *
 * Der Pfad gilt für Konzept-FASSUNGEN und für eigene Konzepte (z. B. die eigene Kopie), sobald ein Slot auf eine Fassung zeigt;
 * alle anderen Konzepte rechnet {@see ConceptService::preisCockpit} unverändert (Master sieht seinen Preis wie bisher).
 */
class ConceptPreisService
{
    private const MAX_TIEFE = 4;

    public function __construct(private PaketPreisService $pakete, private TeamVkService $teamVk) {}

    /** @var array<int, bool> Ergebnis von {@see vorwaermen} für die Dauer EINER Liste (danach {@see vergessen}); statisch, weil jeder Aufrufer sich den Dienst frisch aus dem Container holt */
    private static array $vorgewaermt = [];

    /**
     * Listen-Pfad: für ALLE Konzepte einer Liste mit EINER Abfrage klären, welche auf dem Fassungs-Pfad liegen (statt einer Abfrage je Konzept in
     * {@see istFassungsPfad}). Gemessen 14.10.: 5 Konzepte = 5 Abfragen, 50 = 50. Danach {@see vergessen} aufrufen (try/finally).
     *
     * @param  iterable<int|FoodAlchemistConcept>  $konzepte
     */
    public function vorwaermen(iterable $konzepte): void
    {
        $ids = [];
        foreach ($konzepte as $k) {
            $ids[] = (int) ($k instanceof FoodAlchemistConcept ? $k->id : $k);
        }
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return;
        }
        $treffer = DB::table('foodalchemist_concepts AS c')->whereIn('c.id', $ids)->where(fn ($w) => $w->where('c.ist_fassung', true)
            ->orWhereExists(fn ($q) => $q->from('foodalchemist_concept_slots AS s')->whereColumn('s.concept_id', 'c.id')->whereNull('s.deleted_at')
                ->where(fn ($x) => $x
                    ->whereExists(fn ($y) => $y->from('foodalchemist_recipes AS r')->whereColumn('r.id', 's.sales_recipe_id')->where('r.ist_fassung', true))
                    ->orWhereExists(fn ($y) => $y->from('foodalchemist_packages AS p')->whereColumn('p.id', 's.package_id')->where('p.ist_fassung', true))
                    ->orWhereExists(fn ($y) => $y->from('foodalchemist_concepts AS e')->whereColumn('e.id', 's.embedded_concept_id')->where('e.ist_fassung', true)))))
            ->pluck('c.id')->map(fn ($v) => (int) $v)->flip();
        foreach ($ids as $id) {
            self::$vorgewaermt[$id] = $treffer->has($id);
        }
    }

    public function vergessen(): void
    {
        self::$vorgewaermt = [];
    }

    /** Konzept-Fassung oder eigenes Konzept mit mindestens einem Slot, der auf eine Fassung (Rezept, Paket, Paket-Konzept) zeigt. */
    public function istFassungsPfad(FoodAlchemistConcept $concept): bool
    {
        if (isset(self::$vorgewaermt[(int) $concept->id])) {
            return self::$vorgewaermt[(int) $concept->id];
        }
        if (array_key_exists('ist_fassung', $concept->getAttributes())) {
            if ($concept->ist_fassung) {
                return true;
            }
        } elseif ((bool) DB::table('foodalchemist_concepts')->where('id', $concept->id)->value('ist_fassung')) {
            return true;
        }

        return DB::table('foodalchemist_concept_slots AS s')->where('s.concept_id', $concept->id)->whereNull('s.deleted_at')
            ->where(fn ($w) => $w
                ->whereExists(fn ($q) => $q->from('foodalchemist_recipes AS r')->whereColumn('r.id', 's.sales_recipe_id')->where('r.ist_fassung', true))
                ->orWhereExists(fn ($q) => $q->from('foodalchemist_packages AS p')->whereColumn('p.id', 's.package_id')->where('p.ist_fassung', true))
                ->orWhereExists(fn ($q) => $q->from('foodalchemist_concepts AS c')->whereColumn('c.id', 's.embedded_concept_id')->where('c.ist_fassung', true)))
            ->exists();
    }

    /**
     * Cockpit in der Form von {@see ConceptService::preisCockpit} plus `vollstaendig`, `grund`, `fehlende`. Unvollständig = die
     * Preis-/EK-Felder sind null (nie 0).
     */
    public function cockpit(FoodAlchemistConcept $concept, Team $team, ?FoodAlchemistOutlet $outlet = null, int $tiefe = 0): array
    {
        $slots = $concept->slots()->orderBy('position')->with(['package', 'embeddedConcept', 'dish', 'unit:id,slug,dimension,default_in_g'])->get();
        $zeilen = [];
        $vkTotal = 0.0;
        $ekTotal = 0.0;
        $hatLeer = false;
        $grund = null;
        $fehlende = [];

        foreach ($slots as $slot) {
            if (in_array($slot->type, ConceptService::STRUKTUR_TYPEN, true)) {
                continue;
            }
            $z = $this->zeile($slot, $concept, $team, $outlet, $tiefe);
            $zeilen[] = $z['zeile'];
            if ($z['leer']) {
                $hatLeer = true;
            }
            if ($z['grund'] !== null) {
                $grund ??= $z['grund'];
                $fehlende[] = $z['zeile']['label'];

                continue;
            }
            $vkTotal += $z['vk'];
            $ekTotal += $z['ek'];
        }

        $vollstaendig = $grund === null;
        $summe = $vollstaendig ? round($vkTotal, 2) : null;
        // Manueller Konzept-VK einer EIGENEN Kopie überschreibt die Summe (die Fassung trägt keinen Master-Preis)
        $expired = $concept->price_override_expires_at?->isPast() ?? false;
        $manuell = ! $concept->ist_fassung && in_array(($concept->price_mode ?? 'auto'), ['fixed', 'manuell'], true)
            && ! $expired && $concept->price_per_person_manual !== null;
        $preis = $manuell ? round((float) $concept->price_per_person_manual, 2) : $summe;

        return [
            'zeilen' => $zeilen,
            'price_per_person' => $preis,
            'summe_pro_person' => $summe,
            'price_mode' => $manuell ? ($concept->price_mode === 'fixed' ? 'fixed' : 'manuell') : 'auto',
            'ek_per_person' => $vollstaendig ? round($ekTotal, 2) : null,
            'hat_stale' => false,
            'hat_leer' => $hatLeer,
            'hat_ek_luecke' => ! $vollstaendig,
            'vollstaendig' => $vollstaendig && $preis !== null && $preis > 0,
            'grund' => $vollstaendig ? ($preis !== null && $preis > 0 ? null : 'kein_preis') : $grund,
            'fehlende' => array_values(array_unique($fehlende)),
            'quelle' => 'team_kalkulation',
        ];
    }

    /**
     * Für Lese-Sichten: legt bei einer Konzept-Fassung die Team-Preise als reine Speicher-Attribute über die Cache-Spalten (nie
     * gespeichert, nicht dirty, Merker + saving-Hook am Modell wirft bei jedem save()) und merkt das Team für spätere
     * preisCockpit-Aufrufe. Andere Konzepte bleiben unverändert.
     */
    public function anwenden(FoodAlchemistConcept $concept, Team $team): FoodAlchemistConcept
    {
        if (! $concept->ist_fassung) {
            return $concept;
        }
        $r = $this->cockpit($concept, $team);
        $concept->setAttribute('price_per_person_cache', $r['price_per_person']);
        $concept->setAttribute('calculated_price_per_person', $r['summe_pro_person']);
        $concept->setAttribute('ek_per_person_cache', $r['ek_per_person']);
        $concept->setAttribute('price_mode', 'auto');
        $concept->syncOriginalAttributes(['price_per_person_cache', 'calculated_price_per_person', 'ek_per_person_cache', 'price_mode']);
        $concept->preisOverlay = true;
        $concept->preisTeam = $team;

        return $concept;
    }

    /**
     * Preis und EK je Person für Summen über Konzepte (Format-Kapitel, Report): Fassung → Team-Kalkulation (unvollständig =
     * `vollstaendig=false`, Werte null); alle anderen Konzepte → die gespeicherten Caches wie bisher (null zählt dort als 0,
     * das Verhalten des Bestands bleibt unverändert).
     *
     * @return array{vk: ?float, ek: ?float, vollstaendig: bool}
     */
    public function preisWerte(FoodAlchemistConcept $concept, Team $team, ?FoodAlchemistOutlet $outlet = null): array
    {
        $fassung = array_key_exists('ist_fassung', $concept->getAttributes())
            ? (bool) $concept->ist_fassung
            : (bool) DB::table('foodalchemist_concepts')->where('id', $concept->id)->value('ist_fassung');
        if (! $fassung) {
            return ['vk' => (float) ($concept->price_per_person_cache ?? 0), 'ek' => (float) ($concept->ek_per_person_cache ?? 0), 'vollstaendig' => true];
        }
        $r = $this->cockpit(FoodAlchemistConcept::mitFassungen()->findOrFail($concept->id), $team, $outlet);

        return ['vk' => $r['price_per_person'], 'ek' => $r['ek_per_person'], 'vollstaendig' => $r['vollstaendig']];
    }

    /**
     * @return array{zeile: array, vk: float, ek: float, grund: ?string, leer: bool}
     */
    private function zeile(FoodAlchemistConceptSlot $slot, FoodAlchemistConcept $concept, Team $team, ?FoodAlchemistOutlet $outlet, int $tiefe): array
    {
        $basis = ['slot_id' => $slot->id, 'role' => $slot->role, 'wording' => $slot->wording, 'stale' => false];

        if ($slot->embedded_concept_id !== null && $slot->embeddedConcept) {
            $ep = $slot->embeddedConcept;
            if ($ep->ist_fassung && $tiefe < self::MAX_TIEFE) {
                $sub = $this->cockpit($ep, $team, $outlet, $tiefe + 1);
                $vk = $sub['price_per_person'];
                $ek = $sub['ek_per_person'];
                $grund = $vk === null || $ek === null ? ($sub['grund'] ?? 'ek_unvollstaendig') : null;
            } else {   // eigenes Paket-Konzept: gespeicherter Cache wie bisher
                $vk = $ep->price_per_person_cache !== null ? (float) $ep->price_per_person_cache : 0.0;
                $ek = (float) ($ep->ek_per_person_cache ?? 0);
                $grund = null;
            }

            return $this->ergebnis($basis + ['type' => 'paket', 'label' => $ep->name], $vk, $ek, $grund);
        }
        if ($slot->package_id !== null && $slot->package) {
            $pk = $slot->package;
            if ($pk->ist_fassung) {
                $r = $this->pakete->fuer($pk, $team, $outlet);
                $grund = $r['vollstaendig'] && $r['ek_pro_person'] !== null ? null : ($r['grund'] ?? 'ek_unvollstaendig');

                return $this->ergebnis($basis + ['type' => 'paket', 'label' => $pk->name], $r['vk_pro_person'], $r['ek_pro_person'], $grund);
            }

            return $this->ergebnis($basis + ['type' => 'paket', 'label' => $pk->name, 'stale' => (bool) $pk->price_stale],
                (float) ($pk->price_per_person ?? 0), (float) ($pk->ek_per_person ?? 0), null);
        }
        if ($slot->sales_recipe_id !== null && $slot->dish) {
            return $this->gericht($slot, $concept, $team, $outlet, $basis);
        }

        return ['zeile' => $basis + ['type' => 'leer', 'label' => $slot->title ?? '(leer)', 'price' => null, 'ek' => null, 'ek_fehlt' => false],
            'vk' => 0.0, 'ek' => 0.0, 'grund' => null, 'leer' => true];
    }

    private function gericht(FoodAlchemistConceptSlot $slot, FoodAlchemistConcept $concept, Team $team, ?FoodAlchemistOutlet $outlet, array $basis): array
    {
        $dish = $slot->dish;
        $slot->setRelation('concept', $concept);
        $dar = app(DarreichungResolver::class)->fuerSlot($slot);
        $pae = ConcepterAggregateService::portionsAequivalent(
            $slot->quantity !== null ? (float) $slot->quantity : null,
            $slot->unit,
            $dish,
            $dar?->quantity_per_unit_g !== null ? (float) $dar->quantity_per_unit_g : null,
        );
        $kopf = $basis + ['type' => 'gericht', 'label' => $dish->name];
        if ($pae === null) {   // Gramm-Position ohne Portionsgewicht → ehrlich unbekannt
            return ['zeile' => $kopf + ['price' => null, 'ek' => null, 'ek_fehlt' => true], 'vk' => 0.0, 'ek' => 0.0, 'grund' => 'keine_grammatur', 'leer' => false];
        }
        if ($dish->ist_fassung) {
            $r = $this->teamVk->fuer($dish, $team, $outlet, $dar);

            return $this->ergebnis($kopf, $r['vk'] !== null ? $r['vk'] * $pae : null, $r['mek'] !== null ? $r['mek'] * $pae : null,
                $r['vk'] === null || $r['mek'] === null ? (string) ($r['grund'] ?? 'kein_vk') : null);
        }
        // eigenes Gericht (Arbeitszeile des Besitzers): gespeicherte Werte wie ConceptService::preisCockpit
        $stueck = ConcepterAggregateService::stueckModus($slot->unit, $dish);
        $anzahl = $stueck ? (float) $dish->yield_pieces : max(1, (int) ($dish->sales_unit_count ?? 1));
        $vkStd = $dar?->sales_net !== null ? (float) $dar->sales_net : (float) ($dish->sales_net ?? 0);
        $ek = ($dar?->ek_portion !== null && ! $stueck) ? (float) $dar->ek_portion * $pae : (float) ($dish->ek_total_eur ?? 0) / $anzahl * $pae;

        return $this->ergebnis($kopf, $vkStd * $pae, $ek, null);
    }

    /** @param array<string, mixed> $zeile */
    private function ergebnis(array $zeile, ?float $vk, ?float $ek, ?string $grund): array
    {
        $fehlt = $grund !== null || $vk === null || $ek === null;
        $zeile += ['price' => $fehlt ? null : round($vk, 2), 'ek' => $fehlt ? null : round($ek, 2), 'ek_fehlt' => $fehlt];
        if ($fehlt) {
            $zeile['preis_fehlt'] = true;
        }

        return ['zeile' => $zeile, 'vk' => $fehlt ? 0.0 : $vk, 'ek' => $fehlt ? 0.0 : $ek, 'grund' => $fehlt ? ($grund ?? 'ek_unvollstaendig') : null, 'leer' => false];
    }
}
