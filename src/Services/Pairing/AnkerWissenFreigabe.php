<?php

namespace Platform\FoodAlchemist\Services\Pairing;

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Enums\Achse;
use Platform\FoodAlchemist\Enums\WissensStatus;

/**
 * Spec 60 · P8: Anker-Wissen ansehen und anker-weise freigeben — der Weg, der das Signal
 * `pairing_wissen_pruefen` auflöst (ein Signal ohne Werkzeug wäre Rauschen, Spec 21 §9).
 *
 * Freigegeben wird nur, was aus dem Dossier stammt: Bedarfe, Eigenschaften (quelle dossier),
 * Komponenten, Kombinationen/Konflikte (grundlage dossier) und die offenen Rohnamen. Kontrast-
 * Kanten sind abgeleitet und folgen ihren Quellen; Nährwert-Eigenschaften sind gemessen.
 * Ein erneuter Import überschreibt geprüfte und verworfene Einträge nicht (AnkerWissenImport).
 *
 * Nach jeder Änderung: Kontrast neu ableiten und die Profile der Rezepte mit diesem Anker neu
 * rechnen — sonst trüge die Oberfläche weiter „aus Dossier, ungeprüft".
 */
final class AnkerWissenFreigabe
{
    /** Kurzname → [Tabelle, Anker-Spalte, Zusatzbedingung] */
    public const BEREICHE = [
        'bedarfe' => ['foodalchemist_anchor_bedarfe', 'anchor_id', null],
        'eigenschaften' => ['foodalchemist_anchor_eigenschaften', 'anchor_id', ['quelle', 'dossier']],
        'komponenten' => ['foodalchemist_anchor_komponenten', 'anchor_id', null],
        'beziehungen' => ['foodalchemist_anchor_beziehungen', 'anchor_a_id', ['grundlage', 'dossier']],
        'offen' => ['foodalchemist_anchor_wissen_offen', 'anchor_id', null],
    ];

    public function __construct(
        private readonly KontrastAbleitung $kontrast,
        private readonly RezeptProfil $profil,
    ) {}

    /** @return array<string, mixed>|null  null, wenn es den Anker nicht gibt */
    public function ansicht(int $ankerId): ?array
    {
        $anker = DB::table('foodalchemist_vocab_pairing_anchors')->where('id', $ankerId)->first(['id', 'slug', 'display_de', 'inspire_id']);
        if ($anker === null) {
            return null;
        }
        $label = fn (string $achse) => Achse::tryFrom($achse)?->label() ?? $achse;
        $partner = DB::table('foodalchemist_anchor_beziehungen as b')
            ->join('foodalchemist_vocab_pairing_anchors as p', 'p.id', '=', 'b.anchor_b_id')
            ->where('b.anchor_a_id', $ankerId)->where('b.grundlage', 'dossier')->orderBy('b.art')->orderBy('p.display_de')
            ->get(['b.id', 'b.art', 'b.anchor_b_id', 'p.display_de as partner', 'b.beleg', 'b.status']);
        $harmonie3 = DB::table(AnkerGraph::TABELLE)->where('anchor_a_id', $ankerId)->where('stufe', AnkerGraph::HARMONIERT)
            ->pluck('anchor_b_id')->map(fn ($i) => (int) $i)->flip();

        return [
            'anker' => ['id' => (int) $anker->id, 'slug' => $anker->slug, 'name' => $anker->display_de, 'gemessen' => $anker->inspire_id !== null],
            'status' => $this->zaehle($ankerId),
            'bedarfe' => $this->zeilen('bedarfe', $ankerId, ['achse', 'staerke', 'beleg'])
                ->map(fn ($z) => $z + ['achse_label' => $label($z['achse'])])->all(),
            'eigenschaften' => $this->zeilen('eigenschaften', $ankerId, ['achse', 'stufe', 'beleg'])
                ->map(fn ($z) => $z + ['achse_label' => $label($z['achse'])])->all(),
            'komponenten' => $this->zeilen('komponenten', $ankerId, ['name', 'technik', 'liefert'])
                ->map(fn ($z) => ['liefert' => json_decode((string) $z['liefert'], true) ?: []] + $z)->all(),
            'beziehungen' => $partner->map(fn ($b) => [
                'id' => (int) $b->id, 'art' => $b->art, 'partner_id' => (int) $b->anchor_b_id, 'partner' => $b->partner,
                'beleg' => $b->beleg, 'status' => $b->status,
                // der Widerspruch, den das Signal meldet, steht hier direkt an der Zeile
                'messung_3_sterne' => isset($harmonie3[(int) $b->anchor_b_id]),
            ])->all(),
            'offen' => $this->zeilen('offen', $ankerId, ['art', 'name', 'kontext', 'grund'])->all(),
        ];
    }

    /**
     * Status setzen — für den ganzen Anker oder einen einzelnen Eintrag.
     *
     * @return array{anchor_id: int, geaendert: int, status: array<string, array<string, int>>, profile_neu: int}
     */
    public function setze(int $ankerId, WissensStatus $status, ?string $bereich = null, ?int $eintragId = null): array
    {
        if ($bereich !== null && ! isset(self::BEREICHE[$bereich])) {
            throw new \InvalidArgumentException('bereich muss '.implode('|', array_keys(self::BEREICHE)).' sein.');
        }
        if (($bereich === null) !== ($eintragId === null)) {
            throw new \InvalidArgumentException('Einzelner Eintrag braucht bereich UND eintrag_id; ohne beide gilt der ganze Anker.');
        }

        $geaendert = 0;
        DB::transaction(function () use ($ankerId, $status, $bereich, $eintragId, &$geaendert) {
            foreach ($bereich !== null ? [$bereich => self::BEREICHE[$bereich]] : self::BEREICHE as $name => $_) {
                $geaendert += $this->basis($name, $ankerId)->when($eintragId !== null, fn ($q) => $q->where('id', $eintragId))
                    ->where('status', '!=', $status->value)->update(['status' => $status->value, 'updated_at' => now()]);
            }
        });
        if ($bereich !== null && $geaendert === 0 && ! $this->basis($bereich, $ankerId)->where('id', $eintragId)->exists()) {
            throw new \InvalidArgumentException("Eintrag {$bereich}#{$eintragId} gehört nicht zu Anker {$ankerId}.");
        }

        $neu = 0;
        if ($geaendert > 0) {
            $this->kontrast->baue();
            $this->profil->vergiss();
            $rezepte = DB::table('foodalchemist_recipe_profile_anker')->where('anchor_id', $ankerId)
                ->distinct()->orderBy('recipe_id')->pluck('recipe_id');
            foreach ($rezepte as $id) {
                $this->profil->fuer((int) $id);
                $neu++;
            }
        }

        return ['anchor_id' => $ankerId, 'geaendert' => $geaendert, 'status' => $this->zaehle($ankerId), 'profile_neu' => $neu];
    }

    /** @return array<string, array<string, int>> Bereich → Status → Anzahl */
    private function zaehle(int $ankerId): array
    {
        $out = [];
        foreach (array_keys(self::BEREICHE) as $name) {
            $out[$name] = $this->basis($name, $ankerId)->groupBy('status')->pluck(DB::raw('COUNT(*) as n'), 'status')
                ->map(fn ($n) => (int) $n)->all();
        }

        return $out;
    }

    /** @return \Illuminate\Support\Collection<int, array<string, mixed>> */
    private function zeilen(string $bereich, int $ankerId, array $spalten)
    {
        return $this->basis($bereich, $ankerId)->orderBy('id')->get(array_merge(['id', 'status'], $spalten))
            ->map(fn ($z) => (array) $z);
    }

    private function basis(string $bereich, int $ankerId): \Illuminate\Database\Query\Builder
    {
        [$tabelle, $spalte, $bedingung] = self::BEREICHE[$bereich];

        return DB::table($tabelle)->where($spalte, $ankerId)->when($bedingung !== null, fn ($q) => $q->where($bedingung[0], $bedingung[1]));
    }
}
