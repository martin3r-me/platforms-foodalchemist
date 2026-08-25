<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistFormat;
use Platform\FoodAlchemist\Models\FoodAlchemistFormatImage;
use Platform\FoodAlchemist\Models\FoodAlchemistFormatSlot;

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

    // F1: serving_form_id/event_type_id sind Format-Dimensionen (Facetten), spiegeln Concept.
    private const FELDER = ['name', 'consumer_name', 'claim', 'story', 'origin', 'customer', 'status', 'note',
        'serving_form_id', 'event_type_id'];

    /** Leer („"/0) → NULL (optionale FK). */
    private const FELDER_NULLBAR = ['serving_form_id', 'event_type_id'];

    public function paginateBrowser(array $filters, Team $team, int $perPage = 100): LengthAwarePaginator
    {
        return FoodAlchemistFormat::visibleToTeam($team)
            ->with(['eventType:id,name', 'servingForm:id,label']) // F1: Facetten-Spalte im Browser
            // F2-Cutover: Editionen-Spalte zählt die Concept-Referenz-Slots (nicht mehr format_id).
            ->withCount(['slots as editions_count' => fn ($q) => $q->where('type', 'concept')])
            ->when(($filters['search'] ?? '') !== '', fn ($q) => \Platform\FoodAlchemist\Support\Suche::likeAny(
                $q, ['name', "COALESCE(consumer_name, '')", "COALESCE(claim, '')"], $filters['search']))
            ->when(($filters['status'] ?? '') !== '', fn ($q) => $q->where('status', $filters['status']))
            ->when(($filters['origin'] ?? '') !== '', fn ($q) => $q->where('origin', $filters['origin']))
            // F1-Facetten-Filter (spiegelt ConceptService::paginateBrowser)
            ->when(is_numeric($filters['servierform'] ?? null), fn ($q) => $q->where('serving_form_id', (int) $filters['servierform']))
            ->when(is_numeric($filters['eventtyp'] ?? null), fn ($q) => $q->where('event_type_id', (int) $filters['eventtyp']))
            ->when(is_numeric($filters['einsatzmoment'] ?? null), fn ($q) => $q
                ->whereHas('serviceMoments', fn ($w) => $w->where('foodalchemist_service_moments.id', (int) $filters['einsatzmoment'])))
            ->when(is_numeric($filters['season'] ?? null), fn ($q) => $q
                ->whereHas('seasons', fn ($w) => $w->where('foodalchemist_seasons.id', (int) $filters['season'])))
            ->orderBy('name')
            ->paginate($perPage);
    }

    public function detail(Team $team, int $id): ?FoodAlchemistFormat
    {
        return FoodAlchemistFormat::visibleToTeam($team)
            ->with([
                // F2e: der Editor/Detail liest den Aufbau ausschließlich aus den Slots (Concept-Referenzen
                // + Struktur-Blöcke) — der Alt-Editionen-Eager-Load ist mit dem Cutover entfernt.
                // #3/F6: kind → „Paket"-Badge am Slot; ek_per_person_cache → Detail-Cockpit Ø/W-Kontext.
                'slots.concept:id,name,consumer_name,claim,description,status,kind,price_per_person_cache,ek_per_person_cache',
                'images' => fn ($q) => $q->orderBy('sort_order'),
                // F1: Facetten fürs Detail/Editor (Namen → Dimension-Chips im Detail-Panel, #3)
                'servingForm:id,label', 'eventType:id,name', 'serviceMoments:id,name', 'seasons:id,name', 'targetGroups:id,name',
            ])
            ->find($id);
    }

    public function create(Team $team, array $in): FoodAlchemistFormat
    {
        $format = FoodAlchemistFormat::create([
            'team_id' => $team->id,
            'name' => trim((string) ($in['name'] ?? 'Neues Format')) ?: 'Neues Format',
            'consumer_name' => $this->norm($in['consumer_name'] ?? null),
            'claim' => $this->norm($in['claim'] ?? null),
            'story' => $this->norm($in['story'] ?? null),
            'origin' => $this->normOrigin($in['origin'] ?? null),
            'customer' => $this->norm($in['customer'] ?? null),
            'status' => $in['status'] ?? 'draft',
            'note' => $this->norm($in['note'] ?? null),
            'serving_form_id' => $this->nullbareId($in['serving_form_id'] ?? null),
            'event_type_id' => $this->nullbareId($in['event_type_id'] ?? null),
        ]);
        $this->syncFacetten($team, $format, $in);

        return $format;
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
        foreach (self::FELDER_NULLBAR as $feld) {
            if (array_key_exists($feld, $update)) {
                $update[$feld] = $this->nullbareId($update[$feld]);
            }
        }
        if (array_key_exists('origin', $update)) {
            $update['origin'] = $this->normOrigin($update['origin']);
        }
        if (array_key_exists('name', $update)) {
            $update['name'] = trim((string) $update['name']) ?: $format->name;
        }
        $format->update($update);
        $this->syncFacetten($team, $format, $in);

        return $format->refresh();
    }

    /**
     * F1: Facetten-Pivots (Einsatzmomente/Saisons/Zielgruppen) synchronisieren — nur wenn der
     * jeweilige *_ids-Key im Input steht. IDs werden auf team-sichtbares Vokabular gefiltert
     * (kein Cross-Team-Attach). Spiegelt die Concept-Facetten-Pflege.
     */
    private function syncFacetten(Team $team, FoodAlchemistFormat $format, array $in): void
    {
        $map = [
            'einsatzmoment_ids' => [\Platform\FoodAlchemist\Models\FoodAlchemistEinsatzmoment::class, 'serviceMoments'],
            'saison_ids' => [\Platform\FoodAlchemist\Models\FoodAlchemistSaison::class, 'seasons'],
            'target_group_ids' => [\Platform\FoodAlchemist\Models\FoodAlchemistTargetGroup::class, 'targetGroups'],
        ];
        foreach ($map as $key => [$modell, $relation]) {
            if (! array_key_exists($key, $in)) {
                continue;
            }
            $ids = array_values(array_unique(array_map('intval', (array) $in[$key])));
            $sichtbar = $ids === [] ? [] : $modell::visibleToTeam($team)->whereIn('id', $ids)->pluck('id')->all();
            $format->{$relation}()->sync($sichtbar);
        }
    }

    /** Leere/0-FK → NULL (optionale Facetten-FK). */
    private function nullbareId($wert): ?int
    {
        return ($wert === null || $wert === '' || (int) $wert === 0) ? null : (int) $wert;
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
     * Format löschen (Soft-Delete). F2e: die referenzierten Concepts sind unabhängig
     * (reine Slot-Referenz, kein Besitz) und bleiben unangetastet; die Aufbau-Slots
     * gehören zum Format und verschwinden mit ihm aus den Listen. Kein Recompute nötig
     * (Gruppierung trägt keinen Preis).
     */
    public function delete(Team $team, int $id): void
    {
        $format = FoodAlchemistFormat::visibleToTeam($team)->findOrFail($id);
        $this->guardOwner($format, $team);
        $format->delete();
    }

    // ── F2: Aufbau / Slots (Referenz-Concepts + Struktur-Blöcke) ────────────────
    // „Conceptor eine Ebene höher": ein Format-Slot referenziert ein ganzes Concept
    // (type=concept, in mehreren Formaten nutzbar) ODER ist ein Struktur-Block
    // (header/text/spacer). Spiegelt ConceptService::fillSlot/addBlock/reorder.

    /**
     * Concept-Kandidaten für den Format-Picker (aktive Konzepte, keine Pakete).
     * #2: dieselben Filter-Args wie {@see paketKandidaten} (Klasse + Facetten), damit
     * die beiden Picker-Reiter dieselbe Filterkette teilen (spiegelt ConceptService::paginateBrowser).
     */
    public function conceptKandidaten(Team $team, string $suche = '', array $filters = [], int $limit = 50): \Illuminate\Support\Collection
    {
        return $this->kandidatenBasis(FoodAlchemistConcept::visibleToTeam($team)->konzepte(), $suche, $filters, $limit);
    }

    /**
     * #2: Paket-Kandidaten (kind=paket-Concepts) für den Format-Picker — aktive, standardisierte
     * Pakete mit denselben Filter-Args wie {@see conceptKandidaten}. Ein Paket passt als Format-Slot,
     * weil es ein kind=paket-Concept ist (concept_id trägt beide Arten).
     */
    public function paketKandidaten(Team $team, string $suche = '', array $filters = [], int $limit = 50): \Illuminate\Support\Collection
    {
        return $this->kandidatenBasis(FoodAlchemistConcept::visibleToTeam($team)->pakete(), $suche, $filters, $limit);
    }

    /** Gemeinsame Filterkette für Concept-/Paket-Picker (Suche + Klasse + Facetten). */
    private function kandidatenBasis($query, string $suche, array $filters, int $limit): \Illuminate\Support\Collection
    {
        return $query
            ->standardisiert()->echte()
            ->where('status', 'active')   // Picker zeigt nur aktive (Status berücksichtigt)
            ->when($suche !== '', fn ($q) => \Platform\FoodAlchemist\Support\Suche::like($q, 'name', $suche))
            ->when(($filters['class'] ?? '') !== '', fn ($q) => $q->where('class', $filters['class']))
            ->when(is_numeric($filters['servierform'] ?? null), fn ($q) => $q->where('serving_form_id', (int) $filters['servierform']))
            ->when(is_numeric($filters['eventtyp'] ?? null), fn ($q) => $q->where('event_type_id', (int) $filters['eventtyp']))
            ->when(is_numeric($filters['einsatzmoment'] ?? null), fn ($q) => $q
                ->whereHas('serviceMoments', fn ($w) => $w->where('foodalchemist_service_moments.id', (int) $filters['einsatzmoment'])))
            ->when(is_numeric($filters['season'] ?? null), fn ($q) => $q
                ->whereHas('seasons', fn ($w) => $w->where('foodalchemist_seasons.id', (int) $filters['season'])))
            ->orderBy('name')->limit($limit)
            ->get(['id', 'name', 'consumer_name', 'kind', 'class', 'price_per_person_cache']);
    }

    /** Concept als Aufbau-Position (Referenz) einfügen; optional direkt hinter $afterSlotId. */
    public function slotConceptEinfuegen(Team $team, int $formatId, int $conceptId, ?int $afterSlotId = null): FoodAlchemistFormatSlot
    {
        $format = FoodAlchemistFormat::visibleToTeam($team)->findOrFail($formatId);
        $this->guardOwner($format, $team);
        // #2/F6: sowohl Konzepte als auch Pakete (kind=paket-Concept) sind einfügbar — der Slot trägt beide
        // über concept_id. Kein ->konzepte()-Filter mehr, sonst ließe sich kein Paket booken.
        $concept = FoodAlchemistConcept::visibleToTeam($team)->findOrFail($conceptId);

        $slot = $format->slots()->create([
            'team_id' => $format->team_id,
            'type' => 'concept',
            'concept_id' => $concept->id,
            'position' => $this->naechsteSlotPosition($format),
        ]);
        if ($afterSlotId !== null) {
            $this->slotEinfuegenNach($format, $slot->id, $afterSlotId);
        }

        return $slot->refresh();
    }

    /** Struktur-Block (header/text/spacer) einfügen; optional direkt hinter $afterSlotId. */
    public function slotBlockEinfuegen(Team $team, int $formatId, string $type, array $in = [], ?int $afterSlotId = null): FoodAlchemistFormatSlot
    {
        if (! in_array($type, FoodAlchemistFormatSlot::STRUKTUR_TYPEN, true)) {
            throw new \RuntimeException("Unbekannter Block-Typ [{$type}] — erlaubt: " . implode('|', FoodAlchemistFormatSlot::STRUKTUR_TYPEN) . '.');
        }
        $format = FoodAlchemistFormat::visibleToTeam($team)->findOrFail($formatId);
        $this->guardOwner($format, $team);

        $slot = $format->slots()->create([
            'team_id' => $format->team_id,
            'type' => $type,
            'title' => $this->norm($in['title'] ?? null),
            'text_content' => $this->norm($in['text_content'] ?? null),
            'height' => $type === 'spacer' ? ($in['height'] ?? 'mittel') : null,
            'position' => $this->naechsteSlotPosition($format),
        ]);
        if ($afterSlotId !== null) {
            $this->slotEinfuegenNach($format, $slot->id, $afterSlotId);
        }

        return $slot->refresh();
    }

    /** Struktur-Block-Felder (title/text_content/height) pflegen. */
    public function slotBlockSpeichern(Team $team, int $slotId, array $in): FoodAlchemistFormatSlot
    {
        $slot = FoodAlchemistFormatSlot::with('format')->findOrFail($slotId);
        $this->guardOwner($slot->format, $team);
        $upd = [];
        foreach (['title', 'text_content'] as $f) {
            if (array_key_exists($f, $in)) {
                $upd[$f] = $this->norm($in[$f]);
            }
        }
        if (array_key_exists('height', $in)) {
            $upd['height'] = $in['height'] ?: null;
        }
        $slot->update($upd);

        return $slot->refresh();
    }

    /** Aufbau-Position entfernen (Concept-Ref oder Block). */
    public function slotEntfernen(Team $team, int $slotId): void
    {
        $slot = FoodAlchemistFormatSlot::with('format')->findOrFail($slotId);
        $this->guardOwner($slot->format, $team);
        $slot->delete();
    }

    /** @param list<int> $orderedIds neue Reihenfolge der Slots (nur zu diesem Format). */
    public function slotsNeuOrdnen(Team $team, int $formatId, array $orderedIds): void
    {
        $format = FoodAlchemistFormat::visibleToTeam($team)->findOrFail($formatId);
        $this->guardOwner($format, $team);
        DB::transaction(function () use ($format, $orderedIds) {
            $pos = 1;
            foreach (array_values($orderedIds) as $id) {
                $format->slots()->whereKey((int) $id)->update(['position' => $pos++]);
            }
        });
    }

    /** Slot hinter einen anderen ziehen (Einfüge-Ziel / Drag) — renummeriert das Format. */
    public function slotVerschieben(Team $team, int $slotId, ?int $afterSlotId): void
    {
        $slot = FoodAlchemistFormatSlot::with('format')->findOrFail($slotId);
        $this->guardOwner($slot->format, $team);
        $this->slotEinfuegenNach($slot->format, $slotId, $afterSlotId);
    }

    private function naechsteSlotPosition(FoodAlchemistFormat $format): int
    {
        return (int) ($format->slots()->max('position') ?? 0) + 1;
    }

    /** $slotId direkt hinter $afterSlotId einsortieren (null = an den Anfang); renummeriert 1..n. */
    private function slotEinfuegenNach(FoodAlchemistFormat $format, int $slotId, ?int $afterSlotId): void
    {
        DB::transaction(function () use ($format, $slotId, $afterSlotId) {
            $ids = $format->slots()->orderBy('position')->pluck('id')->map(fn ($v) => (int) $v)->all();
            $ids = array_values(array_filter($ids, fn ($id) => $id !== $slotId));
            $ziel = $afterSlotId !== null ? array_search((int) $afterSlotId, $ids, true) : false;
            $einfuege = $ziel === false ? 0 : $ziel + 1;
            array_splice($ids, $einfuege, 0, [$slotId]);
            $pos = 1;
            foreach ($ids as $id) {
                $format->slots()->whereKey($id)->update(['position' => $pos++]);
            }
        });
    }

    /** Phase D: Standard-Sektions-Gerüst einer neu angelegten Edition (Concepter 2.0). */
    public const SEKTIONS_GERUEST = ['Amuse', 'Vorspeise', 'Hauptgang', 'Dessert'];

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
        // F2e: ausschließlich über die Concept-Referenz-Slots (Alt-Editionen-Fallback entfernt).
        $format = FoodAlchemistFormat::visibleToTeam($team)
            ->with(['slots.concept:id,price_per_person_cache'])
            ->findOrFail($id);

        return $format->priceRange();
    }

    // ── Druck-Dokument (F3) ────────────────────────────────────────────────

    /**
     * F3: Druck-/PDF-Daten des Formats — die schöne Kunden-Ausgabe (kein technischer
     * Report). Spiegelt {@see FoodbookService::dokumentDaten}: das Format ist das
     * Oberkapitel (Marken-Identität + Hero), die Aufbau-Slots (in Position-Reihenfolge)
     * liefern die Positionen — Concept-Slots als „Editionen" (Kapitel-Parität: Titel,
     * Claim, Hinführung, €/Gast + die Menü-Zeilen über {@see WordingResolver::gerichtZeilen}),
     * header/text/spacer als Struktur. LIVE aus den referenzierten Concepts (kein Snapshot).
     * PDF-safe: Hero als base64-dataUri (kein externer Asset).
     *
     * @return array{
     *     format: FoodAlchemistFormat, intern: bool, name: string, consumer_name: ?string,
     *     claim: ?string, story: ?string, hero: ?string, range: array{min: ?float, max: ?float},
     *     positionen: list<array<string, mixed>>, mwst: ?array, stand: mixed
     * }
     */
    public function dokumentDaten(Team $team, int $formatId, bool $intern = false): array
    {
        $format = FoodAlchemistFormat::visibleToTeam($team)
            ->with([
                'slots' => fn ($q) => $q->orderBy('position'),
                // Edition-Identität (Kapitel-Parität) + Preis-Cache.
                'slots.concept:id,name,consumer_name,claim,description,status,price_per_person_cache',
                // Menü-Zeilen der Edition (gleiche Wording-Auflösung wie Foodbook/Editor-Vorschau).
                'slots.concept.slots' => fn ($q) => $q->orderBy('position'),
                'slots.concept.slots.dish:id,name,sales_wording_standard',
                'slots.concept.slots.package.dishes.dish:id,name,sales_wording_standard',
                'slots.concept.slots.embeddedConcept:id,name,consumer_name,price_per_person_cache',
                'slots.concept.slots.embeddedConcept.slots.dish:id,name,sales_wording_standard',
                'heroImage',
            ])
            ->findOrFail($formatId);

        $wording = app(WordingResolver::class);

        $positionen = [];
        foreach ($format->slots as $slot) {
            if ($slot->type === 'concept') {
                $concept = $slot->concept;
                if ($concept === null) {
                    continue; // referenziertes Concept nicht mehr sichtbar → Position auslassen
                }
                $positionen[] = [
                    'kind' => 'edition',
                    'title' => $concept->consumer_name ?: $concept->name,
                    'claim' => $concept->claim,
                    'text' => trim((string) $concept->description) ?: null,
                    'preis_pp' => $concept->price_per_person_cache !== null ? (float) $concept->price_per_person_cache : null,
                    'gerichte' => $wording->gerichtZeilen($concept),
                ];
            } elseif ($slot->type === 'header') {
                $text = trim((string) $slot->title);
                if ($text === '') {
                    continue;
                }
                $positionen[] = ['kind' => 'header', 'text' => $text];
            } elseif ($slot->type === 'text') {
                $text = trim((string) $slot->text_content);
                if ($text === '') {
                    continue;
                }
                $positionen[] = ['kind' => 'text', 'text' => $text];
            } elseif ($slot->type === 'spacer') {
                $positionen[] = ['kind' => 'spacer', 'height' => $slot->height ?: 'mittel'];
            }
        }

        return [
            'format' => $format,
            'intern' => $intern,
            'name' => (string) $format->name,
            'consumer_name' => $format->consumer_name,
            'claim' => $format->claim,
            'story' => $format->story,
            'hero' => $format->heroImage?->dataUri(),
            'range' => $format->priceRange(),
            'positionen' => $positionen,
            'mwst' => app(TeamSettingsService::class)->mwst($team),
            'stand' => $format->updated_at,
        ];
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
