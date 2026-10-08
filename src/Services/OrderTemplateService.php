<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistOrder;
use Platform\FoodAlchemist\Models\FoodAlchemistOrderTemplate;
use Platform\FoodAlchemist\Models\FoodAlchemistOrderTemplateLine;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;

/**
 * Spec 68 · Bestellvorlagen = gespeicherte Bestellrunde.
 *
 * Eine Vorlage hält nur Quellen im Format der Bestellrunde (`OrderService::previewFromSources` /
 * `generateDraftsFromSources`): Grundprodukt + Menge (Artikel wählt die Lead-Strategie), Rezept /
 * Gericht + Portionen/Ansätze/kg (Bedarf aus der Rezeptur, die „Musterproduktion") oder fester
 * Artikel + Gebinde. Anwenden öffnet die Runde vorbefüllt oder legt die Entwürfe direkt an — kein
 * zweiter Bestellweg mit eigenen Regeln.
 *
 * Team-strikt (`where team_id`): Vorlagen sind Arbeitsstand eines Betriebs.
 */
class OrderTemplateService
{
    public function __construct(private OrderService $orders) {}

    /** @return Collection<int, FoodAlchemistOrderTemplate> */
    public function liste(Team $team): Collection
    {
        return FoodAlchemistOrderTemplate::where('team_id', $team->id)
            ->withCount('lines')->orderBy('name')->get();
    }

    public function detail(Team $team, int $id): FoodAlchemistOrderTemplate
    {
        return FoodAlchemistOrderTemplate::where('team_id', $team->id)
            ->with(['lines.gp:id,name', 'lines.recipe:id,name,is_sales_recipe', 'lines.supplierItem:id,designation,supplier_id', 'lines.supplierItem.supplier:id,name'])
            ->findOrFail($id);
    }

    /** @param array{name:string, note?:?string, weekday?:?int} $daten */
    public function anlegen(Team $team, array $daten, ?int $userId = null): FoodAlchemistOrderTemplate
    {
        return FoodAlchemistOrderTemplate::create([
            'team_id' => $team->id, 'name' => $this->name($daten['name'] ?? ''), 'note' => $this->text($daten['note'] ?? null),
            'weekday' => $this->wochentag($daten['weekday'] ?? null), 'created_by' => $userId,
        ]);
    }

    /** @param array{name?:string, note?:?string, weekday?:?int} $daten */
    public function aendern(Team $team, int $id, array $daten): FoodAlchemistOrderTemplate
    {
        $v = FoodAlchemistOrderTemplate::where('team_id', $team->id)->findOrFail($id);
        $upd = [];
        if (array_key_exists('name', $daten)) {
            $upd['name'] = $this->name($daten['name']);
        }
        if (array_key_exists('note', $daten)) {
            $upd['note'] = $this->text($daten['note']);
        }
        if (array_key_exists('weekday', $daten)) {
            $upd['weekday'] = $this->wochentag($daten['weekday']);
        }
        $v->update($upd);

        return $v->refresh();
    }

    public function loeschen(Team $team, int $id): void
    {
        $v = FoodAlchemistOrderTemplate::where('team_id', $team->id)->findOrFail($id);
        DB::transaction(function () use ($v) {
            FoodAlchemistOrderTemplateLine::where('order_template_id', $v->id)->delete();
            $v->delete();
        });
    }

    /**
     * Position aufnehmen. Gleiche Quelle (Typ + Bezug) noch einmal → Menge/Einheit werden gesetzt.
     */
    public function positionSetzen(Team $team, int $id, string $typ, int $bezugId, mixed $menge, ?string $einheit = null, ?string $notiz = null): FoodAlchemistOrderTemplateLine
    {
        $v = FoodAlchemistOrderTemplate::where('team_id', $team->id)->findOrFail($id);
        if (! in_array($typ, FoodAlchemistOrderTemplateLine::TYPEN, true)) {
            throw new \RuntimeException('Typ muss gp, recipe oder supplier_item sein.');
        }
        $spalte = ['gp' => 'gp_id', 'recipe' => 'recipe_id', 'supplier_item' => 'supplier_item_id'][$typ];
        $sichtbar = match ($typ) {
            'gp' => FoodAlchemistGp::visibleToTeam($team)->whereKey($bezugId)->exists(),
            'recipe' => FoodAlchemistRecipe::visibleToTeam($team)->whereKey($bezugId)->exists(),
            default => FoodAlchemistSupplierItem::visibleToTeam($team)->whereKey($bezugId)->exists(),
        };
        if (! $sichtbar) {
            throw new \RuntimeException(['gp' => 'Grundprodukt', 'recipe' => 'Rezept', 'supplier_item' => 'Lieferantenartikel'][$typ] . ' nicht gefunden.');
        }
        $einheit = $this->einheit($typ, $einheit, $bezugId);
        $line = FoodAlchemistOrderTemplateLine::firstOrNew(['order_template_id' => $v->id, 'type' => $typ, $spalte => $bezugId]);
        $line->fill([
            'team_id' => $team->id, 'qty' => $this->menge($menge), 'unit' => $einheit,
            'position' => $line->exists ? $line->position : (int) FoodAlchemistOrderTemplateLine::where('order_template_id', $v->id)->max('position') + 1,
        ]);
        if ($notiz !== null) {
            $line->note = $this->text($notiz);
        }
        $line->save();

        return $line;
    }

    /** Menge und/oder Einheit einer Position ändern. */
    public function positionAendern(Team $team, int $lineId, mixed $menge = null, ?string $einheit = null): FoodAlchemistOrderTemplateLine
    {
        $line = FoodAlchemistOrderTemplateLine::where('team_id', $team->id)->findOrFail($lineId);
        $upd = [];
        if ($menge !== null) {
            $upd['qty'] = $this->menge($menge);
        }
        if ($einheit !== null) {
            $upd['unit'] = $this->einheit($line->type, $einheit, (int) ($line->recipe_id ?? 0));
        }
        $line->update($upd);

        return $line->refresh();
    }

    public function positionEntfernen(Team $team, int $lineId): void
    {
        FoodAlchemistOrderTemplateLine::where('team_id', $team->id)->findOrFail($lineId)->delete();
    }

    /**
     * Quellen für die Bestellrunde. Mengen je Position überschreibbar (line_id => Menge, 0 = auslassen).
     *
     * @param  array<int, mixed>  $mengen
     * @return list<array{type:string, id:int, qty:float, unit:string, delivery_date:?string, reference:string}>
     */
    public function quellen(Team $team, int $id, ?string $liefertag = null, array $mengen = []): array
    {
        $v = $this->detail($team, $id);
        $tag = $liefertag !== null && $liefertag !== '' ? Carbon::parse($liefertag)->toDateString() : null;
        $out = [];
        foreach ($v->lines as $l) {
            $q = $l->alsQuelle();
            if (array_key_exists($l->id, $mengen) && $mengen[$l->id] !== null && $mengen[$l->id] !== '') {
                $q['qty'] = (float) str_replace(',', '.', (string) $mengen[$l->id]);
            }
            if ($q['qty'] <= 0 || $q['id'] <= 0) {
                continue;
            }
            $out[] = $q + ['delivery_date' => $tag, 'reference' => 'Vorlage ' . $v->name];
        }

        return $out;
    }

    /** Vorschau: was würde bei welchem Lieferanten bestellt (gleiche Rechnung wie die Bestellrunde). */
    public function vorschau(Team $team, int $id, ?string $liefertag = null, array $mengen = [], ?string $strategie = null): array
    {
        $quellen = $this->quellen($team, $id, $liefertag, $mengen);

        return $quellen === [] ? ['orders_preview' => [], 'unresolved' => [], 'warnings' => []]
            : $this->orders->previewFromSources($team, $quellen, $strategie);
    }

    /**
     * Anwenden: Entwürfe je Lieferant zum Liefertag anlegen (über die Bestellrunde).
     *
     * @return array{orders: list<int>, unresolved: array, warnings: array}
     */
    public function anwenden(Team $team, int $id, ?string $liefertag = null, array $mengen = [], ?string $strategie = null, ?int $userId = null): array
    {
        $quellen = $this->quellen($team, $id, $liefertag, $mengen);
        if ($quellen === []) {
            throw new \RuntimeException('Die Vorlage hat keine Positionen mit Menge.');
        }
        $r = $this->orders->generateDraftsFromSources($team, $quellen, $strategie, $userId);
        if (! empty($r['orders'])) {
            FoodAlchemistOrderTemplate::whereKey($id)->update(['last_used_at' => now()]);
        }

        return ['orders' => $r['orders'] ?? [], 'unresolved' => $r['unresolved'] ?? [], 'warnings' => $r['warnings'] ?? []];
    }

    /**
     * Aus Quellen einer Bestellrunde speichern (Cockpit-Arbeitsstand).
     *
     * @param  list<array{type:string, id:int|string, qty?:mixed, unit?:?string}>  $quellen
     */
    public function ausQuellen(Team $team, string $name, array $quellen, ?int $userId = null): FoodAlchemistOrderTemplate
    {
        $quellen = array_values(array_filter($quellen, fn ($q) => in_array($q['type'] ?? '', FoodAlchemistOrderTemplateLine::TYPEN, true)));
        if ($quellen === []) {
            throw new \RuntimeException('Keine übernehmbaren Positionen (Grundprodukt, Rezept oder Artikel).');
        }

        return DB::transaction(function () use ($team, $name, $quellen, $userId) {
            $v = $this->anlegen($team, ['name' => $name], $userId);
            foreach ($quellen as $q) {
                $this->positionSetzen($team, $v->id, (string) $q['type'], (int) $q['id'], $q['qty'] ?? 1, $q['unit'] ?? null);
            }

            return $v->refresh();
        });
    }

    /**
     * Aus einer Bestellung: Zeilen mit Grundprodukt → GP-Position (Menge = Gebinde × Inhalt),
     * Zeilen ohne Grundprodukt → fester Artikel in Gebinden.
     */
    public function ausBestellung(Team $team, int $orderId, string $name, ?int $userId = null): FoodAlchemistOrderTemplate
    {
        $order = FoodAlchemistOrder::where('team_id', $team->id)->with('lines.supplierItem:id,qty,unit_code')->findOrFail($orderId);
        $quellen = [];
        foreach ($order->lines as $l) {
            $packs = (float) $l->qty_packs;
            if ($packs <= 0 || $l->supplier_item_id === null) {
                continue;
            }
            $inhalt = $this->inhalt((float) ($l->supplierItem?->qty ?? $l->pack_qty ?? 0), (string) ($l->supplierItem?->unit_code ?? $l->unit_code));
            if ($l->gp_id !== null && $inhalt !== null) {
                $quellen[] = ['type' => 'gp', 'id' => (int) $l->gp_id, 'qty' => round($packs * $inhalt[0], 3), 'unit' => $inhalt[1]];
            } else {
                $quellen[] = ['type' => 'supplier_item', 'id' => (int) $l->supplier_item_id, 'qty' => $packs, 'unit' => 'gebinde'];
            }
        }
        if ($quellen === []) {
            throw new \RuntimeException('Die Bestellung hat keine Positionen mit Menge.');
        }
        $v = $this->ausQuellen($team, $name, $quellen, $userId);
        if ($order->reference) {
            $v->update(['note' => 'aus Bestellung „' . $order->reference . '"']);
        }

        return $v;
    }

    // ── intern ──────────────────────────────────────────────────────────────

    /** Gebinde-Inhalt als [Menge, Einheit der GP-Position] (kg|stk). l zählt wie kg (Bestellrunde rechnet 1 l = 1.000 g). */
    private function inhalt(float $qty, string $unit): ?array
    {
        if ($qty <= 0) {
            return null;
        }

        return match (mb_strtolower(trim($unit))) {
            'kg', 'l' => [$qty, 'kg'],
            'g', 'ml' => [$qty / 1000, 'kg'],
            'stk', 'st', 'stück' => [$qty, 'stk'],
            default => null,
        };
    }

    private function einheit(string $typ, ?string $einheit, int $bezugId): string
    {
        $erlaubt = FoodAlchemistOrderTemplateLine::EINHEITEN[$typ];
        if ($einheit === null || $einheit === '') {
            if ($typ === 'recipe') {
                return FoodAlchemistRecipe::whereKey($bezugId)->value('is_sales_recipe') ? 'portions' : 'ansaetze';
            }

            return array_key_first($erlaubt);
        }
        if (! array_key_exists($einheit, $erlaubt)) {
            throw new \RuntimeException('Einheit „' . $einheit . '" passt nicht — erlaubt: ' . implode(', ', array_keys($erlaubt)) . '.');
        }

        return $einheit;
    }

    private function menge(mixed $menge): float
    {
        $roh = trim(str_replace(',', '.', (string) ($menge ?? '')));
        if ($roh === '' || ! is_numeric($roh) || (float) $roh < 0) {
            throw new \RuntimeException('Menge braucht eine Zahl ≥ 0.');
        }

        return round((float) $roh, 3);
    }

    private function name(mixed $name): string
    {
        $name = trim((string) $name);
        if ($name === '') {
            throw new \RuntimeException('Die Vorlage braucht einen Namen.');
        }

        return mb_substr($name, 0, 120);
    }

    private function text(mixed $t): ?string
    {
        $t = trim((string) ($t ?? ''));

        return $t !== '' ? mb_substr($t, 0, 2000) : null;
    }

    private function wochentag(mixed $w): ?int
    {
        if ($w === null || $w === '') {
            return null;
        }
        $w = (int) $w;
        if ($w < 1 || $w > 7) {
            throw new \RuntimeException('Bestelltag muss 1 (Montag) bis 7 (Sonntag) sein.');
        }

        return $w;
    }
}
