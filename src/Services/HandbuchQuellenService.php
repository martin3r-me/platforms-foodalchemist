<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistAngebot;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarte;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplan;

/**
 * Spec 86 — Quellen-Adapter des Handbuchs: liest aus dem Ausgabe-Dokument NUR die Struktur
 * (Kapitel/Rubriken/Tage), je Position den Namen, die Gericht-ID und die Allergen-Codes.
 * Preise werden nie angefasst (intern=false, mitKaskade=false).
 *
 * Rückgabe: `title`, `subtitle`, `sections[]` (title, text, items[] mit key `r<id>`, recipe_id, label, indent, codes),
 * `legende` ({allergene[], zusatzstoffe[]} mit code+label).
 */
class HandbuchQuellenService
{
    /** @var array<string,true> */
    private array $usedAlg = [];

    /** @var array<string,true> */
    private array $usedZus = [];

    /** @var array<int,FoodAlchemistRecipe> */
    private array $rezepte = [];

    private const WOCHENTAGE = [1 => 'Mo', 2 => 'Di', 3 => 'Mi', 4 => 'Do', 5 => 'Fr', 6 => 'Sa', 7 => 'So'];

    public function __construct(private ConcepterAggregateService $agg)
    {
    }

    /** @return array{title:string, subtitle:?string, sections:list<array>, legende:mixed} */
    public function lies(Team $team, string $type, Model $entity): array
    {
        $this->usedAlg = [];
        $this->usedZus = [];

        return match ($type) {
            HandbuchService::TYPE_FOODBOOK => $this->foodbook($team, $entity),
            HandbuchService::TYPE_SPEISEKARTE => $this->speisekarte($team, $entity),
            HandbuchService::TYPE_SPEISEPLAN => $this->speiseplan($team, $entity),
            HandbuchService::TYPE_ANGEBOT => $this->angebot($team, $entity),
            default => throw new \InvalidArgumentException("Handbuch-Typ '{$type}' wird nicht unterstützt."),
        };
    }

    private function foodbook(Team $team, FoodAlchemistFoodbook $fb): array
    {
        $dok = app(FoodbookService::class)->dokumentDaten($team, $fb, false, [], false);
        $sections = [];
        foreach ($dok['kapitel'] ?? [] as $k) {
            $items = [];
            foreach ($k['bloecke'] ?? [] as $b) {
                foreach ($b['gerichte'] ?? [] as $g) {
                    if (! empty($g['recipe_id'])) {
                        $items[] = $this->item((int) $g['recipe_id'], (string) ($g['text'] ?? ''), (int) ($g['einrueckung'] ?? 0), array_values((array) ($g['codes'] ?? [])));
                    }
                }
            }
            $sections[] = ['title' => (string) ($k['title'] ?? ''), 'text' => $k['text'] ?? null, 'items' => $items];
        }

        return [
            'title' => (string) ($fb->label ?: $fb->code ?: 'Foodbook'),
            'subtitle' => $fb->description ? (string) $fb->description : null,
            'personen' => $fb->personen ?? null,
            'sections' => $sections,
            'legende' => $dok['legende'] ?? null,
        ];
    }

    private function speisekarte(Team $team, FoodAlchemistSpeisekarte $karte): array
    {
        $dok = app(SpeisekarteService::class)->dokumentDaten($team, $karte, false, [], false);
        $rubriken = $karte->sections()->with('items')->get()->keyBy('id');
        $sections = [];
        foreach ($dok['rubriken'] as $r) {
            // dokumentDaten läuft über DIESELBE Liste (sichtbar, nach Position) → Index-gleich.
            $positionen = $rubriken->get($r['id'])?->items->where('visible', true)->sortBy('position')->values() ?? collect();
            $items = [];
            foreach ($r['positionen'] as $i => $p) {
                $pos = $positionen->get($i);
                if (($p['typ'] ?? '') === 'gericht_ref' && $pos?->sales_recipe_id !== null) {
                    $items[] = $this->item((int) $pos->sales_recipe_id, (string) ($p['name'] ?? ''), 0, array_values((array) ($p['codes'] ?? [])));
                } elseif (($p['typ'] ?? '') === 'menue_ref') {
                    foreach ($p['gaenge'] ?? [] as $g) {
                        if (! empty($g['recipe_id'])) {
                            $items[] = $this->item((int) $g['recipe_id'], (string) ($g['text'] ?? ''), 1, array_values((array) ($g['codes'] ?? [])));
                        }
                    }
                }
            }
            $sections[] = ['title' => (string) ($r['title'] ?? ''), 'text' => $r['claim'] ?? null, 'items' => $items];
        }

        return [
            'title' => (string) ($karte->name ?: $karte->code ?: 'Speisekarte'),
            'subtitle' => $karte->description ? (string) $karte->description : null,
            'sections' => $sections,
            'legende' => $dok['legende'] ?? null,
        ];
    }

    /**
     * Speiseplan: die Woche mit Belegung (laufende Woche, sonst die erste belegte), alle Mahlzeiten,
     * ein Abschnitt je Tag. Konzept-/Paket-Einträge werden in ihre Gerichte aufgelöst.
     */
    private function speiseplan(Team $team, FoodAlchemistSpeiseplan $plan): array
    {
        $svc = app(SpeiseplanService::class);
        $plan->loadMissing('entries');
        $mit = $plan->entries->filter(fn ($e) => $e->entry_date !== null);
        $heute = Carbon::now()->startOfWeek(Carbon::MONDAY);
        $mo = $mit->contains(fn ($e) => $e->entry_date->between($heute, $heute->copy()->addDays(6)))
            ? $heute
            : ($mit->isNotEmpty() ? Carbon::parse($mit->min('entry_date'))->startOfWeek(Carbon::MONDAY) : $heute);
        $ende = $mo->copy()->addDays(6)->endOfDay();

        $sections = [];
        foreach ($mit->filter(fn ($e) => $e->entry_date->between($mo, $ende))->groupBy(fn ($e) => $e->entry_date->format('Y-m-d'))->sortKeys() as $ymd => $eintraege) {
            $items = [];
            foreach ($eintraege as $e) {
                $gerichte = $svc->eintragGerichte($e);
                foreach ($gerichte as $g) {
                    $items[] = $this->item((int) $g->id, $gerichte->count() === 1 ? $svc->eintragName($e) : (string) $g->name, 0, $this->codesFuer((int) $g->id));
                }
            }
            if ($items !== []) {
                $tag = Carbon::parse($ymd);
                $sections[] = ['title' => self::WOCHENTAGE[$tag->isoWeekday()] . ' ' . $tag->format('d.m.'), 'text' => null, 'items' => $items];
            }
        }

        return [
            'title' => (string) ($plan->name ?: 'Speiseplan'),
            'subtitle' => 'KW ' . $mo->isoWeek() . ' · ' . $mo->format('d.m.') . ' bis ' . $mo->copy()->addDays(6)->format('d.m.Y'),
            'sections' => $sections,
            'legende' => $this->legende(),
        ];
    }

    private function angebot(Team $team, FoodAlchemistAngebot $angebot): array
    {
        $komp = app(OfferCompositionService::class)->komposition($team, $angebot, null, false);
        $sections = [];
        foreach ($komp['kapitel'] as $k) {
            $gruppen = $k['ist_format'] ? ($k['editionen'] ?? []) : ($k['bloecke'] ?? []);
            $items = [];
            foreach ($gruppen as $grp) {
                foreach ($grp['gerichte'] ?? [] as $g) {
                    if (! empty($g['recipe_id'])) {
                        $items[] = $this->item((int) $g['recipe_id'], (string) ($g['text'] ?? ''), (int) ($g['einrueckung'] ?? 0), $this->codesFuer((int) $g['recipe_id']));
                    }
                }
            }
            $sections[] = ['title' => (string) ($k['title'] ?? ''), 'text' => $k['text'] ?? null, 'items' => $items];
        }

        return [
            'title' => (string) ($angebot->name ?: 'Angebot'),
            'subtitle' => $angebot->occasion ? (string) $angebot->occasion : null,
            'personen' => $angebot->personen ?? null,
            'sections' => $sections,
            'legende' => $this->legende(),
        ];
    }

    /** @param list<string> $codes */
    private function item(int $recipeId, string $label, int $indent, array $codes): array
    {
        return ['key' => 'r' . $recipeId, 'recipe_id' => $recipeId, 'label' => $label, 'indent' => $indent, 'codes' => $codes];
    }

    /** @return list<string> */
    private function codesFuer(int $recipeId): array
    {
        $this->rezepte[$recipeId] ??= FoodAlchemistRecipe::find($recipeId);
        $dish = $this->rezepte[$recipeId];

        return $dish === null ? [] : $this->agg->gerichtCodes($dish, $this->usedAlg, $this->usedZus);
    }

    /** @return array{allergene:list<array{code:string,label:string}>, zusatzstoffe:list<array{code:string,label:string}>} */
    private function legende(): array
    {
        $katalog = $this->agg->kennzeichnungKatalog();
        $alg = [];
        foreach ($katalog['allergene'] as $slug => $e) {
            if (isset($this->usedAlg[$slug])) {
                $alg[] = ['code' => $e['code'], 'label' => $e['label']];
            }
        }
        $zus = [];
        foreach ($katalog['zusatzstoffe'] as $slug => $e) {
            if (isset($this->usedZus[$slug])) {
                $zus[] = ['code' => $e['code'], 'label' => $e['label']];
            }
        }

        return ['allergene' => $alg, 'zusatzstoffe' => $zus];
    }
}
