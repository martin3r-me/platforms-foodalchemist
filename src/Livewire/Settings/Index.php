<?php

namespace Platform\FoodAlchemist\Livewire\Settings;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * M1-01 / D-1 §4: Settings-Gerüst — vertikale Sektions-Navigation, jede Sektion
 * eine eigene URL (V-17: kein Tab-State-Verlust). Die Sektionen selbst sind
 * eigenständige Livewire-Komponenten (Isolation, lazy pro Route).
 *
 * Edit-Gating verantwortet JEDE Sektion selbst — es gibt kein zentrales Gate im Gerüst.
 * Der Vertrag pro mutierender Sektion (MVP-039/041): serverseitig `TeamScope::owns()` bzw.
 * `isOwnedBy()` vor jedem Write; globale (team_id NULL) und geerbte Zeilen sind read-only.
 * `Curate::canCurate` blendet im UI die Buttons aus — es ersetzt den Server-Guard NICHT
 * (UI versteckt, Server verweigert; nie nur eins von beidem). Aufschlagsklassen war die
 * Ausnahme, die den früheren pauschalen „row-gated"-Kommentar widerlegte; jetzt geführt.
 */
class Index extends Component
{
    public string $sektion = 'einheiten';

    /**
     * @var array<string, array{label: string, hint: string}>
     *
     * Reihenfolge = FA-Arbeits-Kaskade (2026-08-28, Dominique): erst die Stammdaten/Vokabulare,
     * auf die alles zugreift, dann Einkauf→Kalkulation→Preise, dann KI & Kreativ-Steuerung, dann
     * Wissen, dann Produktion, zuletzt Ausgabe/Betrieb. Bewusst KEINE UI-Gruppen (mehrere Sektionen
     * gehören funktional in zwei Töpfe zugleich — Küchen-Profil ist Produktion UND Generator-Default,
     * Wissens-Kategorien Vokabular UND KI-Futter). Die Blöcke unten sind reine Sortier-Kommentare,
     * sie rendern nicht. `einheiten` bleibt zuerst → mount()-Default unverändert. Ein Filter über der
     * Liste (index.blade.php) macht die Reihenfolge nebensächlich fürs schnelle Finden.
     */
    public const SEKTIONEN = [
        // — Stammdaten & Vokabulare —
        'einheiten' => ['label' => 'Einheiten', 'hint' => 'Gramm-/ml-Defaults, Stück-Gewichte (GL-02/GL-11)'],
        'warengruppen' => ['label' => 'Warengruppen & Sub-Kategorien', 'hint' => '§3-Codes fix · Sub-Kategorien-Housekeeping'],
        'taxonomie' => ['label' => 'Rezept-Taxonomie', 'hint' => 'Hauptgruppen + Kategorien (M4-Browser-Bäume)'],
        'vk-taxonomie' => ['label' => 'VK-Taxonomie', 'hint' => 'Speisen-Hauptgruppen → Klassen mit Rezept-Zählern (D-6 §4.6)'],
        'behaelter' => ['label' => 'Behälter & Geräte', 'hint' => 'Behälter · Regen-Geräte · Servier-Vehikel · Koch-Equipment'],
        // Konzept-Taxonomie (Kategorie/Klasse) ausgemustert 2026-07-25 (Dominique): Concept-Picker filtern
        // jetzt auf die Concepter-Dimensionen. Komponente/Route/DB bleiben (nicht-destruktiv), nur aus dem Nav raus.
        'concepter-dimensionen' => ['label' => 'Concepter-Dimensionen', 'hint' => 'Facetten: Einsatzmoment · Eventtyp · Saison · Servierform (Darreichungs-Scharnier)'],

        // — Einkauf, Kalkulation & Preise —
        'einkauf' => ['label' => 'Einkauf & Lead-LA', 'hint' => 'Lead-Strategie (V-27) · Stamm-Lieferanten-Matrix · Lagerorte'],
        'kalkulation' => ['label' => 'Kalkulation', 'hint' => 'Gar-/Putzverlust-, MwSt-Defaults, Rundung (GL-02)'],
        // #502 (2026-07-13): Regel-Cockpit zurück unter Einstellungen (Werkstatt aufgelöst) —
        //   Zuschläge, Fixkosten, Stundensatz, Marge. MwSt-Defaults liegen unter 'kalkulation'.
        'herstellkosten' => ['label' => 'Herstellkosten & Zuschläge', 'hint' => 'Zuschlagsschema, Fixkosten, Stundensatz, Marge — rollt auf HK2/VK aus (#379/#502)'],
        // R5 (Dominique): eigene Seiten statt Sammel-Sektion — mit Anlegen/Bearbeiten
        'aufschlagsklassen' => ['label' => 'Preisklassen', 'hint' => 'Relative Faktoren auf den dynamischen Unternehmens-Basissatz'],

        // — KI & Kreativ-Steuerung (speist die KI-Generierung) —
        'ki' => ['label' => 'KI', 'hint' => 'Provider · Tiering (V-01) · Nutzung · Kill-Switch (M7-08)'],
        'kueche' => ['label' => 'Küchen-Profil', 'hint' => 'Mandanten-Tendenz für den Generator (M7-07, Hooks gewinnen)'],
        // Ebene 1 der DNA-Kette (Umzug 2026-07-21): Team-Food-DNA wohnt bei den Einstellungen, nicht als Top-Level-Nav
        'food-dna' => ['label' => 'Food DNA (Identität)', 'hint' => 'Leitbild · Signature-Stil · Aromatik · No-Gos · Schreibstil — stehende KI-Referenz (Ebene 1)'],
        // Spec 42 F3: Kunde-DNA (Ebene 2) zog aus dem Foodbook hierher — Marken-DNA gehört zum Kunden, nicht pro Foodbook.
        'kunde-dna' => ['label' => 'Kunde-DNA (pro Kunde)', 'hint' => 'Marken-Positionierung · Ton · No-Gos · Schreibstil je CRM-Kunde — Ebene 2 der DNA-Kette'],
        'schreibstile' => ['label' => 'Schreibstile', 'hint' => 'Sprach-Duktus = Prompt-Material (GL-06) · anlegen + bearbeiten'],
        'brief-vorlagen' => ['label' => 'Schnellstart-Vorlagen', 'hint' => 'Brief-Templates für die Planung-Erzeugung — im Editor anlegen (Snapshot), hier verwalten (auch per MCP)'],
        'trendradar' => ['label' => 'Trendradar', 'hint' => '08:00-Konzept-Automatisierung an/aus · Signal · Trends jetzt importieren & clustern'],

        // — Wissen (#469: Vokabular, das die KI mit Wissen füttert) —
        'wissenskategorien' => ['label' => 'Wissens-Kategorien', 'hint' => 'Vokabular fürs Wissens-Modul (#469) — Klassifikation + grobe Routing-Ebene'],
        'einsatzorte' => ['label' => 'Einsatzorte (Wissen)', 'hint' => 'Bindungs-Ziele fürs Wissen (#469) — Bereiche grob + KI-Prompts fein'],

        // — Produktion & Kapazität —
        // Spec 30 E3: Arbeitsplätze mit optionaler Tageskapazität — bewusst getrennt vom
        // Koch-Equipment (das sagt „was braucht ein Rezept", der Posten „wo wird gearbeitet").
        'posten' => ['label' => 'Posten & Kapazität', 'hint' => 'Küchen-Arbeitsplätze · netto verplanbare Minuten/Tag (freiwillig) · Wochentag-Abweichungen'],
        // Stufe 3 P3.1: Rollen als Kostenträger (Küchenchef/Koch/Hilfskoch) — Satz je Rolle.
        // Rolle ≠ Mensch: keine Namen/Schichten. Posten-Besetzung leitet Kapazität + Kosten ab.
        'rollen' => ['label' => 'Rollen & Sätze', 'hint' => 'Küchen-Rollen als Kostenträger · €/Std je Rolle · speist Kapazität + Produktionskosten'],

        // — Ausgabe & Betrieb —
        // Spec 43: visueller Struktur-Builder für Präsentations-Designs (Block-Palette · Live-Vorschau · Tokens)
        'praesentations-designs' => ['label' => 'Präsentations-Designs', 'hint' => 'Visueller Struktur-Builder fürs digitale Kundenbuch — Blöcke · Live-Vorschau · Farben/Typo (Spec 43)'],
        // Spec 33 P2: Die Tabelle gab es seit Spec 19, die Pflege nie — deshalb war sie leer
        // und `outlet_id` an der Speisekarte hatte nicht einmal ein Eingabefeld.
        'betriebe' => ['label' => 'Betriebe & Standorte', 'hint' => 'Trägt die Betriebsbrille im Controlling — welcher Standort fährt welche Ausgabe'],
    ];

    public function mount(string $sektion = 'einheiten'): void
    {
        abort_unless(array_key_exists($sektion, self::SEKTIONEN), 404);
        $this->sektion = $sektion;
    }

    public function render()
    {
        $team = Auth::user()?->currentTeamRelation;

        return view('foodalchemist::livewire.settings.index', [
            'sektionen' => self::SEKTIONEN,
            'istKindTeam' => $team !== null && $team->parent_team_id !== null,
        ])->layout('platform::layouts.app');
    }
}
