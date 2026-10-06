<?php

namespace Platform\FoodAlchemist\Services\Pairing;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\Achse;
use Platform\FoodAlchemist\Enums\Kantenart;
use Platform\FoodAlchemist\Enums\WissensStatus;
use Platform\FoodAlchemist\Services\PairingService;

/**
 * Spec 60 · P7b: Kombinationsplan für den Generator — dieselbe Logik VOR dem Generieren, die
 * danach das fertige Rezept prüft ({@see Kombinationslogik}).
 *
 * Aus den Leit-Wörtern der Beschreibung werden Anker NUR exakt aufgelöst (kein Wortteil-Raten).
 * Je Leit-Aroma:
 *   harmonie   Partner mit Stufe 3 (echtes Food Pairing) — die bevorzugte Palette
 *   braucht    Bedarfe aus dem Anker-Wissen, je mit Lieferanten (Kontrast-Kanten)
 *   vermeiden  Konflikte („Zerstört")
 * Für Gerichte zusätzlich: Basisrezepte aus dem Bestand, die offene Bedarfe decken und mit einem
 * Leit-Aroma harmonieren (mit recipe_id → als Komponente wiederverwendbar).
 */
final class KombinationsPlan
{
    private const PALETTE = 8;

    private const LIEFERANTEN = 4;

    private const LEIT_MAX = 4;

    public function __construct(
        private readonly PairingService $pairing,
        private readonly AnkerGraph $graph,
        private readonly Kombinationslogik $logik,
    ) {}

    /**
     * @param  list<string>  $leitWoerter
     * @return array<string, mixed>|null  null, wenn kein Leit-Wort einen Anker exakt trifft
     */
    public function fuer(Team $team, array $leitWoerter, bool $gericht, ?string $diaet = null, ?string $richtung = null): ?array
    {
        $anker = [];
        foreach ($leitWoerter as $wort) {
            $id = $this->pairing->ankerIdExakt($wort);
            if ($id !== null && ! in_array($id, $anker, true)) {
                $anker[] = $id;
            }
            if (count($anker) >= self::LEIT_MAX) {
                break;
            }
        }
        if ($anker === []) {
            return null;
        }
        $namen = DB::table('foodalchemist_vocab_pairing_anchors')->whereIn('id', $anker)->pluck('display_de', 'id');

        $leit = [];
        foreach ($anker as $id) {
            $braucht = [];
            foreach (DB::table('foodalchemist_anchor_bedarfe')->where('anchor_id', $id)
                ->where('status', '!=', WissensStatus::Verworfen->value)
                ->orderByRaw("CASE staerke WHEN 'muss' THEN 0 ELSE 1 END")->get(['achse', 'staerke']) as $b) {
                $achse = Achse::tryFrom((string) $b->achse);
                if ($achse === null) {
                    continue;
                }
                $liefern = $this->graph->beziehungen([$id], Kantenart::Kontrast)
                    ->where('achse', $achse->value)->take(self::LIEFERANTEN)->pluck('zu')->all();
                $braucht[] = ['achse' => $achse->label(), 'staerke' => (string) $b->staerke,
                    'liefern' => $this->namen($liefern)];
            }
            $leit[] = [
                'aroma' => (string) $namen[$id],
                'harmonie' => $this->graph->partner($id, AnkerGraph::HARMONIERT, self::PALETTE)->pluck('display_de')->all(),
                'braucht' => $braucht,
                'vermeiden' => $this->namen($this->graph->beziehungen([$id], Kantenart::Konflikt)->pluck('zu')->all()),
            ];
        }

        $plan = [
            'rolle' => $gericht ? 'komposition' : 'aroma_ausschoepfen',
            'hinweis' => 'Kombinationslogik (gemessen + Anker-Wissen): `harmonie` = echtes Food Pairing (Foodpairing 3★) '
                .'je Leit-Aroma — bevorzugt daraus abrunden. `braucht` = was das Leit-Aroma von außen braucht (Kontrast, '
                .'z. B. Säure gegen Süße/Fett); decke jeden „muss"-Bedarf mit einem der genannten Lieferanten oder einer '
                .'gleichwertigen Zutat. `vermeiden` nie kombinieren. Erfinde keine unbelegten Paarungen.'
                .($gericht ? ' `komponenten` = vorhandene Basisrezepte, die einen offenen Bedarf decken — als Komponente '
                    .'wiederverwenden (sub_rezept_id) statt nachzubauen.' : ''),
            'leit_aromen' => $leit,
        ];

        if ($gericht) {
            $analyse = $this->logik->analysiereBestandteile($this->logik->bestandteileAusAnkern($anker));
            $komponenten = [];
            foreach ($this->logik->vorschlaegeFuer($analyse, $diaet, $richtung, (int) $team->id, 3) as $v) {
                if ($v['basisrezepte'] === []) {
                    continue;
                }
                $komponenten[] = ['deckt' => Achse::from($v['achse'])->label(),
                    'basisrezepte' => array_map(fn ($b) => ['sub_rezept_id' => $b['recipe_id'], 'name' => $b['name']], $v['basisrezepte'])];
            }
            if ($komponenten !== []) {
                $plan['komponenten'] = $komponenten;
            }
        }

        return $plan;
    }

    /**
     * @param  list<int>  $ids
     * @return list<string>
     */
    private function namen(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $n = DB::table('foodalchemist_vocab_pairing_anchors')->whereIn('id', $ids)->pluck('display_de', 'id');

        return array_values(array_filter(array_map(fn ($id) => $n[$id] ?? null, $ids)));
    }
}
