<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Platform\Core\Models\Team;
use Platform\Core\Models\User;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Models\FoodAlchemistLieferantenLink as Link;
use Platform\FoodAlchemist\Models\FoodAlchemistPriceImportRun as Lauf;

/**
 * Hinweis „neuer Upload“ an das Team des Links (Stufe 2b). Typ `foodalchemist.lieferant.upload` (registriert im FA-Provider, nichts in platform-core).
 *
 * - Empfänger: Mitglieder des Link-Teams mit mindestens Rolle Kuratieren, keine KI-Benutzer. Ein fremdes Team bekommt nie etwas.
 * - Bündelung: höchstens ein Hinweis je Team + Lieferant und Zeitfenster; weitere Uploads im Fenster stehen nur im Protokoll (und am Lauf).
 * - Das Protokoll ist der Rückfall: der Upload-Eintrag am Link entsteht immer. Scheitert der Versand, wird der Fehler geloggt und als
 *   Protokoll-Ereignis „benachrichtigung_fehlgeschlagen“ festgehalten (nicht verschluckt); der Upload selbst gelingt trotzdem.
 */
class LieferantenUploadBenachrichtigung
{
    public const TYP = 'foodalchemist.lieferant.upload';

    public function __construct(private readonly FaRechte $rechte, private readonly LieferantenLinkService $links) {}

    /** Typ in der Core-Registry anmelden (nur wenn das Benachrichtigungs-Modul da ist). Idempotent. */
    public static function registriere(): void
    {
        if (! class_exists(\Platform\Notifications\NotificationTypeRegistry::class)) {
            return;
        }
        \Platform\Notifications\NotificationTypeRegistry::register(self::TYP, [
            'label' => 'Lieferanten-Upload',
            'description' => 'Wenn ein Lieferant über seinen Link eine Datei zur Prüfung eingereicht hat',
            'group' => 'foodalchemist',
            'default_channels' => ['database'],
        ]);
    }

    /** @return int Zahl der benachrichtigten Personen (0 = gebündelt, niemand da oder Versand gescheitert) */
    public function melde(Link $link, Lauf $lauf): int
    {
        if (! class_exists(\Platform\Notifications\NotificationDispatcher::class)) {
            return 0;
        }
        $schluessel = sprintf('fa:lfl:hinweis:%d:%d', $link->team_id, $link->supplier_id);
        $fenster = max(1, (int) config('foodalchemist.lieferanten_link.benachrichtigung_fenster_minuten', 10));
        if (! Cache::add($schluessel, 1, now()->addMinutes($fenster))) {
            return 0;   // gebündelt: im Zeitfenster ging schon ein Hinweis für diesen Lieferanten raus
        }

        try {
            $team = Team::find($link->team_id);
            $empfaenger = $team === null ? collect() : $this->empfaenger($team);
            if ($empfaenger->isEmpty()) {
                return 0;
            }
            $lieferant = (string) ($link->supplier?->name ?? 'Lieferant');
            app(\Platform\Notifications\NotificationDispatcher::class)->dispatch(self::TYP, [
                'title' => "Neuer Upload von {$lieferant}",
                'message' => sprintf('%s hat „%s“ (%d Zeilen) zur Prüfung eingereicht.', $lieferant, $lauf->filename, (int) ($lauf->counts['zeilen'] ?? 0)),
                'team_id' => $link->team_id,
                'noticable_type' => Lauf::class,
                'noticable_id' => $lauf->id,
                'metadata' => ['url' => $this->url($lauf), 'link_id' => $link->id, 'lauf_id' => $lauf->id],
            ], $empfaenger->all());

            return $empfaenger->count();
        } catch (\Throwable $e) {
            Cache::forget($schluessel);   // der nächste Upload darf es erneut versuchen
            Log::error('FoodAlchemist: Upload-Benachrichtigung fehlgeschlagen', ['link_id' => $link->id, 'lauf_id' => $lauf->id, 'fehler' => $e->getMessage()]);
            $this->links->ereignis($link, 'benachrichtigung_fehlgeschlagen', ['lauf_id' => $lauf->id, 'fehler' => mb_substr($e->getMessage(), 0, 200)]);

            return 0;
        }
    }

    /** @return \Illuminate\Support\Collection<int, User> */
    private function empfaenger(Team $team): \Illuminate\Support\Collection
    {
        $ids = collect($this->rechte->mitglieder($team))
            ->filter(fn (array $m) => ! $m['ki'] && FaRolle::from($m['rolle'])->mindestens(FaRolle::Kuratieren))
            ->pluck('user_id')->all();

        return $ids === [] ? collect() : User::whereIn('id', $ids)->get();
    }

    private function url(Lauf $lauf): string
    {
        try {
            return route('foodalchemist.preislisten.index');
        } catch (\Throwable) {
            return '';
        }
    }
}
