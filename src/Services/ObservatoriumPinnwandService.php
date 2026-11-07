<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Collection;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Models\FoodAlchemistObservatoriumPin as Pin;

/**
 * Team-Pinnwand im Observatorium (Dominique 07.11.2026): gemeinsam für alle im Team, anders als die persönliche Merkliste.
 * Anpinnen: Meldungen aus dem Observatorium (gelesen beim Master, {@see ObservatoriumService::detail}) oder eigene Ideen
 * (Titel, Notiz, Link). Streng je Team: nur `team_id = eigenes Team`, nichts wird an Eltern oder Kinder vererbt.
 * Lesen ab Rolle Lesen, anpinnen/ändern/entfernen ab Kuratieren im eigenen Team.
 */
class ObservatoriumPinnwandService
{
    public const MAX_NOTIZ = 1000;

    public function __construct(private readonly FaRechte $rechte, private readonly ObservatoriumService $observatorium) {}

    /** @return Collection<int, Pin> neueste zuerst, mit Meldung */
    public function liste(Team $team): Collection
    {
        $this->rechte->pruefeAngemeldet($team, FaRolle::Lesen, 'Pinnwand lesen');

        return Pin::where('team_id', $team->id)->with('eingang:id,titel,teaser,zusammenfassung,url,herausgeber,veroeffentlicht_am,quelle_id', 'eingang.quelle:id,name')
            ->orderByDesc('created_at')->orderByDesc('id')->get();
    }

    /** Meldung anpinnen (einmal je Team; erneutes Anpinnen ergänzt nur die Notiz). */
    public function meldungAnpinnen(Team $team, int $eingangId, ?string $notiz, ?int $userId): Pin
    {
        $this->rechte->pruefeAngemeldet($team, FaRolle::Kuratieren, 'An die Pinnwand heften');
        $this->observatorium->detail($team, $eingangId);   // nur sichtbare Meldungen (beim Master gelesen)
        $pin = Pin::where('team_id', $team->id)->where('eingang_id', $eingangId)->first()
            ?? new Pin(['team_id' => $team->id, 'eingang_id' => $eingangId, 'erstellt_von' => $userId]);
        if (($n = self::notiz($notiz)) !== null) {
            $pin->notiz = $n;
        }
        $pin->save();

        return $pin;
    }

    /** Eigene Idee anheften: Titel Pflicht, Notiz und Link (http/https) optional. */
    public function ideeAnpinnen(Team $team, string $titel, ?string $notiz, ?string $link, ?int $userId): Pin
    {
        $this->rechte->pruefeAngemeldet($team, FaRolle::Kuratieren, 'An die Pinnwand heften');
        $titel = trim($titel);
        if ($titel === '' || mb_strlen($titel) > 200) {
            throw new \RuntimeException('Bitte einen Titel mit höchstens 200 Zeichen angeben.');
        }

        return Pin::create(['team_id' => $team->id, 'titel' => $titel, 'notiz' => self::notiz($notiz), 'link' => self::link($link), 'erstellt_von' => $userId]);
    }

    public function notizSetzen(Team $team, int $pinId, ?string $notiz): Pin
    {
        $this->rechte->pruefeAngemeldet($team, FaRolle::Kuratieren, 'Pinnwand bearbeiten');
        $pin = Pin::where('team_id', $team->id)->findOrFail($pinId);
        $pin->update(['notiz' => self::notiz($notiz)]);

        return $pin;
    }

    public function entfernen(Team $team, int $pinId): void
    {
        $this->rechte->pruefeAngemeldet($team, FaRolle::Kuratieren, 'Pinnwand bearbeiten');
        Pin::where('team_id', $team->id)->findOrFail($pinId)->delete();
    }

    /** IDs der angepinnten Meldungen (für den Zustand der Knöpfe). */
    public function angepinnteMeldungen(Team $team): array
    {
        return Pin::where('team_id', $team->id)->whereNotNull('eingang_id')->pluck('eingang_id')->map(fn ($i) => (int) $i)->all();
    }

    private static function notiz(?string $notiz): ?string
    {
        $n = trim((string) $notiz);
        if (mb_strlen($n) > self::MAX_NOTIZ) {
            throw new \RuntimeException('Die Notiz darf höchstens '.self::MAX_NOTIZ.' Zeichen haben.');
        }

        return $n === '' ? null : $n;
    }

    private static function link(?string $link): ?string
    {
        $l = trim((string) $link);
        if ($l === '') {
            return null;
        }
        if (mb_strlen($l) > 500 || ! filter_var($l, FILTER_VALIDATE_URL) || ! in_array(strtolower((string) parse_url($l, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            throw new \RuntimeException('Der Link muss mit http:// oder https:// beginnen (höchstens 500 Zeichen).');
        }

        return $l;
    }
}
