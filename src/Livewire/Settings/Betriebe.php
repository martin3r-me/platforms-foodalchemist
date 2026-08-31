<?php

namespace Platform\FoodAlchemist\Livewire\Settings;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\WithFileUploads;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;
use Platform\FoodAlchemist\Services\FoodAlchemistMediaService;

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
    use WithFileUploads;

    public ?int $editId = null;

    /** @var array{name:string,color:string,sort_order:int|string,vorlage:string} */
    public array $form = ['name' => '', 'color' => '', 'sort_order' => 100, 'vorlage' => ''];

    /** Präsentations-Logo je Betrieb (temporärer Upload) — ersetzt beim Betriebs-Link das Dokument-Logo. */
    public $logoUpload = null;

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
            'vorlage' => (string) ($o->presentation_design ?? ''),
        ];
    }

    public function abbrechen(): void
    {
        $this->reset('editId', 'form', 'fehler', 'logoUpload');
    }

    /** Betriebs-Logo hochladen (sofort beim Auswählen) — ersetzt beim Betriebs-Link das Dokument-Logo. */
    public function updatedLogoUpload(): void
    {
        $this->fehler = null;
        $o = $this->editId !== null ? $this->eigenes($this->editId) : null;
        $team = $this->team();
        if ($o === null || $team === null || $this->logoUpload === null) {
            return;
        }
        $this->validate(['logoUpload' => 'image|max:4096'], ['logoUpload.image' => 'Bitte eine Bilddatei wählen.', 'logoUpload.max' => 'Max. 4 MB.']);

        $media = app(FoodAlchemistMediaService::class);
        $media->delete($o->logo_context_file_id, (string) $o->logo_path, $team);   // altes Logo weg
        $res = $media->storeImage($this->logoUpload, $team, 'foodalchemist.outlet', $o->id, "foodalchemist/outlet-logo/{$o->id}");
        $o->update(['logo_path' => $res['path'], 'logo_context_file_id' => $res['context_file_id']]);
        $this->logoUpload = null;
    }

    /** Betriebs-Logo entfernen → beim Betriebs-Link greift wieder das Dokument-Logo. */
    public function logoLoeschen(): void
    {
        $o = $this->editId !== null ? $this->eigenes($this->editId) : null;
        $team = $this->team();
        if ($o === null || $team === null) {
            return;
        }
        app(FoodAlchemistMediaService::class)->delete($o->logo_context_file_id, (string) $o->logo_path, $team);
        $o->update(['logo_path' => null, 'logo_context_file_id' => null]);
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
            // Slice F: Präsentations-Vorlage je Betrieb (leer = Dokument-Vorlage beim Betriebs-Link).
            'presentation_design' => ($v = trim((string) ($this->form['vorlage'] ?? ''))) !== '' ? $v : null,
        ]);

        // Ebene 2: Kosten-Overrides je Betrieb wohnen jetzt in „Herstellkosten & Zuschläge"
        // (Team/Betrieb-Wähler) — hier nur noch Identität, Farbe, Reihenfolge, Präsentations-Vorlage.
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

        // Slice F: Präsentations-Vorlagen (Built-ins + team-eigene Designs) für die „Vorlage je Betrieb"-Wahl.
        $vorlagenOptionen = $team === null ? []
            : app(\Platform\FoodAlchemist\Services\PresentationDesignService::class)->pickerOptions($team, 'foodbook');

        // Betriebs-Logo-Vorschau (outlet_id → URL), für die Edit-Zeile.
        $media = app(FoodAlchemistMediaService::class);
        $logoUrls = [];
        foreach ($betriebe as $o) {
            if (($o->logo_context_file_id ?? null) || ($o->logo_path ?? null)) {
                $logoUrls[(int) $o->id] = $media->url($o->logo_context_file_id, $o->logo_path);
            }
        }

        return view('foodalchemist::livewire.settings.betriebe', [
            'betriebe' => $betriebe,
            'nutzung' => $nutzung,
            'vorlagenOptionen' => $vorlagenOptionen,
            'logoUrls' => $logoUrls,
        ]);
    }
}
