<?php

namespace Platform\FoodAlchemist\Livewire\Settings;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;
use Platform\FoodAlchemist\Services\OutletSettingsService;

/**
 * Spec 33 · P2 — Pflege der Betriebe/Standorte (Outlets).
 *
 * **Warum das hier entsteht.** `foodalchemist_outlets` gibt es seit Spec 19, das Model sagt
 * ausdrücklich „team-pflegbar über die Einstellungen" — nur wurde diese Oberfläche nie gebaut.
 * Ergebnis: die Tabelle war leer (0 Datensätze im Dev-Bestand), das Feld `outlet_id` an der
 * Speisekarte hatte nicht einmal ein Eingabefeld, und die Achse war damit tot.
 *
 * Mit Spec 33 trägt der Betrieb die **Betriebsbrille** der Portfolio-Übersicht — ohne gepflegte
 * Outlets bleibt sie leer. Die Pflege ist deshalb keine Zugabe, sondern Voraussetzung.
 *
 * Bewusst schlank gehalten (Muster {@see Einsatzorte}): Name, Farbe fürs Tag-Rendering,
 * Reihenfolge, aktiv/inaktiv. **Keine Hierarchie** (Region → Betrieb → Küche) — eine Ebene
 * darüber ist ein eigenes Vorhaben und würde hier nur halb entstehen.
 *
 * **Löschen ist bewusst nicht drin**: ein Outlet hängt an Ausgaben und Kapiteln. Statt einer
 * Kaskade, die stillschweigend Zuordnungen kappt, wird inaktiv geschaltet — dieselbe
 * Zurückhaltung wie beim Ausgabe-Status.
 */
class Betriebe extends Component
{
    public ?int $editId = null;

    /** @var array{name:string,color:string,sort_order:int|string} */
    public array $form = ['name' => '', 'color' => '', 'sort_order' => 100];

    /** Ebene 2: Kalkulations-Overrides des bearbeiteten Betriebs (leer = erbt vom Team). */
    public array $overrides = [
        'margin_pct' => '', 'target_food_cost_pct' => '', 'stundensatz_eur' => '',
        'hk2_surcharge_pct' => '', 'labor_overhead_pct' => '',
    ];

    public string $neuName = '';

    public ?string $fehler = null;

    private function team(): ?Team
    {
        return Auth::user()?->currentTeamRelation;
    }

    public function anlegen(): void
    {
        $this->fehler = null;
        $team = $this->team();
        $name = trim($this->neuName);
        if ($team === null || $name === '') {
            return;
        }

        // Unique ist (team_id, name) — ohne Vorprüfung liefe der Nutzer in einen SQL-Fehler.
        if (FoodAlchemistOutlet::where('team_id', $team->id)->where('name', $name)->exists()) {
            $this->fehler = 'Es gibt bereits einen Betrieb mit diesem Namen.';

            return;
        }

        FoodAlchemistOutlet::create([
            'team_id' => $team->id,
            'name' => $name,
            'sort_order' => (int) (FoodAlchemistOutlet::where('team_id', $team->id)->max('sort_order') ?? 0) + 10,
        ]);
        $this->neuName = '';
    }

    public function edit(int $id): void
    {
        $o = $this->eigenes($id);
        if ($o === null) {
            return;
        }
        $this->editId = $id;
        $this->form = [
            'name' => (string) $o->name,
            'color' => (string) ($o->color ?? ''),
            'sort_order' => (int) $o->sort_order,
        ];
        $s = app(OutletSettingsService::class)->for($o);
        foreach (array_keys($this->overrides) as $k) {
            $this->overrides[$k] = $s->{$k} !== null ? (string) $s->{$k} : '';
        }
    }

    public function abbrechen(): void
    {
        $this->reset('editId', 'form', 'fehler', 'overrides');
    }

    public function speichern(): void
    {
        $this->fehler = null;
        $o = $this->editId !== null ? $this->eigenes($this->editId) : null;
        $name = trim((string) ($this->form['name'] ?? ''));
        if ($o === null || $name === '') {
            return;
        }

        $team = $this->team();
        if (FoodAlchemistOutlet::where('team_id', $team->id)->where('name', $name)
            ->where('id', '!=', $o->id)->exists()) {
            $this->fehler = 'Es gibt bereits einen Betrieb mit diesem Namen.';

            return;
        }

        $farbe = trim((string) ($this->form['color'] ?? ''));
        $o->update([
            'name' => $name,
            // Nur echtes Hex durchlassen — die Farbe landet direkt im Style-Attribut.
            'color' => preg_match('/^#[0-9a-fA-F]{6}$/', $farbe) === 1 ? $farbe : null,
            'sort_order' => max(0, (int) ($this->form['sort_order'] ?? 100)),
        ]);

        // Ebene 2: Kalkulations-Overrides des Betriebs (leer = zurück auf Team-Erbe).
        $clean = [];
        foreach (array_keys($this->overrides) as $k) {
            $roh = trim((string) ($this->overrides[$k] ?? ''));
            $clean[$k] = $roh === '' ? null : max(0.0, (float) str_replace(',', '.', $roh));
        }
        app(OutletSettingsService::class)->update($team, $o, $clean);

        $this->abbrechen();
    }

    /** Aktiv/inaktiv statt löschen — an einem Outlet hängen Ausgaben und Kapitel. */
    public function aktivUmschalten(int $id): void
    {
        $o = $this->eigenes($id);
        $o?->update(['is_inactive' => ! $o->is_inactive]);
    }

    /** Nur team-EIGENE Outlets sind änderbar (Vokabular gehört dem Team, das es pflegt). */
    private function eigenes(int $id): ?FoodAlchemistOutlet
    {
        $team = $this->team();

        return $team === null ? null : FoodAlchemistOutlet::where('team_id', $team->id)->find($id);
    }

    public function render()
    {
        $team = $this->team();
        $betriebe = $team === null
            ? collect()
            : FoodAlchemistOutlet::where('team_id', $team->id)
                ->orderBy('sort_order')->orderBy('name')->get();

        // Wo wird der Betrieb schon benutzt? Zeigt, was eine Deaktivierung berührt.
        $ids = $betriebe->pluck('id');
        $nutzung = [];
        foreach ([
            'foodalchemist_menu_cards' => 'Speisekarten',
            'foodalchemist_menu_plans' => 'Speisepläne',
            'foodalchemist_foodbooks' => 'Foodbooks',
            'foodalchemist_foodbook_chapters' => 'Kapitel',
        ] as $tabelle => $klartext) {
            if ($ids->isEmpty()) {
                break;
            }
            foreach (DB::table($tabelle)->whereIn('outlet_id', $ids)->whereNull('deleted_at')
                ->selectRaw('outlet_id, COUNT(*) as n')->groupBy('outlet_id')->get() as $r) {
                $nutzung[(int) $r->outlet_id][$klartext] = (int) $r->n;
            }
        }

        return view('foodalchemist::livewire.settings.betriebe', [
            'betriebe' => $betriebe,
            'nutzung' => $nutzung,
        ]);
    }
}
