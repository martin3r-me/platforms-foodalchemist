<?php

namespace Platform\FoodAlchemist\Livewire;

use Livewire\Component;

/**
 * R7 (Dominique): «In Planung»-Seite — die künftigen Domänen sind in der
 * Sidebar sichtbar, der Klick landet hier auf dem Phase-2-Überblick
 * (Scope-Stand: docs/14_ROADMAP_PHASE2.md, M10/M11+).
 */
class Demnaechst extends Component
{
    /** Statisch aus 14_ROADMAP_PHASE2 — bewusst kein DB-Zeug (reine Vorschau). */
    public const DOMAENEN = [
        ['icon' => '📕', 'name' => 'Foodbook / Portfolio', 'status' => 'M10 — als Nächstes geplant',
            'idee' => 'Speisekarten-/Menü-Builder: Kapitel → Blöcke (VK-Rezepte, Texte, Varianten), Schreibstil-Transformation des VK-Wordings in Brand-Voice, Preis-Snapshot beim Versand (V-25), PDF-Export (V-26).'],
        ['icon' => '🧮', 'name' => 'Kalkulation (HK2)', 'status' => 'M11+ — Brainstorming offen',
            'idee' => 'Produktions- und Produkt-Kalkulation auf Basis Herstellkosten 2: EK + Arbeitszeit × Stundensatz + Gemeinkosten-Zuschläge. Arbeitszeit je Rezept ist schon gepflegt.'],
        ['icon' => '🏭', 'name' => 'Produktionsplanung', 'status' => 'M11+ — Brainstorming offen',
            'idee' => 'Produktionsaufträge aus Bestellmengen → skalierte Basisrezepte (Yield-Mathematik vorhanden), Tagespläne je Station/Equipment.'],
        ['icon' => '📅', 'name' => 'Speiseplan', 'status' => 'M11+ — Brainstorming offen',
            'idee' => 'Wochen-/Zyklenpläne aus VK-Rezepten mit Diät- und Allergen-Abdeckung; Sektor-Eignung als Filter.'],
        ['icon' => '🛒', 'name' => 'Einkauf', 'status' => 'M11+ — Brainstorming offen',
            'idee' => 'Bestellvorschläge aus Produktionsplan × Lead-LA — die Vorbestellzeiten (V-29) sind als Felder schon importiert.'],
        ['icon' => '📦', 'name' => 'Lager', 'status' => 'M11+ — Brainstorming offen',
            'idee' => 'Bestände je Artikel/GP, Wareneingang gegen Bestellung, Chargen für die Allergen-Rückverfolgung.'],
        ['icon' => '📊', 'name' => 'Controlling', 'status' => 'M11+ — Brainstorming offen',
            'idee' => 'Soll/Ist-Wareneinsatz, Margen-Trends, KI-Kosten-Auswertung.'],
    ];

    public function render()
    {
        return view('foodalchemist::livewire.demnaechst', ['domaenen' => self::DOMAENEN])
            ->layout('platform::layouts.app');
    }
}
