<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\AngebotStatus;
use Platform\FoodAlchemist\Models\FoodAlchemistAngebot;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistOfferBlock;
use Platform\FoodAlchemist\Models\FoodAlchemistOfferChapter;

/**
 * Angebot kopieren: Stammdaten, Branding, Kapitelbaum, Blöcke, referenzierte Katalog-Concepts und
 * angebots-lokale Concept-Entwürfe. Die Kopie ist ein neues, unabhängiges Angebot:
 *  - Status „Anfrage“, keine Veröffentlichung (kein Token/Slug/Snapshot), keine Einfrierung.
 *  - Bilder (Logo, Cover, Kapitelbild, Kapitel-Galerie, Titelbild lokaler Concepts) werden als eigene Dateien
 *    kopiert — Löschen am Original zerstört die Kopie nicht.
 *  - Angebots-lokale Concepts (offer_id = Original) werden mitkopiert und umgehängt, damit Änderungen an der
 *    Kopie das Original nicht verändern. Katalog-Concepts bleiben geteilte Verweise.
 */
class AngebotKopieService
{
    /** Spalten, die eine Kopie NICHT übernimmt (Identität, Veröffentlichung, Preis-Override-Protokoll). */
    private const OHNE = [
        'uuid', 'offer_number',
        'presentation_enabled', 'presentation_token', 'presentation_slug', 'presentation_snapshot_json',
        'presentation_published_at', 'presentation_published_by', 'presentation_expires_at',
        'logo_context_file_id', 'logo_path', 'cover_context_file_id', 'cover_image_path', 'presentation_images_json',
        'deleted_at',
    ];

    public function __construct(private FoodAlchemistMediaService $media) {}

    public function kopieren(Team $team, int $id, ?string $name = null): FoodAlchemistAngebot
    {
        $orig = FoodAlchemistAngebot::visibleToTeam($team)
            ->with(['chapters.images', 'chapters.blocks', 'concepts.slots', 'referencedConcepts'])
            ->findOrFail($id);

        $kopie = DB::transaction(function () use ($team, $orig, $name) {
            $kopie = $orig->replicate(self::OHNE);
            $kopie->forceFill([
                'team_id' => $team->id,
                'name' => trim((string) $name) !== '' ? trim((string) $name) : $orig->name . ' (Kopie)',
                'status' => AngebotStatus::Anfrage->value,
                'created_by_user_id' => Auth::id(),
            ])->save();

            // Angebots-lokale Concepts (Entwürfe) zuerst, damit Blöcke umgehängt werden können.
            $conceptMap = [];
            foreach ($orig->concepts as $c) {
                $neu = $c->replicate(['uuid', 'image_context_file_id', 'image_path', 'presentation_token', 'presentation_slug', 'presentation_snapshot_json']);
                $neu->forceFill(['team_id' => $team->id, 'offer_id' => $kopie->id])->save();
                $conceptMap[(int) $c->id] = (int) $neu->id;
                if ($bild = $this->bild($c->image_context_file_id, $c->image_path, $team, 'foodalchemist.concept', $neu->id, "foodalchemist/concept/{$neu->id}")) {
                    $neu->forceFill(['image_context_file_id' => $bild['context_file_id'], 'image_path' => $bild['path']])->save();
                }
            }
            foreach ($orig->concepts as $c) {
                foreach ($c->slots as $slot) {
                    $s = $slot->replicate(['uuid']);
                    $s->forceFill([
                        'team_id' => $team->id,
                        'concept_id' => $conceptMap[(int) $c->id],
                        'embedded_concept_id' => $slot->embedded_concept_id !== null
                            ? ($conceptMap[(int) $slot->embedded_concept_id] ?? $slot->embedded_concept_id) : null,
                    ])->save();
                }
            }

            // Kapitelbaum (Eltern vor Kindern) + Blöcke + Bilder.
            $kapitelMap = [];
            $offen = $orig->chapters->sortBy('position')->values();
            while ($offen->isNotEmpty()) {
                $rest = collect();
                foreach ($offen as $k) {
                    if ($k->parent_id !== null && ! isset($kapitelMap[(int) $k->parent_id])) {
                        $rest->push($k);

                        continue;
                    }
                    $kapitelMap[(int) $k->id] = $this->kopiereKapitel($team, $k, $kopie->id, $k->parent_id !== null ? $kapitelMap[(int) $k->parent_id] : null, $conceptMap);
                }
                if ($rest->count() === $offen->count()) {   // verwaister Elternverweis → als Wurzel übernehmen
                    foreach ($rest as $k) {
                        $kapitelMap[(int) $k->id] = $this->kopiereKapitel($team, $k, $kopie->id, null, $conceptMap);
                    }
                    break;
                }
                $offen = $rest;
            }

            // Zusätzlich referenzierte Katalog-Concepts (geteilt).
            $kopie->referencedConcepts()->sync(
                $orig->referencedConcepts->mapWithKeys(fn ($c) => [(int) $c->id => ['position' => $c->pivot->position]])->all()
            );

            return $kopie;
        });

        // Branding-Bilder als eigene Dateien (nach der Transaktion: Datei-I/O).
        $updates = [];
        if ($b = $this->bild($orig->logo_context_file_id, $orig->logo_path, $team, 'foodalchemist.offer', $kopie->id, "foodalchemist/offer-branding/{$kopie->id}")) {
            $updates += ['logo_context_file_id' => $b['context_file_id'], 'logo_path' => $b['path']];
        }
        if ($b = $this->bild($orig->cover_context_file_id, $orig->cover_image_path, $team, 'foodalchemist.offer', $kopie->id, "foodalchemist/offer-branding/{$kopie->id}")) {
            $updates += ['cover_context_file_id' => $b['context_file_id'], 'cover_image_path' => $b['path']];
        }
        $bilder = [];
        foreach ((is_array($orig->presentation_images_json) ? $orig->presentation_images_json : []) as $nr => $pb) {
            if (is_array($pb) && ($b = $this->bild($pb['context_file_id'] ?? null, $pb['path'] ?? null, $team, 'foodalchemist.offer', $kopie->id, "foodalchemist/offer-branding/{$kopie->id}"))) {
                $bilder[(string) $nr] = $b;
            }
        }
        if ($bilder !== []) {
            $updates['presentation_images_json'] = $bilder;
        }
        if ($updates !== []) {
            $kopie->forceFill($updates)->save();
        }

        return $kopie->refresh();
    }

    /** @param array<int,int> $conceptMap */
    private function kopiereKapitel(Team $team, FoodAlchemistOfferChapter $k, int $offerId, ?int $parentId, array $conceptMap): int
    {
        $neu = $k->replicate(['uuid', 'image_context_file_id', 'image_path', 'logo_context_file_id', 'logo_path']);
        $neu->forceFill(['team_id' => $team->id, 'offer_id' => $offerId, 'parent_id' => $parentId])->save();
        if ($b = $this->bild($k->image_context_file_id, $k->image_path, $team, 'foodalchemist.offer_chapter', $neu->id, "foodalchemist/offer_chapter/{$neu->id}")) {
            $neu->forceFill(['image_context_file_id' => $b['context_file_id'], 'image_path' => $b['path']])->save();
        }
        // Kapitel-Logo (2026-11-13): eigene Datei-Kopie, damit Entfernen in einem Angebot das andere nicht trifft.
        if ($b = $this->bild($k->logo_context_file_id, $k->logo_path, $team, 'foodalchemist.offer_chapter', $neu->id, "foodalchemist/offer_chapter/{$neu->id}/logo")) {
            $neu->forceFill(['logo_context_file_id' => $b['context_file_id'], 'logo_path' => $b['path']])->save();
        }
        foreach ($k->images as $img) {
            $b = $this->bild($img->context_file_id, $img->path, $team, 'foodalchemist.offer_chapter', $neu->id, "foodalchemist/offer_chapter/{$neu->id}");
            if ($b !== null) {
                $i = $img->replicate(['uuid']);
                $i->forceFill(['chapter_id' => $neu->id, 'context_file_id' => $b['context_file_id'], 'path' => $b['path']]
                    + (array_key_exists('team_id', $img->getAttributes()) ? ['team_id' => $team->id] : []))->save();
            }
        }
        foreach ($k->blocks as $block) {
            /** @var FoodAlchemistOfferBlock $block */
            $nb = $block->replicate(['uuid']);
            $nb->forceFill([
                'chapter_id' => $neu->id,
                'concept_id' => $block->concept_id !== null ? ($conceptMap[(int) $block->concept_id] ?? $block->concept_id) : null,
            ] + (array_key_exists('team_id', $block->getAttributes()) ? ['team_id' => $team->id] : []))->save();
        }

        return (int) $neu->id;
    }

    /** @return array{context_file_id:int,path:string}|null */
    private function bild(?int $contextFileId, ?string $pfad, Team $team, string $typ, int $id, string $ordner): ?array
    {
        if (! $contextFileId && trim((string) $pfad) === '') {
            return null;
        }

        return $this->media->kopiereBild($contextFileId, $pfad, $team, $typ, $id, $ordner);
    }
}
