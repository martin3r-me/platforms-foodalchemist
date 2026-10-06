<?php

namespace Platform\FoodAlchemist\Support;

/**
 * M0-12: Zentrale Dichte-/Klassen-Maps — Ist-App-Dichte in DESIGN.md-Optik
 * (Linear/Raycast, frosted). EINZIGE Quelle für wiederkehrende Content-Klassen;
 * keine Insellösungen in Views/Bausteinen (Roadmap Standard-DoD).
 *
 * Nutzung in Views (Variablennamen bleiben sprechend):
 *     @php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))
 *     <div class="{{ $card }} p-5">…
 *
 * Hinweis zur Roadmap-Formulierung „livewire/_density.blade.php": Blade-@include
 * leakt keine Variablen in den Eltern-Scope — deshalb Support-Klasse statt Partial
 * (dokumentiert in 12_ROADMAP M0-12).
 */
final class Ui
{
    // Design „Labor und Tageslicht" (fa-pass, 2026-10-05): flache Flächen statt Milchglas,
    // Labels 12 px normal geschrieben, Akzent Grünspan (violet-* ist in foodalchemist-pass.css umgemappt).

    /**
     * @return array<string, mixed>
     */
    public static function maps(): array
    {
        return [
            // ── Flächen
            'card' => 'rounded-[10px] bg-[var(--fa-surface)] border border-[var(--fa-line)]',
            'cardAccent' => 'hidden',
            // Modal-Sektion als frosted Card (UX-Umbau 2026-07-03): hebt die borderless Inputs
            // vom Modal-Grund ab → Kontrast statt Grau-auf-Grau. Von <x-foodalchemist::modal-section> genutzt.
            // 2026-07-31: nahezu opake, klar begrenzte Karte → schwebt deutlich auf dem dunklen
            // Editor-Canvas (DESIGN.md-Tiefe im Light-Theme nachgebaut, kein dark: — README §158).
            'sectionCard' => 'rounded-[10px] bg-[var(--fa-surface)] border border-[var(--fa-line)] p-4',
            // Dunkler Editor-Canvas (Body-Grund hinter den Karten) — nur die grossen Editoren nutzen ihn
            // (Modal-Prop darkCanvas / Concepter-Seite). Light-Theme, kein dark:. Text lebt in den Karten.
            'editorCanvas' => 'bg-[var(--fa-ground)]',
            // Neutrale KPI-Kachel (frosted White-Card + Accent-Haarlinie) — löst das flächige
            // bg-black/[0.03]-Grau in den Modal-Köpfen ab; Lead-KPIs bleiben orange/emerald.
            'kpiTile' => 'rounded-lg bg-[var(--fa-surface)] border border-[var(--fa-line)] px-3 py-2',
            'kpiTileAccent' => 'hidden',

            // ── Formulare
            'input' => 'w-full h-8 px-2.5 text-[13px] text-gray-900 bg-[var(--fa-surface)] rounded-md border border-gray-300 placeholder-gray-500 focus:border-violet-600 focus:ring-2 focus:ring-violet-600/15 transition-colors duration-150',

            // ── Typo
            'label' => 'text-[12px] font-medium text-gray-600',

            // ── Tabelle (R14 Jarvis-Skala: 12px wie .data-table, Header 11px, py-1/px-3)
            'table' => 'w-full text-[13px]',
            'th' => 'px-3 py-2 text-[12px] font-medium text-gray-600 whitespace-nowrap',
            'td' => 'px-3 py-1.5',
            'tr' => 'border-t border-gray-200 hover:bg-gray-50 transition-colors duration-100',

            // ── Definition-Listen (Detail-Sektionen)
            'row' => 'flex justify-between gap-4 py-1.5',
            'dt' => 'text-[12px] font-medium text-gray-600',   // Jarvis .detail h3
            'dd' => 'text-gray-900 text-right',

            // ── Pills
            'pill' => 'inline-flex items-center h-[22px] px-2 rounded-full text-[12px] font-medium',
            // #4 (Dominique 2026-08-27): einheitliche Status-Ampel über GP + Rezept/Gericht.
            // GP-Lebenszyklus: freigegeben=grün · vorläufig=orange · abgelehnt=rot · merged=grau.
            // Rezept/Gericht (RecipeStatus teilt `approved`=freigegeben=grün): Entwurf=grau ·
            // Review=orange · Veraltet=rot · Stub=grau. Vorher fehlten draft/review/… → Review fiel
            // auf secondary/grau zurück (die „veraltete" Farbe). Additiv: GP-Keys byte-identisch.
            'statusPill' => [
                'approved' => 'bg-emerald-50 text-emerald-700',   // freigegeben — grün
                'tentative' => 'bg-amber-50 text-amber-800',      // vorläufig — orange
                'rejected' => 'bg-red-50 text-red-700',           // abgelehnt — rot
                'merged' => 'bg-gray-100 text-gray-600',
                'draft' => 'bg-gray-100 text-gray-600',                // Entwurf — grau
                'review' => 'bg-amber-50 text-amber-800',         // Review — orange
                'deprecated' => 'bg-red-50 text-red-700',         // Veraltet — rot
                'stub' => 'bg-gray-100 text-gray-500',                 // Stub — grau (dezent)
            ],
            'variantPill' => [
                'danger' => 'bg-red-50 text-red-700',
                'warning' => 'bg-amber-50 text-amber-800',
                'success' => 'bg-emerald-50 text-emerald-700',
                'secondary' => 'bg-gray-100 text-gray-600',
                'info' => 'bg-sky-50 text-sky-800',
                'primary' => 'bg-violet-50 text-violet-700',
            ],

            // ── Buttons
            'btnPrimary' => 'inline-flex items-center justify-center whitespace-nowrap gap-2 h-9 px-3.5 text-[13px] font-medium text-[var(--fa-on-accent)] bg-violet-600 rounded-md hover:bg-violet-700 transition-colors duration-150',
            'btnGhost' => 'inline-flex items-center justify-center whitespace-nowrap gap-2 h-9 px-3.5 text-[13px] font-medium text-gray-800 bg-[var(--fa-surface)] border border-gray-300 rounded-md hover:bg-gray-50 transition-colors duration-150',
            'btnGhostXs' => 'inline-flex items-center whitespace-nowrap gap-1 h-7 px-2 text-[12px] font-medium text-gray-700 bg-[var(--fa-surface)] border border-gray-300 rounded-md hover:bg-gray-50 transition-colors duration-150',
            // KI-Aktion (2026-07-31): löst die ✨-Emoji-Ghostbuttons ab — dezent violette Chip-Optik
            // mit Inset-Ring statt Emoji. Icon via @svg('heroicon-o-sparkles', 'w-3.5 h-3.5') davor.
            'btnAi' => 'inline-flex items-center whitespace-nowrap gap-1.5 h-7 px-2.5 text-[12px] font-medium text-violet-700 bg-violet-50 rounded-md ring-1 ring-inset ring-violet-200 hover:bg-violet-100 transition-colors duration-150',
        ];
    }
}
