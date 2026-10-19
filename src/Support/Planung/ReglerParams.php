<?php

namespace Platform\FoodAlchemist\Support\Planung;

use Platform\FoodAlchemist\Livewire\Planung\Index;
use Platform\FoodAlchemist\Models\FoodAlchemistPlanningSession;
use Platform\FoodAlchemist\Services\PlanningCascadeService;

/**
 * Spec 84 C1 Schritt 1 · Leitplanken-Logik der Planung, aus `Livewire\Planung\Index` herausgelöst (wortgleich verschoben).
 * Reine Rechnung über den Regler- und Eingabe-Stand eines Tabs — kein UI, kein Livewire. Die Komponente reicht ihre beiden Arrays
 * herein und liest bei `rehydriereReglerAusParams` den neuen Regler-Stand zurück. Die Konstanten bleiben in Index (Agent + Blade lesen sie).
 */
final class ReglerParams
{
    public function __construct(public array $regler, public array $eingabe) {}

    /**
     * L5: generation_params → Regler zurückspiegeln (Umkehrung von {@see reglerParams}). Fail-soft; nur
     * gesetzte Achsen überschreiben den Default. Auf ALLE Scopes angewandt (die Session hält nur EINEN
     * Satz — den des Start-Tabs; ohne Tab-Herkunft ist der beste Reload derselbe Satz je Tab). Bewusst
     * NICHT rückformatiert: ziel_vk + menue_preis_* (Euro/Komma) — die tippt der Nutzer bei Bedarf neu.
     */
    public function rehydriereReglerAusParams(FoodAlchemistPlanningSession $session): void
    {
        $p = is_array($session->generation_params) ? $session->generation_params : [];
        if ($p === []) {
            return;
        }
        try {
            // Frische: rohe condition-Werte → UI-Slugs (Umkehrung FRISCHE_CONDITION).
            $condToSlug = [];
            foreach (Index::FRISCHE_CONDITION as $slug => $roh) {
                foreach ((array) $roh as $r) {
                    $condToSlug[$r] = $slug;   // ein Slug kann mehrere Roh-Zustände tragen (v3.6 „behandelt“)
                }
            }
            $frischeSlugs = array_values(array_unique(array_filter(array_map(
                static fn ($raw) => $condToSlug[$raw] ?? null,
                (array) ($p['frische_erlaubt'] ?? []),
            ))));
            $bioPraef = match ($p['bio_pref'] ?? null) {
                'bio' => 'bio', 'neutral' => 'egal', 'conventional' => 'konventionell', default => null,
            };
            foreach (Index::SCOPES as $scope) {
                if (! isset($this->regler[$scope])) {
                    continue;
                }
                foreach (['level', 'sektor', 'aroma', 'aroma_kueche', 'occasion', 'serviceform',
                    'kompositions_stil', 'menue_balance', 'menue_typ'] as $k) {
                    if (($p[$k] ?? '') !== '') {
                        $this->regler[$scope][$k] = $p[$k];
                    }
                }
                foreach (['diaet_hart', 'allergen_nogo'] as $k) {
                    if (! empty($p[$k]) && is_array($p[$k])) {
                        $this->regler[$scope][$k] = array_values($p[$k]);
                    }
                }
                if ($frischeSlugs !== []) {
                    $this->regler[$scope]['frische'] = $frischeSlugs;
                }
                if ($bioPraef !== null) {
                    $this->regler[$scope]['bio_praeferenz'] = $bioPraef;
                }
                if (array_key_exists('use_favorites_list', $p)) {
                    $this->regler[$scope]['favoriten'] = (bool) $p['use_favorites_list'];
                    $this->regler[$scope]['favoriten_conv_only'] = (bool) ($p['favorites_convenience_only'] ?? false);
                }
                if (array_key_exists('ki_bilder', $p)) {
                    $this->regler[$scope]['ki_bilder'] = (bool) $p['ki_bilder'];
                }
                // Menü-Gänge/Quoten als Zahl-String zurückschreiben (der Parser liest sie wieder als Zahl).
                foreach (['menue_gaenge' => 'menue_gaenge', 'menue_quote_vegan_pct' => 'menue_quote_vegan',
                    'menue_quote_vegetarisch_pct' => 'menue_quote_vegetarisch'] as $pk => $uiKey) {
                    if (($p[$pk] ?? null) !== null && $p[$pk] !== '') {
                        $this->regler[$scope][$uiKey] = (string) $p[$pk];
                    }
                }
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[Planung] Regler-Rehydrierung übersprungen', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Regler → Richtungs-Param-Bündel — spiegelt EXAKT die Param-Logik der abgelösten
     * Rich-Modals: bio-Bool aus bio_praeferenz, Leer-Hints strippen (diaet_hart-Array +
     * Bools bleiben), Favoriten opt-in, VK-Achsen + Ziel-VK nur bei $vk. Wird am Go an
     * die Kaskade UND (für den Fan-out) an generation_params gereicht.
     *
     * @return array<string,mixed>
     */
    public function reglerParams(string $scope): array
    {
        $r = $this->regler[$scope] ?? Index::REGLER_DEFAULT;
        $vk = $scope !== 'rezept';                              // Basisrezept: keine VK-Achsen
        $menue = $scope === 'concept';                          // Menü-Leitplanken nur am Concept-Tab
        // Extra-Steuerwerte gesondert (sie werden übersetzt, nicht 1:1 durchgereicht).
        $favoriten = (bool) ($r['favoriten'] ?? false);
        $favConvOnly = (bool) ($r['favoriten_conv_only'] ?? false);
        $kiBilder = (bool) ($r['ki_bilder'] ?? false);
        $vollAnreichern = (bool) ($r['voll_anreichern'] ?? true);
        $p = $r;
        // Bio dreiwertig weiterreichen (bio|conventional|neutral) — der Generator/Matcher kennt einen
        // NEUTRALEN Arm (Adjustment 0). Ohne ihn fiel „egal" auf das Bool false → 'conventional' → Bio-GPs
        // wurden aktiv mit −2 bestraft (Bug). Der Bool `bio` bleibt für den MCP-Pfad rückwärtskompatibel.
        $p['bio_pref'] = match ($r['bio_praeferenz'] ?? '') {
            'bio' => 'bio',
            'egal' => 'neutral',
            default => 'conventional',
        };
        $p['bio'] = ($r['bio_praeferenz'] ?? '') === 'bio';
        // Kreativität bestimmt die IDEE, nicht ob bestehende Basisrezepte ignoriert werden. Auch ein
        // voll kreatives Gericht erdet seine Komponenten deshalb bestand-first; nur echte Lücken werden
        // neu angelegt. Basisrezepte haben keine Kreativmodus-Achse und nutzen denselben Resolver.
        // Basisrezept: kein Kreativ-Modus → der Regler-Wert (aus Leitplanken/Briefing) gilt. Vorher stand
        // hier hart 'hybrid' und ein »nur Bestand« aus dem Briefing ging still verloren (demo Session #138).
        $p['bestand'] = $scope === 'rezept'
            ? (in_array($r['bestand'] ?? null, FoodAlchemistPlanningSession::ALLOWED_GENERATION_VALUES['bestand'], true) ? $r['bestand'] : 'hybrid')
            : match ((string) ($this->eingabe[$scope]['creative_mode'] ?? 'hybrid')) {
                'datenbank' => 'nur_bestand',
                default => 'hybrid',
            };
        // Frische (L1.5): Multi-Select Erlaubnis-Liste → harte gps.condition-Erlaubnis + primärer Pref
        // (Tiebreak). [] = egal (Key entfällt, kein Filter). Sonst: erlaubte Roh-Zustände + 'frisch'-Vorzug.
        $frischeSlugs = array_values(array_filter(
            (array) ($r['frische'] ?? []),
            static fn ($s) => isset(Index::FRISCHE_CONDITION[$s]),
        ));
        unset($p['frische']);   // Roh-Array wird übersetzt, nicht 1:1 durchgereicht
        if ($frischeSlugs !== []) {
            $p['frische_erlaubt'] = array_values(array_merge(...array_map(static fn ($s) => (array) Index::FRISCHE_CONDITION[$s], $frischeSlugs)));
            // Primärer Pref für den Match-Tiebreak (Generator mappt frisch|tk|konserve→VariantPref;
            // 'trocken' fällt auf fresh_first, egal — der harte Filter erledigt die Zustands-Auswahl).
            $p['frische'] = in_array('frisch', $frischeSlugs, true) ? 'frisch' : $frischeSlugs[0];
        }
        // L6 »Menge & Ziel«: Zahl-Achsen mit Band-Guard (ungültig/leer → Key entfällt = keine Vorgabe),
        // Saison als Enum-Durchreichung. Reine KI-Vorgaben (Prompt); deterministische Erzwingung Follow-up.
        unset($p['pax'], $p['ziel_portion_g'], $p['ziel_we_pct']);   // Roh-Felder → geparst durchreichen
        if (($pax = $this->intRegler($r['pax'] ?? '', 1, 100000)) !== null) {
            $p['pax'] = $pax;
        }
        // Ziel-Portion (g) ist eine per-Portion-Vorgabe (Gericht/Basisrezept). Für ein Concept (ganzes
        // Menü aus vielen Gängen mit je eigener Portion) ist eine einzelne Gramm-Zahl scope-fremd und
        // im Prompt nur Rauschen (Leitplanken-Hygiene 2026-08-18) → nur außerhalb des Concept-Scopes.
        if ($scope !== 'concept' && ($portion = $this->intRegler($r['ziel_portion_g'] ?? '', 1, 5000)) !== null) {
            $p['ziel_portion_g'] = $portion;
        }
        if (($wePct = $this->intRegler($r['ziel_we_pct'] ?? '', 1, 100)) !== null) {
            $p['ziel_we_pct'] = $wePct;
        }
        // Basisrezept-Ziel (scope=rezept): Menge (Dezimal erlaubt) + Einheit statt Pax/Portion — nur
        // zusammen und mit gültiger Einheit durchreichen (reiner KI-Hint, whitelist gegen MENGE_EINHEITEN).
        unset($p['ziel_menge'], $p['ziel_einheit']);
        $mengeRaw = str_replace(',', '.', trim((string) ($r['ziel_menge'] ?? '')));
        if ($mengeRaw !== '' && is_numeric($mengeRaw) && (float) $mengeRaw > 0
            && ($r['ziel_einheit'] ?? '') !== '' && isset(Index::MENGE_EINHEITEN[$r['ziel_einheit']])) {
            $p['ziel_menge'] = (float) $mengeRaw;
            $p['ziel_einheit'] = (string) $r['ziel_einheit'];
        }
        // Basisrezept: Pax/Portion haben in diesem Tab kein Eingabefeld. Kamen sie trotzdem in die Regler
        // (Assistent, Altsitzung), liefen sie unsichtbar neben dem Ansatz in den Lauf — demo Lauf #79:
        // 100 Pax × 80 g (8 kg) UND ziel_menge 2 kg. Ohne Ansatz → daraus ableiten; danach entfernen.
        if ($scope === 'rezept') {
            if (! isset($p['ziel_menge']) && isset($p['pax'], $p['ziel_portion_g'])) {
                $gramm = $p['pax'] * $p['ziel_portion_g'];
                $p['ziel_menge'] = $gramm >= 1000 ? round($gramm / 1000, 3) : (float) $gramm;
                $p['ziel_einheit'] = $gramm >= 1000 ? 'kg' : 'g';
            }
            unset($p['pax'], $p['ziel_portion_g']);
        }
        if (! isset(Index::SAISON_OPTIONEN[$p['saison'] ?? '']) || ($p['saison'] ?? '') === '') {
            unset($p['saison']);   // leer/unbekannt = keine Saison-Vorgabe
        }
        if (! $vk) {
            unset($p['occasion'], $p['serviceform'], $p['kompositions_stil']);
        }
        // Roh-Felder raus, die übersetzt (nicht 1:1) durchgereicht werden — inkl. der Menü-Roh-Eingaben
        // (sie werden unten als kanonische _pp-Keys geparst und nur für den Concept-Scope gesetzt).
        unset($p['favoriten'], $p['favoriten_conv_only'], $p['ki_bilder'], $p['ziel_vk'], $p['voll_anreichern'],
            $p['menue_typ'], $p['menue_gaenge'], $p['menue_preis_min'], $p['menue_preis_ziel'], $p['menue_preis_max'],
            $p['menue_quote_vegan'], $p['menue_quote_vegetarisch'], $p['menue_balance']);
        $p = array_filter($p, fn ($v) => $v !== '' && $v !== null && $v !== []);
        $p['use_favorites_list'] = $favoriten;
        $p['favorites_convenience_only'] = $favoriten && $favConvOnly;
        $p['ki_bilder'] = $kiBilder;   // Preisfrage: KI-Fotos bei Anreicherung ja/nein
        // Der Freigabe-Job liest `complete_coverage`. Fehlte der Key, fiel er historisch auf TRUE
        // zurück und ignorierte den ausgeschalteten UI-Schalter. Jetzt reist die Entscheidung explizit.
        $p['complete_coverage'] = $vollAnreichern;
        // Ziel-VK (Netto je Portion) ist die Gericht-Preisachse. Für ein Concept ist der Menü-Preis-
        // Korridor p. P. (unten) die EINZIGE Preisquelle (Entscheid 2026-08-18) — kein konkurrierender
        // Portions-VK im Concept-Prompt. rezept trägt ohnehin keinen VK ($vk deckt das mit ab).
        if ($scope === 'gericht' && ($ziel = $this->zielVkEur($scope)) !== null) {
            $p['ziel_vk_eur'] = $ziel;
        }
        if ($menue) {
            // Concept-Typ (#35): nur 'buffet' fließt als Signal ein — Menü/leer lässt den Key weg
            // (byte-identisch zum bisherigen Verhalten). Steuert Slot-Typ (station) + Gänge-Cap.
            if (($typ = $this->menueTyp($scope)) !== null) {
                $p['menue_typ'] = $typ;
            }
            // Menü-Leitplanken (Zusammenstellung): Anzahl Gänge + Zielpreis-Korridor je Person.
            // Nur gültige Werte fließen ein (ungültige/leer = keine Vorgabe → kein Key, Prompt unverändert).
            if (($g = $this->menueGaenge($scope)) !== null) {
                $p['menue_gaenge'] = $g;
            }
            if (($mn = $this->menuePreisEur($scope, 'menue_preis_min')) !== null) {
                $p['menue_preis_min_pp'] = $mn;
            }
            if (($mz = $this->menuePreisEur($scope, 'menue_preis_ziel')) !== null) {
                $p['menue_preis_ziel_pp'] = $mz;
            }
            if (($mx = $this->menuePreisEur($scope, 'menue_preis_max')) !== null) {
                $p['menue_preis_max_pp'] = $mx;
            }
            // Diät-Quoten (Portfolio-Anteil, weich): mind. X % der Positionen vegan/vegetarisch.
            if (($qv = $this->menueQuote($scope, 'menue_quote_vegan')) !== null) {
                $p['menue_quote_vegan_pct'] = $qv;
            }
            if (($qg = $this->menueQuote($scope, 'menue_quote_vegetarisch')) !== null) {
                $p['menue_quote_vegetarisch_pct'] = $qg;
            }
            // Portfolio-Balance (Menü-Vielfalt, weich): nur ein erlaubter Enum-Wert fliesst ein.
            if (($bal = $this->menueBalance($scope)) !== null) {
                $p['menue_balance'] = $bal;
            }
        }

        return $p;
    }

    /**
     * Menü-Leitplanken (nur Concept) auf ihre kanonischen menue_*-Keys reduziert — dieselbe Teilmenge,
     * die {@see PlanningCascadeService::dispatchConceptStep} an die Konzept-Erzeugung reicht. Damit
     * bekommen BEIDE Concept-Wege (Schnell-Go über den Job UND KI-Kopf inline) exakt denselben
     * Leitplanken-Satz. Leer für nicht-Concept-Scopes (keine Menü-Achsen).
     *
     * @return array<string,mixed>
     */
    public function menueAchsenFuer(string $scope): array
    {
        if ($scope !== 'concept') {
            return [];
        }

        return array_filter(
            $this->reglerParams($scope),
            static fn ($k) => str_starts_with((string) $k, 'menue_'),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * Prüft die Concept-Menü-Leitplanken auf mistgetippte Zahlen und liefert eine Klartext-Fehlermeldung
     * (oder null, wenn alles gültig/leer ist). Ein Mensch, der 45,x statt 45 meinte, wird GESAGT statt
     * still verworfen — derselbe Grundsatz wie bei Ziel-VK. Geteilt von {@see goKaskade} und {@see kiKopf},
     * damit der KI-Kopf-Pfad nicht mit einer stillschweigend verworfenen Vorgabe generiert.
     */
    public function menueLeitplankenFehler(string $scope): ?string
    {
        if ($scope !== 'concept') {
            return null;
        }
        foreach (['menue_preis_min' => 'Preis-Untergrenze', 'menue_preis_ziel' => 'Zielpreis', 'menue_preis_max' => 'Preis-Obergrenze'] as $feld => $lbl) {
            if (trim((string) ($this->regler[$scope][$feld] ?? '')) !== '' && $this->menuePreisEur($scope, $feld) === null) {
                return "Menü-{$lbl}: bitte einen Netto-Preis je Person zwischen 0,50 € und 2.000,00 € angeben (z. B. 45,00) — oder das Feld leer lassen.";
            }
        }
        if (trim((string) ($this->regler[$scope]['menue_gaenge'] ?? '')) !== '' && $this->menueGaenge($scope) === null) {
            return 'Menü-Gänge: bitte eine ganze Zahl zwischen 1 und 20 angeben (z. B. 4) — oder das Feld leer lassen.';
        }
        foreach (['menue_quote_vegan' => 'Vegan-Anteil', 'menue_quote_vegetarisch' => 'Vegetarisch-Anteil'] as $feld => $lbl) {
            if (trim((string) ($this->regler[$scope][$feld] ?? '')) !== '' && $this->menueQuote($scope, $feld) === null) {
                return "Menü-{$lbl}: bitte einen Prozentwert zwischen 0 und 100 angeben (z. B. 30) — oder das Feld leer lassen.";
            }
        }

        return null;
    }

    /** L6: ganze Zahl im Band [min,max] aus einem Roh-Regler, sonst null (leer/ungültig = keine Vorgabe). */
    private function intRegler(mixed $roh, int $min, int $max): ?int
    {
        $s = trim((string) $roh);
        if ($s === '' || ! ctype_digit($s)) {
            return null;
        }
        $n = (int) $s;

        return ($n >= $min && $n <= $max) ? $n : null;
    }

    /** Menü-Gänge/Positionen: ganze Zahl 1–20, sonst null (leer/ungültig = keine Vorgabe). Concept-Scope. */
    private function menueGaenge(string $scope): ?int
    {
        $roh = trim((string) ($this->regler[$scope]['menue_gaenge'] ?? ''));
        if ($roh === '' || ! ctype_digit($roh)) {
            return null;
        }
        $n = (int) $roh;

        return $n >= 1 && $n <= 20 ? $n : null;
    }

    /** Diät-Quote (Portfolio-Anteil): ganze Prozentzahl 0–100, sonst null (leer/ungültig = keine Vorgabe). Concept-Scope. */
    private function menueQuote(string $scope, string $feld): ?int
    {
        $roh = str_replace(['%', ' '], '', trim((string) ($this->regler[$scope][$feld] ?? '')));
        if ($roh === '' || ! ctype_digit($roh)) {
            return null;
        }
        $n = (int) $roh;

        return $n >= 0 && $n <= 100 ? $n : null;
    }

    /** Portfolio-Balance (Menü-Vielfalt): erlaubter {@see MENUE_BALANCE}-Enum-Wert, sonst null (leer/unbekannt = keine Vorgabe). Concept-Scope. */
    private function menueBalance(string $scope): ?string
    {
        $roh = trim((string) ($this->regler[$scope]['menue_balance'] ?? ''));

        return isset(Index::MENUE_BALANCE[$roh]) ? $roh : null;
    }

    /**
     * Concept-Typ (#35): NUR 'buffet' ist ein Signal (station-Slots + eigene Positionen-Logik). Menü/leer
     * → null (kein Key → byte-identisch: gang-Slots wie bisher). Bewusst asymmetrisch, damit bestehende
     * Menü-Flows unverändert bleiben. Concept-Scope. {@see MENUE_TYPEN}
     */
    private function menueTyp(string $scope): ?string
    {
        $roh = trim((string) ($this->regler[$scope]['menue_typ'] ?? ''));

        return $roh === 'buffet' ? 'buffet' : null;
    }

    /** Menü-Preis je Person (min/ziel/max): „45,00 €" → 45.0; außerhalb 0,50–2.000,00 € → null. Analog {@see zielVkEur}. */
    private function menuePreisEur(string $scope, string $feld): ?float
    {
        $roh = str_replace([' ', '€'], '', trim((string) ($this->regler[$scope][$feld] ?? '')));
        if ($roh === '') {
            return null;
        }
        $roh = str_replace(',', '.', $roh);
        if (! is_numeric($roh)) {
            return null;
        }
        $eur = round((float) $roh, 2);

        return $eur >= 0.5 && $eur <= 2000.0 ? $eur : null;
    }

    /** „8,50 €" → 8.5; außerhalb 0,50–500,00 € → null (Aufrufer meldet). Spiegel VkGeneratorModal. Per Scope. */
    public function zielVkEur(string $scope): ?float
    {
        $roh = str_replace([' ', '€'], '', trim((string) ($this->regler[$scope]['ziel_vk'] ?? '')));
        if ($roh === '') {
            return null;
        }
        $roh = str_replace(',', '.', $roh);
        if (! is_numeric($roh)) {
            return null;
        }
        $eur = round((float) $roh, 2);

        return $eur >= 0.5 && $eur <= 500.0 ? $eur : null;
    }

    /**
     * Effektiver Brief für die Kaskade: Basisrezept/Gericht = „Titel — Beschreibung" (Titel = form.title,
     * Beschreibung = form.brief), Concept = das Briefing (form.brief). Platzhalter-Titel der Schnell-
     * Erstellung zählen nicht als Titel. Leer → starteKaskade fällt auf briefAusSession zurück.
     */
    public function effektiverBrief(string $scope): string
    {
        $titel = trim((string) ($this->eingabe[$scope]['titel'] ?? ''));
        $besch = trim((string) ($this->eingabe[$scope]['brief'] ?? ''));
        if ($scope === 'concept') {
            return $besch;   // das Briefing ist die ganze Concept-Eingabe
        }
        if ($titel !== '' && $besch !== '') {
            return $titel . ' — ' . $besch;
        }

        return $besch !== '' ? $besch : $titel;
    }
}
