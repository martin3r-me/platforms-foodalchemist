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
        'abend_konzept', 'addons', 'getraenke', 'pax', 'website', 'ts'];

    public function store(string $token, Request $request, KonfiguratorAnfrageService $svc): RedirectResponse
    {
        $k = $svc->liveKonfigurator($token);
        abort_if($k === null, 404);
        $zurueck = route('foodalchemist.presentation.show', ['type' => 'konfigurator', 'token' => $token]);

        // Auswahl im Hash zurückgeben: das Skript stellt sie wieder her und springt zur Anfrage (s=anfrage)
        $hash = '#' . http_build_query(array_filter([
            'k' => (int) $request->input('konzept') ?: null, 't' => preg_replace('/[^a-z_]/', '', (string) $request->input('tageszeit')) ?: null,
            'ab' => (int) $request->input('abend_konzept') ?: null,
            'a' => implode('.', array_filter(array_map('intval', explode(',', (string) $request->input('addons'))))) ?: null,
            'g' => implode('.', array_filter(array_map('intval', explode(',', (string) $request->input('getraenke'))))) ?: null,   // Spec 94c
            'p' => (int) $request->input('pax') ?: null, 's' => 'anfrage',
        ]));
        try {
            $svc->anfragen($k, $request->only(self::FELDER));
        } catch (ValidationException $e) {
            return redirect()->to($zurueck . '?anfrage=fehler' . $hash)->withErrors($e->errors())->withInput($request->except(['website', 'ts']));
        }

        return redirect()->to($zurueck . '?anfrage=ok' . $hash);
    }
}
