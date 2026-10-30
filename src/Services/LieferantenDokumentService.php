<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Platform\Core\Models\Team;
use Platform\Core\Models\User;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Models\FoodAlchemistLieferantenLink as Link;
use Platform\FoodAlchemist\Models\FoodAlchemistPriceImportLine as Zeile;
use Platform\FoodAlchemist\Models\FoodAlchemistPriceImportRun as Lauf;
use Platform\FoodAlchemist\Models\FoodAlchemistProduktpass;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\Produktpass\ProduktpassService;
use Platform\FoodAlchemist\Support\UploadScanner;

/**
 * Datenblätter und Produktpässe vom Lieferanten (Lieferanten-Link, Stufe 2c). Die Datei des Lieferanten wird geprüft und NICHT öffentlich
 * zwischengelagert (lokale, private Platte). Sie erscheint als Prüflisten-Zeile (Lauf der Art „dokumente“, Herkunft „lieferanten_link“). Erst
 * wenn das Team sie übernimmt, läuft der bestehende Produktpass-Weg ({@see ProduktpassService::hochladen}: Ablage, KI liest im Hintergrund,
 * Allergene nur als Vorschlag bis zur Bestätigung). Es gibt keine neue Dokument-Tabelle.
 *
 * Dateiprüfung: nur PDF/JPG/PNG, Endung UND Datei-Kopf UND Mime müssen zusammenpassen, Größe, PDF ohne aktive Inhalte (JavaScript, Launch,
 * Einbettungen), Bilder müssen echte Bilder sein, austauschbarer {@see UploadScanner}. Nichts wird ausgeführt. Der Artikel muss dem Lieferanten
 * des Links gehören (Fence): fremde Artikelnummern ergeben nie eine Zuordnung.
 */
class LieferantenDokumentService
{
    public const ORDNER = 'fa-lieferanten-dokumente';

    /** Endung ⇒ [Mime, Datei-Kopf]. */
    private const ARTEN = [
        'pdf' => ['application/pdf', "%PDF-"],
        'jpg' => ['image/jpeg', "\xFF\xD8\xFF"],
        'jpeg' => ['image/jpeg', "\xFF\xD8\xFF"],
        'png' => ['image/png', "\x89PNG\r\n\x1A\n"],
    ];

    public function __construct(
        private readonly LieferantenLinkService $links,
        private readonly UploadScanner $scanner,
        private readonly LieferantenUploadBenachrichtigung $benachrichtigung,
        private readonly FaRechte $rechte,
        private readonly ProduktpassService $produktpass,
    ) {}

    /**
     * Lieferant reicht ein Dokument zu einem Artikel ein.
     *
     * @return array{lauf: Lauf, zugeordnet: bool}
     *
     * @throws \RuntimeException mit einer Meldung, die dem Lieferanten gezeigt werden darf
     */
    public function annehmen(Link $link, string $artikelnummer, UploadedFile $datei, ?Request $request = null): array
    {
        $team = Team::findOrFail($link->team_id);
        try {
            if (! in_array('dokumente', (array) $link->bereiche, true)) {
                throw new \RuntimeException('Dieser Bereich ist für deinen Zugang nicht freigegeben.');
            }
            $artikelnummer = trim($artikelnummer);
            if ($artikelnummer === '' || mb_strlen($artikelnummer) > 64) {
                throw new \RuntimeException('Bitte die Artikelnummer angeben (höchstens 64 Zeichen).');
            }
            [$endung, $mime] = $this->pruefeDatei($datei);
            $item = $this->artikelZu($team, $link, $artikelnummer);
            $rel = self::ORDNER . '/' . Str::uuid() . '.' . $endung;
            Storage::disk('local')->put($rel, (string) file_get_contents((string) $datei->getRealPath()));
        } catch (\RuntimeException $e) {
            $this->links->ereignis($link, 'abgelehnt', ['bereich' => 'dokumente', 'grund' => mb_substr($e->getMessage(), 0, 200)], $request);

            throw $e;
        }

        $name = $this->anzeigename((string) $datei->getClientOriginalName(), $endung);
        $lauf = Lauf::create(['team_id' => $team->id, 'supplier_id' => $link->supplier_id, 'filename' => $name, 'status' => 'staged', 'art' => Lauf::ART_DOKUMENTE,
            'herkunft' => Lauf::HERKUNFT_LIEFERANTEN_LINK, 'link_id' => $link->id, 'counts' => ['zeilen' => 1]]);
        Zeile::create(['team_id' => $team->id, 'run_id' => $lauf->id, 'row_nr' => 1, 'supplier_id' => $link->supplier_id, 'supplier_name' => $link->supplier?->name,
            'article_number' => $artikelnummer, 'designation' => $item?->designation,
            'status' => $item !== null ? Zeile::STATUS_TREFFER : Zeile::STATUS_OHNE_TREFFER, 'aktion' => Zeile::AKTION_NEU,
            'reason' => $item === null ? 'Kein Artikel mit dieser Artikelnummer bei diesem Lieferanten — nicht zuordenbar.' : null,
            'daten' => ['pfad' => $rel, 'name' => $name, 'mime' => $mime, 'kb' => (int) ceil(filesize((string) $datei->getRealPath()) / 1024), 'supplier_item_id' => $item?->id]]);
        $link->forceFill(['upload_count' => (int) $link->upload_count + 1, 'last_used_at' => now()])->save();
        $this->links->ereignis($link, 'upload', ['bereich' => 'dokumente', 'lauf_id' => $lauf->id, 'datei' => $name, 'artikelnummer' => $artikelnummer, 'zugeordnet' => $item !== null], $request);
        $this->benachrichtigung->melde($link, $lauf);

        return ['lauf' => $lauf, 'zugeordnet' => $item !== null];
    }

    /** Nur lesen: der Artikel des Link-Lieferanten zur Artikelnummer (nie ein Artikel eines anderen Lieferanten). */
    private function artikelZu(Team $team, Link $link, string $artikelnummer): ?FoodAlchemistSupplierItem
    {
        return FoodAlchemistSupplierItem::visibleToTeam($team)->where('supplier_id', $link->supplier_id)->where('article_number', $artikelnummer)->first();
    }

    /** @return array{0: string, 1: string} [Endung, Mime] */
    public function pruefeDatei(UploadedFile $datei): array
    {
        if (! $datei->isValid()) {
            throw new \RuntimeException('Die Datei konnte nicht hochgeladen werden. Bitte noch einmal versuchen.');
        }
        $endung = strtolower((string) pathinfo((string) $datei->getClientOriginalName(), PATHINFO_EXTENSION));
        if (! isset(self::ARTEN[$endung])) {
            throw new \RuntimeException('Dieses Dateiformat nehmen wir nicht an. Erlaubt sind PDF, JPG und PNG.');
        }
        $pfad = (string) $datei->getRealPath();
        $groesse = (int) @filesize($pfad);
        if ($groesse <= 0) {
            throw new \RuntimeException('Die Datei ist leer.');
        }
        if ($groesse > FoodAlchemistProduktpass::MAX_KB * 1024) {
            throw new \RuntimeException('Die Datei ist größer als ' . (FoodAlchemistProduktpass::MAX_KB / 1024) . ' MB.');
        }
        [$mime, $kopf] = self::ARTEN[$endung];
        if (! str_starts_with((string) file_get_contents($pfad, false, null, 0, strlen($kopf)), $kopf) || (new \finfo(FILEINFO_MIME_TYPE))->file($pfad) !== $mime) {
            throw new \RuntimeException('Der Inhalt passt nicht zum Dateiformat.');
        }
        if ($mime === 'application/pdf' && preg_match('/\/(JavaScript|JS|Launch|EmbeddedFile|OpenAction|RichMedia)\b/', (string) file_get_contents($pfad))) {
            throw new \RuntimeException('PDFs mit aktiven Inhalten (Skripte, Einbettungen) nehmen wir nicht an. Bitte als einfaches PDF speichern.');
        }
        if ($mime !== 'application/pdf' && @getimagesize($pfad) === false) {
            throw new \RuntimeException('Der Inhalt passt nicht zum Dateiformat.');
        }
        if ($this->scanner->pruefe($pfad, $endung) !== null) {
            throw new \RuntimeException('Die Datei wurde von der Sicherheitsprüfung abgelehnt.');
        }

        return [$endung, $mime];
    }

    /**
     * Offene Dokument-Zeilen des Teams (Lauf-Art „dokumente“), neueste zuerst.
     *
     * @return \Illuminate\Support\Collection<int, Zeile>
     */
    public function offene(Team $team, int $limit = 100): \Illuminate\Support\Collection
    {
        return Zeile::where('team_id', $team->id)->where('entscheidung', Zeile::ENTSCHEIDUNG_OFFEN)->whereIn('status', [Zeile::STATUS_TREFFER, Zeile::STATUS_OHNE_TREFFER])
            ->whereIn('run_id', Lauf::where('team_id', $team->id)->where('art', Lauf::ART_DOKUMENTE)->select('id'))
            ->orderByDesc('id')->limit($limit)->get();
    }

    /**
     * Übernehmen oder verwerfen. Übernehmen geht NUR über den Produktpass-Weg des Artikels (Rolle Kuratieren, Besitzer-Team); Zeilen ohne Artikel,
     * mit gelöschtem Artikel oder mit einem Artikel eines anderen Lieferanten als dem des Links werden nicht übernommen.
     *
     * @param  list<int>  $zeilenIds
     * @return array{uebernommen: int, verworfen: int, fehler: list<string>}
     */
    public function entscheide(Team $team, ?User $user, array $zeilenIds, string $entscheidung): array
    {
        $this->rechte->pruefe($user, $team, FaRolle::Kuratieren, 'Dokumente vom Lieferanten übernehmen oder verwerfen');
        if (! in_array($entscheidung, ['uebernehmen', 'verwerfen'], true)) {
            throw new \RuntimeException('Entscheidung: uebernehmen oder verwerfen.');
        }
        $bilanz = ['uebernommen' => 0, 'verworfen' => 0, 'fehler' => []];
        $zeilen = $this->offene($team, 500)->whereIn('id', $zeilenIds);
        foreach ($zeilen as $z) {
            $link = Link::find(Lauf::whereKey($z->run_id)->value('link_id'));
            if ($entscheidung === 'verwerfen') {
                $this->loesche($z, Zeile::ENTSCHEIDUNG_VERWERFEN, 'Vom Team verworfen.');
                $bilanz['verworfen']++;
                $link && $this->links->ereignis($link, 'dokument_verworfen', ['zeile_id' => $z->id, 'datei' => $z->daten['name'] ?? null]);

                continue;
            }
            try {
                $this->uebernimm($team, $user, $z, $link);
                $bilanz['uebernommen']++;
                $link && $this->links->ereignis($link, 'dokument_uebernommen', ['zeile_id' => $z->id, 'datei' => $z->daten['name'] ?? null, 'artikel_id' => $z->daten['supplier_item_id'] ?? null]);
            } catch (\RuntimeException $e) {
                $bilanz['fehler'][] = "Zeile {$z->id}: " . $e->getMessage();
            }
        }
        $this->laufeAbschliessen($team, $zeilen->pluck('run_id')->unique()->all());

        return $bilanz;
    }

    private function uebernimm(Team $team, User $user, Zeile $z, ?Link $link): void
    {
        $daten = (array) $z->daten;
        $item = FoodAlchemistSupplierItem::visibleToTeam($team)->where('supplier_id', $link?->supplier_id ?? 0)->find((int) ($daten['supplier_item_id'] ?? 0));
        $pfad = (string) ($daten['pfad'] ?? '');
        if ($item === null) {
            throw new \RuntimeException('Zu dieser Zeile gibt es keinen Artikel dieses Lieferanten.');
        }
        if (! str_starts_with($pfad, self::ORDNER . '/') || ! Storage::disk('local')->exists($pfad)) {
            throw new \RuntimeException('Die Datei liegt nicht mehr vor.');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'fa-dok-');
        file_put_contents($tmp, Storage::disk('local')->get($pfad));
        try {
            $this->produktpass->hochladen($team, $item, new UploadedFile($tmp, (string) ($daten['name'] ?? 'dokument'), (string) ($daten['mime'] ?? null), null, true), $user->id);
        } finally {
            @unlink($tmp);
        }
        $this->loesche($z, Zeile::ENTSCHEIDUNG_UEBERNEHMEN, null, Zeile::STATUS_ANGEWENDET);
    }

    /** Datei entfernen und die Zeile abschließen. */
    private function loesche(Zeile $z, string $entscheidung, ?string $grund, ?string $status = null): void
    {
        $pfad = (string) ($z->daten['pfad'] ?? '');
        if (str_starts_with($pfad, self::ORDNER . '/')) {
            Storage::disk('local')->delete($pfad);
        }
        $z->forceFill(['entscheidung' => $entscheidung, 'status' => $status ?? Zeile::STATUS_VERWORFEN, 'reason' => $grund ?? $z->reason])->save();
    }

    /** @param list<int> $laufIds */
    private function laufeAbschliessen(Team $team, array $laufIds): void
    {
        foreach ($laufIds as $id) {
            if (Zeile::where('run_id', $id)->where('entscheidung', Zeile::ENTSCHEIDUNG_OFFEN)->doesntExist()) {
                Lauf::where('id', $id)->where('team_id', $team->id)->update(['status' => Zeile::where('run_id', $id)->where('status', Zeile::STATUS_ANGEWENDET)->exists() ? 'applied' : 'verworfen']);
            }
        }
    }

    private function anzeigename(string $roh, string $endung): string
    {
        $basis = preg_replace('/[^\p{L}\p{N} ._\-]+/u', '_', pathinfo($roh, PATHINFO_FILENAME)) ?: 'dokument';

        return mb_substr($basis, 0, 80) . '.' . $endung;
    }
}
