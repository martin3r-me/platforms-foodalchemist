<?php

namespace Platform\FoodAlchemist\Services\Konfigurator;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistLeistungVorlage;
use Platform\FoodAlchemist\Models\FoodAlchemistMarkupClass;

/**
 * Spec 94 · Eine Prüfung der Rechen- und Preisfelder für Leistungs-Vorlage und Konfigurator-Leistung (Oberfläche und MCP).
 * Liefert die bereinigten Werte oder wirft \InvalidArgumentException mit einer Meldung für den Menschen.
 */
final class LeistungsRegelPruefung
{
    /**
     * @param  array<string,mixed>  $in  Eingabe (nur bekannte Felder werden übernommen)
     * @param  array<string,mixed>  $bisher  aktuelle Werte beim Ändern (für Felder, die nicht mitkommen)
     * @return array<string,mixed>
     */
    public function pruefe(Team $team, array $in, array $bisher = []): array
    {
        $w = array_merge($bisher, array_intersect_key($in, array_flip(FoodAlchemistLeistungVorlage::REGEL_FELDER)));
        $zahl = fn (string $f) => isset($w[$f]) && $w[$f] !== '' ? (float) str_replace(',', '.', (string) $w[$f]) : null;
        $ganz = fn (string $f) => isset($w[$f]) && $w[$f] !== '' ? (int) $w[$f] : null;

        $aus = [
            'kategorie' => (string) ($w['kategorie'] ?? ''),
            'bezeichnung' => trim((string) ($w['bezeichnung'] ?? '')),
            'notiz' => ($w['notiz'] ?? '') !== '' ? (string) $w['notiz'] : null,
            'mengen_regel' => (string) ($w['mengen_regel'] ?? 'fest'),
            'anzahl' => $zahl('anzahl'),
            'bezug' => ($w['bezug'] ?? '') !== '' ? (string) $w['bezug'] : null,
            'bezug_leistung_id' => $ganz('bezug_leistung_id'),
            'schluessel_n' => $zahl('schluessel_n'),
            'min' => $zahl('min'),
            'max' => $zahl('max'),
            'ab_pax' => $ganz('ab_pax'),
            'bis_pax' => $ganz('bis_pax'),
            'stufen_json' => $w['stufen_json'] ?? null,
            'reserve_pct' => $zahl('reserve_pct'),
            'preis_je' => (string) ($w['preis_je'] ?? 'stueck'),
            'stunden_quelle' => ($w['stunden_quelle'] ?? '') !== '' ? (string) $w['stunden_quelle'] : null,
            'stunden' => $zahl('stunden'),
            'stunden_zuschlag' => $zahl('stunden_zuschlag'),
            'haeufigkeit' => (string) ($w['haeufigkeit'] ?? 'je_event'),
            'ek_satz' => $zahl('ek_satz') ?? 0.0,
            'markup_class_id' => $ganz('markup_class_id'),
            'vk_satz' => $zahl('vk_satz'),
            'vk_modus' => (string) ($w['vk_modus'] ?? 'auto'),
            'vk_begruendung' => trim((string) ($w['vk_begruendung'] ?? '')) ?: null,
            'abrechnung' => (string) ($w['abrechnung'] ?? 'all_in') ?: 'all_in',
            'abrechnung_einheit_vocab_id' => $ganz('abrechnung_einheit_vocab_id'),
        ];

        $fehler = fn (string $m) => throw new \InvalidArgumentException($m);
        // Spec 94b: Kategorien aus dem Vokabular. Eine bestehende, inzwischen ausgeschaltete Kategorie bleibt beim Ändern gültig.
        $kategorien = app(LeistungsKategorieService::class);
        $behalten = ($bisher['kategorie'] ?? null) === $aus['kategorie'] && isset($kategorien->namen($team)[$aus['kategorie']]);
        if (! $behalten && ! isset($kategorien->auswahl($team)[$aus['kategorie']])) {
            $fehler('Kategorie fehlt oder ist nicht wählbar (' . implode(', ', $kategorien->auswahl($team)) . ').');
        }
        if ($aus['bezeichnung'] === '') {
            $fehler('Bezeichnung ist Pflicht.');
        }
        if (! in_array($aus['mengen_regel'], FoodAlchemistLeistungVorlage::MENGEN_REGELN, true)) {
            $fehler('Mengenregel muss fest, Schlüssel oder Stufen sein.');
        }
        if ($aus['mengen_regel'] === 'fest' && ($aus['anzahl'] === null || $aus['anzahl'] < 0)) {
            $fehler('Bei fester Menge ist die Anzahl Pflicht.');
        }
        if ($aus['mengen_regel'] === 'schluessel') {
            if (! in_array($aus['bezug'], FoodAlchemistLeistungVorlage::BEZUEGE, true)) {
                $fehler('Beim Schlüssel ist der Bezug Pflicht (Gäste oder eine andere Leistung).');
            }
            if ($aus['schluessel_n'] === null || $aus['schluessel_n'] <= 0) {
                $fehler('Der Schlüssel braucht eine Zahl größer 0 (z. B. 30 für 1 Servicekraft je 30 Gäste).');
            }
            if ($aus['bezug'] === 'leistung' && $aus['bezug_leistung_id'] === null) {
                $fehler('Bezug „Leistung“ braucht die Leistung, an der die Menge hängt.');
            }
        }
        if ($aus['mengen_regel'] !== 'schluessel') {
            $aus['bezug'] = null;
            $aus['bezug_leistung_id'] = null;
        }
        if ($aus['mengen_regel'] === 'stufen') {
            $aus['stufen_json'] = $this->stufen($aus['stufen_json']);
        } else {
            $aus['stufen_json'] = null;
        }
        if ($aus['min'] !== null && $aus['max'] !== null && $aus['min'] > $aus['max']) {
            $fehler('Mindestanzahl ist größer als die Höchstanzahl.');
        }
        if ($aus['ab_pax'] !== null && $aus['bis_pax'] !== null && $aus['ab_pax'] > $aus['bis_pax']) {
            $fehler('Gästebereich: „ab“ liegt über „bis“.');
        }
        if ($aus['reserve_pct'] !== null && $aus['reserve_pct'] < 0) {
            $fehler('Die Reserve darf nicht negativ sein.');
        }
        if (! in_array($aus['preis_je'], FoodAlchemistLeistungVorlage::PREIS_JE, true)) {
            $fehler('Preis je Stück oder je Stunde.');
        }
        if ($aus['preis_je'] === 'stunde') {
            if (! in_array($aus['stunden_quelle'], FoodAlchemistLeistungVorlage::STUNDEN_QUELLEN, true)) {
                $fehler('Bei Preis je Stunde: Stunden aus der Einsatzzeit oder feste Stunden.');
            }
            if ($aus['stunden_quelle'] === 'fest' && ($aus['stunden'] === null || $aus['stunden'] <= 0)) {
                $fehler('Feste Stunden brauchen eine Stundenzahl größer 0.');
            }
        } else {
            $aus['stunden_quelle'] = null;
            $aus['stunden'] = null;
            $aus['stunden_zuschlag'] = null;
        }
        if (! in_array($aus['haeufigkeit'], FoodAlchemistLeistungVorlage::HAEUFIGKEITEN, true)) {
            $fehler('Häufigkeit je Event oder je Tageszeit.');
        }
        if ($aus['ek_satz'] < 0) {
            $fehler('Der EK-Satz darf nicht negativ sein.');
        }
        if ($aus['markup_class_id'] !== null
            && ! FoodAlchemistMarkupClass::visibleToTeam($team)->bereich('leistung')->whereKey($aus['markup_class_id'])->where('is_inactive', false)->exists()) {
            $fehler('Die Aufschlagsklasse ist keine aktive Leistungsklasse dieses Teams.');
        }
        if (! in_array($aus['abrechnung'], FoodAlchemistLeistungVorlage::ABRECHNUNGEN, true)) {
            $fehler('Abrechnung im All-in oder nach Aufwand.');
        }
        if ($aus['abrechnung'] !== 'aufwand') {
            $aus['abrechnung_einheit_vocab_id'] = null;
        } elseif ($aus['abrechnung_einheit_vocab_id'] !== null
            && ! \Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit::visibleToTeam($team)->whereKey($aus['abrechnung_einheit_vocab_id'])->exists()) {
            $fehler('Die Einheit der Abrechnung gibt es nicht oder sie ist für dieses Team nicht sichtbar.');
        }
        if (! in_array($aus['vk_modus'], FoodAlchemistLeistungVorlage::VK_MODI, true)) {
            $fehler('VK-Modus automatisch oder fest.');
        }
        if ($aus['vk_modus'] === 'fixed') {
            if ($aus['vk_satz'] === null || $aus['vk_satz'] < 0) {
                $fehler('Ein fester VK braucht einen Betrag.');
            }
            if ($aus['vk_begruendung'] === null) {
                $fehler('Ein fester VK braucht eine Begründung.');
            }
        } else {
            $aus['vk_begruendung'] = null;
        }

        return $aus;
    }

    /** @return list<array{bis_pax:?int, anzahl:float}> */
    private function stufen(mixed $roh): array
    {
        $roh = is_string($roh) ? json_decode($roh, true) : $roh;
        $stufen = [];
        foreach (is_array($roh) ? $roh : [] as $s) {
            if (! is_array($s) || ! isset($s['anzahl']) || ! is_numeric($s['anzahl']) || (float) $s['anzahl'] < 0) {
                continue;
            }
            $stufen[] = ['bis_pax' => isset($s['bis_pax']) && $s['bis_pax'] !== '' ? (int) $s['bis_pax'] : null, 'anzahl' => (float) $s['anzahl']];
        }
        if ($stufen === []) {
            throw new \InvalidArgumentException('Stufen brauchen mindestens eine Zeile „bis Gäste → Anzahl“.');
        }
        usort($stufen, fn ($a, $b) => ($a['bis_pax'] ?? PHP_INT_MAX) <=> ($b['bis_pax'] ?? PHP_INT_MAX));

        return $stufen;
    }
}
