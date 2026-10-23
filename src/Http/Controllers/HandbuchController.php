<?php

namespace Platform\FoodAlchemist\Http\Controllers;

use Illuminate\Contracts\View\View;
use Platform\Core\Http\Controllers\Controller;
use Platform\FoodAlchemist\Services\HandbuchService;

/**
 * Spec 86 — Öffentliches Handbuch (Standards zum Nachschlagen). KEIN Login, KEIN Team-Kontext:
 * per Token aufgelöst, rendert NUR aus dem eingefrorenen Snapshot. Ungültig, abgelaufen oder
 * zurückgezogen → 404.
 */
class HandbuchController extends Controller
{
    public function show(string $token, HandbuchService $svc): View
    {
        $snapshot = $svc->resolveByToken($token);
        abort_if($snapshot === null, 404);

        return view('foodalchemist::handbuch.show', ['snapshot' => $snapshot]);
    }
}
