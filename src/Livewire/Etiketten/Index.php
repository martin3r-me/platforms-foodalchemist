<?php

namespace Platform\FoodAlchemist\Livewire\Etiketten;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\EtikettService;

/**
 * Spec 70 · Etikett drucken: Formular links, Live-Vorschau rechts (iframe auf die Druckansicht).
 * Aufruf aus Rezept, Gericht, Grundprodukt und Lager (?quelle=recipe|gp|stellplatz&id=…).
 * Leere Datumsfelder = automatisch (Platzhalter zeigt den berechneten Wert). Spec 76: Lagerarten + Haltbarkeit
 * pflegt man am Rezept; hier wird nur noch aus den erlaubten Lagerarten gewählt.
 */
class Index extends Component
{
    #[Url(as: 'quelle')]
    public string $quelle = '';

    #[Url(as: 'id')]
    public ?int $bezugId = null;

    public $vorlageId = '';

    public int $anzahl = 1;

    public int $startplatz = 1;

    /** Eingaben (leer = automatisch). */
    public array $e = [
        'bezeichnung' => '', 'zusatz' => '', 'menge' => '', 'kuerzel' => '', 'charge' => '', 'lagerung' => '',
        'hergestellt_am' => '', 'eingefroren_am' => '', 'geoeffnet_am' => '', 'verbrauchen_bis' => '',
    ];

    /** Spec 76: Menge als Zahl + Einheit (setzt e.menge, z. B. „2,5 kg"). */
    public string $mengeZahl = '';

    public string $mengeEinheit = 'kg';

    public string $suche = '';

    public string $suchArt = 'recipe';

    public ?string $fehler = null;

    public ?string $hinweis = null;

    public function mount(EtikettService $svc): void
    {
        $this->vorlageId = (string) ($svc->vorlage($this->team(), null)->id);
        // Spec 76: Vorbelegung per Link (z. B. aus der Produktion: hergestellt_am, menge)
        foreach ((array) request('e', []) as $k => $v) {
            if (array_key_exists($k, $this->e) && is_scalar($v)) {
                $this->e[$k] = (string) $v;
            }
        }
        if ($this->e['menge'] !== '' && preg_match('/^([\d.,]+)\s*(kg|g|l|ml|Portionen|Stück)$/u', $this->e['menge'], $m)) {
            [$this->mengeZahl, $this->mengeEinheit] = [$m[1], $m[2]];
        }
    }

    public function updatedMengeZahl(): void
    {
        $this->mengeSetzen();
    }

    public function updatedMengeEinheit(): void
    {
        $this->mengeSetzen();
    }

    private function mengeSetzen(): void
    {
        $zahl = trim(str_replace('.', ',', $this->mengeZahl));
        if ($zahl !== '' && ! is_numeric(str_replace(',', '.', $zahl))) {
            $this->fehler = 'Menge braucht eine Zahl.';

            return;
        }
        $this->fehler = null;
        $this->e['menge'] = $zahl !== '' ? $zahl . ' ' . $this->mengeEinheit : '';
    }

    public function waehlen(string $quelle, int $id): void
    {
        $this->quelle = in_array($quelle, ['recipe', 'gp', 'stellplatz', 'charge'], true) ? $quelle : 'recipe';
        $this->bezugId = $id;
        $this->suche = '';
        $this->e = array_map(fn () => '', $this->e);
        $this->mengeZahl = '';
        // Einheit passend vorbelegen: Gericht → Portionen, sonst kg
        $this->mengeEinheit = $this->quelle === 'recipe' && \Platform\FoodAlchemist\Models\FoodAlchemistRecipe::whereKey($id)->value('is_sales_recipe') ? 'Portionen' : 'kg';
    }

    public function render(EtikettService $svc)
    {
        $team = $this->team();
        $vorlagen = $svc->vorlagen($team);
        $vorlage = $vorlagen->firstWhere('id', (int) $this->vorlageId) ?? $vorlagen->first();
        $format = EtikettService::FORMATE[$vorlage->format] ?? EtikettService::FORMATE['a4_24'];
        $daten = null;
        $url = null;
        $pdfUrl = null;
        if ($this->quelle !== '' && $this->bezugId !== null) {
            try {
                $daten = $svc->daten($team, $this->quelle, $this->bezugId, $this->e, $vorlage);
                $args = [$this->quelle, $this->bezugId, (int) $vorlage->id, $this->e, max(1, $this->anzahl), max(1, $this->startplatz)];
                $url = $svc->druckUrl(...$args);
                $pdfUrl = $svc->druckUrl(...[...$args, true]);
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
                $this->fehler = 'Nicht gefunden.';
            } catch (\Throwable $ex) {
                $this->fehler = $ex->getMessage();
            }
        }
        $treffer = collect();
        if (mb_strlen(trim($this->suche)) >= 2) {
            $q = '%' . trim($this->suche) . '%';
            $treffer = $this->suchArt === 'gp'
                ? FoodAlchemistGp::visibleToTeam($team)->where('name', 'like', $q)->whereNotIn('status', ['merged', 'rejected'])->orderBy('name')->limit(10)->get(['id', 'name'])
                : FoodAlchemistRecipe::visibleToTeam($team)->where('name', 'like', $q)->orderBy('name')->limit(10)->get(['id', 'name', 'is_sales_recipe']);
        }
        $felder = collect($svc->normalisiereFelder((string) $vorlage->typ, $vorlage->felder))->where('an', true)->pluck('key')->all();

        return view('foodalchemist::livewire.etiketten.index', [
            'vorlagen' => $vorlagen, 'vorlage' => $vorlage, 'format' => $format, 'daten' => $daten,
            'url' => $url, 'pdfUrl' => $pdfUrl, 'vorschauUrl' => $url !== null ? $url . (str_contains($url, '?') ? '&' : '?') . 'vorschau=1' : null,
            'treffer' => $treffer, 'felder' => $felder,
            'verlauf' => $svc->verlauf($team, 20),   // Spec 76: Druckprotokoll
        ])->layout(\Platform\FoodAlchemist\Support\FaShell::layout());
    }

    private function team(): Team
    {
        return Auth::user()?->currentTeamRelation ?? abort(403);
    }
}
