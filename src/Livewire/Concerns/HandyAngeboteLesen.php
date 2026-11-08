<?php

namespace Platform\FoodAlchemist\Livewire\Concerns;

use Illuminate\Support\Facades\Auth;
use Platform\FoodAlchemist\Services\AngebotService;

/**
 * Angebote am Handy (Dominique 08.11.2026: „wie beim Konfigurator und Foodbook“): Karten mit Suche und Status-Chips, derselbe
 * Listenweg wie am Computer (AngebotService::paginateBrowser, team-sichtbar inkl. Spec 84 E8). Antippen öffnet die vorhandene
 * Live-Vorschau (/angebote/{id}/praesentation, Kundensicht); „Dokument“ öffnet das vorhandene Angebots-Dokument mit Preisen.
 * Editor, Anlegen, Status ändern, Kalkulation und Veröffentlichen bleiben am Computer. Die Komponente ruft handyAngebote() am
 * Anfang von render().
 */
trait HandyAngeboteLesen
{
    use HandyLesebereich;

    public function handyLeseMethoden(): array
    {
        return ['waehleStatus', 'nextPage', 'previousPage', 'gotoPage', 'setPage', 'resetPage'];
    }

    private function handyAngebote(AngebotService $svc)
    {
        $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
        $ton = ['secondary' => 'neutral', 'info' => 'info', 'warning' => 'warn', 'primary' => 'accent', 'success' => 'ok', 'danger' => 'crit'];
        $eintraege = $svc->paginateBrowser(['search' => $this->search, 'status' => $this->statusFilter], $team, 30);
        $eintraege->getCollection()->loadMissing('crmCompany');

        return view('foodalchemist::livewire.handy.angebote', [
            'eintraege' => $eintraege,
            'statusWerte' => $svc->statusWerte(),
            'karten' => $eintraege->getCollection()->map(fn ($a) => [
                'id' => (int) $a->id,
                'name' => $a->name !== null && $a->name !== '' ? $a->name : 'Ohne Namen',
                'zeile' => implode(' · ', array_filter([
                    $a->crmCompany?->display_name,
                    $a->occasion,
                    $a->event_date?->format('d.m.Y'),
                    $a->personen ? $a->personen . ' Gäste' : null,
                ])),
                'preis' => $a->total_price,
                'status' => $a->status->label(),
                'ton' => $ton[$a->status->badgeVariant()] ?? 'neutral',
                'href' => route('foodalchemist.angebote.praesentation', $a->id),
                'dokument' => route('foodalchemist.angebote.dokument', $a->id),
            ]),
        ])->layout(\Platform\FoodAlchemist\Support\FaShell::layout());
    }
}
