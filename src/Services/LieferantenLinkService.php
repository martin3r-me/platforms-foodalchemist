<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Platform\Core\Models\Team;
use Platform\Core\Models\User;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Models\FoodAlchemistLieferantenLink as Link;
use Platform\FoodAlchemist\Models\FoodAlchemistLieferantenLinkEreignis;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Support\TeamSperre;

/**
 * Lieferanten-Link (Stufe 2a, Kurator 29.10.2026): persönlicher Zugang je Lieferant ohne Login.
 *
 *  - Der Token wird NUR bei der Erzeugung angezeigt; gespeichert ist sein sha256-Hash ({@see aufloesen} sucht über den Hash).
 *  - Genau ein Team und ein Lieferant je Link; er läuft ab (Standard 30, höchstens 90 Tage) und ist widerrufbar.
 *  - Rechte zum Erzeugen/Widerrufen/Ansehen: Haupt-Team des Kunden mit Rolle Kuratieren (wie der Preislisten-Import). Eigene Lieferanten des Teams; globale
 *    Stamm-Lieferanten (team_id leer) nur das Master-Team; geerbte Lieferanten des Eltern-Teams nie (D1).
 *  - Jedes Ereignis steht im Protokoll (erzeugt, geöffnet, Upload, abgelehnt, widerrufen); die IP nur als Hash.
 *  - Ungültig, abgelaufen, widerrufen oder Team gesperrt ⇒ {@see aufloesen} liefert null — der Aufrufer antwortet für alle Fälle gleich.
 */
class LieferantenLinkService
{
    public function __construct(private readonly PreislistenImportService $liste) {}

    public static function standardTage(): int
    {
        return (int) config('foodalchemist.lieferanten_link.standard_tage', 30);
    }

    public static function maxTage(): int
    {
        return (int) config('foodalchemist.lieferanten_link.max_tage', 90);
    }

    /** Darf `$user` für `$team` Lieferanten-Links verwalten? (Haupt-Team + Kuratieren) */
    public function darfVerwalten(Team $team, ?User $user): bool
    {
        return $this->liste->darfImportieren($team, $user);
    }

    /** Darf das Team für diesen Lieferanten einen Link verwalten? Eigener Lieferant, oder globaler nur durch das Master-Team. */
    public function lieferantErlaubt(Team $team, FoodAlchemistSupplier $supplier): bool
    {
        if ($supplier->team_id === null) {
            return MasterTeam::istMaster($team);
        }

        return (int) $supplier->team_id === (int) $team->id;
    }

    /**
     * Link erzeugen. Der Klartext-Token steckt NUR in der Rückgabe.
     *
     * @param  array{tage?: int, bereiche?: list<string>, kontakt_name?: ?string, kontakt_email?: ?string}  $opt
     * @return array{link: Link, token: string, url: string}
     */
    public function erzeugen(Team $team, ?User $user, FoodAlchemistSupplier $supplier, array $opt = []): array
    {
        $this->pruefeRecht($team, $user);
        if (! $this->lieferantErlaubt($team, $supplier)) {
            throw new FaRechtFehltException(FaRolle::Kuratieren, null, 'Link für diesen Lieferanten (nur eigene Lieferanten; globale nur das Master-Team)');
        }
        $tage = max(1, min(self::maxTage(), (int) ($opt['tage'] ?? self::standardTage())));
        $bereiche = array_values(array_intersect(array_keys(Link::BEREICHE), (array) ($opt['bereiche'] ?? array_keys(Link::BEREICHE))));
        if ($bereiche === []) {
            throw new \RuntimeException('Mindestens ein Bereich muss freigegeben sein (' . implode(', ', array_keys(Link::BEREICHE)) . ').');
        }
        $email = isset($opt['kontakt_email']) ? trim((string) $opt['kontakt_email']) : null;
        if ($email !== null && $email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('Die E-Mail-Adresse des Kontakts ist ungültig.');
        }

        $token = Str::random(48);
        $link = Link::create([
            'team_id' => $team->id, 'supplier_id' => $supplier->id, 'token_hash' => $this->hash($token), 'token_hinweis' => substr($token, 0, 6),
            'kontakt_name' => $this->kurz($opt['kontakt_name'] ?? null), 'kontakt_email' => $email !== '' ? $this->kurz($email) : null,
            'bereiche' => $bereiche, 'expires_at' => now()->addDays($tage), 'created_by' => $user?->id,
        ]);
        $this->ereignis($link, 'erzeugt', ['tage' => $tage, 'bereiche' => $bereiche, 'von' => $user?->id]);

        return ['link' => $link, 'token' => $token, 'url' => self::url($token)];
    }

    /** Widerruf — sofort wirksam, idempotent. */
    public function widerrufen(Team $team, ?User $user, Link $link): Link
    {
        $this->pruefeRecht($team, $user);
        if ((int) $link->team_id !== (int) $team->id) {
            throw new \Illuminate\Database\Eloquent\ModelNotFoundException();
        }
        if (! $link->widerrufen()) {
            $link->forceFill(['revoked_at' => now(), 'revoked_by' => $user?->id])->save();
            $this->ereignis($link, 'widerrufen', ['von' => $user?->id]);
        }

        return $link;
    }

    /** Links des Teams (optional eines Lieferanten), neueste zuerst. Nie mit Token. @return Collection<int, Link> */
    public function liste(Team $team, ?User $user, ?int $supplierId = null): Collection
    {
        $this->pruefeRecht($team, $user);

        return Link::where('team_id', $team->id)->when($supplierId !== null, fn ($q) => $q->where('supplier_id', $supplierId))->orderByDesc('id')->get();
    }

    public function link(Team $team, int $id): ?Link
    {
        return Link::where('team_id', $team->id)->find($id);
    }

    /** @return Collection<int, FoodAlchemistLieferantenLinkEreignis> */
    public function protokoll(Team $team, Link $link, int $limit = 50): Collection
    {
        if ((int) $link->team_id !== (int) $team->id) {
            return collect();
        }

        return FoodAlchemistLieferantenLinkEreignis::where('link_id', $link->id)->where('team_id', $team->id)->orderByDesc('id')->limit($limit)->get();
    }

    /**
     * Link zum Token — oder null, wenn er unbekannt, abgelaufen, widerrufen, der Lieferant weg oder das Team gesperrt ist. Der Aufrufer (Portal)
     * antwortet in ALLEN diesen Fällen gleich, damit sich nichts über den Zustand eines Tokens erraten lässt.
     */
    public function aufloesen(string $token): ?Link
    {
        if (! preg_match('/^[A-Za-z0-9]{32,64}$/', $token)) {
            return null;
        }
        $link = Link::where('token_hash', $this->hash($token))->first();
        if ($link === null || $link->widerrufen() || $link->abgelaufen()) {
            return null;
        }
        $team = Team::find($link->team_id);
        if ($team === null || TeamSperre::istGesperrt($team) || FoodAlchemistSupplier::whereKey($link->supplier_id)->doesntExist()) {
            return null;
        }

        return $link;
    }

    /** Nutzung festhalten: erste/letzte Nutzung, bei der ersten Öffnung ein Protokoll-Eintrag. */
    public function benutzt(Link $link, ?Request $request = null): void
    {
        $erste = $link->first_used_at === null;
        $link->forceFill(['first_used_at' => $link->first_used_at ?? now(), 'last_used_at' => now()])->save();
        if ($erste) {
            $this->ereignis($link, 'geoeffnet', [], $request);
        }
    }

    /** @param array<string, mixed> $detail */
    public function ereignis(Link $link, string $art, array $detail = [], ?Request $request = null): FoodAlchemistLieferantenLinkEreignis
    {
        return FoodAlchemistLieferantenLinkEreignis::create([
            'team_id' => $link->team_id, 'link_id' => $link->id, 'art' => $art, 'detail' => $detail === [] ? null : $detail,
            'ip_hash' => $request?->ip() !== null ? hash('sha256', $request->ip() . '|' . config('app.key')) : null,
        ]);
    }

    public static function url(string $token): string
    {
        return route('foodalchemist.presentation.lieferanten_portal', ['token' => $token]);
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    private function kurz(?string $s): ?string
    {
        $s = trim((string) $s);

        return $s === '' ? null : mb_substr($s, 0, 190);
    }

    private function pruefeRecht(Team $team, ?User $user): void
    {
        if (! $this->darfVerwalten($team, $user)) {
            throw new FaRechtFehltException(FaRolle::Kuratieren, null, 'Lieferanten-Link verwalten (nur das Haupt-Team des Kunden)');
        }
    }
}
