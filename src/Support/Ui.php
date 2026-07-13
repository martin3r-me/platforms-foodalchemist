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
            'card' => 'rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-white/20 dark:border-white/10 shadow-sm shadow-black/5',
            'cardAccent' => 'absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-violet-500/50 to-transparent',
            // Modal-Sektion als frosted Card (UX-Umbau 2026-07-03): hebt die borderless Inputs
            // vom Modal-Grund ab → Kontrast statt Grau-auf-Grau. Von <x-foodalchemist::modal-section> genutzt.
            'sectionCard' => 'rounded-xl bg-white/55 dark:bg-white/[0.04] border border-white/20 dark:border-white/10 shadow-sm shadow-black/5 p-4',
            // Neutrale KPI-Kachel (frosted White-Card + Accent-Haarlinie) — löst das flächige
            // bg-black/[0.03]-Grau in den Modal-Köpfen ab; Lead-KPIs bleiben orange/emerald.
            'kpiTile' => 'relative overflow-hidden rounded-lg bg-white/60 dark:bg-white/5 border border-white/20 dark:border-white/10 shadow-sm shadow-black/5 px-3 py-2',
            'kpiTileAccent' => 'absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-violet-500/40 to-transparent',

            // ── Formulare
            'input' => 'w-full px-3 py-1.5 text-xs bg-black/[0.03] dark:bg-white/5 rounded-lg border-0 placeholder-gray-400 focus:ring-2 focus:ring-violet-500/20 focus:bg-white dark:focus:bg-white/10 transition-all duration-150',

            // ── Typo
            'label' => 'text-[10px] font-medium uppercase tracking-wider text-gray-600 dark:text-gray-400',

            // ── Tabelle (R14 Jarvis-Skala: 12px wie .data-table, Header 11px, py-1/px-3)
            'table' => 'w-full text-xs',
            'th' => 'px-3 py-1.5 text-[11px] font-medium uppercase tracking-wider text-gray-600 dark:text-gray-400 whitespace-nowrap',
            'td' => 'px-3 py-1',
            'tr' => 'border-t border-black/5 dark:border-white/10 hover:bg-gradient-to-r hover:from-violet-500/5 hover:to-indigo-500/5 transition-all duration-150',

            // ── Definition-Listen (Detail-Sektionen)
            'row' => 'flex justify-between gap-4 py-1.5',
            'dt' => 'text-[10px] font-medium uppercase tracking-wider text-gray-600 dark:text-gray-400',   // Jarvis .detail h3
            'dd' => 'text-gray-900 dark:text-gray-100 text-right',

            // ── Pills
            'pill' => 'inline-flex px-1.5 py-px rounded-full text-[11px]',
            'statusPill' => [
                'approved' => 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
                'tentative' => 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
                'rejected' => 'bg-red-500/10 text-red-600 dark:text-red-400',
                'merged' => 'bg-black/5 dark:bg-white/10 text-gray-600 dark:text-gray-400',
            ],
            'variantPill' => [
                'danger' => 'bg-red-500/10 text-red-600 dark:text-red-400',
                'warning' => 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
                'success' => 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
                'secondary' => 'bg-black/5 dark:bg-white/10 text-gray-600 dark:text-gray-400',
                'info' => 'bg-sky-500/10 text-sky-600 dark:text-sky-400',
                'primary' => 'bg-violet-500/10 text-violet-600 dark:text-violet-400',
            ],

            // ── Buttons
            'btnPrimary' => 'inline-flex items-center whitespace-nowrap gap-2 px-3.5 py-2 text-[13px] font-medium text-white bg-gradient-to-r from-violet-500 to-indigo-500 rounded-lg shadow-sm shadow-violet-500/25 hover:shadow-md hover:shadow-violet-500/30 transition-all duration-150',
            'btnGhost' => 'inline-flex items-center whitespace-nowrap gap-2 px-3.5 py-2 text-[13px] font-medium text-gray-600 dark:text-gray-300 bg-white/60 dark:bg-white/5 backdrop-blur-sm border border-black/5 dark:border-white/10 rounded-lg hover:bg-white/80 dark:hover:bg-white/10 transition-all duration-150',
            'btnGhostXs' => 'inline-flex items-center whitespace-nowrap gap-1 px-2 py-0.5 text-[11px] font-medium text-gray-600 dark:text-gray-300 bg-white/60 dark:bg-white/5 border border-black/5 dark:border-white/10 rounded-md hover:bg-white/80 dark:hover:bg-white/10 transition-all duration-150',
        ];
    }
}
