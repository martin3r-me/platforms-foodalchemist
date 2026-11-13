<?php

namespace Platform\FoodAlchemist\Livewire\Praesentation;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\KapitelLogoService;

/**
 * Optionales Logo je Kapitel (Präsentation 2026-11-13) — ein Baustein für Foodbook-Kapitel, Angebots-Kapitel und
 * Speisekarten-Rubrik. Eingebettet in den jeweiligen Editor; die Bearbeitungssperre gilt für dessen Datensatz.
 */
class KapitelLogo extends Component
{
    use \Platform\FoodAlchemist\Livewire\Concerns\MitBearbeitungssperre;   // Spec 65: dasselbe Ziel wie der Editor
    use WithFileUploads;

    /** Art → [Sperr-Typ des Editors, Spalte mit dessen Id]. */
    private const ZIELE = ['foodbook' => ['foodbook', 'foodbook_id'], 'angebot' => ['angebot', 'offer_id'], 'speisekarte' => ['speisekarte', 'menu_card_id']];

    #[Locked]
    public string $art = 'foodbook';

    #[Locked]
    public int $kapitelId = 0;

    public $logoUpload = null;

    public ?string $fehler = null;

    protected function sperrZiel(): ?array
    {
        [$typ, $spalte] = self::ZIELE[$this->art] ?? [null, null];
        $klasse = KapitelLogoService::ARTEN[$this->art][0] ?? null;
        $elternId = $klasse !== null ? $klasse::query()->whereKey($this->kapitelId)->value($spalte) : null;

        return $typ !== null && $elternId !== null ? [$typ, (int) $elternId] : null;
    }

    public function updatedLogoUpload(): void
    {
        $this->fehler = null;
        if (self::sperreAktiv() && ! $this->darfSchreiben()) {
            $this->reset('logoUpload');
            $this->sperreAbgewiesen('updatedLogoUpload');

            return;
        }
        if ($this->logoUpload === null) {
            return;
        }
        $this->validate(['logoUpload' => 'image|max:8192'], [], ['logoUpload' => 'Kapitel-Logo']);
        try {
            app(KapitelLogoService::class)->setzen($this->team(), $this->art, $this->kapitelId, $this->logoUpload);
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
        $this->reset('logoUpload');
    }

    public function entfernen(KapitelLogoService $svc): void
    {
        $this->fehler = null;
        try {
            $svc->entfernen($this->team(), $this->art, $this->kapitelId);
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function render()
    {
        return view('foodalchemist::livewire.praesentation.kapitel-logo', [
            'logoUrl' => isset(KapitelLogoService::ARTEN[$this->art]) ? app(KapitelLogoService::class)->url($this->team(), $this->art, $this->kapitelId) : null,
        ]);
    }

    private function team(): Team
    {
        return Auth::user()?->currentTeamRelation ?? abort(403);
    }
}
