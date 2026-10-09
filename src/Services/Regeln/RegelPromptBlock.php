<?php

namespace Platform\FoodAlchemist\Services\Regeln;

use Platform\FoodAlchemist\Models\FoodAlchemistRule;

/**
 * Spec 81 Teil F5 — die aktiven Regeln als Systemnachricht für jeden Prompt, der sie beim Schreiben braucht.
 *
 * Der Code korrigiert vieles beim Anlegen, aber nicht alles: einen erfundenen Typ („Knusprige Komponente:" —
 * Live-Test demo 09.10., Lauf 86) kann er nicht reparieren. Darum bekommt die KI die Regeln VORHER, und zwar aus
 * der Regel-Tabelle statt als Prosa-Dossier: kürzer, immer aktuell, eine Wahrheit. Erst danach dürfen die Listen
 * aus den Regelwerk-Dossiers verschwinden (Teil E).
 *
 * Der Block ist byte-stabil (Regeln nach Schlüssel sortiert, kein Datum, keine IDs) und wird vom Gateway direkt
 * hinter dem Kanon als Systemnachricht gesendet — im cachebaren Präfix, nie im wechselnden Kontext-JSON.
 * Nur Regeln, die beim Schreiben zählen; Matching-Regeln und große Zuordnungs-Tabellen korrigiert der Code selbst.
 */
final class RegelPromptBlock
{
    private const ZIELE_REZEPT = [
        'rezept.name', 'rezept.beschreibung', 'rezept.schritt', 'gp.verarbeitung', 'gp.attribut',
        'rezeptzeile.vegan', 'rezeptzeile.vegetarisch',
    ];

    private const ZIELE_VK = ['vk.name', 'vk.name.hg', 'vk.name.bausteine'];

    private const ZIELE_GP = ['gp.name', 'gp.verarbeitung', 'gp.attribut'];

    /**
     * Welche Prompts welche Regeln bekommen. Typ-Vokabular nur, wo Basisrezept-Namen entstehen.
     *
     * @var array<string, array{ziele: list<string>, typ: bool}>
     */
    private const KONSUMENTEN = [
        'recipe.generator' => ['ziele' => self::ZIELE_REZEPT, 'typ' => true],
        'recipe.ueberarbeiten' => ['ziele' => self::ZIELE_REZEPT, 'typ' => true],
        'recipe.review' => ['ziele' => self::ZIELE_REZEPT, 'typ' => true],
        'recipe.komponenten_plan' => ['ziele' => ['rezept.name'], 'typ' => true],
        'recipe.name_putzen' => ['ziele' => ['rezept.name'], 'typ' => true],
        'recipe.titel_vorschlag' => ['ziele' => ['rezept.name'], 'typ' => true],
        'recipe.steps' => ['ziele' => ['rezept.schritt'], 'typ' => false],
        'vk.generator' => ['ziele' => [...self::ZIELE_REZEPT, ...self::ZIELE_VK], 'typ' => true],
        'vk.ueberarbeiten' => ['ziele' => [...self::ZIELE_REZEPT, ...self::ZIELE_VK], 'typ' => true],
        'vk.review' => ['ziele' => [...self::ZIELE_REZEPT, ...self::ZIELE_VK], 'typ' => true],
        'vk.titel_vorschlag' => ['ziele' => self::ZIELE_VK, 'typ' => false],
        'gp.suggest' => ['ziele' => self::ZIELE_GP, 'typ' => false],
        'gp.conformance_revise' => ['ziele' => self::ZIELE_GP, 'typ' => false],
    ];

    /** Arten, die als Anweisung taugen. Zuordnung (Default-GP-Aliase) ist groß und wird vom Code korrigiert. */
    private const ARTEN = ['vokabular', 'verbot', 'pflichtangabe', 'ersetzung', 'schwelle'];

    /** Wofür eine Regel gilt — damit „würfel nicht verwenden" nicht den Zubereitungstext trifft. */
    private const GELTUNG = [
        'rezept.name' => 'Rezeptname',
        'rezept.beschreibung' => 'Beschreibung',
        'rezept.schritt' => 'Zubereitungsschritte',
        'gp.name' => 'Grundprodukt-Name',
        'gp.verarbeitung' => 'Zutatenzeilen mit FRISCHEN Grundprodukten (Zuschnitt gehört in die Notiz der Zeile, im Zubereitungstext erlaubt)',
        'gp.attribut' => 'Zutatenzeilen (Grundprodukt-Wahl)',
        'rezeptzeile.vegan' => 'Zutatenzeilen, wenn das Rezept vegan ist',
        'rezeptzeile.vegetarisch' => 'Zutatenzeilen, wenn das Rezept vegetarisch ist',
        'vk.name' => 'Gerichtname',
        'vk.name.hg' => 'Hauptgruppen-Kürzel des Gerichtnamens',
        'vk.name.bausteine' => 'Bausteine des Gerichtnamens',
    ];

    /** @return list<string> Prompt-Keys, die einen Regel-Block bekommen (für Messung und Tests). */
    public static function konsumenten(): array
    {
        return array_keys(self::KONSUMENTEN);
    }

    /** Der Block für einen Prompt-Key, oder null (kein Konsument / keine aktive Regel). */
    public function fuerPromptKey(string $promptKey): ?string
    {
        $k = self::KONSUMENTEN[$promptKey] ?? null;
        if ($k === null) {
            return null;
        }
        $teile = [];
        if ($k['typ'] && ($vok = $this->typVokabular()) !== '') {
            $teile[] = $vok;
        }
        $regeln = array_values(array_filter(array_map(
            fn (FoodAlchemistRule $r) => in_array($r->ziel, $k['ziele'], true) ? $this->zeile($r) : null,
            $this->sortiert(),
        )));
        if ($regeln !== []) {
            $teile[] = "Regeln:\n- " . implode("\n- ", $regeln);
        }
        if ($teile === []) {
            return null;
        }

        return "VERBINDLICHE REGELN (aus Einstellungen › Regeln; der Code prüft sie nach — Verstöße werden korrigiert oder als Befund gemeldet)\n\n"
            . implode("\n\n", $teile);
    }

    private function typVokabular(): string
    {
        $typ = RegelBuch::falls('basisrezept.1.2.typ');
        if ($typ === null) {
            return '';
        }
        $gruppen = [];
        foreach ((array) ($typ->params['werte'] ?? []) as $w) {
            $g = trim((string) ($w['gruppe'] ?? ''));
            if ($g !== '') {
                $gruppen[$g][] = (string) $w['wert'];
            }
        }
        if ($gruppen === []) {
            return '';
        }
        $zeilen = [];
        foreach ($gruppen as $g => $werte) {
            $zeilen[] = '- ' . $g . ': ' . implode(', ', array_unique($werte));
        }

        return "Typ-Vokabular für Basisrezept-Namen („Typ: Hauptzutat …“). Der Teil vor dem Doppelpunkt — auch in jeder "
            . "Unterrezept-Zeile — MUSS einer dieser Typen sein; die Gruppennamen selbst sind KEINE Typen:\n" . implode("\n", $zeilen);
    }

    /** @return list<FoodAlchemistRule> aktive Regeln, deterministisch nach Schlüssel (byte-stabil für den Cache) */
    private function sortiert(): array
    {
        $alle = RegelBuch::alle();
        ksort($alle, SORT_STRING);

        return array_values(array_filter($alle, static fn (FoodAlchemistRule $r) => $r->schluessel !== 'basisrezept.1.2.typ'
            && in_array($r->art, self::ARTEN, true)));
    }

    private function zeile(FoodAlchemistRule $r): ?string
    {
        $p = (array) $r->params;
        $kopf = trim(($r->paragraph ? $r->paragraph . ' ' : '') . $r->titel) . ' [' . (self::GELTUNG[$r->ziel] ?? $r->ziel) . ']';
        $liste = static fn (array $w) => implode(', ', array_slice(array_values(array_filter(array_map('strval', $w), 'strlen')), 0, 40));
        $bed = ! empty($p['bedingung']) ? ' (gilt bei ' . $this->bedingung((array) $p['bedingung']) . ')' : '';

        return match ($r->art) {
            'verbot' => $kopf . ': nicht verwenden — ' . $liste([...(array) ($p['tokens'] ?? []), ...(array) ($p['teile'] ?? [])])
                . (($p['grund'] ?? '') !== '' ? '. ' . $p['grund'] : '') . $bed,
            'vokabular' => $kopf . ': nur ' . $liste(array_map(static fn ($w) => (string) ($w['wert'] ?? ''), (array) ($p['werte'] ?? []))),
            'pflichtangabe' => $kopf . ': Pflicht — ' . (trim((string) ($p['hinweis'] ?? '')) ?: $r->titel) . $bed,
            'schwelle' => $kopf . ': ' . (($p['vergleich'] ?? '') === 'zwischen'
                ? ($p['min'] ?? '') . '–' . ($p['max'] ?? '') : ($p['vergleich'] ?? '') . ' ' . ($p['wert'] ?? '')) . ' ' . ($p['einheit'] ?? ''),
            'ersetzung' => $kopf . ': ' . implode('; ', array_map(static fn ($x) => ($x['von'] ?? '') . ' → ' . ($x['nach'] ?? ''), array_slice((array) ($p['paare'] ?? []), 0, 20))),
            default => null,
        };
    }

    private function bedingung(array $b): string
    {
        ksort($b, SORT_STRING);

        return implode(', ', array_map(static fn ($k, $v) => $k . ' = ' . implode('/', (array) $v), array_keys($b), $b));
    }
}
