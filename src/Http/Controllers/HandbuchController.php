<?php

namespace Platform\FoodAlchemist\Http\Controllers;

use Illuminate\Contracts\View\View;
use Platform\Core\Http\Controllers\Controller;
use Platform\FoodAlchemist\Services\HandbuchService;
use Symfony\Component\HttpFoundation\Response;

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

        return view('foodalchemist::handbuch.show', ['snapshot' => $snapshot, 'cssVersion' => substr((string) @md5_file($this->cssPfad()), 0, 8)]);
    }

    /** Stylesheet des Handbuchs (Farben als CSS-Variablen, kein Inline-Style in der View). Öffentlich, kein Token nötig. */
    public function stil(): Response
    {
        $pfad = $this->cssPfad();
        abort_unless(is_file($pfad), 404);

        return response()->file($pfad, ['Content-Type' => 'text/css; charset=utf-8', 'Cache-Control' => 'public, max-age=3600']);
    }

    private function cssPfad(): string
    {
        return dirname(__DIR__, 3) . '/resources/css/handbuch.css';
    }
}
