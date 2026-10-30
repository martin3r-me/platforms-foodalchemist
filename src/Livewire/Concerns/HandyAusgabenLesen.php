<?php

namespace Platform\FoodAlchemist\Livewire\Concerns;

use Illuminate\Support\Facades\Auth;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Services\SpeisekarteService;
use Platform\FoodAlchemist\Services\SpeiseplanService;

/**
 * #1003 PR6 · Foodbooks, Speisekarten und Speisepläne am Handy (die drei Ausgaben): Karten je Ausgabe mit Suche.
 * Derselbe Listenweg wie am Computer (paginateBrowser, team-sichtbar). Antippen öffnet die vorhandene Live-Vorschau
 * (/…/{id}/praesentation) — derselbe Renderer wie der öffentliche Link, Kundensicht ohne EK; „Intern“ öffnet bei Foodbook
 * und Speisekarte den vorhandenen Report im Profil Kalkulation. Editor, Kapitel,
 * Rubriken, Branding, KI, Veröffentlichen, Duplizieren und Löschen bleiben am Computer: am Handy laufen nur Suche
 * und Blättern. Die Komponente ruft handyAusgabe('<art>') am Anfang von render().
 */
trait HandyAusgabenLesen
{
    use HandyLesebereich;

    public function handyLeseMethoden(): array
    {
        return ['nextPage', 'previousPage', 'gotoPage', 'setPage', 'resetPage'];
    }

    /** $art: foodbook | speisekarte | speiseplan */
    private function handyAusgabe(string $art)
    {
        $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
        $ton = ['success' => 'ok', 'warning' => 'warn', 'danger' => 'crit', 'info' => 'info'];
        $mehrzahl = fn (int $n, string $eins, string $viele) => number_format($n, 0, ',', '.') . ' ' . ($n === 1 ? $eins : $viele);

        [$service, $titel, $route, $report, $suchHinweis, $unterzeile] = match ($art) {
            'foodbook' => [FoodbookService::class, 'Foodbooks', 'foodalchemist.foodbooks.praesentation', 'foodalchemist.foodbooks.report', 'Foodbook oder Kunde suchen',
                fn ($r) => [$r->label, implode(' · ', array_filter([$r->crmCompany?->display_name, $r->jahr, $mehrzahl((int) $r->chapters_count, 'Kapitel', 'Kapitel')]))]],
            'speisekarte' => [SpeisekarteService::class, 'Speisekarten', 'foodalchemist.speisekarte.praesentation', 'foodalchemist.speisekarte.report', 'Speisekarte suchen',
                fn ($r) => [$r->name, implode(' · ', array_filter([self::HANDY_KARTEN_TYP[$r->karten_typ] ?? null, $mehrzahl((int) $r->sections_count, 'Rubrik', 'Rubriken')]))]],
            'speiseplan' => [SpeiseplanService::class, 'Speisepläne', 'foodalchemist.speiseplan.praesentation', null, 'Plan suchen',
                fn ($r) => [$r->name, implode(' · ', array_filter([$r->start_date ? 'ab ' . $r->start_date->format('d.m.Y') : null,
                    $mehrzahl((int) $r->cycle_weeks, 'Woche', 'Wochen'), $mehrzahl((int) $r->entries_count, 'Eintrag', 'Einträge')]))]],
        };

        $eintraege = app($service)->paginateBrowser(['search' => $this->search], $team);

        return view('foodalchemist::livewire.handy.ausgaben', [
            'titel' => $titel,
            'suchHinweis' => $suchHinweis,
            'eintraege' => $eintraege,
            'karten' => $eintraege->getCollection()->map(function ($r) use ($unterzeile, $route, $report, $ton) {
                [$name, $zeile] = $unterzeile($r);
                $status = $r->statusWert();

                return [
                    'id' => $r->id,
                    'name' => $name !== null && $name !== '' ? $name : 'Ohne Namen',
                    'zeile' => $zeile,
                    'status' => $status->label(),
                    'ton' => $ton[$status->badgeVariant()] ?? 'neutral',
                    'href' => route($route, $r->id),
                    // Interne Sicht (Dominique 30.10.): der vorhandene Report im Profil Kalkulation (EK, VK, Wareneinsatz je Gericht) —
                    // derselbe Bericht wie „Bericht öffnen“ am Computer. Der Speiseplan hat keinen Report.
                    'intern' => $report !== null ? route($report, ['id' => $r->id, 'profil' => 'kalkulation']) : null,
                ];
            }),
        ])->layout(\Platform\FoodAlchemist\Support\FaShell::layout());
    }

    private const HANDY_KARTEN_TYP = ['alacarte' => 'À la carte', 'tageskarte' => 'Tageskarte', 'saisonkarte' => 'Saisonkarte',
        'getraenkekarte' => 'Getränkekarte', 'weinkarte' => 'Weinkarte'];
}
