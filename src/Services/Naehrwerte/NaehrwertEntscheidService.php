<?php

namespace Platform\FoodAlchemist\Services\Naehrwerte;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Platform\Core\Models\Team;
use Platform\Core\Models\User;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Models\FoodAlchemistItemNutritional;
use Platform\FoodAlchemist\Models\FoodAlchemistPlattformEinstellung;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\FaRechte;
use Platform\FoodAlchemist\Services\NaehrwertAggregatService;
use Platform\FoodAlchemist\Services\PlattformProtokoll;
use Platform\FoodAlchemist\Services\SignalService;
use Platform\FoodAlchemist\Services\SupplierItemService;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Support\NaehrwertPruefModus;

/**
 * Entscheidungen zur Nährwert-Prüfung (PR C): Modus umschalten (nur Master-Team) und einen markierten Artikel freigeben oder korrigieren.
 */
class NaehrwertEntscheidService
{
    public function __construct(private readonly FaRechte $rechte) {}

    /**
     * Modus als Plattform-Einstellung setzen — nur FA-Admin im Master-Team (Dominique 30.10.: nur BHG.DIGITAL). `null` entfernt die
     * Einstellung, dann gilt die env. Ein gesetzter Notaus (env) bleibt stärker.
     *
     * @return array{vorher: string, modus: string, quelle: string}
     */
    public function setzeModus(Team $team, ?User $user, ?string $modus): array
    {
        if (! MasterTeam::istMaster($team)) {
            throw new FaRechtFehltException(FaRolle::Admin, null, 'Nährwert-Prüfung umschalten (nur im Master-Team)');
        }
        $this->rechte->pruefe($user, $team, FaRolle::Admin, 'Nährwert-Prüfung umschalten');
        if ($modus !== null && ! in_array($modus, NaehrwertPruefModus::MODI, true)) {
            throw new \RuntimeException('modus muss aus, trocken oder scharf sein (oder leer = zurück auf die env).');
        }
        $vorher = NaehrwertPruefModus::aktuell();
        $zeile = DB::transaction(function () use ($modus, $user) {
            if ($modus === null) {
                $z = FoodAlchemistPlattformEinstellung::where('schluessel', FoodAlchemistPlattformEinstellung::NAEHRWERT_PRUEFUNG_MODUS)->first();
                $z?->delete();

                return $z;
            }

            return FoodAlchemistPlattformEinstellung::updateOrCreate(['schluessel' => FoodAlchemistPlattformEinstellung::NAEHRWERT_PRUEFUNG_MODUS],
                ['wert' => $modus, 'gesetzt_von' => $user?->id]);
        });
        NaehrwertPruefModus::flush();
        app(PlattformProtokoll::class)->schreibe('naehrwert_pruefung_modus_gesetzt', ['von' => $vorher, 'nach' => NaehrwertPruefModus::aktuell()], $user, $zeile);

        return ['vorher' => $vorher, 'modus' => NaehrwertPruefModus::aktuell(), 'quelle' => NaehrwertPruefModus::quelle()];
    }

    /**
     * Werte eines markierten Artikels freigeben: er zählt wieder in den GP-Nährwerten, solange sich seine Werte nicht ändern.
     *
     * @return array{la_id: int, freigegeben: bool, befunde: list<array<string, mixed>>}
     */
    public function freigeben(Team $team, ?User $user, int $laId, ?string $grund = null): array
    {
        [$item, $artikel] = $this->artikel($team, $user, $laId, 'Nährwerte freigeben');
        DB::table('foodalchemist_item_nutrition_freigaben')->updateOrInsert(['supplier_item_id' => $item->id], [
            'uuid' => (string) Str::uuid7(), 'werte_hash' => NaehrwertPruefService::werteHash($artikel['roh']),
            'regeln' => json_encode(array_map(fn (NaehrwertBefund $b) => $b->toArray(), $artikel['befunde']), JSON_UNESCAPED_UNICODE),
            'team_id' => $team->id, 'user_id' => $user?->id, 'grund' => $grund !== null ? mb_substr($grund, 0, 500) : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->signalSchliessen($team, $item->id, 'freigegeben');

        return ['la_id' => $item->id, 'freigegeben' => true, 'befunde' => array_map(fn (NaehrwertBefund $b) => $b->toArray(), $artikel['befunde'])];
    }

    /**
     * Werte eines Artikels korrigieren (gleicher Weg wie die Handpflege: SupplierItemService::setNutrition, nur Besitzer-Team) und neu prüfen.
     * Felder, die nicht mitkommen, bleiben stehen.
     *
     * @param  array<string, float|int|string|null>  $werte
     * @return array{la_id: int, befunde_vorher: list<array<string, mixed>>, befunde_nachher: list<array<string, mixed>>, zaehlt: bool}
     */
    public function korrigieren(Team $team, ?User $user, int $laId, array $werte): array
    {
        [$item, $vorher] = $this->artikel($team, $user, $laId, 'Nährwerte korrigieren');
        $erlaubt = array_keys(SupplierItemService::NAEHRWERT_FELDER);
        if (($fremd = array_diff(array_keys($werte), $erlaubt)) !== []) {
            throw new \RuntimeException('Unbekannte Nährwert-Felder: ' . implode(', ', $fremd) . '. Erlaubt: ' . implode(', ', $erlaubt) . '.');
        }
        $svc = app(SupplierItemService::class);
        $svc->setNutrition($team, $item, array_merge($svc->getNutrition($item->fresh()), array_map(fn ($v) => $v === null ? '' : (string) $v, $werte)));
        $nachher = $this->pruefen($item->id);
        if ($nachher !== null && $nachher['zaehlt']) {
            $this->signalSchliessen($team, $item->id, 'korrigiert');
        }

        return ['la_id' => $item->id, 'befunde_vorher' => array_map(fn ($b) => $b->toArray(), $vorher['befunde']),
            'befunde_nachher' => array_map(fn ($b) => $b->toArray(), $nachher['befunde'] ?? []), 'zaehlt' => (bool) ($nachher['zaehlt'] ?? true)];
    }

    /** @return array{0: FoodAlchemistSupplierItem, 1: array<string, mixed>} */
    private function artikel(Team $team, ?User $user, int $laId, string $wofuer): array
    {
        $this->rechte->pruefe($user, $team, FaRolle::Kuratieren, $wofuer);
        $item = FoodAlchemistSupplierItem::ohneTeamSicht('naehrwert-pruefung')->findOrFail($laId);
        if (! $item->isOwnedBy($team)) {
            throw new \RuntimeException('Der Artikel gehört einem anderen Team — entscheiden kann nur das Besitzer-Team (globale Artikel: Master-Team).');
        }
        $artikel = $this->pruefen($item->id);
        if ($artikel === null) {
            throw new \RuntimeException('Der Artikel hat keine zählenden Nährwerte oder hängt an keinem Grundprodukt — nichts zu entscheiden.');
        }

        return [$item, $artikel];
    }

    /**
     * Prüfung des Artikels im Kreis seiner Geschwister (erstes GP der Verknüpfung) — auch für die Anzeige am Artikel.
     *
     * @return array{la_id: int, roh: array, wirksam: array, faktor: ?int, befunde: list<NaehrwertBefund>, freigegeben: bool, zaehlt: bool}|null
     */
    public function pruefen(int $laId): ?array
    {
        $gpId = FoodAlchemistSupplierItemStructure::ohneTeamSicht('naehrwert-pruefung')->where('supplier_item_id', $laId)->value('gp_id');
        if ($gpId === null || ! FoodAlchemistItemNutritional::where('supplier_item_id', $laId)->exists()) {
            return null;
        }
        foreach (app(NaehrwertAggregatService::class)->pruefung([(int) $gpId])[(int) $gpId] ?? [] as $a) {
            if ($a['la_id'] === $laId) {
                return $a;
            }
        }

        return null;
    }

    private function signalSchliessen(Team $team, int $laId, string $wie): void
    {
        app(SignalService::class)->schliesseGemessen($team, SignalTyp::NaehrwertPruefen, 'nw-la-' . $laId, NaehrwertSignalService::QUELLE, "Nährwerte {$wie}");
    }
}
