<?php

namespace Platform\FoodAlchemist\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Platform\Core\Models\ContextFile;
use Platform\FoodAlchemist\Services\KapitelLogoService;

/**
 * Präsentation 2026-11-13 — ergänzt die Kapitel eines Snapshots beim Bauen um
 *  - `logo` (Identifier des optionalen Kapitel-Logos, Foodbook / Angebot / Speisekarte) und
 *  - `transparent: true` an Kapitelbildern mit Alpha-Kanal (PNG oder WebP; der Speicher wandelt PNG in WebP).
 * Transparente Bilder zeigt der Renderer bei Darstellung „automatisch“ ganz statt als beschnittenen Fotoband
 * (Anlass: ein Logo als Kapitelbild wurde aufgeblasen und abgeschnitten).
 */
final class KapitelBildDaten
{
    /** Präsentations-Typ → [Kapitel-Art im KapitelLogoService, Anker-Präfix]. */
    private const TYPEN = [
        'foodbook' => ['foodbook', 'k'],
        'angebot' => ['angebot', 'k'],
        'speisekarte' => ['speisekarte', 'r'],
    ];

    /**
     * @param  list<array<string,mixed>>  $sections
     * @return list<array<string,mixed>>
     */
    public static function anreichern(string $type, array $sections): array
    {
        $logos = self::logos($type, $sections);
        foreach ($sections as $i => $s) {
            $id = self::kapitelId($type, $s);
            if ($id !== null && isset($logos[$id])) {
                $sections[$i]['logo'] = $logos[$id];
            }
            if (is_array($s['image'] ?? null) && self::istTransparent($s['image'])) {
                $sections[$i]['image']['transparent'] = true;
            }
            foreach ($s['images'] ?? [] as $j => $bild) {
                if (is_array($bild) && self::istTransparent($bild)) {
                    $sections[$i]['images'][$j]['transparent'] = true;
                }
            }
        }

        return $sections;
    }

    /** @return array<int, array{context_file_id:?int, path:?string}> Kapitel-Id → Logo-Identifier */
    private static function logos(string $type, array $sections): array
    {
        if (! isset(self::TYPEN[$type])) {
            return [];
        }
        $ids = array_values(array_filter(array_map(fn ($s) => self::kapitelId($type, $s), $sections)));
        if ($ids === []) {
            return [];
        }
        $klasse = KapitelLogoService::ARTEN[self::TYPEN[$type][0]][0];
        $logos = [];
        foreach ($klasse::query()->whereIn('id', $ids)->get(['id', 'logo_context_file_id', 'logo_path']) as $k) {
            if ($k->logo_context_file_id || $k->logo_path) {
                $logos[(int) $k->id] = ['context_file_id' => $k->logo_context_file_id ? (int) $k->logo_context_file_id : null, 'path' => $k->logo_path];
            }
        }

        return $logos;
    }

    private static function kapitelId(string $type, array $s): ?int
    {
        $praefix = self::TYPEN[$type][1] ?? null;
        $anker = (string) ($s['anker'] ?? '');
        if ($praefix === null || ! preg_match('/^' . $praefix . '(\d+)$/', $anker, $m)) {
            return null;
        }

        return (int) $m[1];
    }

    /** Hat das Bild einen Alpha-Kanal? Ergebnis je Datei dauerhaft gecacht (Dateien sind unveränderlich). */
    public static function istTransparent(array $bild): bool
    {
        $cf = isset($bild['context_file_id']) ? (int) $bild['context_file_id'] : 0;
        $pfad = trim((string) ($bild['path'] ?? ''));
        if ($cf <= 0 && $pfad === '') {
            return false;
        }
        $schluessel = 'fa-bild-transparent:' . ($cf > 0 ? 'cf' . $cf : md5($pfad));

        $gemerkt = Cache::get($schluessel);
        if ($gemerkt !== null) {
            return $gemerkt === 1;
        }
        $bytes = self::bytes($cf, $pfad);
        if ($bytes === null) {
            return false;   // nicht lesbar: nicht merken, beim nächsten Veröffentlichen erneut prüfen
        }
        $alpha = self::kopfHatAlpha($bytes);
        Cache::forever($schluessel, $alpha ? 1 : 0);

        return $alpha;
    }

    private static function bytes(int $cf, string $pfad): ?string
    {
        try {
            $datei = $cf > 0 ? ContextFile::find($cf) : null;
            if ($datei !== null) {
                return Storage::disk($datei->disk)->exists($datei->path) ? Storage::disk($datei->disk)->get($datei->path) : null;
            }

            return $pfad !== '' && Storage::disk('public')->exists($pfad) ? Storage::disk('public')->get($pfad) : null;
        } catch (\Throwable $e) {
            unset($e);   // Bild nicht lesbar → wie bisher als Fotoband zeigen

            return null;
        }
    }

    /** PNG (Farbtyp 4/6 oder tRNS-Chunk vor IDAT) bzw. WebP (VP8X-Alpha-Flag oder VP8L-Alpha-Bit). */
    public static function kopfHatAlpha(?string $b): bool
    {
        if ($b === null || strlen($b) < 30) {
            return false;
        }
        if (str_starts_with($b, "\x89PNG\r\n\x1a\n")) {
            $farbtyp = ord($b[25]);
            if ($farbtyp === 4 || $farbtyp === 6) {
                return true;
            }
            $idat = strpos($b, 'IDAT');
            $trns = strpos($b, 'tRNS');

            return $trns !== false && ($idat === false || $trns < $idat);
        }
        if (substr($b, 0, 4) === 'RIFF' && substr($b, 8, 4) === 'WEBP') {
            $chunk = substr($b, 12, 4);
            if ($chunk === 'VP8X') {
                return (ord($b[20]) & 0x10) !== 0;
            }
            if ($chunk === 'VP8L' && ord($b[20]) === 0x2F) {
                return ((unpack('V', substr($b, 21, 4))[1] >> 28) & 1) === 1;
            }
        }

        return false;
    }
}
