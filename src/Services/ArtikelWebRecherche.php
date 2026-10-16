<?php

namespace Platform\FoodAlchemist\Services;

use Platform\FoodAlchemist\Models\FoodAlchemistItemAllergen;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\Websuche\WebSucheTransport;

/**
 * Web-Recherche zu EINEM Lieferantenartikel (nur auf Knopfdruck, ein Aufruf je Klick). Liefert ausschließlich VORSCHLÄGE:
 * dieser Dienst schreibt nichts (keine DB, kein Feld, keine Allergene). Übernahme macht der Mensch per Klick, nur in leere Felder,
 * Allergene mit eigenem Bestätigungsschritt (Produktpass-Mechanismus).
 *
 * Rückgabe in der Form der Produktpass-Quellen (`ProduktdatenQuelle::liefere`, Quelle `websuche`):
 *   quelle   'websuche'
 *   felder   key => {wert, konfidenz, url, zitat?, einheit?}      Schlüssel aus {@see FELDER}
 *   allergene key => {wert enthalten|spuren|nicht_enthalten, konfidenz, url, zitat}   14 EU-Keys
 *   deutung  key => {wert, url}    Produkttyp/Hauptzutat/Warengruppe/Unterkategorie/Marke/Zustand/Form (GP-Dialog)
 *   quellen  list<{url,title}>     alle tatsächlich gefundenen Quellen
 *
 * Regeln, die HIER erzwungen werden:
 * - Jede Angabe braucht eine url, und die url muss unter den vom Transport gemeldeten Quellen sein (keine URL aus dem Modellgedächtnis).
 * - Fehlt ein Wert: Schlüssel weglassen. Nie „nicht_enthalten" ableiten: es braucht ein wörtliches Zitat.
 * - Unbekannte Schlüssel und ungültige Werte werden verworfen.
 *
 * Hinweis: Sobald Pauls Interface `Services\Produktpass\ProduktdatenQuelle` in main liegt, kommt `implements ProduktdatenQuelle`
 * dazu (Signatur identisch) und der Container-Tag `produktdaten.quellen`.
 */
class ArtikelWebRecherche
{
    /** Erlaubte Feldschlüssel (Produktpass-Form: FA-Spalten bzw. Gruppe.Feld). */
    public const FELDER = [
        'zutatenliste', 'gebinde.qty', 'gebinde.einheit', 'herkunft', 'marke', 'hersteller', 'ean', 'haltbarkeit.chilled_days',
        'naehrwerte.energy_kcal', 'naehrwerte.energy_kj', 'naehrwerte.fat', 'naehrwerte.saturated_fat', 'naehrwerte.carbs_absorbable',
        'naehrwerte.sugar', 'naehrwerte.protein', 'naehrwerte.sodium',
        'zusatzstoffe.with_dye', 'zusatzstoffe.with_preservative', 'zusatzstoffe.with_antioxidant', 'zusatzstoffe.with_sweetener',
    ];

    /** Deutung für den GP-Dialog: bestimmt, was der Artikel IST (kein Marken- oder Fantasiename im GP-Namen). */
    public const DEUTUNG = ['produkttyp', 'hauptzutat', 'warengruppe', 'unterkategorie', 'marke_fantasiename', 'zustand', 'form'];

    public function __construct(private WebSucheTransport $transport)
    {
    }

    public function name(): string
    {
        return 'websuche';
    }

    /**
     * @param  array  $optionen  reserviert (z. B. 'nur' => ['deutung'])
     * @return array{quelle: string, felder: array, allergene: array, deutung: array, quellen: array}
     *
     * @throws \RuntimeException bei Transportfehler (Meldung lesbar) oder wenn nichts Belegbares gefunden wurde
     */
    public function liefere(FoodAlchemistSupplierItem $la, array $optionen = []): array
    {
        $roh = $this->transport->suche($this->anweisung(), $this->kontext($la));
        $quellen = $this->quellen($roh['quellen'] ?? []);
        if ($quellen === []) {
            throw new \RuntimeException('Keine belegbaren Quellen im Web gefunden.');
        }
        $erlaubt = array_column($quellen, 'url');
        $antwort = is_array($roh['antwort'] ?? null) ? $roh['antwort'] : [];

        $felder = [];
        foreach ((array) ($antwort['felder'] ?? []) as $key => $eintrag) {
            if (in_array($key, self::FELDER, true) && ($e = $this->eintrag($eintrag, $erlaubt)) !== null) {
                $felder[$key] = $e;
            }
        }
        $allergene = [];
        foreach ((array) ($antwort['allergene'] ?? []) as $key => $eintrag) {
            $e = $this->eintrag($eintrag, $erlaubt);
            $wert = $e['wert'] ?? null;
            if ($e === null || ! array_key_exists($key, FoodAlchemistItemAllergen::ALLERGENE) || ! in_array($wert, ['enthalten', 'spuren', 'nicht_enthalten'], true)) {
                continue;
            }
            // „nicht enthalten" nie ableiten: nur mit wörtlichem Zitat aus der Quelle
            if ($wert === 'nicht_enthalten' && trim((string) ($e['zitat'] ?? '')) === '') {
                continue;
            }
            $allergene[$key] = $e;
        }
        $deutung = [];
        foreach ((array) ($antwort['deutung'] ?? []) as $key => $eintrag) {
            if (in_array($key, self::DEUTUNG, true) && ($e = $this->eintrag($eintrag, $erlaubt)) !== null && is_string($e['wert'])) {
                $deutung[$key] = ['wert' => $e['wert'], 'url' => $e['url']];
            }
        }

        if ($felder === [] && $allergene === [] && $deutung === []) {
            throw new \RuntimeException('Im Web wurde nichts Belegbares zu diesem Artikel gefunden.');
        }

        return ['quelle' => 'websuche', 'felder' => $felder, 'allergene' => $allergene, 'deutung' => $deutung, 'quellen' => $quellen];
    }

    /** @return list<array{url: string, title: string}> */
    private function quellen(array $roh): array
    {
        $aus = [];
        foreach ($roh as $q) {
            $url = is_array($q) ? (string) ($q['url'] ?? '') : (string) $q;
            if (preg_match('#^https?://#i', $url) === 1 && ! isset($aus[$url])) {
                $aus[$url] = ['url' => $url, 'title' => is_array($q) ? (string) ($q['title'] ?? '') : ''];
            }
        }

        return array_values($aus);
    }

    /** @return array{wert: mixed, konfidenz: float, url: string, zitat?: string, einheit?: string}|null */
    private function eintrag(mixed $e, array $erlaubteUrls): ?array
    {
        if (! is_array($e) || ! array_key_exists('wert', $e) || $e['wert'] === null || $e['wert'] === '' || ! is_string($url = $e['url'] ?? null) || ! in_array($url, $erlaubteUrls, true)) {
            return null;
        }
        $out = ['wert' => $e['wert'], 'konfidenz' => max(0.0, min(1.0, (float) ($e['konfidenz'] ?? 0.5))), 'url' => $url];
        foreach (['zitat', 'einheit'] as $opt) {
            if (isset($e[$opt]) && is_string($e[$opt]) && trim($e[$opt]) !== '') {
                $out[$opt] = trim($e[$opt]);
            }
        }

        return $out;
    }

    private function kontext(FoodAlchemistSupplierItem $la): array
    {
        $la->loadMissing('supplier:id,name');

        return array_filter([
            'bezeichnung' => (string) $la->designation,
            'artikelnummer' => $la->article_number,
            'lieferant' => $la->supplier?->name,
            'ean_gebinde' => $la->ean_packaging,
            'ean_bestell' => $la->ean_ordering,
            'marke' => $la->brand,
            'hersteller' => $la->manufacturer,
            'marketing_name' => $la->marketing_name,
            'regulierter_name' => $la->regulated_name,
        ], fn ($v) => $v !== null && $v !== '');
    }

    private function anweisung(): string
    {
        return (string) (config('foodalchemist.prompts', [])['artikel.web_recherche']['task'] ?? '');
    }
}
