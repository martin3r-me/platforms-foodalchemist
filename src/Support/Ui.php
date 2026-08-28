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
    /**
     * @return array<string, mixed>
     */
    public static function maps(): array
    {
        return [
            // ── Flächen
            'card' => 'rounded-xl bg-white/60 backdrop-blur-xl border border-white/20 shadow-sm shadow-black/5',
            'cardAccent' => 'absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-violet-500/50 to-transparent',
            // Modal-Sektion als frosted Card (UX-Umbau 2026-07-03): hebt die borderless Inputs
            // vom Modal-Grund ab → Kontrast statt Grau-auf-Grau. Von <x-foodalchemist::modal-section> genutzt.
            // 2026-07-31: nahezu opake, klar begrenzte Karte → schwebt deutlich auf dem dunklen
            // Editor-Canvas (DESIGN.md-Tiefe im Light-Theme nachgebaut, kein dark: — README §158).
            'sectionCard' => 'rounded-xl bg-white/90 border border-white/40 shadow-lg p-4',
            // Dunkler Editor-Canvas (Body-Grund hinter den Karten) — nur die grossen Editoren nutzen ihn
            // (Modal-Prop darkCanvas / Concepter-Seite). Light-Theme, kein dark:. Text lebt in den Karten.
            'editorCanvas' => 'bg-gradient-to-b from-slate-700 to-slate-800',
            // Neutrale KPI-Kachel (frosted White-Card + Accent-Haarlinie) — löst das flächige
            // bg-black/[0.03]-Grau in den Modal-Köpfen ab; Lead-KPIs bleiben orange/emerald.
            'kpiTile' => 'relative overflow-hidden rounded-lg bg-white/60 border border-white/20 shadow-sm shadow-black/5 px-3 py-2',
            'kpiTileAccent' => 'absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-violet-500/40 to-transparent',

            // ── Formulare
            'input' => 'w-full px-3 py-1.5 text-xs bg-black/[0.03] rounded-lg border-0 placeholder-gray-400 focus:ring-2 focus:ring-violet-500/20 focus:bg-white transition-all duration-150',

            // ── Typo
            'label' => 'text-[10px] font-medium uppercase tracking-wider text-gray-600',

            // ── Tabelle (R14 Jarvis-Skala: 12px wie .data-table, Header 11px, py-1/px-3)
            'table' => 'w-full text-xs',
            'th' => 'px-3 py-1.5 text-[11px] font-medium uppercase tracking-wider text-gray-600 whitespace-nowrap',
            'td' => 'px-3 py-1',
            'tr' => 'border-t border-black/5 hover:bg-gradient-to-r hover:from-violet-500/5 hover:to-indigo-500/5 transition-all duration-150',

            // ── Definition-Listen (Detail-Sektionen)
            'row' => 'flex justify-between gap-4 py-1.5',
            'dt' => 'text-[10px] font-medium uppercase tracking-wider text-gray-600',   // Jarvis .detail h3
            'dd' => 'text-gray-900 text-right',

            // ── Pills
            'pill' => 'inline-flex px-1.5 py-px rounded-full text-[11px]',
            // #4 (Dominique 2026-08-27): einheitliche Status-Ampel über GP + Rezept/Gericht.
            // GP-Lebenszyklus: freigegeben=grün · vorläufig=orange · abgelehnt=rot · merged=grau.
            // Rezept/Gericht (RecipeStatus teilt `approved`=freigegeben=grün): Entwurf=grau ·
            // Review=orange · Veraltet=rot · Stub=grau. Vorher fehlten draft/review/… → Review fiel
            // auf secondary/grau zurück (die „veraltete" Farbe). Additiv: GP-Keys byte-identisch.
            'statusPill' => [
                'approved' => 'bg-emerald-500/10 text-emerald-600',   // freigegeben — grün
                'tentative' => 'bg-amber-500/10 text-amber-600',      // vorläufig — orange
                'rejected' => 'bg-red-500/10 text-red-600',           // abgelehnt — rot
                'merged' => 'bg-black/5 text-gray-600',
                'draft' => 'bg-black/5 text-gray-600',                // Entwurf — grau
                'review' => 'bg-amber-500/10 text-amber-600',         // Review — orange
                'deprecated' => 'bg-red-500/10 text-red-600',         // Veraltet — rot
                'stub' => 'bg-black/5 text-gray-500',                 // Stub — grau (dezent)
            ],
            'variantPill' => [
                'danger' => 'bg-red-500/10 text-red-600',
                'warning' => 'bg-amber-500/10 text-amber-600',
                'success' => 'bg-emerald-500/10 text-emerald-600',
                'secondary' => 'bg-black/5 text-gray-600',
                'info' => 'bg-sky-500/10 text-sky-600',
                'primary' => 'bg-violet-500/10 text-violet-600',
            ],

            // ── Buttons
            'btnPrimary' => 'inline-flex items-center whitespace-nowrap gap-2 px-3.5 py-2 text-[13px] font-medium text-white bg-gradient-to-r from-violet-500 to-indigo-500 rounded-lg shadow-sm shadow-violet-500/25 hover:shadow-md hover:shadow-violet-500/30 transition-all duration-150',
            'btnGhost' => 'inline-flex items-center whitespace-nowrap gap-2 px-3.5 py-2 text-[13px] font-medium text-gray-600 bg-white/60 backdrop-blur-sm border border-black/5 rounded-lg hover:bg-white/80 transition-all duration-150',
            'btnGhostXs' => 'inline-flex items-center whitespace-nowrap gap-1 px-2 py-0.5 text-[11px] font-medium text-gray-600 bg-white/60 border border-black/5 rounded-md hover:bg-white/80 transition-all duration-150',
            // KI-Aktion (2026-07-31): löst die ✨-Emoji-Ghostbuttons ab — dezent violette Chip-Optik
            // mit Inset-Ring statt Emoji. Icon via @svg('heroicon-o-sparkles', 'w-3.5 h-3.5') davor.
            'btnAi' => 'inline-flex items-center whitespace-nowrap gap-1.5 px-2.5 py-1 text-[11px] font-medium text-violet-600 bg-violet-500/10 rounded-md ring-1 ring-inset ring-violet-500/20 hover:bg-violet-500/[0.16] hover:ring-violet-500/30 transition-all duration-150',
        ];
    }
}
