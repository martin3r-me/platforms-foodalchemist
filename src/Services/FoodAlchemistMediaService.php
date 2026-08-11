<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Platform\Core\Models\ContextFile;
use Platform\Core\Models\Team;
use Platform\Core\Services\ContextFileService;

class FoodAlchemistMediaService
{
    public function __construct(
        private ContextFileService $contextFiles,
    ) {
    }

    /**
     * @return array{context_file_id:int,path:string}
     */
    public function storeImage(UploadedFile $file, Team $team, string $contextType, int $contextId, string $folder): array
    {
        $result = $this->contextFiles->uploadForContext($file, $contextType, $contextId, [
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
