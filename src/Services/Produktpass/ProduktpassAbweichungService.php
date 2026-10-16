<?php

namespace Platform\FoodAlchemist\Services\Produktpass;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Enums\SignalSeverity;
use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Models\FoodAlchemistItemAllergen;
use Platform\FoodAlchemist\Models\FoodAlchemistItemDeclaration;
use Platform\FoodAlchemist\Models\FoodAlchemistItemNutritional;
use Platform\FoodAlchemist\Models\FoodAlchemistProduktpassFeld;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\FaRechte;
use Platform\FoodAlchemist\Services\SignalService;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Support\ProduktpassSchutz;

/**
 * Necta-Abweichungen an Produktpass-Feldern der Kerngruppen inkl. Gebinde (Entscheid Dominique 16.10.2026, Variante 1): der Pass-Wert gilt, bis jemand
 * entscheidet. Die Abweichung steht als Prüffall `produktpass_abweichung` (foodalchemist_necta_pruefaelle, legacy_id = Necta-Artikel) und
 * als EIN Signal je Artikel (Schwere: Allergene/Unterarten kritisch, sonst Warnung — die höchste offene zählt).
 *
 *  - Pass behalten → Prüffall `verworfen`; der Abgleich meldet dieselbe Necta-Angabe gegen denselben Pass-Wert nicht wieder.
 *  - Necta übernehmen → Necta-Wert eintragen, Marker an DIESEM Feld löschen, Prüffall `uebernommen`.
 *  - Neuen Pass hochladen → Reiter Produktpass (kein eigener Schritt hier).
 *
 * Rechte wie beim Produktpass ({@see ProduktpassService}): Besitzer-Team des Artikels, Kuratieren (MCP: Admin).
 */
class ProduktpassAbweichungService
{
    public const ART = 'produktpass_abweichung';

    public const SIGNAL_QUELLE = 'produktpass_abweichung';

    private const OBJEKTE = ['supplier_item', 'item_allergen', 'item_declaration', 'item_nutritional'];

    private const NAEHRWERT_LABEL = ['energy_kcal' => 'Energie (kcal)', 'energy_kj' => 'Energie (kJ)', 'protein' => 'Eiweiß', 'fat' => 'Fett',
        'saturated_fat' => 'gesättigte Fettsäuren', 'carbs_absorbable' => 'Kohlenhydrate', 'sugar' => 'Zucker', 'roughage' => 'Ballaststoffe',
        'sodium' => 'Natrium', 'salt' => 'Salz'];

    public function __construct(private FaRechte $rechte)
    {
    }

    /**
     * Offene Abweichungen eines Artikels, Allergene zuerst.
     *
     * @return list<array{id:int, objekt:string, feld:string, label:string, pass:?string, necta:?string, kritisch:bool}>
     */
    public function offene(FoodAlchemistSupplierItem $item): array
    {
        if ($item->legacy_id === null) {
            return [];
        }

        return DB::table('foodalchemist_necta_pruefaelle')->where('art', self::ART)->where('legacy_id', $item->legacy_id)
            ->whereIn('objekt', self::OBJEKTE)->where('status', 'offen')->orderBy('id')->get()
            ->map(fn ($p) => ['id' => (int) $p->id, 'objekt' => $p->objekt, 'feld' => (string) $p->feld, 'label' => self::label($p->objekt, (string) $p->feld),
                'pass' => $p->fa_wert, 'necta' => $p->necta_wert, 'kritisch' => $p->objekt === 'item_allergen'])
            ->sortBy(fn ($a) => $a['kritisch'] ? 0 : 1)->values()->all();
    }

    public function behalten(Team $team, int $pruefallId, ?int $userId, FaRolle $mindestens = FaRolle::Kuratieren): void
    {
        [$fall, $item] = $this->fallUndArtikel($team, $pruefallId, $userId, $mindestens, 'Produktpass-Wert gegen Necta behalten');
        DB::table('foodalchemist_necta_pruefaelle')->where('id', $fall->id)
            ->update(['status' => 'verworfen', 'erledigt_von' => $userId, 'erledigt_at' => now(), 'updated_at' => now()]);
        $this->signalAktualisieren((int) $item->id);
    }

    public function uebernehmen(Team $team, int $pruefallId, ?int $userId, FaRolle $mindestens = FaRolle::Kuratieren): void
    {
        [$fall, $item] = $this->fallUndArtikel($team, $pruefallId, $userId, $mindestens, 'Necta-Wert statt Produktpass übernehmen');
        $feld = (string) $fall->feld;
        $wert = self::typisiert($fall->objekt, $feld, $fall->necta_wert);
        DB::transaction(function () use ($fall, $item, $feld, $wert, $userId) {
            // über die Modelle: Hooks (Jod aus der Zutatenliste, Aktivitätslog) laufen mit
            if ($fall->objekt === 'supplier_item') {
                $item->update([$feld => $wert]);
            } else {
                $klasse = ['item_allergen' => FoodAlchemistItemAllergen::class, 'item_declaration' => FoodAlchemistItemDeclaration::class,
                    'item_nutritional' => FoodAlchemistItemNutritional::class][$fall->objekt];
                $zeile = $klasse::firstOrNew(['supplier_item_id' => $item->id]);
                if (! $zeile->exists) {
                    $zeile->team_id = $item->team_id;
                }
                $zeile->{$feld} = $wert;
                $zeile->save();
            }
            FoodAlchemistProduktpassFeld::where('supplier_item_id', $item->id)->where('objekt', $fall->objekt)->where('feld', $feld)->delete();
            DB::table('foodalchemist_necta_pruefaelle')->where('id', $fall->id)
                ->update(['status' => 'uebernommen', 'erledigt_von' => $userId, 'erledigt_at' => now(), 'updated_at' => now()]);
        });
        ProduktpassSchutz::zuruecksetzen();
        $this->signalAktualisieren((int) $item->id);
    }

    /**
     * Nach neuem Pass-Wert (Upload, Übernahme, Bestätigung): offene Abweichungen, bei denen der Artikel jetzt den Necta-Wert trägt,
     * sind erledigt (`uebernommen`, ohne Person). Danach Signal neu setzen.
     */
    public function erledigteSchliessen(FoodAlchemistSupplierItem $item): int
    {
        $tabellen = ['supplier_item' => 'foodalchemist_supplier_items', 'item_allergen' => 'foodalchemist_item_allergens',
            'item_declaration' => 'foodalchemist_item_declarations', 'item_nutritional' => 'foodalchemist_item_nutritionals'];
        $n = 0;
        foreach ($this->offene($item) as $a) {
            $zeile = $a['objekt'] === 'supplier_item'
                ? DB::table($tabellen['supplier_item'])->where('id', $item->id)->first()
                : DB::table($tabellen[$a['objekt']])->where('supplier_item_id', $item->id)->whereNull('deleted_at')->first();
            if ($zeile !== null && self::gleich($a['feld'], $zeile->{$a['feld']} ?? null, $a['necta'])) {
                $n += DB::table('foodalchemist_necta_pruefaelle')->where('id', $a['id'])->where('status', 'offen')
                    ->update(['status' => 'uebernommen', 'erledigt_at' => now(), 'updated_at' => now()]);
            }
        }
        if ($n > 0) {
            $this->signalAktualisieren((int) $item->id);
        }

        return $n;
    }

    /** Signal des Artikels neu setzen: offen → erzeugen/aktualisieren (Schwere = höchste offene), nichts offen → gemessen schließen. */
    public function signalAktualisieren(int $itemId): void
    {
        $item = FoodAlchemistSupplierItem::withoutGlobalScopes()->find($itemId);
        $teamId = $item?->team_id ?? MasterTeam::id();
        $team = $teamId !== null ? Team::find($teamId) : null;
        if ($item === null || $team === null) {
            return;
        }
        $offen = $this->offene($item);
        $dedup = self::ART.':'.$item->id;
        $signale = app(SignalService::class);
        if ($offen === []) {
            $signale->schliesseGemessen($team, SignalTyp::ProduktpassAbweichung, $dedup, self::SIGNAL_QUELLE, 'Alle Necta-Abweichungen am Produktpass entschieden');

            return;
        }
        $kritisch = collect($offen)->contains(fn ($a) => $a['kritisch']);
        $felder = collect($offen)->pluck('label')->unique()->values();
        $signale->erzeuge($team, SignalTyp::ProduktpassAbweichung, $kritisch ? SignalSeverity::Kritisch : SignalSeverity::Warnung,
            'Necta weicht vom Produktpass ab: '.$item->designation, [
                'description' => count($offen).' Feld(er): '.$felder->take(6)->implode(', ').($felder->count() > 6 ? ' …' : '')
                    .'. Der Produktpass-Wert gilt, bis jemand entscheidet.',
                'dedup_key' => $dedup, 'source' => self::SIGNAL_QUELLE, 'ref_type' => 'supplier_item', 'ref_id' => $item->id,
                'payload' => ['supplier_item_id' => $item->id, 'pruefaelle' => collect($offen)->pluck('id')->all()],
            ]);
    }

    /** Gleiche Angabe? Unterarten (`details`) als JSON-Objekt, sonst normalisiert (k. A. = NULL). */
    public static function gleich(string $feld, mixed $a, mixed $b): bool
    {
        return $feld === 'details'
            ? ProduktpassSchutz::jsonNorm($a) === ProduktpassSchutz::jsonNorm($b)
            : \Platform\FoodAlchemist\Services\Necta\NectaAbgleichService::norm($a) === \Platform\FoodAlchemist\Services\Necta\NectaAbgleichService::norm($b);
    }

    public static function label(string $objekt, string $feld): string
    {
        return match ($objekt) {
            'item_allergen' => $feld === 'details' ? 'Allergen-Unterarten'
                : 'Allergen '.(FoodAlchemistItemAllergen::ALLERGENE[substr($feld, strlen('allergen_'))] ?? $feld),
            'item_declaration' => (FoodAlchemistItemDeclaration::STOFFE + FoodAlchemistItemDeclaration::FREIWILLIG)[$feld] ?? $feld,
            'item_nutritional' => 'Nährwert '.(self::NAEHRWERT_LABEL[$feld] ?? $feld),
            default => ['ingredients_supplier' => 'Zutatenliste', 'qty' => 'Gebinde-Menge', 'unit_code' => 'Gebinde-Einheit',
                'qty_ordering_per_packaging' => 'Stück je Gebinde', 'packaging_unit' => 'Gebinde-Art'][$feld] ?? $feld,
        };
    }

    /** Prüffall-Text (necta_wert) zurück in den Spaltentyp. */
    private static function typisiert(string $objekt, string $feld, ?string $text): mixed
    {
        if ($text === null || $text === '') {
            return null;
        }

        return match (true) {
            $objekt === 'item_allergen' && $feld === 'details' => json_decode($text, true) ?: null,
            $objekt === 'item_declaration' && $feld !== 'details' => (int) $text,
            $objekt === 'item_nutritional' && is_numeric($text) => (float) $text,
            $objekt === 'supplier_item' && in_array($feld, ['qty', 'qty_ordering_per_packaging'], true) && is_numeric($text) => (float) $text,
            default => $text,
        };
    }

    /** @return array{0: object, 1: FoodAlchemistSupplierItem} */
    private function fallUndArtikel(Team $team, int $pruefallId, ?int $userId, FaRolle $mindestens, string $wofuer): array
    {
        $fall = DB::table('foodalchemist_necta_pruefaelle')->where('id', $pruefallId)->where('art', self::ART)->first();
        if ($fall === null || ! in_array($fall->objekt, self::OBJEKTE, true)) {
            throw new \RuntimeException('Diese Abweichung gibt es nicht.');
        }
        if ($fall->status !== 'offen') {
            throw new \RuntimeException('Diese Abweichung ist schon entschieden.');
        }
        $item = FoodAlchemistSupplierItem::visibleToTeam($team)->where('legacy_id', $fall->legacy_id)->first();
        if ($item === null) {
            throw new \RuntimeException('Der Artikel zu dieser Abweichung ist für dieses Team nicht sichtbar.');
        }
        $this->rechte->pruefeId($userId, $team, $mindestens, $wofuer);
        if (! $item->isOwnedBy($team)) {
            throw new \RuntimeException('Dieser Artikel kommt aus einem übergeordneten Katalog. Entscheiden kann nur das Team, dem er gehört.');
        }

        return [$fall, $item];
    }
}
