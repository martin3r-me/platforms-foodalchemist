<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\SignalSeverity;
use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel;
use Platform\FoodAlchemist\Models\FoodAlchemistSignal;

/**
 * Spec 19 E9.2/E9.3 — Pairing-Inspiration für die Kreativ-Phase.
 *
 * Ein Werkzeug (der Anker-/Pairing-Graph), drei Verhalten je Kreativ-Modus:
 *   - voll_kreativ → ABSTRAKT: nur Aroma-Nachbarn (produkt-blind), öffnet den Ideenraum,
 *                    verankert NICHT am Lager.
 *   - hybrid       → GEERDET: Nachbarn + welche echten GPs das Aroma tragen (Verfügbarkeits-
 *                    Marker folgt in E9.3), sichtbar aber nicht führend.
 *   - datenbank    → GEERDET: wie hybrid; die UI (E9.4) betont das Verfügbare.
 *
 * Rein LESEND. Erdet nichts, legt nichts an. Recycelt PairingService (ankerNeighbors +
 * gpsForAnkerIds). Die Verfügbarkeits-Buckets (führen/leicht/Lücke) + Lücke→Signal sind E9.3.
 */
class PairingInspirationService
{
    public function __construct(
        private readonly PairingService $pairing,
        private readonly FavoriteGpService $favoriten,
        private readonly SignalService $signale,
    ) {}

    /** voll_kreativ ⇒ abstrakt (kein GP), sonst geerdet. */
    private function istGeerdet(string $modus): bool
    {
        return $modus !== 'voll_kreativ';
    }

    /**
     * Aroma-Anker per Suchbegriff finden (UI-Auflösung Freitext → Anker-Slug).
     *
     * @return \Illuminate\Support\Collection<int, object> {id, slug, display_de}
     */
    public function sucheAnker(string $term, int $limit = 10): Collection
    {
        $term = trim($term);
        if ($term === '') {
            return collect();
        }
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $term) . '%';

        return DB::table('foodalchemist_vocab_pairing_anchors')
            ->where(fn ($q) => $q->where('slug', 'like', $like)->orWhere('display_de', 'like', $like))
            ->orderByRaw('CASE WHEN slug = ? THEN 0 ELSE 1 END', [$term])
            ->orderBy('display_de')->limit(max(1, min(50, $limit)))
            ->get(['id', 'slug', 'display_de']);
    }

    /**
     * Inspiration zu einem oder mehreren Aroma-Ankern (Slugs).
     *
     * @param  list<string>  $seedSlugs
     * @return array{modus: string, geerdet: bool, seeds: list<string>, inspiration: list<array{
     *   seed: string, seed_label: string, nachbarn: list<array{
     *     anker_id: int, slug: string, label: string, typ: ?string, evidence: ?string,
     *     gps?: list<array{id: int, name: string, is_favorite: bool, has_lead_la: bool, status: string}>
     *   }>
     * }>}
     */
    public function inspiration(Team $team, array $seedSlugs, string $modus, int $limitProSeed = 8): array
    {
        $modus = in_array($modus, FoodAlchemistFoodbookKapitel::CREATIVE_MODES, true)
            ? $modus : FoodAlchemistFoodbookKapitel::CREATIVE_MODE_DEFAULT;
        $geerdet = $this->istGeerdet($modus);

        $seeds = collect($seedSlugs)->map(fn ($s) => trim((string) $s))->filter()->unique()->values();
        $labelBySlug = DB::table('foodalchemist_vocab_pairing_anchors')
            ->whereIn('slug', $seeds->all())->pluck('display_de', 'slug');

        $inspiration = [];
        foreach ($seeds as $slug) {
            $nachbarn = $this->pairing->ankerNeighbors($slug, null, $limitProSeed);
            if ($nachbarn->isEmpty()) {
                continue;
            }

            // Geerdet: in EINER Query alle tragenden GPs für die Nachbar-Anker holen (nach Anker gruppiert)
            // + Verfügbarkeits-Buckets (führen/leicht/Lücke) in EINER weiteren Query (E9.3).
            $gpsByAnker = collect();
            $buckets = [];
            if ($geerdet) {
                $ankerIds = $nachbarn->pluck('id')->map(fn ($i) => (int) $i)->all();
                $gpsByAnker = $this->pairing->gpsForAnkerIds($team, $ankerIds)->groupBy('anchor_id');
                $alleGpIds = $gpsByAnker->flatten(1)->pluck('id')->map(fn ($i) => (int) $i)->unique()->all();
                $buckets = $this->favoriten->verfuegbarkeit($team, $alleGpIds);
            }

            $nachbarnOut = $nachbarn->map(function ($n) use ($geerdet, $gpsByAnker, $buckets) {
                $row = [
                    'anker_id' => (int) $n->id,
                    'slug' => (string) $n->slug,
                    'label' => (string) ($n->display_de ?: $n->slug),
                    'typ' => $n->type !== null ? (string) $n->type : null,
                    'evidence' => $n->evidence !== null ? (string) $n->evidence : null,
                ];
                if ($geerdet) {
                    $gps = ($gpsByAnker->get((int) $n->id) ?? collect())
                        ->map(fn ($g) => [
                            'id' => (int) $g->id, 'name' => (string) $g->name,
                            'is_favorite' => (bool) $g->is_favorite,
                            'has_lead_la' => $g->lead_la_supplier_item_id !== null,
                            'status' => (string) $g->status,
                            'bucket' => $buckets[(int) $g->id]['bucket'] ?? 'luecke',
                        ])->values()->all();
                    $row['gps'] = $gps;
                    // Nachbar ist eine Lücke, wenn KEIN tragender GP beschaffbar (führen/leicht) ist —
                    // das Aroma wird gewünscht, aber nichts im Sortiment trägt es sourcebar.
                    $row['luecke'] = collect($gps)->every(fn ($g) => $g['bucket'] === 'luecke');
                }

                return $row;
            })->values()->all();

            $inspiration[] = [
                'seed' => $slug,
                'seed_label' => (string) ($labelBySlug[$slug] ?? $slug),
                'nachbarn' => $nachbarnOut,
            ];
        }

        return ['modus' => $modus, 'geerdet' => $geerdet, 'seeds' => $seeds->all(), 'inspiration' => $inspiration];
    }

    /**
     * Spec 19 E9.3: Sortiments-Lücke ins Signale-Cockpit melden — der BEWUSSTE Schreibpfad
     * (nicht in inspiration(), das rein lesend bleibt: Browsen soll nicht spammen). Aufrufer
     * = Kreativ-Tab „Lücke melden" bzw. Kapitel-Go, wenn ein gewünschtes Aroma nicht beschaffbar
     * ist. Idempotent über dedup_key je (Team, Anker) — wiederholtes Melden aktualisiert EIN
     * offenes Signal statt zu duplizieren. „Lücke ist Signal, kein Fehler" (Design 2026-07-25).
     */
    public function meldeLuecke(Team $team, string $ankerSlug, array $context = []): FoodAlchemistSignal
    {
        $slug = trim($ankerSlug);
        $label = (string) (DB::table('foodalchemist_vocab_pairing_anchors')->where('slug', $slug)->value('display_de') ?: $slug);

        return $this->signale->erzeuge(
            $team,
            SignalTyp::SortimentsLuecke,
            SignalSeverity::Info,
            'Sortiments-Lücke: ' . $label,
            [
                'description' => 'Aroma „' . $label . '" wurde in der Kreativ-Phase gewünscht, aber kein beschaffbarer GP trägt es. Kandidat für die Beschaffung/Sortimentspflege.',
                'dedup_key' => 'sortiments_luecke:' . $slug,
                'source' => 'kreativ_phase',
                'payload' => ['anchor_slug' => $slug, 'anchor_label' => $label] + $context,
            ],
        );
    }
}
