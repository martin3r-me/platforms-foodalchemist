<?php

namespace Platform\FoodAlchemist\Http\Controllers;

use Illuminate\Contracts\View\View;
use Platform\Core\Http\Controllers\Controller;
use Platform\FoodAlchemist\Services\PresentationService;

/**
 * Spec 43 — Öffentlicher Renderer des digitalen Kundenbuchs. KEIN Login, KEIN Team-
 * Kontext: die Ausgabe wird ausschließlich per Token aufgelöst und NUR aus dem
 * eingefrorenen Snapshot gerendert (kein Live-/team-gescopter Query). Ungültig,
 * abgelaufen oder zurückgezogen → 404.
 */
class PresentationController extends Controller
{
    public function show(string $type, string $token, PresentationService $svc): View
    {
        $snapshot = $svc->resolveByToken($type, $token);
        abort_if($snapshot === null, 404);

        return view('foodalchemist::presentation.show', ['snapshot' => $snapshot]);
    }

    /**
     * Interne LIVE-Vorschau (auth + team-gescopt): rendert dieselben Templates aus den
     * aktuellen Daten (nicht aus dem Snapshot) — „Vorschau == Veröffentlicht". Optionaler
     * ?design=-Override probiert ein anderes Design, ohne es zu speichern.
     */
    public function preview(int $id, PresentationService $svc): View
    {
        return $this->doPreview(PresentationService::TYPE_FOODBOOK, $id, $svc);
    }

    public function previewSpeisekarte(int $id, PresentationService $svc): View
    {
        return $this->doPreview(PresentationService::TYPE_SPEISEKARTE, $id, $svc);
    }

    public function previewSpeiseplan(int $id, PresentationService $svc): View
    {
        return $this->doPreview(PresentationService::TYPE_SPEISEPLAN, $id, $svc);
    }

    private function doPreview(string $type, int $id, PresentationService $svc): View
    {
        $team = auth()->user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
        $snapshot = $svc->previewData($team, $type, $id, request()->query('design'));

        return view('foodalchemist::presentation.show', ['snapshot' => $snapshot, 'preview' => true]);
    }
}
