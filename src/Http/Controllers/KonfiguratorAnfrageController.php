<?php

namespace Platform\FoodAlchemist\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Platform\FoodAlchemist\Services\Konfigurator\KonfiguratorAnfrageService;

/**
 * Spec 94 · POST /p/konfigurator/{token}/anfrage — öffentliche Anfrage aus dem Konfigurator-Link (ohne Login).
 * Throttle und NoCache an der Route; Prüfung und Anlage im KonfiguratorAnfrageService. Nur die bekannten Felder.
 */
class KonfiguratorAnfrageController
{
    private const FELDER = ['name', 'firma', 'email', 'telefon', 'datum', 'nachricht', 'datenschutz', 'konzept', 'tageszeit',
        'abend_konzept', 'addons', 'pax', 'website', 'ts'];

    public function store(string $token, Request $request, KonfiguratorAnfrageService $svc): RedirectResponse
    {
        $k = $svc->liveKonfigurator($token);
        abort_if($k === null, 404);
        $zurueck = route('foodalchemist.presentation.show', ['type' => 'konfigurator', 'token' => $token]);

        try {
            $svc->anfragen($k, $request->only(self::FELDER));
        } catch (ValidationException $e) {
            return redirect()->to($zurueck . '?anfrage=fehler#konfig-anfrage')->withErrors($e->errors())->withInput($request->except(['website', 'ts']));
        }

        return redirect()->to($zurueck . '?anfrage=ok#konfig-anfrage');
    }
}
