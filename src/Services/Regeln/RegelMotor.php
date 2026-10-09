<?php

namespace Platform\FoodAlchemist\Services\Regeln;

use Platform\FoodAlchemist\Models\FoodAlchemistRule;
use Platform\FoodAlchemist\Services\Regeln\Arten\Ersetzung;
use Platform\FoodAlchemist\Services\Regeln\Arten\Pflichtangabe;
use Platform\FoodAlchemist\Services\Regeln\Arten\RegelArt;
use Platform\FoodAlchemist\Services\Regeln\Arten\Schwelle;
use Platform\FoodAlchemist\Services\Regeln\Arten\Verbot;
use Platform\FoodAlchemist\Services\Regeln\Arten\Vokabular;
use Platform\FoodAlchemist\Services\Regeln\Arten\Zuordnung;

/**
 * Regel-Motor (Spec 81 Teil D): führt Regeln als Daten aus. Liefert Werte, Befunde und Korrekturen —
 * WIE gematcht oder generiert wird, bleibt Code der jeweiligen Stelle.
 */
final class RegelMotor
{
    /** @var array<string, RegelArt> */
    private array $arten;

    public function __construct()
    {
        $this->arten = [
            'vokabular' => new Vokabular,
            'ersetzung' => new Ersetzung,
            'pflichtangabe' => new Pflichtangabe,
            'verbot' => new Verbot,
            'zuordnung' => new Zuordnung,
            'schwelle' => new Schwelle,
        ];
    }

    public function art(string $art): RegelArt
    {
        return $this->arten[$art] ?? throw new \InvalidArgumentException("Unbekannte Regel-Art «{$art}».");
    }

    /**
     * Befunde einer Regel für einen Text, angereichert um Regel-Herkunft (für `conformance_findings`).
     *
     * @param  array<string, mixed>  $kontext
     * @return list<array{paragraph: ?string, schweregrad: string, begruendung: string, vorschlag: ?string, treffer: ?string, rule_id: int, schluessel: string, quelle: string}>
     */
    public function pruefe(FoodAlchemistRule $regel, string $text, array $kontext = []): array
    {
        $out = [];
        foreach ($this->art($regel->art)->pruefe($regel, $text, $kontext) as $b) {
            $out[] = [
                'paragraph' => $regel->paragraph,
                'schweregrad' => $regel->wirkung === 'warnen' ? 'weich' : 'hart',
                'begruendung' => $b['grund'],
                'vorschlag' => $b['vorschlag'],
                'treffer' => $b['treffer'],
                'rule_id' => (int) $regel->id,
                'schluessel' => (string) $regel->schluessel,
                'quelle' => 'code',
            ];
        }

        return $out;
    }

    /** Korrigierte Fassung — nur bei Wirkung „korrigieren" und einer Art, die korrigieren kann. */
    public function korrigiere(FoodAlchemistRule $regel, string $text, array $kontext = []): ?string
    {
        $art = $this->art($regel->art);
        if ($regel->wirkung !== 'korrigieren' || ! $art->kannKorrigieren()) {
            return null;
        }

        return $art->korrigiere($regel, $text, $kontext);
    }

    /**
     * Prüft Schema, Wirkung und Beispiele einer (noch ungespeicherten) Regel.
     *
     * Beispiele: `{richtig: [..], falsch: [..]}`, je Eintrag ein Text oder `{text, kontext?, erwartet?}`.
     * richtig = kein Befund (bei Zuordnung: ein Treffer); falsch = mindestens ein Befund (Zuordnung: kein Treffer).
     * `erwartet` vergleicht das Korrektur-/Zuordnungs-Ergebnis.
     *
     * @return list<string>
     */
    public function validiere(FoodAlchemistRule $regel): array
    {
        if (! in_array($regel->art, FoodAlchemistRule::ARTEN, true)) {
            return ['art: ' . implode('|', FoodAlchemistRule::ARTEN)];
        }
        $art = $this->art($regel->art);
        $fehler = $art->validiere((array) $regel->params);
        if (! in_array($regel->wirkung, FoodAlchemistRule::WIRKUNGEN, true)) {
            $fehler[] = 'wirkung: ' . implode('|', FoodAlchemistRule::WIRKUNGEN);
        } elseif ($regel->wirkung === 'korrigieren' && ! $art->kannKorrigieren()) {
            $fehler[] = "Die Art «{$regel->art}» kann nicht korrigieren — Wirkung warnen oder blockieren.";
        }
        if (trim((string) $regel->schluessel) === '' || trim((string) $regel->titel) === '' || trim((string) $regel->ziel) === '') {
            $fehler[] = 'schluessel, titel und ziel sind Pflicht';
        }
        if ($fehler !== []) {
            return $fehler;
        }

        foreach (['richtig' => true, 'falsch' => false] as $seite => $sollOk) {
            foreach ((array) (($regel->beispiele ?? [])[$seite] ?? []) as $bsp) {
                $text = is_array($bsp) ? (string) ($bsp['text'] ?? '') : (string) $bsp;
                $kontext = is_array($bsp) ? (array) ($bsp['kontext'] ?? []) : [];
                if ($regel->art === 'zuordnung') {
                    /** @var Zuordnung $art */
                    $e = $art->finde($regel, $text, $kontext);
                    $ok = $e !== null && (! is_array($bsp) || ! isset($bsp['erwartet']) || $e['ziel_name'] === $bsp['erwartet']);
                } else {
                    $ok = $art->pruefe($regel, $text, $kontext) === [];
                    if (is_array($bsp) && isset($bsp['erwartet'])) {
                        $ok = $art->korrigiere($regel, $text, $kontext) === $bsp['erwartet'];
                    }
                }
                if ($ok !== $sollOk) {
                    $fehler[] = "Beispiel ({$seite}) stimmt nicht: »{$text}«";
                }
            }
        }

        return $fehler;
    }
}
