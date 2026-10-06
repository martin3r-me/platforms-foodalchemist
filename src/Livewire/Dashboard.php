<?php

namespace Platform\FoodAlchemist\Livewire;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\On;
use Livewire\Component;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistSignal;
use Platform\FoodAlchemist\Services\ActiveOutletContext;
use Platform\FoodAlchemist\Services\KpiService;

/**
 * R6 (Dominique: «Dashboard komplett ausbauen nach deinem Gusto»):
 * Bestands-KPIs (KpiService, 60-s-Cache) + Workflow-Zähler (Review-Pipeline,
 * Allergen-Lücken, ungemappte Zutaten) + KI-Nutzung — alles klickbar in den
 * jeweiligen Browser mit vorgesetztem Filter (URL-Parameter, #[Url]-Vertrag).
 *
 * fa-pass (2026-10-05): Einstieg «Was ist heute zu tun?». Zusätzlich rein lesend:
 * offene Signale nach Schweregrad und offene Vorschläge (dieselben Zählungen wie die
 * «Zu prüfen»-Seite, damit beide Orte dieselbe Zahl zeigen). `aufgaben` ist reine
 * Anzeige-Aufbereitung: kritisch zuerst, erledigte Punkte ans Ende.
 */
class Dashboard extends Component
{
    public function rendered()
    {
        $this->dispatch('comms', [
            'model' => null,
            'modelId' => null,
            'subject' => 'Food Alchemist Dashboard',
            'description' => 'Bestands- und Workflow-Übersicht',
            'url' => route('foodalchemist.dashboard'),
            'source' => 'foodalchemist.dashboard',
            'recipients' => [],
            'meta' => ['view_type' => 'dashboard'],
        ]);
    }

    /** Betriebswechsel in der Kopfzeile → Signale/Kennzahlen neu rendern (wie ReviewQueue). */
    #[On('aktiver-betrieb-geaendert')]
    public function betriebGewechselt(): void
    {
        // Leer: der Listener löst den Re-Render aus, render() liest den aktiven Betrieb neu.
    }

    public function render(KpiService $kpis)
    {
        $team = Auth::user()?->currentTeamRelation;
        $kette = $team !== null ? FoodAlchemistGp::teamAncestryIds($team) : [];

        $rezept = fn () => DB::table('foodalchemist_recipes')->whereIn('team_id', $kette)->whereNull('deleted_at');

        $workflow = $team === null ? [] : [
            'basis' => (clone $rezept())->where('is_sales_recipe', false)->count(),
            'vk' => (clone $rezept())->where('is_sales_recipe', true)->count(),
            'templates' => (clone $rezept())->where('is_template', true)->count(),
            'review' => (clone $rezept())->where('status', 'review')->count(),
            'draft' => (clone $rezept())->where('status', 'draft')->count(),
            'approved' => (clone $rezept())->where('status', 'approved')->count(),
            'allergen_low' => (clone $rezept())->whereIn('allergens_confidence', ['low', 'unknown'])->count(),
            'ungemappt' => (clone $rezept())->where('n_ingredients_unmapped', '>', 0)->count(),
            'vk_ohne_klasse' => (clone $rezept())->where('is_sales_recipe', true)->whereNull('dish_class_id')->count(),
        ];

        $ki = ['calls' => 0, 'accepted' => 0];
        if ($team !== null && Schema::hasTable('foodalchemist_ai_call_log')) {
            $ki = [
                'calls' => DB::table('foodalchemist_ai_call_log')->where('team_id', $team->id)->count(),
                'accepted' => DB::table('foodalchemist_ai_call_log')->where('team_id', $team->id)->whereNotNull('accepted_at')->count(),
            ];
        }

        // Anzeige: offene Signale je Schweregrad, gleiche Sicht wie ReviewQueue (Betriebsbrille).
        $signale = ['kritisch' => 0, 'warnung' => 0, 'info' => 0];
        if ($team !== null && Schema::hasTable('foodalchemist_signals')) {
            $outlet = app(ActiveOutletContext::class)->current($team);
            $signale = array_merge($signale, FoodAlchemistSignal::visibleToTeam($team)->offen()->lane($outlet)
                ->selectRaw('severity, COUNT(*) as c')->groupBy('severity')->pluck('c', 'severity')
                ->map(fn ($c) => (int) $c)->all());
        }

        // Anzeige: offene Vorschläge (KI-Anreicherung + LA→GP-Zuordnung), gleiche Zählung wie ReviewQueue.
        $vorschlaege = ['bulk' => 0, 'match' => 0];
        if ($team !== null) {
            if (Schema::hasTable('foodalchemist_bulk_proposals')) {
                $vorschlaege['bulk'] = DB::table('foodalchemist_bulk_proposals AS b')
                    ->join('foodalchemist_recipes AS r', 'r.id', '=', 'b.recipe_id')
                    ->where('b.status', 'offen')->whereIn('b.team_id', $kette)->count();
            }
            if (Schema::hasTable('foodalchemist_match_proposals')) {
                $vorschlaege['match'] = DB::table('foodalchemist_match_proposals AS p')
                    ->join('foodalchemist_supplier_items AS i', 'i.id', '=', 'p.supplier_item_id')
                    ->join('foodalchemist_gps AS g', 'g.id', '=', 'p.gp_id')
                    ->where('p.team_id', $team->id)
                    ->where('p.status', 'offen')->whereNull('p.deleted_at')->count();
            }
        }

        return view('foodalchemist::livewire.dashboard', [
            'kpis' => $kpis->forTeam($team),
            'workflow' => $workflow,
            'ki' => $ki,
            'signale' => $signale,
            'vorschlaege' => $vorschlaege,
            'aufgaben' => $this->aufgaben($workflow, $signale, $vorschlaege),
            'teamName' => $team?->name,
            // Spec 32: der R2.7-Benchmark ist ins Controlling-Zentrum gezogen. Er kostete hier
            // einen `kpisFuerTeam`-Lauf JE Peer-Team bei jedem Dashboard-Aufruf — für eine
            // Kennzahl, die niemand auf der Bestandsübersicht sucht.
        ])->layout('foodalchemist::layouts.standalone');
    }

    /**
     * Reine Anzeige-Aufbereitung: jede offene Aufgabe mit Zahl, Ton und Ziel-Link.
     * Reihenfolge = Dringlichkeit (Gastsicherheit vor Datenpflege), erledigte (0) ans Ende.
     *
     * @return list<array{key: string, icon: string, titel: string, text: string, zahl: int, ton: string, url: string}>
     */
    private function aufgaben(array $workflow, array $signale, array $vorschlaege): array
    {
        $review = route('foodalchemist.review');
        $liste = [
            ['key' => 'signale-kritisch', 'icon' => 'heroicon-o-exclamation-circle', 'titel' => 'Kritische Signale',
                'text' => 'Brauchen zuerst eine Entscheidung.',
                'zahl' => $signale['kritisch'] ?? 0, 'ton' => 'crit', 'url' => $review . '?tab=signale'],
            ['key' => 'allergene', 'icon' => 'heroicon-o-shield-exclamation', 'titel' => 'Allergenangaben unsicher',
                'text' => 'Vor der Ausgabe an Gäste prüfen.',
                'zahl' => $workflow['allergen_low'] ?? 0, 'ton' => 'crit', 'url' => route('foodalchemist.recipes.index')],
            ['key' => 'ungemappt', 'icon' => 'heroicon-o-link', 'titel' => 'Zutaten ohne Grundprodukt',
                'text' => 'Allergene bleiben unbekannt, bis die Zutat einem Grundprodukt zugeordnet ist.',
                'zahl' => $workflow['ungemappt'] ?? 0, 'ton' => 'warn', 'url' => $review . '?tab=pflege'],
            ['key' => 'vorschlaege', 'icon' => 'heroicon-o-inbox', 'titel' => 'Vorschläge übernehmen',
                'text' => number_format($vorschlaege['bulk'] ?? 0, 0, ',', '.') . ' aus der KI-Anreicherung, '
                    . number_format($vorschlaege['match'] ?? 0, 0, ',', '.') . ' Artikel-Zuordnungen.',
                'zahl' => ($vorschlaege['bulk'] ?? 0) + ($vorschlaege['match'] ?? 0), 'ton' => 'warn', 'url' => $review . '?tab=vorschlaege'],
            ['key' => 'review', 'icon' => 'heroicon-o-clock', 'titel' => 'Rezepte prüfen',
                'text' => 'Freigeben oder zurück in den Entwurf.',
                'zahl' => $workflow['review'] ?? 0, 'ton' => 'warn', 'url' => route('foodalchemist.recipes.index') . '?status=review'],
            ['key' => 'vk-ohne-klasse', 'icon' => 'heroicon-o-tag', 'titel' => 'Gerichte ohne Speisen-Klasse',
                'text' => 'Die Speisen-Klasse fehlt. Im Gericht über „Klassifizieren“ setzen.',
                'zahl' => $workflow['vk_ohne_klasse'] ?? 0, 'ton' => 'warn', 'url' => route('foodalchemist.verkauf.index')],
            ['key' => 'signale-warnung', 'icon' => 'heroicon-o-exclamation-triangle', 'titel' => 'Weitere Signale',
                'text' => 'Warnungen und Hinweise aus den Prüfläufen.',
                'zahl' => ($signale['warnung'] ?? 0) + ($signale['info'] ?? 0), 'ton' => 'info', 'url' => $review . '?tab=signale'],
        ];

        $offen = array_values(array_filter($liste, fn (array $a) => $a['zahl'] > 0));
        $erledigt = array_values(array_filter($liste, fn (array $a) => $a['zahl'] === 0));

        return [...$offen, ...array_map(fn (array $a) => [...$a, 'ton' => 'ok'], $erledigt)];
    }
}
