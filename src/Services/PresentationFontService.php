<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistPresentationFont;

/**
 * Eigene Schriften je Team für Präsentations-Designs. Eine Datei = ein Schnitt (Familie × Stärke × Stil).
 * Im Design referenziert als tokens_json.typography.heading/body = "team:<Familie>"; beim Veröffentlichen
 * friert buildSnapshot die benötigten Schnitte ein (resolved_design.fonts), das Layout schreibt @font-face.
 */
class PresentationFontService
{
    public const FORMATE = ['woff2' => 'woff2', 'woff' => 'woff', 'ttf' => 'truetype', 'otf' => 'opentype'];

    public const MAX_KB = 5120;

    public function __construct(private FoodAlchemistMediaService $media) {}

    /** Familienname auf sichere Zeichen kürzen (wird unescaped in CSS geschrieben). */
    public static function familie(?string $name): string
    {
        $f = trim(preg_replace('/[^\p{L}\p{N} \-]/u', '', (string) $name) ?? '');

        return mb_substr(preg_replace('/\s+/', ' ', $f) ?? '', 0, 60);
    }

    /** @return Collection<int, FoodAlchemistPresentationFont> */
    public function list(Team $team): Collection
    {
        return FoodAlchemistPresentationFont::visibleToTeam($team)->orderBy('family')->orderBy('weight')->orderBy('style')->get();
    }

    /** @return list<string> sichtbare Familien (eindeutig, sortiert) */
    public function familien(Team $team): array
    {
        return $this->list($team)->pluck('family')->unique()->values()->all();
    }

    public function upload(Team $team, UploadedFile $file, string $familie, int $weight = 400, string $style = 'normal'): FoodAlchemistPresentationFont
    {
        $familie = self::familie($familie);
        if ($familie === '') {
            throw new \RuntimeException('Bitte einen Namen für die Schrift angeben (Buchstaben, Ziffern, Leerzeichen, Bindestrich).');
        }
        $ext = strtolower($file->getClientOriginalExtension());
        if (! isset(self::FORMATE[$ext])) {
            throw new \RuntimeException('Nur Schriftdateien: ' . implode(', ', array_keys(self::FORMATE)) . '.');
        }
        if ($file->getSize() > self::MAX_KB * 1024) {
            throw new \RuntimeException('Die Schriftdatei ist größer als 5 MB.');
        }
        $weight = max(100, min(900, (int) round($weight / 100) * 100));
        $style = $style === 'italic' ? 'italic' : 'normal';

        // Gleicher Schnitt ersetzt den alten (eine Datei je Familie × Stärke × Stil).
        $alt = FoodAlchemistPresentationFont::where('team_id', $team->id)->where('family', $familie)
            ->where('weight', $weight)->where('style', $style)->first();
        $font = $alt ?? FoodAlchemistPresentationFont::create([
            'team_id' => $team->id, 'family' => $familie, 'weight' => $weight, 'style' => $style, 'format' => self::FORMATE[$ext],
        ]);
        if ($alt !== null) {
            $this->media->delete($alt->context_file_id, (string) $alt->path, $team);
        }
        $m = $this->media->storeFile($file, $team, 'foodalchemist.presentation_font', (int) $font->id, "foodalchemist/presentation_fonts/{$team->id}");
        $font->update(['format' => self::FORMATE[$ext], 'context_file_id' => $m['context_file_id'], 'path' => $m['path']]);

        return $font->refresh();
    }

    public function delete(Team $team, int $id): void
    {
        $font = FoodAlchemistPresentationFont::visibleToTeam($team)->findOrFail($id);
        if (! $font->isOwnedBy($team)) {
            throw new \RuntimeException('Geerbte Schrift: Löschen kann nur das Besitzer-Team.');
        }
        $this->media->delete($font->context_file_id, (string) $font->path, $team);
        $font->delete();
    }

    /**
     * Die Schnitte, die ein Token-Satz braucht (typography.heading/body = "team:<Familie>") — für den Snapshot.
     *
     * @return list<array{family:string, weight:int, style:string, format:string, context_file_id:?int, path:?string}>
     */
    public function fuerTokens(Team $team, array $tokens): array
    {
        $familien = [];
        foreach (['heading', 'body'] as $k) {
            $wert = $tokens['typography'][$k] ?? null;
            if (is_string($wert) && str_starts_with($wert, 'team:')) {
                $familien[] = self::familie(substr($wert, 5));
            }
        }
        $familien = array_values(array_unique(array_filter($familien)));
        if ($familien === []) {
            return [];
        }

        return FoodAlchemistPresentationFont::visibleToTeam($team)->whereIn('family', $familien)->get()
            ->map(fn ($f) => [
                'family' => $f->family, 'weight' => (int) $f->weight, 'style' => $f->style, 'format' => $f->format,
                'context_file_id' => $f->context_file_id, 'path' => $f->path,
            ])->values()->all();
    }
}
