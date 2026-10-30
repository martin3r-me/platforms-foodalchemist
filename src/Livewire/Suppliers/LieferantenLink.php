<?php

namespace Platform\FoodAlchemist\Livewire\Suppliers;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Models\FoodAlchemistLieferantenLink as Link;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Services\LieferantenLinkService;

/**
 * Lieferanten-Link am Lieferanten (Stufe 2a): erzeugen, Link kopieren (einmalig sichtbar), widerrufen, Protokoll. Eigene Komponente, vom Lieferanten-Modal
 * nur eingebettet. Rechte wie der Preislisten-Import (Haupt-Team + Kuratieren); der Token steht nie in der Liste, nur sein Hinweis.
 */
class LieferantenLink extends Component
{
    public int $supplierId;

    public int $tage = 30;

    /** @var list<string> */
    public array $bereiche = ['preisliste', 'stammdaten', 'dokumente', 'formular'];

    public string $kontaktName = '';

    public string $kontaktEmail = '';

    /** Der neue Link — nur direkt nach dem Erzeugen sichtbar. */
    public ?string $neueUrl = null;

    public ?int $protokollLinkId = null;

    public ?string $fehler = null;

    public function mount(int $supplierId): void
    {
        $this->supplierId = $supplierId;
        $this->tage = LieferantenLinkService::standardTage();
    }

    public function erzeugen(): void
    {
        $this->fehler = $this->neueUrl = null;
        $team = Auth::user()?->currentTeamRelation;
        $lieferant = $team !== null ? FoodAlchemistSupplier::visibleToTeam($team)->find($this->supplierId) : null;
        if ($team === null || $lieferant === null) {
            $this->fehler = 'Lieferant nicht gefunden.';

            return;
        }
        try {
            $r = app(LieferantenLinkService::class)->erzeugen($team, Auth::user(), $lieferant, [
                'tage' => $this->tage, 'bereiche' => $this->bereiche, 'kontakt_name' => $this->kontaktName, 'kontakt_email' => $this->kontaktEmail,
            ]);
        } catch (FaRechtFehltException|\RuntimeException $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        $this->neueUrl = $r['url'];
        $this->kontaktName = $this->kontaktEmail = '';
    }

    public function widerrufen(int $id): void
    {
        $this->fehler = null;
        $team = Auth::user()?->currentTeamRelation;
        $svc = app(LieferantenLinkService::class);
        $link = $team !== null ? $svc->link($team, $id) : null;
        if ($link === null || (int) $link->supplier_id !== $this->supplierId) {
            return;
        }
        try {
            $svc->widerrufen($team, Auth::user(), $link);
        } catch (FaRechtFehltException|\RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function protokoll(int $id): void
    {
        $this->protokollLinkId = $this->protokollLinkId === $id ? null : $id;
    }

    public function render()
    {
        $team = Auth::user()?->currentTeamRelation;
        $svc = app(LieferantenLinkService::class);
        $darf = $team !== null && $svc->darfVerwalten($team, Auth::user());
        $lieferant = $team !== null ? FoodAlchemistSupplier::visibleToTeam($team)->find($this->supplierId) : null;
        $erlaubt = $darf && $lieferant !== null && $svc->lieferantErlaubt($team, $lieferant);

        return view('foodalchemist::livewire.suppliers.lieferanten-link', [
            'darf' => $darf,
            'erlaubt' => $erlaubt,
            'links' => $erlaubt ? $svc->liste($team, Auth::user(), $this->supplierId) : collect(),
            'protokoll' => $erlaubt && $this->protokollLinkId !== null && ($l = $svc->link($team, $this->protokollLinkId)) !== null ? $svc->protokoll($team, $l) : collect(),
            'alleBereiche' => Link::BEREICHE,
            'maxTage' => LieferantenLinkService::maxTage(),
        ]);
    }
}
