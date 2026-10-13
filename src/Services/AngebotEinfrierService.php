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
use Platform\FoodAlchemist\Models\FoodAlchemistAngebotUebernahme;
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
        private readonly PlattformProtokoll $protokoll,
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

        $e = DB::transaction(function () use ($angebot, $zeilen, $user) {
            $e = FoodAlchemistAngebotEinfrierung::create([
                'team_id' => $angebot->team_id, 'angebot_id' => $angebot->id, 'status_beim_einfrieren' => (string) ($angebot->status->value ?? $angebot->status),
                'eingefroren_at' => now(), 'eingefroren_von' => $user?->id,
            ]);
            foreach ($zeilen as $z) {
                FoodAlchemistAngebotEinfrierZeile::create($z + ['einfrierung_id' => $e->id]);
            }

            return $e->load('zeilen');
        });
        $this->signaleNachfuehren($angebot, $e);

        return $e;
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
        $this->signaleNachfuehren($angebot, $aktiv);

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

    // ── Übernehmen (Schritt 4b) ────────────────────────────────────────────────────────────────

    /**
     * Was „Übernehmen“ täte: neue Fassung, Allergen-Diff (Endstand), Zwischenfassungen, betroffene Angebote, ob eine Bestätigung nötig ist.
     *
     * @return array<string,mixed>
     */
    public function uebernahmeVorschau(FoodAlchemistAngebot $angebot, int $zeileId, int $familieId): array
    {
        [$zeile, $eintrag, $neu] = $this->uebernahmeZiel($angebot, $zeileId, $familieId);
        $diff = $this->vergleich->bildDiff((array) ($eintrag['allergene'] ?? []), $neu);
        $zwischen = $this->vergleich->zwischen($familieId, (int) $eintrag['fassung_nr'], (int) $neu->fassung_nr);
        $allergen = $diff !== [] || collect($zwischen)->contains('allergen_geaendert', true);

        return [
            'zeile_id' => (int) $zeile->id, 'gericht' => $zeile->name, 'familie_id' => $familieId,
            'von_fassung_id' => (int) $eintrag['fassung_id'], 'von_nr' => (int) $eintrag['fassung_nr'], 'nach_fassung_id' => (int) $neu->id, 'nach_nr' => (int) $neu->fassung_nr,
            'aenderungsnotiz' => $neu->aenderungsnotiz, 'allergen_geaendert' => $allergen, 'allergen_diff' => $diff, 'zwischen' => $zwischen,
            'braucht_bestaetigung' => $allergen, 'betroffene_ausgaben' => $this->betroffeneAusgaben($angebot, $familieId),
        ];
    }

    /**
     * Neuere Fassung übernehmen. Ändert sich das Allergen- oder Zusatzstoffbild, braucht es die ausdrückliche Bestätigung UND die Rolle Freigeben
     * (Entscheidung Dominique 13.10.); sonst genügt Kuratieren. Person und Zeitpunkt stehen in der Übernahme-Zeile und im Plattform-Protokoll.
     *
     * Was passiert: In den Rezepten des Angebots-Teams, die die alte Fassung als Komponente tragen, zeigt die Zutat danach auf die neue Fassung
     * (Gericht der Position und seine eigenen Unter-Rezepte); ist das Gericht selbst die Fassung, werden die Verweise des Angebots (Blöcke, angebots-lokale
     * Concepts und Pakete) umgestellt. Geteilte Concepts und Pakete bleiben unberührt und werden gemeldet. Danach rechnet das Gericht neu, und die Zeile
     * des Snapshots wird auf den neuen Stand gezogen.
     *
     * @return array<string,mixed> Bericht
     */
    public function uebernehmen(Team $team, ?User $user, FoodAlchemistAngebot $angebot, int $zeileId, int $familieId, bool $bestaetigt = false): array
    {
        $vorschau = $this->uebernahmeVorschau($angebot, $zeileId, $familieId);
        $this->rechte->pruefe($user, $team, $vorschau['braucht_bestaetigung'] ? FaRolle::Freigeben : FaRolle::Kuratieren,
            $vorschau['braucht_bestaetigung'] ? 'Fassung mit geändertem Allergenbild übernehmen' : 'Neuere Fassung übernehmen');
        if ($vorschau['braucht_bestaetigung'] && ! $bestaetigt) {
            throw new \RuntimeException('Die neue Fassung ändert das Allergen- oder Zusatzstoffbild. Übernehmen verlangt die ausdrückliche Bestätigung (bestaetigt=true) nach Durchsicht von Diff und betroffenen Angeboten.');
        }
        [$zeile, $eintrag, $neu] = $this->uebernahmeZiel($angebot, $zeileId, $familieId);
        $gericht = FoodAlchemistRecipe::mitFassungen()->findOrFail($zeile->recipe_id);
        if (! $gericht->ist_fassung && (int) $gericht->team_id !== (int) $angebot->team_id) {   // eine Fassung gehört dem Master; verändert wird nur, was das Angebots-Team besitzt
            throw new \RuntimeException('Das Gericht gehört nicht zu diesem Team, der Verweis lässt sich hier nicht umstellen.');
        }
        $eigentuemer = Team::find($angebot->team_id) ?? $team;

        return DB::transaction(function () use ($team, $user, $angebot, $zeile, $eintrag, $neu, $gericht, $vorschau, $eigentuemer, $familieId) {
            $umgehaengt = $this->verweiseUmstellen($angebot, $gericht, (int) $eintrag['fassung_id'], (int) $neu->id);
            if ((int) $gericht->id === (int) $eintrag['fassung_id']) {   // das Gericht war selbst die Fassung: die Zeile zeigt jetzt auf die neue
                $zeile->update(['recipe_id' => $neu->id, 'name' => (string) $neu->name]);
                $gericht = $neu;
            }
            $geaendert = array_values(array_unique(array_merge($umgehaengt['rezepte'], $gericht->ist_fassung ? [] : [(int) $gericht->id])));
            if ($geaendert !== []) {
                app(RecipeRecomputeService::class)->recomputeMany($geaendert);
            }
            $this->zeileNeuEinfrieren($zeile, $gericht->refresh(), $eigentuemer);

            $u = FoodAlchemistAngebotUebernahme::create([
                'team_id' => $angebot->team_id, 'angebot_id' => $angebot->id, 'einfrier_zeile_id' => $zeile->id, 'familie_id' => $familieId,
                'von_fassung_id' => $vorschau['von_fassung_id'], 'nach_fassung_id' => $vorschau['nach_fassung_id'],
                'allergen_geaendert' => $vorschau['allergen_geaendert'], 'allergen_bestaetigt' => $vorschau['braucht_bestaetigung'],
                'bestaetigt_von' => $vorschau['braucht_bestaetigung'] ? $user?->id : null, 'bestaetigt_at' => $vorschau['braucht_bestaetigung'] ? now() : null,
                'diff' => $vorschau['allergen_diff'], 'betroffene_ausgaben' => $vorschau['betroffene_ausgaben'], 'umgehaengt' => $umgehaengt,
            ]);
            $this->protokoll->schreibe('angebot_fassung_uebernommen', ['angebot_id' => (int) $angebot->id, 'familie_id' => $familieId,
                'von' => $vorschau['von_nr'], 'nach' => $vorschau['nach_nr'], 'allergen_geaendert' => $vorschau['allergen_geaendert'], 'bestaetigt' => $vorschau['braucht_bestaetigung']], $user, $u);

            $this->signaleNachfuehren($angebot);

            return ['uebernahme_id' => (int) $u->id, 'von_nr' => $vorschau['von_nr'], 'nach_nr' => $vorschau['nach_nr'], 'allergen_geaendert' => $vorschau['allergen_geaendert'],
                'umgehaengt' => $umgehaengt, 'betroffene_ausgaben' => $vorschau['betroffene_ausgaben']];
        });
    }

    /**
     * Andere Angebote des Mandanten (und seiner Unter-Teams), in denen dieselbe Fassungs-Familie vorkommt: eingefroren (Snapshot) oder live (Gericht im Baum).
     *
     * @return list<array{angebot_id:int, name:?string, status:string, art:string}>
     */
    public function betroffeneAusgaben(FoodAlchemistAngebot $angebot, int $familieId): array
    {
        $out = [];
        $teamIds = \Platform\FoodAlchemist\Jobs\RecomputeTeamRecipesJob::teamUndNachfahren((int) $this->rechte->hauptTeam(Team::findOrFail($angebot->team_id))->id);
        foreach (FoodAlchemistAngebot::whereIn('team_id', $teamIds)->where('id', '!=', $angebot->id)->orderBy('id')->get() as $a) {
            $aktiv = $this->aktive($a);
            $art = null;
            if ($aktiv !== null && $aktiv->zeilen->contains(fn ($z) => collect($z->fassungen)->contains('fassung_von_id', $familieId))) {
                $art = 'eingefroren';
            } elseif ($this->positionsGerichte($a)->contains(fn ($g) => collect($this->fassungenImBaum($g))->contains('fassung_von_id', $familieId))) {
                $art = 'live';
            }
            if ($art !== null) {
                $out[] = ['angebot_id' => (int) $a->id, 'name' => $a->name, 'status' => (string) ($a->status->value ?? $a->status), 'art' => $art];
            }
        }

        return $out;
    }

    /** @return array{0: FoodAlchemistAngebotEinfrierZeile, 1: array<string,mixed>, 2: FoodAlchemistRecipe} */
    private function uebernahmeZiel(FoodAlchemistAngebot $angebot, int $zeileId, int $familieId): array
    {
        $aktiv = $this->aktive($angebot) ?? throw new \RuntimeException('Das Angebot ist nicht eingefroren.');
        $zeile = $aktiv->zeilen->firstWhere('id', $zeileId) ?? throw new \RuntimeException('Zeile gehört nicht zum eingefrorenen Stand dieses Angebots.');
        $eintrag = collect($zeile->fassungen)->firstWhere('fassung_von_id', $familieId) ?? throw new \RuntimeException('Diese Fassungs-Familie steht nicht in der Zeile.');
        $neu = $this->vergleich->neuesteFuer($familieId, Team::findOrFail($angebot->team_id));
        if ($neu === null || (int) $neu->fassung_nr <= (int) $eintrag['fassung_nr']) {
            throw new \RuntimeException('Es gibt keine neuere Fassung.');
        }

        return [$zeile, $eintrag, $neu];
    }

    /**
     * Verweise von der alten auf die neue Fassung umstellen.
     *
     * @return array{rezepte: list<int>, bloecke: int, concepts_lokal: int, nicht_umgehaengt: list<string>}
     */
    private function verweiseUmstellen(FoodAlchemistAngebot $angebot, FoodAlchemistRecipe $gericht, int $alt, int $neu): array
    {
        $rezepte = [];
        $bloecke = 0;
        $lokal = 0;
        $offen = [];
        // 1) Zutaten in den eigenen Rezepten des Teams (Gericht und seine Unter-Rezepte), die die alte Fassung als Komponente tragen
        $besucht = [];
        $lauf = function (FoodAlchemistRecipe $r, int $tiefe) use (&$lauf, &$besucht, &$rezepte, $alt, $neu, $angebot) {
            if (isset($besucht[$r->id]) || $tiefe > self::MAX_TIEFE || $r->ist_fassung) {
                return;
            }
            $besucht[$r->id] = true;
            if ((int) $r->team_id === (int) $angebot->team_id
                && $r->ingredients()->where('referenced_recipe_id', $alt)->update(['referenced_recipe_id' => $neu]) > 0) {
                $rezepte[] = (int) $r->id;
            }
            foreach (FoodAlchemistRecipe::mitFassungen()->whereIn('id', $r->ingredients()->whereNotNull('referenced_recipe_id')->pluck('referenced_recipe_id'))->get() as $kind) {
                $lauf($kind, $tiefe + 1);
            }
        };
        $lauf($gericht, 0);

        // 2) Ist das Gericht selbst die alte Fassung: Verweise des Angebots umstellen
        if ((int) $gericht->id === $alt) {
            $bloecke = FoodAlchemistOfferChapter::where('offer_id', $angebot->id)->get()
                ->sum(fn ($k) => $k->blocks()->where('sales_recipe_id', $alt)->update(['sales_recipe_id' => $neu]));
            foreach ($angebot->concepts()->get() as $c) {   // angebots-lokale Concepts gehören dem Angebot
                $lokal += $c->slots()->where('sales_recipe_id', $alt)->update(['sales_recipe_id' => $neu]);
            }
            foreach ($angebot->referencedConcepts()->get() as $c) {   // geteilte Katalog-Concepts bleiben unberührt
                if ($c->slots()->where('sales_recipe_id', $alt)->exists()) {
                    $offen[] = 'Concept „'.$c->name.'“ (geteilt)';
                }
            }
        }

        return ['rezepte' => $rezepte, 'bloecke' => (int) $bloecke, 'concepts_lokal' => $lokal, 'nicht_umgehaengt' => $offen];
    }

    /** Die Zeile des Snapshots auf den jetzigen Stand des Gerichts ziehen (nach dem Übernehmen). */
    private function zeileNeuEinfrieren(FoodAlchemistAngebotEinfrierZeile $zeile, FoodAlchemistRecipe $gericht, Team $eigentuemer): void
    {
        $fassungen = $this->fassungenImBaum($gericht);
        $k = $this->kosten->fuer($gericht, $eigentuemer);
        $zeile->update([
            'fassungen' => array_map(fn ($f) => ['fassung_id' => (int) $f->id, 'fassung_von_id' => (int) $f->fassung_von_id, 'fassung_nr' => (int) $f->fassung_nr,
                'allergene' => $this->vergleich->allergenBild($f)], $fassungen),
            'ek_summe' => $k['ek_summe'], 'ek_per_kg' => $k['ek_per_kg'], 'n_priced' => $k['n_priced'], 'n_total' => $k['n_total'],
            'vollstaendig' => $k['vollstaendig'], 'grund' => $k['vollstaendig'] ? null : ($k['grund'] ?? 'unvollstaendig'), 'fehlende' => $k['fehlende'],
            'vk' => $k['vollstaendig'] ? $k['vk'] : null, 'allergene' => $this->vergleich->allergenBild($gericht), 'kosten_stand_at' => $k['berechnet_at'],
        ]);
    }

    /** Aktions-Signale „Neuere Fassung verfügbar“ nach Einfrieren, Verwerfen oder Übernehmen nachführen (anlegen, aktualisieren, schließen). Nie ein Grund zu scheitern. */
    private function signaleNachfuehren(FoodAlchemistAngebot $angebot, ?FoodAlchemistAngebotEinfrierung $einfrierung = null): void
    {
        try {
            app(FassungSignalService::class)->nachAngebotsAenderung($angebot, $einfrierung);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Angebot geändert, aber das Nachführen der Fassungs-Signale ist fehlgeschlagen: '.$e->getMessage(), ['exception' => $e, 'angebot_id' => $angebot->id]);
        }
    }
}
