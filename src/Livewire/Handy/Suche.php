<?php

namespace Platform\FoodAlchemist\Livewire\Handy;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Platform\FoodAlchemist\Services\FaRechte;
use Platform\FoodAlchemist\Services\FavoriteGpService;
use Platform\FoodAlchemist\Services\GpService;
use Platform\FoodAlchemist\Services\RecipeService;
use Platform\FoodAlchemist\Services\SalesRecipeService;
use Platform\FoodAlchemist\Services\SupplierItemService;
use Platform\FoodAlchemist\Services\SupplierService;
use Platform\FoodAlchemist\Support\FaBereiche;
use Platform\FoodAlchemist\Support\FaShell;

/**
 * #1003 Globale Suche der Handy-Oberfläche: ein Feld für Rezept, Gericht, Grundprodukt, Lieferant und
 * Artikel (Bezeichnung, Artikelnummer, EAN). Keine eigene Suchlogik: je Art der Dienst, den auch die
 * Listen bzw. MCP-Suchen nutzen (Sicht visibleToTeam steckt dort). Je Abschnitt nur, was die Person
 * im Bereich sehen darf. Wenige Treffer je Art (Handy: Wichtigstes zuerst, Rest in der Liste).
 * Ohne Suchwort: zuletzt angesehen (pro Gerät, im Browser) und Favoriten des Teams.
 */
class Suche extends Component
{
    public const JE_ART = 5;

    #[Url(as: 'q')]
    public string $q = '';

    public function render()
    {
        $user = Auth::user();
        $team = $user?->currentTeamRelation;
        $q = trim($this->q);
        $abschnitte = [];
        $favoriten = collect();
        $start = microtime(true);

        $darf = function (string $route) use ($user, $team): bool {
            return $team !== null && app(FaRechte::class)->darfBereich($user, $team, FaBereiche::fuerRoute($route));
        };

        if ($team !== null && mb_strlen($q) >= 2) {
            if ($darf('foodalchemist.recipes.index')) {
                $abschnitte[] = ['titel' => 'Basisrezepte', 'treffer' => app(RecipeService::class)
                    ->paginateBrowser(['search' => $q], $team, self::JE_ART)->getCollection()
                    ->map(fn ($r) => ['titel' => $r->name, 'zusatz' => $r->category?->name, 'url' => route('foodalchemist.recipes.index', ['rezept' => $r->id])])];
            }
            if ($darf('foodalchemist.verkauf.index')) {
                $abschnitte[] = ['titel' => 'Gerichte', 'treffer' => app(SalesRecipeService::class)
                    ->paginateBrowser(['search' => $q], $team, self::JE_ART)->getCollection()
                    ->map(fn ($r) => ['titel' => $r->name, 'zusatz' => $r->dishClass?->name, 'url' => route('foodalchemist.verkauf.index', ['rezept' => $r->id])])];
            }
            if ($darf('foodalchemist.gps.index')) {
                $abschnitte[] = ['titel' => 'Grundprodukte', 'treffer' => app(GpService::class)
                    ->paginate(['search' => $q], $team, self::JE_ART)->getCollection()
                    ->map(fn ($g) => ['titel' => $g->name, 'zusatz' => null, 'url' => route('foodalchemist.gps.index', ['gp' => $g->id])])];
            }
            if ($darf('foodalchemist.suppliers.index')) {
                $abschnitte[] = ['titel' => 'Lieferanten', 'treffer' => app(SupplierService::class)
                    ->listWithCounts($team, false, $q)->take(self::JE_ART)
                    ->map(fn ($s) => ['titel' => $s->name, 'zusatz' => $s->item_count.' Artikel', 'url' => route('foodalchemist.suppliers.index', ['lieferant' => $s->id])])];

                $artikel = app(SupplierItemService::class)->byEan($team, $q, [], self::JE_ART);
                if ($artikel->isEmpty()) {
                    $artikel = app(SupplierItemService::class)->searchGlobalErste($team, $q, [], self::JE_ART);
                }
                $abschnitte[] = ['titel' => 'Lieferantenartikel', 'treffer' => $artikel
                    ->map(fn ($a) => [
                        'titel' => $a->designation,
                        'zusatz' => trim(($a->supplier?->name ?? '').($a->article_number ? ' · '.$a->article_number : '')),
                        'url' => route('foodalchemist.suppliers.index', ['lieferant' => $a->supplier_id, 'aq' => $a->article_number ?: $a->designation]),
                    ])];
            }
            $abschnitte = array_values(array_filter($abschnitte, fn ($a) => $a['treffer']->isNotEmpty()));
        } elseif ($team !== null && $darf('foodalchemist.gps.index')) {
            $favoriten = app(FavoriteGpService::class)->current($team)->take(8)
                ->map(fn ($g) => ['titel' => $g->name, 'zusatz' => null, 'url' => route('foodalchemist.gps.index', ['gp' => $g->id])]);
        }

        return view('foodalchemist::livewire.handy.suche', [
            'abschnitte' => $abschnitte,
            'favoriten' => $favoriten,
            'gesucht' => mb_strlen($q) >= 2,
            'dauerMs' => (int) round((microtime(true) - $start) * 1000),
        ])->layout(FaShell::layout());
    }
}
