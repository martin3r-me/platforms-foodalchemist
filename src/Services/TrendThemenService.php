<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistTrendBeleg;
use Platform\FoodAlchemist\Models\FoodAlchemistTrendEingang;
use Platform\FoodAlchemist\Models\FoodAlchemistTrendInspiration;
use Platform\FoodAlchemist\Support\StichwortFilter;

/**
 * Spec 93 B · Themen = automatische Inspirationen (herkunft = automatisch). Ein erschlossener Artikel kommt zum Thema mit
 * derselben normalisierten Schreibweise im eigenen Team, sonst entsteht ein neues; der Artikel wird Beleg des Themas
 * (fundstueck = false, Quelle = Art der Quelle). Automatische Themen erscheinen nie auf der Pinnwand.
 *
 * Schritt 2 der Spec (Ähnlichkeit über die Vektorsuche) ist aus, bis `trends.quellen.buendel_aehnlichkeit` auf demo
 * gemessen und gesetzt ist. Manuelle Inspirationen werden nie gebündelt; ihren Schlüssel liefert {@see schluessel()} trotzdem,
 * damit Abschnitt C Pinnwand-Fundstücke als Sichtung zählen kann.
 */
class TrendThemenService
{
    /** Normalisierte Schreibweise eines Themas: klein, Umlaute ausgeschrieben, Satzzeichen weg, Leerraum einfach. */
    public static function schluessel(string $thema): string
    {
        $s = StichwortFilter::normalisieren($thema);
        $s = preg_replace('/[^\p{L}\p{N} ]+/u', ' ', $s) ?? $s;

        return mb_substr(trim(preg_replace('/\s+/u', ' ', $s) ?? $s), 0, 190);
    }

    /** Hängt einen erschlossenen Artikel an sein Thema (bestehend oder neu) und gibt das Thema zurück. */
    public function buendeln(FoodAlchemistTrendEingang $e): ?FoodAlchemistTrendInspiration
    {
        $thema = trim((string) $e->thema);
        $schluessel = self::schluessel($thema);
        if ($schluessel === '') {
            return null;
        }

        return DB::transaction(function () use ($e, $thema, $schluessel) {
            $insp = FoodAlchemistTrendInspiration::where('team_id', $e->team_id)->where('herkunft', 'automatisch')
                ->where('thema_schluessel', $schluessel)->orderBy('id')->first();
            if ($insp === null) {
                $insp = FoodAlchemistTrendInspiration::create([
                    'team_id' => $e->team_id, 'titel' => mb_substr($thema, 0, 255), 'herkunft' => 'automatisch',
                    'thema_schluessel' => $schluessel, 'schlagworte' => null,
                ]);
            }
            $beleg = $this->beleg($e);
            if ($beleg === null) {
                FoodAlchemistTrendBeleg::create([
                    'team_id' => $e->team_id, 'trend_id' => $insp->trend_id, 'inspiration_id' => $insp->id,
                    'quelle' => $e->quelle?->art ?? 'presse', 'titel' => mb_substr((string) $e->titel, 0, 255),
                    'url' => mb_substr((string) $e->url, 0, 1000), 'notiz' => $e->zusammenfassung,
                    'fundort' => $e->quelle?->name, 'beobachtet_am' => ($e->veroeffentlicht_am ?? $e->created_at ?? now())->toDateString(),
                    'fundstueck' => false,
                ]);
            } elseif ((int) $beleg->inspiration_id !== (int) $insp->id) {
                $alt = $beleg->inspiration_id;
                $beleg->update(['inspiration_id' => $insp->id, 'trend_id' => $insp->trend_id]);
                $this->leeresThemaEntfernen($alt);
            }
            $e->update(['thema_inspiration_id' => $insp->id]);
            $insp->touch();

            return $insp;
        });
    }

    /** Nimmt den Artikel aus seinem Thema (verworfen); ein leeres automatisches Thema verschwindet. */
    public function loesen(FoodAlchemistTrendEingang $e): void
    {
        $beleg = $this->beleg($e);
        if ($beleg === null) {
            return;
        }
        $alt = $beleg->inspiration_id;
        $beleg->delete();
        $e->update(['thema_inspiration_id' => null]);
        $this->leeresThemaEntfernen($alt);
    }

    /**
     * Automatische Themen des Teams mit Artikelzahl, neueste Bewegung zuerst (für den Reiter „Eingang“).
     *
     * @return Collection<int, FoodAlchemistTrendInspiration>
     */
    public function themen(Team $team, int $limit = 50): Collection
    {
        return FoodAlchemistTrendInspiration::visibleToTeam($team)->where('herkunft', 'automatisch')
            ->withCount('quellen')->orderByDesc('updated_at')->limit($limit)->get();
    }

    /** @return list<string> Namen der automatischen Themen des Teams, sortiert (stabiler Prompt-Fingerabdruck) */
    public function themenNamen(int $teamId, int $limit = 80): array
    {
        return FoodAlchemistTrendInspiration::where('team_id', $teamId)->where('herkunft', 'automatisch')
            ->orderByDesc('updated_at')->limit($limit)->pluck('titel')->sort(SORT_NATURAL | SORT_FLAG_CASE)->values()->all();
    }

    /** Beleg dieses Artikels in seinem automatischen Thema (gleicher Link im Thema aus thema_inspiration_id). */
    private function beleg(FoodAlchemistTrendEingang $e): ?FoodAlchemistTrendBeleg
    {
        if ($e->thema_inspiration_id === null) {
            return null;
        }

        return FoodAlchemistTrendBeleg::where('team_id', $e->team_id)->where('fundstueck', false)
            ->where('inspiration_id', $e->thema_inspiration_id)->where('url', mb_substr((string) $e->url, 0, 1000))->first();
    }

    private function leeresThemaEntfernen(?int $id): void
    {
        if ($id === null) {
            return;
        }
        $insp = FoodAlchemistTrendInspiration::where('herkunft', 'automatisch')->find($id);
        if ($insp !== null && ! $insp->quellen()->exists()) {
            $insp->delete();
        }
    }
}
