<?php

namespace Platform\FoodAlchemist\Livewire\Concerns;

use Platform\FoodAlchemist\Services\AngebotKopieService;
use Platform\FoodAlchemist\Services\AngebotService;

/**
 * Angebots-Editor: Branding-Bilder (Logo, Cover, freie Präsentationsbilder 1–3) und „Angebot kopieren".
 * Aus Angebote\Editor ausgelagert (Dateigrößen-Wächter). Die Properties (logoUpload, coverUpload, praesBildUpload,
 * brandingFehler, selectedId) stehen im Editor; die Bearbeitungssperre (uploadGesperrt, sperreAktiv, darfSchreiben,
 * sperreAbgewiesen) kommt aus {@see MitBearbeitungssperre}.
 */
trait AngebotBrandingBilder
{
    /** Angebot kopieren (Kapitel, Blöcke, lokale Concepts, Bilder) und die Kopie direkt öffnen. */
    public function kopieren(): void
    {
        if ($this->selectedId === null) {
            return;
        }
        $kopie = app(AngebotKopieService::class)->kopieren($this->team(), $this->selectedId);
        $this->dispatch('angebot-gespeichert');
        $this->oeffnen((int) $kopie->id);
        $this->dispatch('toast', text: 'Kopie angelegt: ' . $kopie->name);
    }

    public function updatedLogoUpload(): void
    {
        if ($this->uploadGesperrt('logoUpload')) {
            return;
        }
        $this->brandingBildHochladen('logoUpload', 'storeLogo');
    }

    public function updatedCoverUpload(): void
    {
        if ($this->uploadGesperrt('coverUpload')) {
            return;
        }
        $this->brandingBildHochladen('coverUpload', 'storeCover');
    }

    /** Auto-Upload bei Dateiwahl: validieren → Service (räumt Altdatei) → Feld leeren. */
    private function brandingBildHochladen(string $prop, string $serviceMethod): void
    {
        $this->brandingFehler = null;
        if ($this->selectedId === null || $this->{$prop} === null) {
            return;
        }
        $this->validate([$prop => 'image|max:8192'], [], [$prop => $prop === 'logoUpload' ? 'Logo' : 'Cover-Bild']);
        try {
            app(AngebotService::class)->{$serviceMethod}($this->team(), $this->selectedId, $this->{$prop});
        } catch (\RuntimeException $e) {
            $this->brandingFehler = $e->getMessage();
        }
        $this->reset($prop);
    }

    public function updatedPraesBildUpload($wert, $schluessel): void
    {
        $nr = (int) $schluessel;
        if (self::sperreAktiv() && ! $this->darfSchreiben()) {   // wie uploadGesperrt, aber für den Array-Slot
            $this->praesBildUpload[$nr] = null;
            $this->sperreAbgewiesen('updatedPraesBildUpload');

            return;
        }
        $this->brandingFehler = null;
        if ($this->selectedId === null || $nr < 1 || $nr > 3 || ($this->praesBildUpload[$nr] ?? null) === null) {
            return;
        }
        $this->validate(["praesBildUpload.{$nr}" => 'image|max:8192'], [], ["praesBildUpload.{$nr}" => "Präsentationsbild {$nr}"]);
        try {
            app(AngebotService::class)->storePraesentationsBild($this->team(), $this->selectedId, $nr, $this->praesBildUpload[$nr]);
        } catch (\RuntimeException $e) {
            $this->brandingFehler = $e->getMessage();
        }
        $this->praesBildUpload[$nr] = null;
    }

    public function praesBildEntfernen(int $nr, AngebotService $svc): void
    {
        if ($this->selectedId !== null) {
            $svc->clearPraesentationsBild($this->team(), $this->selectedId, $nr);
        }
    }

    public function brandingLogoEntfernen(AngebotService $svc): void
    {
        if ($this->selectedId !== null) {
            $svc->clearLogo($this->team(), $this->selectedId);
        }
    }

    public function brandingCoverEntfernen(AngebotService $svc): void
    {
        if ($this->selectedId !== null) {
            $svc->clearCover($this->team(), $this->selectedId);
        }
    }
}
