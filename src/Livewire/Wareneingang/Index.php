<?php

namespace Platform\FoodAlchemist\Livewire\Wareneingang;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Models\FoodAlchemistDeliveryNote;
use Platform\FoodAlchemist\Models\FoodAlchemistDeliveryNoteLine;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistInventoryLocation;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Services\FaRechte;
use Platform\FoodAlchemist\Services\WareneingangService;

/**
 * Spec 75a · Einkauf → Wareneingang. Reiter „Erwartet" (offene Lieferungen nach Liefertag) und
 * „Lieferscheine" (alle Belege). Erfassen belegt alle offenen Positionen des Lieferanten vor —
 * über alle offenen Bestellungen, gruppiert je Bestellung. Rechte prüft der Service (Spec 61);
 * die Oberfläche blendet Schreib-Knöpfe nur zusätzlich aus.
 */
class Index extends Component
{
    use WithFileUploads;

    #[Url(as: 'reiter')]
    public string $reiter = 'erwartet';

    // ── Erfassen ──
    public bool $formOffen = false;

    public ?int $formId = null;

    /** @var array{supplier_id: ?int, nummer: string, datum: string, notiz: string, location_id: ?int} */
    public array $form = ['supplier_id' => null, 'nummer' => '', 'datum' => '', 'notiz' => '', 'location_id' => null];

    /** @var array<int, array<string,mixed>> je order_line_id: Vorschlag + qty/grund/note */
    public array $zeilen = [];

    /** @var list<array{gp_id:int, name:string, menge:string, note:string}> */
    public array $ohne = [];

    /** @var array<int, bool> order_id => abschließen */
    public array $abschliessen = [];

    public string $gpSuche = '';

    public $anhang = null;

    // ── Liste ──
    public ?int $fLieferant = null;

    public string $fStatus = '';

    public string $fSuche = '';

    public bool $fAbweichung = false;

    public ?int $offenId = null;

    public ?string $fehler = null;

    public ?string $hinweis = null;

    public function mount(): void
    {
        $this->formZuruecksetzen();
    }

    public function reiterSetzen(string $reiter): void
    {
        $this->reiter = in_array($reiter, ['erwartet', 'lieferscheine', 'rechnungen', 'abgleich'], true) ? $reiter : 'erwartet';
    }

    public function neu(): void
    {
        $this->formZuruecksetzen();
        $this->formOffen = true;
    }

    /** Lieferschein zu einem Lieferanten erfassen: alle offenen Positionen vorbelegt. */
    public function erfassen(int $supplierId, WareneingangService $svc): void
    {
        $this->formZuruecksetzen();
        $this->formOffen = true;
        $this->lieferantLaden($supplierId, $svc);
    }

    public function lieferantWaehlen($supplierId, WareneingangService $svc): void
    {
        if ($this->formId !== null) {
            return;
        }
        $this->lieferantLaden($supplierId !== '' && $supplierId !== null ? (int) $supplierId : null, $svc);
    }

    public function bearbeiten(int $id, WareneingangService $svc): void
    {
        $this->aktion(function () use ($id, $svc) {
            $d = $svc->detail($this->team(), $id);
            if ($d['status'] !== FoodAlchemistDeliveryNote::STATUS_ENTWURF) {
                throw new \RuntimeException('Nur Entwürfe lassen sich bearbeiten.');
            }
            $this->formZuruecksetzen();
            $this->formOffen = true;
            $this->formId = $id;
            $this->form = ['supplier_id' => $d['supplier_id'], 'nummer' => (string) $d['delivery_note_number'],
                'datum' => (string) $d['delivered_on'], 'notiz' => (string) $d['note'], 'location_id' => $d['inventory_location_id']];
            $vorschlag = collect($svc->vorbelegen($this->team(), $d['supplier_id']))->keyBy('order_line_id');
            foreach ($d['zeilen'] as $z) {
                if ($z['order_line_id'] !== null) {
                    $basis = $vorschlag->get($z['order_line_id']) ?? ['order_line_id' => $z['order_line_id'], 'order_id' => $z['order_id'], 'nummer' => $z['nummer'],
                        'reference' => null, 'designation' => $z['designation'], 'article_number' => null, 'packaging_unit' => $z['packaging_unit'],
                        'bestellt' => $z['bestellt'], 'bisher' => null, 'offen' => $z['erwartet'], 'pack_price' => $z['pack_price']];
                    $this->zeilen[$z['order_line_id']] = $basis + ['an' => true, 'qty' => $this->zahlText($z['qty_packs']), 'grund' => (string) $z['abweichung_grund'], 'note' => (string) $z['note']];
                } else {
                    $this->ohne[] = ['gp_id' => (int) $z['gp_id'], 'name' => $z['designation'], 'menge' => $this->zahlText($z['menge']), 'note' => (string) $z['note']];
                }
            }
            foreach ($vorschlag as $olId => $v) {
                $this->zeilen[$olId] ??= $v + ['an' => false, 'qty' => $this->zahlText($v['offen']), 'grund' => '', 'note' => ''];
            }
            $this->abschlussVorschlag();
        });
    }

    public function allesWieBestellt(): void
    {
        foreach ($this->zeilen as $id => $z) {
            $this->zeilen[$id]['an'] = true;
            $this->zeilen[$id]['qty'] = $this->zahlText($z['offen']);
            $this->zeilen[$id]['grund'] = '';
        }
        $this->abschlussVorschlag();
    }

    public function updatedZeilen(): void
    {
        $this->abschlussVorschlag();
    }

    public function ohneHinzu(int $gpId): void
    {
        $gp = FoodAlchemistGp::visibleToTeam($this->team())->find($gpId);
        if ($gp !== null) {
            $this->ohne[] = ['gp_id' => (int) $gp->id, 'name' => (string) $gp->name, 'menge' => '', 'note' => ''];
        }
        $this->gpSuche = '';
    }

    public function ohneEntfernen(int $i): void
    {
        unset($this->ohne[$i]);
        $this->ohne = array_values($this->ohne);
    }

    public function anhangVerwerfen(): void
    {
        $this->anhang = null;
    }

    public function speichern(WareneingangService $svc): void
    {
        $this->aktion(function () use ($svc) {
            $this->schreiben($svc);
            $this->hinweis = 'Lieferschein als Entwurf gespeichert.';
        });
    }

    public function buchen(WareneingangService $svc): void
    {
        $this->aktion(function () use ($svc) {
            $id = $this->schreiben($svc);
            $abschluss = array_keys(array_filter($this->abschliessen));
            $svc->buchen($this->team(), $id, Auth::id(), array_map('intval', $abschluss));
            $this->hinweis = 'Lieferschein gebucht'.($abschluss !== [] ? ', '.count($abschluss).' Bestellung(en) abgeschlossen.' : '.');
            $this->formZuruecksetzen();
            $this->reiter = 'lieferscheine';
            $this->offenId = $id;
        });
    }

    public function abbrechen(): void
    {
        $this->formZuruecksetzen();
    }

    public function stornieren(int $id, WareneingangService $svc): void
    {
        $this->aktion(function () use ($id, $svc) {
            $svc->stornieren($this->team(), $id, Auth::id());
            $this->hinweis = 'Lieferschein storniert — Wareneingang und Lager sind zurückgebucht.';
        });
    }

    public function loeschen(int $id, WareneingangService $svc): void
    {
        $this->aktion(function () use ($id, $svc) {
            $svc->loeschen($this->team(), $id, Auth::id());
            $this->hinweis = 'Entwurf gelöscht.';
            if ($this->formId === $id) {
                $this->formZuruecksetzen();
            }
        });
    }

    public function nachlieferung(int $orderId, WareneingangService $svc): void
    {
        $this->aktion(function () use ($orderId, $svc) {
            $r = $svc->nachlieferung($this->team(), $orderId, null, Auth::id());
            $this->hinweis = "Bestellung ord-{$orderId} abgeschlossen. Nachlieferung ord-{$r['order_id']} als Entwurf angelegt ({$r['lines']} Position(en)) — bitte unter Bestellungen prüfen und senden.";
        });
    }

    public function anhangEntfernen(int $id, WareneingangService $svc): void
    {
        $this->aktion(fn () => $svc->anhangEntfernen($this->team(), $id, Auth::id()));
    }

    public function umschalten(int $id): void
    {
        $this->offenId = $this->offenId === $id ? null : $id;
    }

    public function render(WareneingangService $svc, FaRechte $rechte)
    {
        $team = $this->team();
        $erwartet = $svc->erwarteteLieferungen($team);
        $liste = $this->reiter === 'lieferscheine' ? $svc->liste($team, [
            'supplier_id' => $this->fLieferant, 'status' => $this->fStatus, 'suche' => $this->fSuche, 'nur_abweichung' => $this->fAbweichung,
        ]) : [];
        $detail = null;
        if ($this->reiter === 'lieferscheine' && $this->offenId !== null) {
            try {
                $detail = $svc->detail($team, $this->offenId);
                $detail['anhang_url'] = $detail['anhang'] !== null ? $svc->anhangUrl($team, $this->offenId) : null;
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
                $this->offenId = null;
            }
        }
        $gpTreffer = collect();
        if ($this->formOffen && mb_strlen(trim($this->gpSuche)) >= 2) {
            $gpTreffer = FoodAlchemistGp::visibleToTeam($team)->where('name', 'like', '%'.trim($this->gpSuche).'%')
                ->whereNotIn('status', ['merged', 'rejected'])->orderBy('name')->limit(10)->get(['id', 'name']);
        }
        $heute = collect($erwartet)->where('heute', true)->count();
        $ueberfaellig = collect($erwartet)->where('ueberfaellig', true)->count();
        $entwuerfe = FoodAlchemistDeliveryNote::where('team_id', $team->id)->where('status', FoodAlchemistDeliveryNote::STATUS_ENTWURF)->count();

        return view('foodalchemist::livewire.wareneingang.index', [
            'erwartet' => collect($erwartet)->groupBy(fn ($r) => $r['liefertag'] ?? 'ohne'),
            'liste' => $liste,
            'detail' => $detail,
            'lieferanten' => FoodAlchemistSupplier::visibleToTeam($team)->orderBy('name')->get(['id', 'name']),
            'orte' => FoodAlchemistInventoryLocation::where('team_id', $team->id)->where('is_active', true)->orderByDesc('is_default')->orderBy('name')->get(['id', 'name']),
            'gpTreffer' => $gpTreffer,
            'gruende' => FoodAlchemistDeliveryNoteLine::GRUENDE,
            'statusLabels' => FoodAlchemistDeliveryNote::STATUS_LABELS,
            'gruppen' => collect($this->zeilen)->groupBy('order_id'),
            'darf' => $rechte->darf(Auth::user(), $team, FaRolle::Kuratieren),
            'meineRolle' => $rechte->rolle(Auth::user(), $team),
            'kpis' => [
                ['label' => 'Heute erwartet', 'value' => $heute, 'primary' => true, 'kpi' => 'heute'],
                ['label' => 'Überfällig', 'value' => $ueberfaellig, 'tone' => $ueberfaellig > 0 ? 'warn' : null, 'kpi' => 'ueberfaellig'],
                ['label' => 'Offene Lieferungen', 'value' => count($erwartet), 'kpi' => 'offen'],
                ['label' => 'Entwürfe', 'value' => $entwuerfe, 'kpi' => 'entwuerfe'],
            ],
        ])->layout(\Platform\FoodAlchemist\Support\FaShell::layout());
    }

    // ── intern ──

    private function lieferantLaden(?int $supplierId, WareneingangService $svc): void
    {
        $this->form['supplier_id'] = $supplierId;
        $this->zeilen = [];
        $this->abschliessen = [];
        if ($supplierId === null) {
            return;
        }
        foreach ($svc->vorbelegen($this->team(), $supplierId) as $v) {
            $this->zeilen[$v['order_line_id']] = $v + ['an' => true, 'qty' => $this->zahlText($v['offen']), 'grund' => '', 'note' => ''];
        }
        $this->abschlussVorschlag();
    }

    /** Vorschlag: Bestellung abschließen, wenn jede ihrer offenen Positionen vollständig auf dem Lieferschein steht. */
    private function abschlussVorschlag(): void
    {
        $neu = [];
        foreach (collect($this->zeilen)->groupBy('order_id') as $orderId => $rows) {
            $neu[(int) $orderId] = $rows->every(fn ($z) => ! empty($z['an']) && ($this->zahl($z['qty']) ?? 0) + 0.0001 >= (float) $z['offen']);
        }
        $this->abschliessen = $neu;
    }

    private function schreiben(WareneingangService $svc): int
    {
        $lines = [];
        foreach ($this->zeilen as $olId => $z) {
            if (empty($z['an'])) {
                continue;
            }
            $lines[] = ['order_line_id' => (int) $olId, 'qty_packs' => $z['qty'], 'abweichung_grund' => $z['grund'] ?: null, 'note' => $z['note']];
        }
        foreach ($this->ohne as $o) {
            $lines[] = ['gp_id' => $o['gp_id'], 'menge' => $o['menge'], 'designation' => $o['name'], 'note' => $o['note']];
        }
        if ($lines === []) {
            throw new \RuntimeException('Bitte mindestens eine Position auf den Lieferschein nehmen.');
        }
        $in = ['supplier_id' => $this->form['supplier_id'], 'delivery_note_number' => $this->form['nummer'],
            'delivered_on' => $this->form['datum'] ?: null, 'note' => $this->form['notiz'],
            'inventory_location_id' => $this->form['location_id'] ?: null, 'lines' => $lines];
        $note = $svc->speichern($this->team(), $in, Auth::id(), $this->formId);
        $this->formId = (int) $note->id;
        if ($this->anhang !== null) {
            $svc->anhangSpeichern($this->team(), $this->formId, $this->anhang, Auth::id());
            $this->anhang = null;
        }

        return $this->formId;
    }

    private function formZuruecksetzen(): void
    {
        $this->formOffen = false;
        $this->formId = null;
        $this->form = ['supplier_id' => null, 'nummer' => '', 'datum' => now()->toDateString(), 'notiz' => '', 'location_id' => null];
        $this->zeilen = [];
        $this->ohne = [];
        $this->abschliessen = [];
        $this->gpSuche = '';
        $this->anhang = null;
    }

    private function aktion(callable $tu): void
    {
        $this->fehler = null;
        $this->hinweis = null;
        try {
            $tu();
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            $this->fehler = 'Nicht gefunden — bitte Seite neu laden.';
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    private function zahl(mixed $v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        $v = str_replace(',', '.', trim((string) $v));

        return is_numeric($v) ? (float) $v : null;
    }

    private function zahlText(mixed $v): string
    {
        return $v === null ? '' : str_replace('.', ',', (string) (0 + (float) $v));
    }

    private function team(): Team
    {
        return Auth::user()?->currentTeamRelation ?? abort(403);
    }
}
