<?php

namespace Platform\FoodAlchemist\Livewire\Suppliers;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithFileUploads;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Models\FoodAlchemistProduktpass;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\Produktpass\ProduktdatenQuelle;
use Platform\FoodAlchemist\Services\Produktpass\ProduktpassService;
use Platform\FoodAlchemist\Support\Curate;
use Platform\FoodAlchemist\Support\ProduktpassFelder;
use RuntimeException;

/**
 * Reiter „Produktpass" im Artikel-Modal: PDF/Foto hochladen, Versionen, was die KI gelesen hat (Feld · Wert · Konfidenz ·
 * Quelle), Übernahme per Klick und Allergen-Bestätigung. Dieselbe Oberfläche zeigt Vorschläge aus der Websuche
 * (Quelle `websuche`), sobald eine solche Quelle im Container-Tag `produktdaten.quellen` hängt.
 *
 * Rechte wie beim Bearbeiten (Curate → isOwnedBy): Master an globalen Stamm-Artikeln, Mandant an eigenen. Wer den Artikel
 * sieht, sieht auch den Produktpass (lesen/herunterladen).
 */
class ProduktpassPanel extends Component
{
    use WithFileUploads;

    public int $itemId;

    public $datei = null;

    public ?int $angezeigtId = null;

    public ?string $fehler = null;

    public ?string $hinweis = null;

    /** Zuletzt gesehener Status der angezeigten Version — wechselt er auf „gelesen", lädt das Artikel-Modal neu. */
    public ?string $letzterStatus = null;

    public function hochladen(): void
    {
        $this->reset(['fehler', 'hinweis']);
        try {
            $this->validate(['datei' => 'required|file|max:'.FoodAlchemistProduktpass::MAX_KB], ['datei.required' => 'Bitte zuerst eine Datei wählen.']);
            $pass = app(ProduktpassService::class)->hochladen($this->team(), $this->item(), $this->datei, Auth::id());
            $this->angezeigtId = $pass->id;
            $this->datei = null;
            $this->hinweis = 'Hochgeladen. Die KI liest den Produktpass jetzt, das dauert einen Moment.';
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->fehler = collect($e->errors())->flatten()->first();
        } catch (FaRechtFehltException|RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function anzeigen(int $id): void
    {
        $this->angezeigtId = $id;
    }

    public function erneutLesen(int $id): void
    {
        $this->aktion(fn (ProduktpassService $s, FoodAlchemistProduktpass $p) => $s->erneutLesen($this->team(), $p, Auth::id()), $id, 'Wird noch einmal gelesen.');
    }

    public function verwerfen(int $id): void
    {
        $this->aktion(fn (ProduktpassService $s, FoodAlchemistProduktpass $p) => $s->verwerfen($this->team(), $p, Auth::id()), $id, 'Version verworfen. Bereits übernommene Werte bleiben stehen.');
    }

    public function feldUebernehmen(string $key): void
    {
        $this->aktion(fn (ProduktpassService $s, FoodAlchemistProduktpass $p) => $s->feldUebernehmen($this->team(), $p, $key, Auth::id()), $this->angezeigtId, 'Feld übernommen.');
    }

    public function versionUebernehmen(): void
    {
        $this->aktion(fn (ProduktpassService $s, FoodAlchemistProduktpass $p) => $s->versionUebernehmen($this->team(), $p, Auth::id()), $this->angezeigtId, 'Version übernommen.');
    }

    public function allergeneBestaetigen(): void
    {
        $this->aktion(fn (ProduktpassService $s, FoodAlchemistProduktpass $p) => $s->allergeneBestaetigen($this->team(), $p, Auth::id()), $this->angezeigtId, 'Allergene bestätigt und eingetragen.');
    }

    public function allergeneVerwerfen(): void
    {
        $this->aktion(fn (ProduktpassService $s, FoodAlchemistProduktpass $p) => $s->allergeneVerwerfen($this->team(), $p, Auth::id()), $this->angezeigtId, 'Allergen-Vorschlag verworfen. Der bisherige Stand gilt.');
    }

    /** „Recherchieren" — nur, wenn eine Quelle `websuche` angebunden ist (Container-Tag `produktdaten.quellen`). */
    public function recherchieren(): void
    {
        $this->reset(['fehler', 'hinweis']);
        $quelle = $this->webQuelle();
        if ($quelle === null) {
            $this->fehler = 'Die Websuche ist nicht eingerichtet.';

            return;
        }
        try {
            $pass = app(ProduktpassService::class)->vorschlagAusQuelle($this->team(), $this->item(), $quelle, [], Auth::id());
            $this->angezeigtId = $pass->id;
            $this->hinweis = 'Vorschlag aus der Websuche angelegt. Übernommen wird erst per Klick.';
        } catch (FaRechtFehltException|RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function render()
    {
        $item = $this->item();
        $svc = app(ProduktpassService::class);
        $pass = $this->angezeigtId !== null ? FoodAlchemistProduktpass::where('supplier_item_id', $item->id)->find($this->angezeigtId) : null;
        $stand = $svc->stand($item, $pass);
        $angezeigt = $stand['angezeigt'];
        $laeuft = collect($stand['versionen'])->contains(fn ($v) => in_array($v['status'], ['hochgeladen', 'liest'], true));
        // Wechsel auf „gelesen": Felder wurden evtl. direkt geschrieben → das Artikel-Modal lädt seine Formulare neu
        $aktuellerStatus = $angezeigt['status'] ?? null;
        if ($this->letzterStatus !== null && $this->letzterStatus !== 'gelesen' && $aktuellerStatus === 'gelesen') {
            $this->dispatch('produktpass.geaendert', itemId: $item->id);
        }
        $this->letzterStatus = $aktuellerStatus;

        return view('foodalchemist::livewire.suppliers.produktpass-panel', [
            'item' => $item,
            'darfEdit' => Curate::canCurate(Auth::user(), $item),
            'stand' => $stand,
            'laeuft' => $laeuft,
            'schwelle' => ProduktpassFelder::KONFIDENZ_DIREKT,
            'webQuelleDa' => $this->webQuelle() !== null,
            'downloadUrls' => collect($stand['versionen'])->mapWithKeys(fn ($v) => [$v['id'] => $v['datei'] !== null
                ? $svc->downloadUrl(FoodAlchemistProduktpass::find($v['id'])) : null])->all(),
        ]);
    }

    private function aktion(callable $tat, ?int $passId, string $meldung): void
    {
        $this->reset(['fehler', 'hinweis']);
        try {
            $pass = FoodAlchemistProduktpass::where('supplier_item_id', $this->itemId)->findOrFail((int) $passId);
            $tat(app(ProduktpassService::class), $pass);
            $this->hinweis = $meldung;
            $this->dispatch('produktpass.geaendert', itemId: $this->itemId);
        } catch (FaRechtFehltException|RuntimeException $e) {
            $this->fehler = $e->getMessage();
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            $this->fehler = 'Diese Version gibt es nicht mehr.';
        }
    }

    private function webQuelle(): ?ProduktdatenQuelle
    {
        try {
            foreach (app()->tagged('produktdaten.quellen') as $q) {
                if ($q instanceof ProduktdatenQuelle && $q->name() === ProduktpassFelder::QUELLE_WEB) {
                    return $q;
                }
            }
        } catch (\Throwable) {
        }

        return null;
    }

    private function item(): FoodAlchemistSupplierItem
    {
        return FoodAlchemistSupplierItem::visibleToTeam($this->team())->findOrFail($this->itemId);
    }

    private function team(): \Platform\Core\Models\Team
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null) {
            throw new RuntimeException('Kein Team gewählt.');
        }

        return $team;
    }
}
