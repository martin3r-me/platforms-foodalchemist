<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel;
use Platform\FoodAlchemist\Models\FoodAlchemistOfferChapter;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarteRubrik;

/**
 * Optionales Logo je Kapitel (Präsentation 2026-11-13) für alle Präsentationsarten:
 * Foodbook-Kapitel, Angebots-Kapitel, Speisekarten-Rubrik. Ein Schreibpfad für Oberfläche und MCP.
 * Spalten logo_context_file_id / logo_path auf der jeweiligen Kapitel-Tabelle.
 */
final class KapitelLogoService
{
    /** Art → [Modell, Context-Typ, Ordner-Präfix, Name in Meldungen]. */
    public const ARTEN = [
        'foodbook' => [FoodAlchemistFoodbookKapitel::class, 'foodalchemist.foodbook_chapter', 'foodalchemist/chapter', 'Foodbook-Kapitel'],
        'angebot' => [FoodAlchemistOfferChapter::class, 'foodalchemist.offer_chapter', 'foodalchemist/offer_chapter', 'Angebots-Kapitel'],
        'speisekarte' => [FoodAlchemistSpeisekarteRubrik::class, 'foodalchemist.menu_card_section', 'foodalchemist/menu_card_section', 'Speisekarten-Rubrik'],
    ];

    public function __construct(private readonly FoodAlchemistMediaService $media) {}

    /** @return array{context_file_id:int, path:string} */
    public function setzen(Team $team, string $art, int $kapitelId, UploadedFile $datei): array
    {
        $k = $this->eigenesKapitel($team, $art, $kapitelId);
        [, $contextTyp, $ordner] = self::ARTEN[$art];
        $alt = [$k->logo_context_file_id, $k->logo_path];
        $neu = $this->media->storeImage($datei, $team, $contextTyp, $kapitelId, "{$ordner}/{$kapitelId}/logo");
        $k->forceFill(['logo_context_file_id' => $neu['context_file_id'], 'logo_path' => $neu['path']])->save();
        $this->alteDateiLoeschen($team, $alt[0], $alt[1]);

        return $neu;
    }

    public function entfernen(Team $team, string $art, int $kapitelId): void
    {
        $k = $this->eigenesKapitel($team, $art, $kapitelId);
        $alt = [$k->logo_context_file_id, $k->logo_path];
        $k->forceFill(['logo_context_file_id' => null, 'logo_path' => null])->save();
        $this->alteDateiLoeschen($team, $alt[0], $alt[1]);
    }

    /** Logo-URL für die Bearbeitungs-Oberfläche (null = kein Logo). */
    public function url(Team $team, string $art, int $kapitelId): ?string
    {
        $klasse = self::ARTEN[$art][0] ?? null;
        $k = $klasse !== null ? $klasse::visibleToTeam($team)->find($kapitelId, ['id', 'logo_context_file_id', 'logo_path']) : null;
        if ($k === null || (! $k->logo_context_file_id && ! $k->logo_path)) {
            return null;
        }

        return $this->media->url($k->logo_context_file_id, $k->logo_path);
    }

    private function eigenesKapitel(Team $team, string $art, int $kapitelId): Model
    {
        if (! isset(self::ARTEN[$art])) {
            throw new \RuntimeException('Kapitel-Art muss ' . implode('|', array_keys(self::ARTEN)) . ' sein.');
        }
        [$klasse, , , $name] = self::ARTEN[$art];
        $k = $klasse::visibleToTeam($team)->findOrFail($kapitelId);
        if (! $k->isOwnedBy($team)) {
            throw new \RuntimeException("Geerbte(s) {$name}: Ändern kann es nur das Besitzer-Team.");
        }

        return $k;
    }

    /**
     * Datei nur löschen, wenn kein anderes Kapitel (z. B. aus einer Foodbook-Kopie) sie noch nutzt.
     */
    private function alteDateiLoeschen(Team $team, ?int $contextFileId, ?string $pfad): void
    {
        if (! $contextFileId && trim((string) $pfad) === '') {
            return;
        }
        foreach (self::ARTEN as [$klasse]) {
            $q = $klasse::withTrashed();   // auch gelöschte Kapitel: Wiederherstellen braucht die Datei
            $genutzt = $contextFileId ? $q->where('logo_context_file_id', $contextFileId)->exists() : $q->where('logo_path', $pfad)->exists();
            if ($genutzt) {
                return;
            }
        }
        $this->media->delete($contextFileId, $pfad, $team);
    }
}
