<?php

namespace Platform\FoodAlchemist\Support;

use Illuminate\Support\Collection;
use Platform\Core\Models\Team;
use RuntimeException;

/**
 * Kunden-Verknüpfung mit dem CRM — MANDANTENSICHER (Spec 64 §1, 2026-10-06).
 *
 * Vorher nutzten die Ausgabe-Services `CompanyLinkService::searchCompanies()` / `ContactLinkService::searchContacts()`
 * des CRM: beide filtern NICHT nach Team → auf einer Plattform mit mehreren Kunden sah ein Team die CRM-Firmen
 * und -Kontakte aller anderen. Außerdem prüfte `verknuepfeKunde` nicht, ob die ID zum eigenen Team gehört.
 *
 * Regel wie `CoreCrmCompanyOptionsProvider` des CRM: das CRM ist root-scoped → nur Firmen/Kontakte des
 * HAUPT-Teams, nur aktive. Keine Änderung am CRM-Modul.
 */
final class CrmKunden
{
    public static function verfuegbar(): bool
    {
        return class_exists(\Platform\Crm\Models\CrmCompany::class);
    }

    /** @return Collection<int, \Platform\Crm\Models\CrmCompany> */
    public static function firmen(?Team $team, string $suche, int $limit = 10): Collection
    {
        $suche = trim($suche);
        if ($suche === '' || $team === null || ! self::verfuegbar()) {
            return collect();
        }
        $like = '%'.$suche.'%';

        return \Platform\Crm\Models\CrmCompany::query()
            ->where('team_id', $team->getRootTeam()->id)
            ->where('is_active', true)
            ->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('legal_name', 'like', $like)->orWhere('trading_name', 'like', $like))
            ->orderBy('name')
            ->limit($limit)
            ->get();
    }

    /** @return Collection<int, \Platform\Crm\Models\CrmContact> */
    public static function kontakte(?Team $team, string $suche, int $limit = 10): Collection
    {
        $suche = trim($suche);
        if ($suche === '' || $team === null || ! class_exists(\Platform\Crm\Models\CrmContact::class)) {
            return collect();
        }
        $like = '%'.$suche.'%';

        return \Platform\Crm\Models\CrmContact::query()
            ->where('team_id', $team->getRootTeam()->id)
            ->where('is_active', true)
            ->where(fn ($q) => $q->where('first_name', 'like', $like)->orWhere('last_name', 'like', $like)
                ->orWhereHas('emailAddresses', fn ($e) => $e->where('email_address', 'like', $like)))
            ->orderBy('last_name')
            ->limit($limit)
            ->get();
    }

    /** Wirft, wenn Firma/Kontakt nicht zum Haupt-Team gehören. null = Verknüpfung lösen (erlaubt). */
    public static function pruefe(Team $team, ?int $companyId, ?int $contactId): void
    {
        if (($companyId === null && $contactId === null) || ! self::verfuegbar()) {
            return;
        }
        $root = $team->getRootTeam()->id;
        if ($companyId !== null && ! \Platform\Crm\Models\CrmCompany::whereKey($companyId)->where('team_id', $root)->exists()) {
            throw new RuntimeException('Diese CRM-Firma gehört nicht zu deinem Team.');
        }
        if ($contactId !== null && ! \Platform\Crm\Models\CrmContact::whereKey($contactId)->where('team_id', $root)->exists()) {
            throw new RuntimeException('Dieser CRM-Kontakt gehört nicht zu deinem Team.');
        }
    }

    public static function aktuellesTeam(): ?Team
    {
        return auth()->user()?->currentTeamRelation;
    }
}
