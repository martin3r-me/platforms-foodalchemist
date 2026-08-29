<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\Cache;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarte;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplan;

/**
 * Spec 43 — Share-Card-Generator: rendert für die Link-Vorschau (Open Graph) ein
 * 1200×630-Bild aus dem eingefrorenen Snapshot: Cover-Foto (cover-fit) + dunkler
 * Verlauf + Kunde/Jahr + Titel (Serif) + Logo. Rein additiv; nichts wird persistiert
 * außer einem kurzlebigen Cache. Kein Cover → markenfarbener Grund. GD-basiert (kein
 * neuer Composer-Dep); WebP-Cover via Imagick-Fallback, falls GD kein WebP kann.
 */
class PresentationShareCardService
{
    private const W = 1200;
    private const H = 630;

    public function __construct(private FoodAlchemistMediaService $media)
    {
    }

    /** PNG-Bytes der Share-Card oder null (Link nicht live / Render-Fehler → Controller fällt zurück). */
    public function render(string $type, string $ref): ?string
    {
        $entity = $this->liveEntity($type, $ref);
        if ($entity === null) {
            return null;
        }
        $snap = $entity->presentation_snapshot_json;
        if (! is_array($snap) || $snap === []) {
            return null;
        }

        $key = 'fa-og-card:' . md5($type . '|' . $ref . '|' . ($entity->presentation_published_at?->getTimestamp() ?? 0));

        try {
            // PNG base64-kodiert cachen: der database-Cache-Treiber legt in einer TEXT-Spalte ab —
            // rohe Binär-Bytes wären ungültiges UTF-8 und würden den Insert sprengen.
            $b64 = Cache::remember($key, now()->addDay(), fn () => base64_encode($this->compose($snap)));
        } catch (\Throwable) {
            return null; // Controller weicht auf das rohe Cover-Bild aus.
        }
        $png = base64_decode((string) $b64, true);

        return $png !== false && $png !== '' ? $png : null;
    }

    /** Fallback für den Controller: rohe, frisch signierte Cover-/Logo-URL (oder null wenn nicht live). */
    public function fallbackImageUrl(string $type, string $ref): ?string
    {
        $entity = $this->liveEntity($type, $ref);
        $snap = $entity?->presentation_snapshot_json;
        if (! is_array($snap)) {
            return null;
        }
        foreach (['cover', 'logo'] as $k) {
            $img = $snap['branding'][$k] ?? null;
            if (is_array($img) && (($img['context_file_id'] ?? null) || ($img['path'] ?? null))) {
                return $this->media->url($img['context_file_id'] ?? null, $img['path'] ?? null);
            }
        }

        return null;
    }

    private function liveEntity(string $type, string $ref)
    {
        $class = match ($type) {
            'foodbook' => FoodAlchemistFoodbook::class,
            'speisekarte' => FoodAlchemistSpeisekarte::class,
            'speiseplan' => FoodAlchemistSpeiseplan::class,
            default => null,
        };
        if ($class === null) {
            return null;
        }
        $entity = $class::query()->byPresentationRef($ref)->first();

        return ($entity !== null && $entity->isPresentationLive()) ? $entity : null;
    }

    private function compose(array $snap): string
    {
        $W = self::W;
        $H = self::H;
        $img = imagecreatetruecolor($W, $H);
        imagealphablending($img, true);
        imagesavealpha($img, true);

        $pal = $snap['resolved_design']['tokens']['palette'] ?? [];
        $primary = $this->hexToRgb($pal['primary'] ?? '#3f3a34', [63, 58, 52]);
        $accent = $this->hexToRgb($pal['accent'] ?? '#d9b070', [217, 176, 112]);

        // Hintergrund: Cover-Foto (cover-fit) oder markenfarbener Grund.
        $cover = $this->loadImage($this->branding($snap, 'cover'));
        if ($cover !== null) {
            $this->coverInto($img, $cover, $W, $H);
            imagedestroy($cover);
        } else {
            imagefilledrectangle($img, 0, 0, $W, $H, imagecolorallocate($img, $primary[0], $primary[1], $primary[2]));
        }

        // Default: nur Foto + Logo — robust in JEDEM Zuschnitt (viele Clients beschneiden die
        // breite Card zur Quadrat-Kachel; ein eingebrannter Titel würde dort abgeschnitten, und
        // der Messenger zeigt den Titel ohnehin als Text daneben). Token share_card_text=true
        // schaltet den eingebrannten Titel + Kicker dazu.
        $withText = ! empty($snap['resolved_design']['tokens']['share_card_text']);

        if ($withText) {
            $this->wash($img, $W, $H);
        } else {
            $this->topWash($img, $W); // nur oben dezent abdunkeln, damit ein helles Logo trägt
        }

        // Logo oben rechts (optional).
        $logo = $this->loadImage($this->branding($snap, 'logo'));
        if ($logo !== null) {
            $this->placeLogo($img, $logo, $W);
            imagedestroy($logo);
        }

        if ($withText) {
            $fontBold = dirname(__DIR__, 2) . '/resources/fonts/PTSerif-Bold.ttf';
            $fontReg = dirname(__DIR__, 2) . '/resources/fonts/PTSerif-Regular.ttf';

            $title = trim((string) ($snap['title'] ?? 'Foodbook')) ?: 'Foodbook';
            $kicker = trim((string) ($snap['meta']['customer'] ?? ''));
            if (! empty($snap['meta']['jahr'])) {
                $kicker = trim($kicker . '  ·  ' . $snap['meta']['jahr'], ' ·');
            }

            $margin = 66;
            $maxW = $W - 2 * $margin;

            // Titel auto-fit: größte Schrift, die in ≤3 Zeilen passt.
            [$size, $lines] = $this->fitTitle($fontBold, $title, $maxW, [66, 58, 50, 44, 38], 3);
            $lineH = (int) round($size * 1.18);
            $baseBottom = $H - 74;
            $y = $baseBottom - (count($lines) - 1) * $lineH;
            foreach ($lines as $ln) {
                $this->text($img, $fontBold, $size, $margin, $y, $ln, [255, 255, 255], true);
                $y += $lineH;
            }
            if ($kicker !== '') {
                $kickBaseline = $baseBottom - (count($lines) - 1) * $lineH - $size - 20;
                $this->text($img, $fontReg, 27, $margin, $kickBaseline, mb_strtoupper($kicker, 'UTF-8'), $accent, true);
            }
        }

        ob_start();
        imagepng($img);
        $png = (string) ob_get_clean();
        imagedestroy($img);

        return $png;
    }

    private function branding(array $snap, string $key): ?string
    {
        $img = $snap['branding'][$key] ?? null;
        if (! is_array($img)) {
            return null;
        }

        return $this->media->dataUri($img['context_file_id'] ?? null, $img['path'] ?? null);
    }

    private function loadImage(?string $dataUri): ?\GdImage
    {
        if ($dataUri === null) {
            return null;
        }
        $comma = strpos($dataUri, ',');
        $bytes = $comma !== false ? base64_decode(substr($dataUri, $comma + 1), true) : false;
        if ($bytes === false || $bytes === '') {
            return null;
        }
        $im = @imagecreatefromstring($bytes);
        if ($im instanceof \GdImage) {
            return $im;
        }
        // Fallback: GD kann das Format (z.B. WebP) nicht → via Imagick nach PNG wandeln.
        if (class_exists('\Imagick')) {
            try {
                $ik = new \Imagick();
                $ik->readImageBlob($bytes);
                $ik->setImageFormat('png');
                $png = $ik->getImageBlob();
                $ik->clear();
                $im2 = @imagecreatefromstring($png);

                return $im2 instanceof \GdImage ? $im2 : null;
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    /** Quelle zentriert auf W×H beschneiden (object-fit: cover). */
    private function coverInto(\GdImage $dst, \GdImage $src, int $W, int $H): void
    {
        $sw = imagesx($src);
        $sh = imagesy($src);
        if ($sw < 1 || $sh < 1) {
            return;
        }
        $scale = max($W / $sw, $H / $sh);
        $cw = (int) round($W / $scale);
        $ch = (int) round($H / $scale);
        $sx = (int) round(($sw - $cw) / 2);
        $sy = (int) round(($sh - $ch) / 2);
        imagecopyresampled($dst, $src, 0, 0, $sx, $sy, $W, $H, $cw, $ch);
    }

    /** Gesamt-Schleier + kräftiger Verlauf unten für Text-Lesbarkeit. */
    private function wash(\GdImage $img, int $W, int $H): void
    {
        imagefilledrectangle($img, 0, 0, $W, $H, imagecolorallocatealpha($img, 0, 0, 0, 96));
        $bandH = 360;
        $y0 = $H - $bandH;
        for ($i = 0; $i < $bandH; $i++) {
            $t = $i / $bandH;
            $a = (int) round(127 - 127 * 0.80 * $t);
            imageline($img, 0, $y0 + $i, $W, $y0 + $i, imagecolorallocatealpha($img, 0, 0, 0, $a));
        }
    }

    /** Nur oben leicht abdunkeln (Foto-only-Card) — sichert die Lesbarkeit eines hellen Logos. */
    private function topWash(\GdImage $img, int $W): void
    {
        $bandH = 210;
        for ($i = 0; $i < $bandH; $i++) {
            $t = 1 - $i / $bandH; // ganz oben am stärksten
            $a = (int) round(127 - 127 * 0.42 * $t);
            imageline($img, 0, $i, $W, $i, imagecolorallocatealpha($img, 0, 0, 0, $a));
        }
    }

    private function placeLogo(\GdImage $img, \GdImage $logo, int $W): void
    {
        $lw = imagesx($logo);
        $lh = imagesy($logo);
        if ($lw < 1 || $lh < 1) {
            return;
        }
        $maxW = 210;
        $maxH = 88;
        $scale = min($maxW / $lw, $maxH / $lh, 1.0);
        $nw = (int) round($lw * $scale);
        $nh = (int) round($lh * $scale);
        imagecopyresampled($img, $logo, $W - $nw - 56, 52, 0, 0, $nw, $nh, $lw, $lh);
    }

    /** @return array{0:int,1:list<string>} */
    private function fitTitle(string $font, string $title, int $maxW, array $sizes, int $maxLines): array
    {
        foreach ($sizes as $size) {
            $lines = $this->wrap($font, $size, $title, $maxW, $maxLines);
            $fits = true;
            foreach ($lines as $ln) {
                $bb = imagettfbbox($size, 0, $font, $ln);
                if (($bb[2] - $bb[0]) > $maxW) {
                    $fits = false;
                    break;
                }
            }
            if ($fits) {
                return [$size, $lines];
            }
        }
        // Notfall: kleinste Größe, hart umbrochen.
        $small = (int) end($sizes);

        return [$small, $this->wrap($font, $small, $title, $maxW, $maxLines)];
    }

    /** @return list<string> */
    private function wrap(string $font, int $size, string $text, int $maxW, int $maxLines): array
    {
        $words = preg_split('/\s+/', trim($text)) ?: [];
        $lines = [];
        $cur = '';
        foreach ($words as $w) {
            $try = $cur === '' ? $w : $cur . ' ' . $w;
            $bb = imagettfbbox($size, 0, $font, $try);
            if (($bb[2] - $bb[0]) <= $maxW || $cur === '') {
                $cur = $try;
            } else {
                $lines[] = $cur;
                $cur = $w;
                if (count($lines) >= $maxLines) {
                    $cur = '';
                    break;
                }
            }
        }
        if ($cur !== '' && count($lines) < $maxLines) {
            $lines[] = $cur;
        }

        return $lines === [] ? [$text] : $lines;
    }

    private function text(\GdImage $img, string $font, int $size, int $x, int $y, string $s, array $rgb, bool $shadow): void
    {
        if ($shadow) {
            imagettftext($img, $size, 0, $x + 2, $y + 2, imagecolorallocatealpha($img, 0, 0, 0, 64), $font, $s);
        }
        imagettftext($img, $size, 0, $x, $y, imagecolorallocate($img, $rgb[0], $rgb[1], $rgb[2]), $font, $s);
    }

    /** @return array{0:int,1:int,2:int} */
    private function hexToRgb(?string $hex, array $fallback): array
    {
        $hex = ltrim(trim((string) $hex), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (! preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            return $fallback;
        }

        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }
}
