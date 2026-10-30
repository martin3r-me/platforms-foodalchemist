<?php

namespace Platform\FoodAlchemist\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemNonfood;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\NonFoodService;
use Platform\FoodAlchemist\Support\NonFoodRegeln;

/**
 * Stufe 2 der Non-Food-Erkennung: die KI entscheidet NUR die Artikel, die die Regelstufe als „unklar“ markiert hat — in
 * Stapeln von {@see self::STAPEL}, auf der Anreicherungs-Schlange. Knappe Antwort {id, nonfood, unterkategorie, konfidenz}; `grund` nur bei Konfidenz unter der Schwelle. Ein sicheres Urteil (Konfidenz ab {@see NonFoodService::KI_SCHWELLE}) wird
 * gespeichert und nimmt den Artikel aus der Prüfliste; alles Unsichere bleibt `pruefung_offen`. Ein roter oder abgeschalteter
 * LLM-Call ist ein No-op (die Prüfliste bleibt), nie ein Absturz. Von Hand gesetzte Urteile fasst der Job nicht an.
 */
class NonFoodKiBatchJob implements ShouldQueue, \Platform\FoodAlchemist\Services\Ai\KiExternWiederholbar
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 180;

    /** Artikel je KI-Aufruf. 20 → 50 (16.10.2026): die feste Prompt-Hülle (Aufgabe, Unterkategorien, Kontext) wird so auf mehr Artikel verteilt. */
    public const STAPEL = 50;

    /** @param  list<int>  $itemIds */
    public function __construct(
        public array $itemIds,
        public bool $extern = false,   // #1002 LA-Wege: `foodalchemist:nonfood-erkennen --anbieter=extern` → KI-Auftrag statt Plattform-Provider
        public int $versuch = 0,
    ) {
    }

    // ── #1002 · KiExternWiederholbar ──
    public function externAktiv(): bool
    {
        return $this->extern;
    }

    public function externSchluessel(): string
    {
        return 'nonfood-ki:'.md5(implode(',', $this->itemIds));
    }

    /** Stapel eines Stamm-Laufs gehören keinem Mandanten: Team des ersten Artikels, sonst das Master-Team. */
    public function externTeamId(): int
    {
        $team = \Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem::query()->whereIn('id', $this->itemIds)->whereNotNull('team_id')->value('team_id');

        return (int) ($team ?? \Platform\FoodAlchemist\Support\MasterTeam::id());
    }

    public function externVersuch(): int
    {
        return $this->versuch;
    }

    public function externNutzlast(): array
    {
        return ['item_ids' => $this->itemIds];
    }

    public static function fromExternNutzlast(array $n, int $versuch): static
    {
        return new static(array_map('intval', (array) $n['item_ids']), true, $versuch);
    }

    public function handle(AiGatewayService $ki): void
    {
        app(\Platform\FoodAlchemist\Services\Ai\KiExternLauf::class)->fuehreAus($this, fn () => $this->arbeite($ki));
    }

    /** Der Job-Rumpf — im Plattformweg unverändert, im externen Weg in der Sammel-Transaktion (KiExternLauf). */
    private function arbeite(AiGatewayService $ki): void
    {
        $zeilen = FoodAlchemistSupplierItemNonfood::query()
            ->whereIn('supplier_item_id', $this->itemIds)
            ->whereNull('ist_nonfood')->where('quelle', FoodAlchemistSupplierItemNonfood::QUELLE_REGEL)
            ->with('item:id,designation,marketing_name,supplier_id', 'item.supplier:id,name')
            ->get();
        if ($zeilen->isEmpty()) {
            return;
        }

        // Kompakt: Lieferantennamen einmal je Stapel (Index `l`), Verkaufsname nur, wenn er sich von der Bezeichnung unterscheidet
        $lieferanten = [];
        $artikel = $zeilen->map(function ($z) use (&$lieferanten) {
            $name = $z->item?->supplier?->name;
            $l = null;
            if ($name !== null && $name !== '') {
                $l = array_search($name, $lieferanten, true);
                if ($l === false) {
                    $lieferanten[] = $name;
                    $l = count($lieferanten) - 1;
                }
            }
            $bez = (string) $z->item?->designation;
            $verkauf = trim((string) $z->item?->marketing_name);

            return array_filter([
                'id' => (int) $z->supplier_item_id, 'bezeichnung' => $bez,
                'verkaufsname' => $verkauf !== '' && mb_strtolower($verkauf) !== mb_strtolower($bez) ? $verkauf : null,
                'l' => $l,
            ], fn ($v) => $v !== null);
        })->values()->all();

        // Modell kommt aus der KI-Klasse des Prompt-Keys (la.nonfood = leicht), kein eigener Schalter.
        try {
            $v = $ki->propose('la.nonfood', ['lieferanten' => $lieferanten, 'artikel' => $artikel, 'unterkategorien' => NonFoodRegeln::unterkategorien()],
                ['verursacher' => 'plattform']);   // globaler Stamm-Lauf: nicht abrechenbar
        } catch (\Throwable $e) {
            return;   // roter/deaktivierter Call = No-op; die Artikel bleiben in der Prüfliste
        }

        $urteile = collect($v->werte['urteile'] ?? [])->filter(fn ($u) => is_array($u) && isset($u['id']))->keyBy(fn ($u) => (int) $u['id']);
        foreach ($zeilen as $z) {
            $u = $urteile->get((int) $z->supplier_item_id);
            $z->ki_versucht_at = now();
            if ($u === null || ! is_bool($u['nonfood'] ?? null)) {
                $z->save();

                continue;
            }
            $konfidenz = max(0.0, min(1.0, (float) ($u['konfidenz'] ?? 0)));
            $sub = NonFoodRegeln::kanonisch(is_string($u['unterkategorie'] ?? null) ? $u['unterkategorie'] : null);   // alter Name ohne Nummer → neu
            $sub = in_array($sub, NonFoodRegeln::unterkategorien(), true) ? $sub : null;
            $z->konfidenz = $konfidenz;
            // Der Grund kommt nur bei UNSICHEREN Urteilen (knappe Antwort, Auftrag 2): ohne grund steht „KI: sicher“ bzw. „KI: unsicher“
            $grund = trim((string) ($u['grund'] ?? ''));
            $z->begruendung = mb_substr('KI: '.($grund !== '' ? $grund : ($konfidenz >= NonFoodService::KI_SCHWELLE ? 'sicher' : 'unsicher')), 0, 250);
            if ($konfidenz >= NonFoodService::KI_SCHWELLE) {
                $z->ist_nonfood = $u['nonfood'];
                $z->quelle = FoodAlchemistSupplierItemNonfood::QUELLE_KI;
                $z->subkategorie = $u['nonfood'] ? ($sub ?? $z->subkategorie) : null;
                $z->pruefung_offen = false;
            } elseif ($u['nonfood'] && $sub !== null) {
                $z->subkategorie = $sub;   // Vorschlag bleibt sichtbar, Urteil trifft der Mensch
            }
            $z->save();
        }
    }
}
