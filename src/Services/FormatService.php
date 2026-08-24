<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistFormat;
use Platform\FoodAlchemist\Models\FoodAlchemistFormatImage;

/**
 * Format-Modul (Phase A): Format = Marken-/Themen-Container EINE Ebene über dem
 * Concept. Bündelt mehrere Zusammenstellungen (Concepts = Editionen) als
 * Ownership-FK (`concepts.format_id`) + trägt Marketing-Identität + Bildwelt.
 *
 * KEIN eigener Preis (nur read-only Range über die Editionen) und KEIN Recompute
 * beim Gruppieren — attach/detach fasst weder price_per_person_cache noch
 * kapitelAggregat an. Spiegelt ConceptService (visibleToTeam/isOwnedBy/guardOwner).
 */
class FormatService
{
    /** Herkunft (Kunden-IP-Guard): kunde = nie fremd wiederverwenden. */
    public const ORIGINS = ['eigen', 'gruppe', 'kunde'];

    private const FELDER = ['name', 'consumer_name', 'claim', 'story', 'origin', 'customer', 'status', 'note'];

    public function paginateBrowser(array $filters, Team $team, int $perPage = 100): LengthAwarePaginator
    {
        return FoodAlchemistFormat::visibleToTeam($team)
            ->withCount('editions')
            ->when(($filters['search'] ?? '') !== '', fn ($q) => \Platform\FoodAlchemist\Support\Suche::likeAny(
                $q, ['name', "COALESCE(consumer_name, '')", "COALESCE(claim, '')"], $filters['search']))
            ->when(($filters['status'] ?? '') !== '', fn ($q) => $q->where('status', $filters['status']))
            ->when(($filters['origin'] ?? '') !== '', fn ($q) => $q->where('origin', $filters['origin']))
            ->orderBy('name')
            ->paginate($perPage);
    }

    public function detail(Team $team, int $id): ?FoodAlchemistFormat
    {
        return FoodAlchemistFormat::visibleToTeam($team)
            ->with([
                'editions:id,name,consumer_name,claim,description,status,format_id,format_position,price_per_person_cache',
                'images' => fn ($q) => $q->orderBy('sort_order'),
            ])
            ->find($id);
    }

    public function create(Team $team, array $in): FoodAlchemistFormat
    {
        return FoodAlchemistFormat::create([
            'team_id' => $team->id,
            'name' => trim((string) ($in['name'] ?? 'Neues Format')) ?: 'Neues Format',
            'consumer_name' => $this->norm($in['consumer_name'] ?? null),
            'claim' => $this->norm($in['claim'] ?? null),
            'story' => $this->norm($in['story'] ?? null),
            'origin' => $this->normOrigin($in['origin'] ?? null),
            'customer' => $this->norm($in['customer'] ?? null),
            'status' => $in['status'] ?? 'draft',
            'note' => $this->norm($in['note'] ?? null),
        ]);
    }

    public function update(Team $team, int $id, array $in): FoodAlchemistFormat
    {
        $format = FoodAlchemistFormat::visibleToTeam($team)->findOrFail($id);
        $this->guardOwner($format, $team);

        $update = array_intersect_key($in, array_flip(self::FELDER));
        foreach (['consumer_name', 'claim', 'story', 'customer', 'note'] as $feld) {
            if (array_key_exists($feld, $update)) {
                $update[$feld] = $this->norm($update[$feld]);
            }
        }
        if (array_key_exists('origin', $update)) {
            $update['origin'] = $this->normOrigin($update['origin']);
        }
        if (array_key_exists('name', $update)) {
            $update['name'] = trim((string) $update['name']) ?: $format->name;
        }
        $format->update($update);

        return $format->refresh();
    }

    /** Status setzen (draft|active|archiviert) — Inline-Pflege aus dem Browser. */
    public function setStatus(Team $team, int $id, string $status): void
    {
        if (! in_array($status, ['draft', 'active', 'archiviert'], true)) {
            throw new \RuntimeException("Unbekannter Format-Status [{$status}].");
        }
        $format = FoodAlchemistFormat::visibleToTeam($team)->findOrFail($id);
        $this->guardOwner($format, $team);
        $format->update(['status' => $status]);
    }

    /**
     * Format löschen. Editionen werden über nullOnDelete wieder freistehend (kein
     * Datenverlust), Bildwelt cascadet. Kein Recompute nötig (Gruppierung trägt
     * keinen Preis).
     */
    public function delete(Team $team, int $id): void
    {
        $format = FoodAlchemistFormat::visibleToTeam($team)->findOrFail($id);
        $this->guardOwner($format, $team);
        DB::transaction(function () use ($format) {
            // Soft-Delete lässt den DB-seitigen nullOnDelete NICHT feuern (die Zeile bleibt
            // bestehen) — Editionen daher explizit lösen, damit sie wieder freistehend sind.
            // Bulk-Update, kein Recompute (Gruppierung trägt keinen Preis).
            FoodAlchemistConcept::where('format_id', $format->id)->update(['format_id' => null, 'format_position' => 0]);
            $format->delete();
        });
    }

    // ── Editionen (Concept ↔ Format) ────────────────────────────────────────

    /**
     * Bestehendes Concept als Edition zuordnen. Guardet BEIDE Seiten (Format UND
     * Concept müssen sichtbar + team-eigen sein). Kein Recompute — nur die FK +
     * Reihenfolge werden gesetzt.
     */
    public function attachEdition(Team $team, int $formatId, int $conceptId, ?int $position = null): FoodAlchemistConcept
    {
        $format = FoodAlchemistFormat::visibleToTeam($team)->findOrFail($formatId);
        $this->guardOwner($format, $team);

        $concept = FoodAlchemistConcept::visibleToTeam($team)->findOrFail($conceptId);
        if (! $concept->isOwnedBy($team)) {
            throw new \RuntimeException('Geerbtes Concept — Zuordnung nur durchs Besitzer-Team (D1).');
        }

        $pos = $position ?? ((int) (FoodAlchemistConcept::where('format_id', $formatId)->max('format_position') ?? -1) + 1);
        $concept->update(['format_id' => $formatId, 'format_position' => $pos]);

        return $concept->refresh();
    }

    /** Edition aus ihrem Format lösen (Concept wird wieder freistehend). */
    public function detachEdition(Team $team, int $conceptId): FoodAlchemistConcept
    {
        $concept = FoodAlchemistConcept::visibleToTeam($team)->findOrFail($conceptId);
        if (! $concept->isOwnedBy($team)) {
            throw new \RuntimeException('Geerbtes Concept — Zuordnung nur durchs Besitzer-Team (D1).');
        }
        $concept->update(['format_id' => null, 'format_position' => 0]);

        return $concept->refresh();
    }

    /** @param list<int> $conceptIds neue Reihenfolge der Editionen */
    public function reorderEditions(Team $team, int $formatId, array $conceptIds): void
    {
        $format = FoodAlchemistFormat::visibleToTeam($team)->findOrFail($formatId);
        $this->guardOwner($format, $team);
        DB::transaction(function () use ($formatId, $conceptIds) {
            foreach (array_values($conceptIds) as $i => $id) {
                FoodAlchemistConcept::where('id', (int) $id)->where('format_id', $formatId)
                    ->update(['format_position' => $i]);
            }
        });
    }

    /** Phase D: Standard-Sektions-Gerüst einer neu angelegten Edition (Concepter 2.0). */
    public const SEKTIONS_GERUEST = ['Amuse', 'Vorspeise', 'Hauptgang', 'Dessert'];

    /**
     * Phase D (Concepter 2.0): Kunden-Wording einer Edition (= Unterkapitel) pflegen —
     * Foodbook-Kapitel-Parität: consumer_name (Titel), claim, description (Hinführung).
     * Guardet, dass die Edition zu DIESEM Format gehört + team-eigen ist.
     */
    public function updateEditionWording(Team $team, int $formatId, int $conceptId, array $wording): FoodAlchemistConcept
    {
        $format = FoodAlchemistFormat::visibleToTeam($team)->findOrFail($formatId);
        $this->guardOwner($format, $team);
        $concept = FoodAlchemistConcept::visibleToTeam($team)->where('format_id', $formatId)->findOrFail($conceptId);
        if (! $concept->isOwnedBy($team)) {
            throw new \RuntimeException('Geerbte Edition — Pflege nur durchs Besitzer-Team (D1).');
        }

        $felder = array_intersect_key($wording, array_flip(['consumer_name', 'claim', 'description']));

        return app(ConceptService::class)->update($team, $conceptId, $felder);
    }

    /**
     * Phase D (Concepter 2.0): eine NEUE Edition (Concept) im Format-Kontext anlegen und
     * zuordnen. `$withSkeleton` seedet automatisch das Sektions-Gerüst (AMUSE/Vorspeise/
     * Hauptgang/Dessert als Header-Slots) — das „automatisch"-Grundgerüst.
     */
    public function createEdition(Team $team, int $formatId, string $name, bool $withSkeleton = true): FoodAlchemistConcept
    {
        $format = FoodAlchemistFormat::visibleToTeam($team)->findOrFail($formatId);
        $this->guardOwner($format, $team);

        $concepts = app(ConceptService::class);
        $concept = $concepts->create($team, ['name' => trim($name) !== '' ? trim($name) : 'Neue Edition', 'status' => 'draft']);
        $this->attachEdition($team, $formatId, $concept->id);

        if ($withSkeleton) {
            foreach (self::SEKTIONS_GERUEST as $sektion) {
                $concepts->addBlock($team, $concept->id, 'header', ['title' => $sektion]);
            }
        }

        return $concept->refresh();
    }

    // ── Marketing-Bilder ─────────────────────────────────────────────────────

    public function storeImage(Team $team, int $formatId, UploadedFile $file, ?string $caption = null): FoodAlchemistFormatImage
    {
        $format = FoodAlchemistFormat::visibleToTeam($team)->findOrFail($formatId);
        $this->guardOwner($format, $team);

        $stored = app(FoodAlchemistMediaService::class)->storeImage(
            $file, $team, 'foodalchemist_format', $formatId, "foodalchemist/formate/{$formatId}");

        $istErstes = ! FoodAlchemistFormatImage::where('format_id', $formatId)->exists();

        return FoodAlchemistFormatImage::create([
            'team_id' => $format->team_id,
            'format_id' => $formatId,
            'context_file_id' => $stored['context_file_id'],
            'path' => $stored['path'],
            'caption' => $this->norm($caption),
            'is_hero' => $istErstes,   // erstes Bild = Hero, bis manuell umgesetzt
            'sort_order' => (int) (FoodAlchemistFormatImage::where('format_id', $formatId)->max('sort_order') ?? 0) + 10,
        ]);
    }

    public function clearImage(Team $team, int $imageId): void
    {
        $image = $this->ownedImage($team, $imageId);
        app(FoodAlchemistMediaService::class)->delete($image->context_file_id, $image->path, $team);
        $warHero = (bool) $image->is_hero;
        $formatId = (int) $image->format_id;
        $image->delete();
        // Hero verwaist → ältestes verbliebenes Bild wird Hero (immer genau 0/1 Hero).
        if ($warHero) {
            $next = FoodAlchemistFormatImage::where('format_id', $formatId)->orderBy('sort_order')->first();
            $next?->update(['is_hero' => true]);
        }
    }

    /** Genau ein Bild als Hero markieren (alle anderen des Formats zurücksetzen). */
    public function setHero(Team $team, int $imageId): FoodAlchemistFormatImage
    {
        $image = $this->ownedImage($team, $imageId);
        DB::transaction(function () use ($image) {
            FoodAlchemistFormatImage::where('format_id', $image->format_id)->update(['is_hero' => false]);
            $image->update(['is_hero' => true]);
        });

        return $image->refresh();
    }

    /** @param list<int> $imageIds neue Reihenfolge */
    public function reorderImages(Team $team, int $formatId, array $imageIds): void
    {
        $format = FoodAlchemistFormat::visibleToTeam($team)->findOrFail($formatId);
        $this->guardOwner($format, $team);
        DB::transaction(function () use ($formatId, $imageIds) {
            foreach (array_values($imageIds) as $i => $id) {
                FoodAlchemistFormatImage::where('id', (int) $id)->where('format_id', $formatId)
                    ->update(['sort_order' => ($i + 1) * 10]);
            }
        });
    }

    public function setImageCaption(Team $team, int $imageId, ?string $caption): FoodAlchemistFormatImage
    {
        $image = $this->ownedImage($team, $imageId);
        $image->update(['caption' => $this->norm($caption)]);

        return $image->refresh();
    }

    // ── Preis-Range (read-only) ───────────────────────────────────────────────

    /** @return array{min: ?float, max: ?float} */
    public function priceRange(Team $team, int $id): array
    {
        $format = FoodAlchemistFormat::visibleToTeam($team)->with('editions:id,format_id,price_per_person_cache')->findOrFail($id);

        return $format->priceRange();
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function ownedImage(Team $team, int $imageId): FoodAlchemistFormatImage
    {
        $image = FoodAlchemistFormatImage::with('format')->findOrFail($imageId);
        if ($image->format === null || ! in_array((int) $image->format->team_id, FoodAlchemistFormat::teamAncestryIds($team), true)) {
            throw new \Illuminate\Database\Eloquent\ModelNotFoundException('Bild nicht sichtbar.');
        }
        if (! $image->format->isOwnedBy($team)) {
            throw new \RuntimeException('Geerbtes Format — Pflege nur durchs Besitzer-Team (D1).');
        }

        return $image;
    }

    private function guardOwner(FoodAlchemistFormat $format, Team $team): void
    {
        if (! $format->isOwnedBy($team)) {
            throw new \RuntimeException('Geerbtes Format — Pflege nur durchs Besitzer-Team (D1).');
        }
    }

    private function normOrigin(?string $v): ?string
    {
        $v = $this->norm($v);
        if ($v === null) {
            return null;
        }
        $v = mb_strtolower($v);
        if (! in_array($v, self::ORIGINS, true)) {
            throw new \RuntimeException('Unbekannte Herkunft "' . $v . '" — erlaubt: ' . implode(', ', self::ORIGINS) . '.');
        }

        return $v;
    }

    private function norm(?string $v): ?string
    {
        $v = $v !== null ? trim($v) : null;

        return $v === '' ? null : $v;
    }
}
