<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Platform\Core\Models\ContextFile;
use Platform\Core\Models\Team;
use Platform\Core\Services\ContextFileService;

class FoodAlchemistMediaService
{
    public function __construct(
        private ContextFileService $contextFiles,
    ) {
    }

    /** Standard der langen Kante (4K: der Wandmonitor zeigt Fotos groß); einstellbar über foodalchemist.media.max_kante. */
    public const MAX_KANTE = 3840;

    public static function maxKante(): int
    {
        return max(1, (int) config('foodalchemist.media.max_kante', self::MAX_KANTE));
    }

    /**
     * @return array{context_file_id:int,path:string}
     */
    public function storeImage(UploadedFile $file, Team $team, string $contextType, int $contextId, string $folder): array
    {
        $result = $this->contextFiles->uploadForContext($this->verkleinert($file), $contextType, $contextId, [
            'team_id' => $team->id,
            'folder' => $folder,
            'keep_original' => false,
            'generate_variants' => false,
        ]);

        return [
            'context_file_id' => (int) $result['id'],
            'path' => (string) $result['path'],
        ];
    }

    /**
     * Vorschritt vor dem Core (der speichert Rasterbilder als WebP in Originalgröße): Bilder mit langer Kante über
     * maxKante() werden auf maxKante() verkleinert, Seitenverhältnis bleibt, die EXIF-Ausrichtung wird angewendet (Handyfotos
     * liegen sonst quer). Kleinere Bilder, Nicht-Bilder, SVG/GIF und Formate, die GD nicht lesen kann (z. B. HEIC), gehen
     * unverändert durch. Dieselbe Bibliothek wie der Core (Intervention Image, GD).
     */
    public function verkleinert(UploadedFile $file): UploadedFile
    {
        $mime = strtolower((string) $file->getMimeType());
        $pfad = (string) $file->getRealPath();
        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) || $pfad === '') {
            return $file;
        }
        $masse = @getimagesize($pfad);
        if ($masse === false || max((int) $masse[0], (int) $masse[1]) <= self::maxKante()) {
            return $file;
        }
        try {
            $bild = ImageManager::gd()->read($pfad);   // autoOrientation: EXIF-Drehung wird beim Lesen angewendet
            $bild->scaleDown(self::maxKante(), self::maxKante());
            $kodiert = match ($mime) {
                'image/png' => $bild->toPng(),
                'image/webp' => $bild->toWebp(90),
                default => $bild->toJpeg(90),
            };
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::info('Bild nicht verkleinert, unverändert gespeichert: '.$e->getMessage(), ['mime' => $mime]);

            return $file;
        }
        $tmp = (string) tempnam(sys_get_temp_dir(), 'fa-klein-');
        file_put_contents($tmp, (string) $kodiert);
        register_shutdown_function(static fn () => @unlink($tmp));

        return new UploadedFile($tmp, $file->getClientOriginalName(), $mime, null, true);
    }

    /**
     * Bilddatei in den Speicher eines anderen Teams kopieren (Kündigung/Eigene Kopie eines Abo-Objekts, Spec 82 D5): Quelle = ContextFile des Masters
     * bzw. Legacy-Pfad auf der public-Disk. Fehlt die Datei, kommt `null` (kein Abbruch — der Aufrufer meldet einen Hinweis).
     *
     * @return array{context_file_id:int,path:string}|null
     */
    public function kopiereBild(?int $contextFileId, ?string $legacyPath, Team $ziel, string $contextType, int $contextId, string $folder): ?array
    {
        $bytes = null;
        $name = 'bild.jpg';
        $mime = 'image/jpeg';
        try {
            $quelle = $contextFileId ? ContextFile::find($contextFileId) : null;
            if ($quelle !== null && Storage::disk($quelle->disk)->exists($quelle->path)) {
                $bytes = Storage::disk($quelle->disk)->get($quelle->path);
                $name = (string) ($quelle->original_name ?: basename($quelle->path));
                $mime = (string) ($quelle->mime_type ?: 'image/jpeg');
            } elseif (trim((string) $legacyPath) !== '' && Storage::disk('public')->exists((string) $legacyPath)) {
                $bytes = Storage::disk('public')->get((string) $legacyPath);
                $name = basename((string) $legacyPath);
                $mime = (string) (Storage::disk('public')->mimeType((string) $legacyPath) ?: 'image/jpeg');
            }
            if ($bytes === null) {
                return null;
            }
            $tmp = tempnam(sys_get_temp_dir(), 'fa-bild-');
            file_put_contents($tmp, $bytes);
            try {
                return $this->storeImage(new UploadedFile($tmp, $name, $mime, null, true), $ziel, $contextType, $contextId, $folder);
            } finally {
                @unlink($tmp);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Bild konnte nicht kopiert werden: '.$e->getMessage(), ['context_file_id' => $contextFileId, 'ziel_team' => $ziel->id]);

            return null;
        }
    }

    public function delete(?int $contextFileId, ?string $legacyPath, Team $team): void
    {
        if ($contextFileId !== null) {
            $this->contextFiles->delete($contextFileId, $team->id);

            return;
        }

        $legacyPath = trim((string) $legacyPath);
        if ($legacyPath !== '' && Storage::disk('public')->exists($legacyPath)) {
            Storage::disk('public')->delete($legacyPath);
        }
    }

    public function dataUri(?int $contextFileId, ?string $legacyPath): ?string
    {
        $file = $contextFileId ? ContextFile::find($contextFileId) : null;
        if ($file !== null && Storage::disk($file->disk)->exists($file->path)) {
            $mime = $file->mime_type ?: (Storage::disk($file->disk)->mimeType($file->path) ?: 'image/png');
            $bytes = Storage::disk($file->disk)->get($file->path);

            return 'data:' . $mime . ';base64,' . base64_encode($bytes);
        }

        $legacyPath = trim((string) $legacyPath);
        if ($legacyPath === '' || ! Storage::disk('public')->exists($legacyPath)) {
            return null;
        }

        $mime = Storage::disk('public')->mimeType($legacyPath) ?: 'image/png';
        $bytes = Storage::disk('public')->get($legacyPath);

        return 'data:' . $mime . ';base64,' . base64_encode($bytes);
    }

    public function url(?int $contextFileId, ?string $legacyPath): string
    {
        $file = $contextFileId ? ContextFile::find($contextFileId) : null;
        if ($file !== null) {
            return $file->url;
        }

        return Storage::disk('public')->url((string) $legacyPath);
    }
}
