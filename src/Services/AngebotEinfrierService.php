<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\Core\Models\User;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Models\FoodAlchemistAngebot;
use Platform\FoodAlchemist\Models\FoodAlchemistAngebotEinfrierung;
use Platform\FoodAlchemist\Models\FoodAlchemistAngebotEinfrierZeile;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistOfferChapter;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;

/**
 * Spec 82 §12 B3 · Eingefrorenes Angebot (Durchstich Schritt 4a).
 *
 * Beim Wechsel auf „angebot“ (Freigabe, Entscheidung Dominique 13.10.) hält ein Snapshot je GERICHT MIT ABO-FASSUNG im Baum fest, was es war:
 * Fassungs-IDs, EK mit Status (vollständig/unvollständig, nie 0 € als Preis), VK, Allergenbild. Eigene Gerichte ohne Fassung bleiben wie heute
 * (Entscheidung Kurator D3). `sales_recipe_id` bleibt unberührt. Eine neue Master-Fassung ändert den Snapshot nie; sie wird als „neuere
 * Fassung verfügbar“ mit Allergen-Diff angezeigt ({@see veraltet}), das Übernehmen mit Bestätigung folgt in 4b.
 *
 * Zurück auf „anfrage“/„in_arbeit“ verwirft den Snapshot (nach Bestätigung): das Angebot folgt dann wieder der neuesten Fassung.
 */
class AngebotEinfrierService
{
    private const MAX_TIEFE = 8;

    public function __construct(
        private readonly FaRechte $rechte,
        private readonly EinfrierKosten $kosten,
        private readonly FassungsVergleich $vergleich,
    ) {}

    public function aktive(FoodAlchemistAngebot $angebot): ?FoodAlchemistAngebotEinfrierung
    {
        return FoodAlchemistAngebotEinfrierung::where('angebot_id', $angebot->id)->whereNull('verworfen_at')->latest('id')->first();
    }

    /**
     * Einfrieren. Ohne Gericht mit Abo-Fassung entsteht nichts (null). Ist schon eingefroren, bleibt der Stand (kein stilles Überschreiben).
     */
    public function einfrieren(Team $team, ?User $user, FoodAlchemistAngebot $angebot): ?FoodAlchemistAngebotEinfrierung
    {
        $this->rechte->pruefeAngemeldet($team, FaRolle::Kuratieren, 'Angebot einfrieren');
        if (($bestehend = $this->aktive($angebot)) !== null) {
            return $bestehend;
        }
        $eigentuemer = Team::find($angebot->team_id) ?? $team;
        $zeilen = [];
        foreach ($this->positionsGerichte($angebot) as $gericht) {
            $fassungen = $this->fassungenImBaum($gericht);
            if ($fassungen === []) {
                continue;   // eigenes Gericht ohne Fassung: bleibt wie heute
            }
            $k = $this->kosten->fuer($gericht, $eigentuemer);
            $zeilen[] = [
                'team_id' => $angebot->team_id, 'recipe_id' => $gericht->id, 'name' => (string) $gericht->name,
                'fassungen' => array_map(fn ($f) => ['fassung_id' => (int) $f->id, 'fassung_von_id' => (int) $f->fassung_von_id, 'fassung_nr' => (int) $f->fassung_nr,
                    'allergene' => $this->vergleich->allergenBild($f)], $fassungen),
                'ek_summe' => $k['ek_summe'], 'ek_per_kg' => $k['ek_per_kg'], 'n_priced' => $k['n_priced'], 'n_total' => $k['n_total'],
                'vollstaendig' => $k['vollstaendig'], 'grund' => $k['vollstaendig'] ? null : ($k['grund'] ?? 'unvollstaendig'), 'fehlende' => $k['fehlende'],
                'vk' => $k['vollstaendig'] ? $k['vk'] : null, 'allergene' => $this->vergleich->allergenBild($gericht), 'kosten_stand_at' => $k['berechnet_at'],
            ];
        }
        if ($zeilen === []) {
            return null;
        }

        return DB::transaction(function () use ($angebot, $zeilen, $user) {
            $e = FoodAlchemistAngebotEinfrierung::create([
                'team_id' => $angebot->team_id, 'angebot_id' => $angebot->id, 'status_beim_einfrieren' => (string) ($angebot->status->value ?? $angebot->status),
                'eingefroren_at' => now(), 'eingefroren_von' => $user?->id,
            ]);
            foreach ($zeilen as $z) {
                FoodAlchemistAngebotEinfrierZeile::create($z + ['einfrierung_id' => $e->id]);
            }

            return $e->load('zeilen');
        });
    }

    /** Snapshot verwerfen (Rückweg auf Entwurf). Er bleibt als Historie, ist aber nicht mehr aktiv. */
    public function verwerfen(Team $team, ?User $user, FoodAlchemistAngebot $angebot): bool
    {
        $this->rechte->pruefeAngemeldet($team, FaRolle::Kuratieren, 'Eingefrorenen Stand verwerfen');
        $aktiv = $this->aktive($angebot);
        if ($aktiv === null) {
            return false;
        }
        $aktiv->update(['verworfen_at' => now(), 'verworfen_von' => $user?->id]);

        return true;
    }

    /**
     * Welche eingefrorenen Fassungen haben inzwischen eine NEUERE Fassung beim Mandanten? Mit Allergen-Diff (Endstand) und den Zwischenfassungen.
     *
     * @return list<array<string,mixed>>
     */
    public function veraltet(FoodAlchemistAngebot $angebot): array
    {
        $aktiv = $this->aktive($angebot);
        if ($aktiv === null) {
            return [];
        }
        $eigentuemer = Team::find($angebot->team_id);
        $out = [];
        foreach ($aktiv->zeilen as $zeile) {
            foreach ($zeile->fassungen as $f) {
                $neu = $this->vergleich->neuesteFuer((int) $f['fassung_von_id'], $eigentuemer);
                if ($neu === null || (int) $neu->fassung_nr <= (int) $f['fassung_nr']) {
                    continue;
                }
                $diff = $this->vergleich->bildDiff((array) ($f['allergene'] ?? []), $neu);   // Fassung gegen Fassung, nicht Gericht gegen Fassung
                $out[] = [
                    'zeile_id' => (int) $zeile->id, 'gericht' => $zeile->name, 'familie_id' => (int) $f['fassung_von_id'],
                    'eingefroren_fassung_id' => (int) $f['fassung_id'], 'eingefroren_nr' => (int) $f['fassung_nr'],
                    'neue_fassung_id' => (int) $neu->id, 'neue_nr' => (int) $neu->fassung_nr, 'aenderungsnotiz' => $neu->aenderungsnotiz,
                    'allergen_geaendert' => $diff !== [], 'allergen_diff' => $diff,
                    'zwischen' => $this->vergleich->zwischen((int) $f['fassung_von_id'], (int) $f['fassung_nr'], (int) $neu->fassung_nr),
                ];
            }
        }

        return $out;
    }

    /**
     * Alle Gerichte der Positionen: Einzel-Gerichte (recipe_ref), Gerichte der Concepts (Slots, Pakete, eingebettete Concepts) aus Kapiteln,
     * Format-Slots und angebots-lokalen/referenzierten Menüs.
     *
     * @return Collection<int, FoodAlchemistRecipe>
     */
    public function positionsGerichte(FoodAlchemistAngebot $angebot): Collection
    {
        $ids = [];
        $concepts = collect($angebot->concepts()->get())->merge($angebot->referencedConcepts()->get());
        $kapitel = FoodAlchemistOfferChapter::where('offer_id', $angebot->id)->with(['blocks' => fn ($q) => $q->where('visible', true), 'blocks.concept', 'format.slots.concept'])->get();
        foreach ($kapitel as $k) {
            foreach ($k->blocks as $b) {
                if ($b->type === 'recipe_ref' && $b->sales_recipe_id !== null) {
                    $ids[(int) $b->sales_recipe_id] = true;
                } elseif ($b->type === 'concept_ref' && $b->concept !== null) {
                    $concepts->push($b->concept);
                }
            }
            if ($k->format !== null) {
                foreach ($k->format->slots as $fs) {
                    if ($fs->type === 'concept' && $fs->concept !== null) {
                        $concepts->push($fs->concept);
                    }
                }
            }
        }
        $gesehen = [];
        foreach ($concepts as $c) {
            $this->gerichteDesConcepts($c, $ids, $gesehen, 0);
        }

        return FoodAlchemistRecipe::mitFassungen()->whereIn('id', array_keys($ids))->orderBy('id')->get();
    }

    /** @param array<int,true> $ids @param array<int,true> $gesehen */
    private function gerichteDesConcepts(FoodAlchemistConcept $concept, array &$ids, array &$gesehen, int $tiefe): void
    {
        if (isset($gesehen[$concept->id]) || $tiefe > 4) {
            return;
        }
        $gesehen[$concept->id] = true;
        foreach ($concept->slots()->with(['paket.dishes', 'embeddedConcept'])->get() as $slot) {
            if ($slot->sales_recipe_id !== null) {
                $ids[(int) $slot->sales_recipe_id] = true;
            }
            foreach ($slot->paket?->dishes ?? [] as $pg) {
                if ($pg->sales_recipe_id !== null) {
                    $ids[(int) $pg->sales_recipe_id] = true;
                }
            }
            if ($slot->embeddedConcept !== null) {
                $this->gerichteDesConcepts($slot->embeddedConcept, $ids, $gesehen, $tiefe + 1);
            }
        }
    }

    /**
     * Fassungs-Zeilen im Baum eines Gerichts (das Gericht selbst, wenn es eine Fassung ist, und alle Unter-Rezepte, die Fassungen sind).
     *
     * @return list<FoodAlchemistRecipe>
     */
    public function fassungenImBaum(FoodAlchemistRecipe $gericht): array
    {
        $gefunden = [];
        $besucht = [];
        $lauf = function (FoodAlchemistRecipe $r, int $tiefe) use (&$lauf, &$gefunden, &$besucht) {
            if (isset($besucht[$r->id]) || $tiefe > self::MAX_TIEFE) {
                return;
            }
            $besucht[$r->id] = true;
            if ($r->ist_fassung) {
                $gefunden[$r->id] = $r;
            }
            $kinder = $r->ingredients()->whereNotNull('referenced_recipe_id')->pluck('referenced_recipe_id');
            foreach (FoodAlchemistRecipe::mitFassungen()->whereIn('id', $kinder)->get() as $kind) {
                $lauf($kind, $tiefe + 1);
            }
        };
        $lauf($gericht, 0);

        return array_values($gefunden);
    }
}
