<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Jobs\BestellMailSendenJob;
use Platform\FoodAlchemist\Mail\BestellungMail;
use Platform\FoodAlchemist\Models\FoodAlchemistOrder;
use Platform\FoodAlchemist\Models\FoodAlchemistOrderMail;
use RuntimeException;

/**
 * Spec 63 · Bestellversand per Mail.
 *
 * Versandart je Team (TeamSettings `bestellversand`):
 *  - `mailprogramm` (Standard, bisheriges Verhalten): mailto-Link, nichts geht vom Server raus.
 *  - `server`: Status „versendet" plant eine Mail mit Bestell-PDF an den Lieferanten; Storno einer bereits
 *    versendeten/bestätigten Bestellung plant eine Storno-Mail. Versand im Job (Warteschlange), Protokoll
 *    in `foodalchemist_order_mails`.
 *
 * Text = derselbe Wortlaut wie der mailto-Weg (OrderService::mailtoData / cancellationMailtoData).
 * Mailer = der der Host-App (Laravel Mail; demo/office: Postmark). Lokal: MAIL_MAILER=log.
 */
class OrderMailService
{
    public const TYPEN = ['bestellung', 'storno'];

    /** Platzhalter für Betreff/Text-Vorlagen (Einstellungen → Einkauf → Bestellversand) */
    public const PLATZHALTER = ['{lieferant}', '{referenz}', '{positionen}', '{liefertermin}', '{summe}', '{team}', '{besteller}'];

    public function __construct(
        private OrderService $orders,
        private TeamSettingsService $settings,
    ) {
    }

    public function istServerVersand(Team $team): bool
    {
        return ($this->settings->for($team)->bestellversand ?? 'mailprogramm') === 'server';
    }

    /** Vor dem Statuswechsel prüfen, ob eine Mail überhaupt rausgehen kann (sonst Wechsel verweigern). */
    public function pruefeVersandbar(Team $team, FoodAlchemistOrder $order): void
    {
        if (! $this->istServerVersand($team)) {
            return;
        }
        $an = trim((string) ($order->supplier?->email_order ?? ''));
        if ($an === '' || ! filter_var($an, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException(
                'Bestellung kann nicht per Mail versendet werden: Beim Lieferanten „'.($order->supplier?->name ?? '?')
                .'" fehlt eine gültige Bestell-E-Mail (Lieferant → Bestell-E-Mail).'
            );
        }
    }

    /** Legt den Protokoll-Eintrag an und stellt den Versand in die Warteschlange (nach dem Commit). */
    public function planen(Team $team, FoodAlchemistOrder $order, string $typ): FoodAlchemistOrderMail
    {
        if (! in_array($typ, self::TYPEN, true)) {
            throw new RuntimeException("Unbekannter Mail-Typ „{$typ}\".");
        }
        $user = auth()->user();
        $daten = $this->inhalt($team, (int) $order->id, $typ, $user?->name);
        if (trim($daten['to']) === '') {
            throw new RuntimeException('Keine Empfänger-Adresse für die '.($typ === 'storno' ? 'Storno-' : 'Bestell-').'Mail.');
        }
        $s = $this->settings->for($team);

        $mail = FoodAlchemistOrderMail::create([
            'team_id' => $team->id,
            'order_id' => $order->id,
            'typ' => $typ,
            'an' => $daten['to'],
            'kopie_an' => $s->bestellversand_kopie_an ?: null,
            'antwort_an' => $s->bestellversand_antwort_an ?: $user?->email,
            'betreff' => $daten['subject'],
            'status' => 'geplant',
            'ausgeloest_von' => $user?->id,
        ]);

        BestellMailSendenJob::dispatch((int) $mail->id)->afterCommit();

        return $mail;
    }

    /** Versendet einen Eintrag (vom Job aufgerufen). Fehler werden protokolliert und weitergereicht (Retry). */
    public function senden(int $mailId): void
    {
        $mail = FoodAlchemistOrderMail::findOrFail($mailId);
        if ($mail->status === 'versendet') {
            return;
        }
        $team = Team::findOrFail($mail->team_id);
        $s = $this->settings->for($team);

        try {
            $besteller = $mail->ausgeloest_von ? \Platform\Core\Models\User::find($mail->ausgeloest_von)?->name : null;
            $text = $this->inhalt($team, (int) $mail->order_id, $mail->typ, $besteller)['body'];
            if (filled($s->bestellversand_signatur)) {
                $text .= "\n\n".trim((string) $s->bestellversand_signatur);
            }

            [$pdf, $pdfName] = $mail->typ === 'bestellung' ? $this->pdf($team, (int) $mail->order_id) : [null, null];

            $versand = Mail::to($mail->an);
            if ($mail->kopie_an) {
                $versand->bcc(array_values(array_filter(array_map('trim', explode(',', $mail->kopie_an)))));
            }
            $versand->send(new BestellungMail(
                betreff: $mail->betreff,
                text: $text,
                pdf: $pdf,
                pdfName: $pdfName,
                absenderName: $s->bestellversand_absender_name ?: $team->name,
                antwortAn: $mail->antwort_an,
            ));

            $mail->forceFill([
                'status' => 'versendet', 'fehler' => null, 'versendet_am' => now(),
                'versuche' => $mail->versuche + 1,
            ])->save();
        } catch (\Throwable $e) {
            $mail->forceFill([
                'status' => 'fehlgeschlagen', 'fehler' => mb_substr($e->getMessage(), 0, 2000),
                'versuche' => $mail->versuche + 1,
            ])->save();

            throw $e;
        }
    }

    /**
     * Betreff + Text: Team-Vorlage mit Platzhaltern, sonst Standard-Wortlaut (= mailto-Weg).
     *
     * @return array{to:string, subject:string, body:string}
     */
    public function inhalt(Team $team, int $orderId, string $typ, ?string $besteller = null): array
    {
        $standard = $typ === 'storno'
            ? $this->stornoStandard($team, $orderId)
            : $this->orders->mailtoData($team, $orderId);
        $s = $this->settings->for($team);
        $betreffVorlage = $typ === 'storno' ? $s->bestellversand_betreff_storno : $s->bestellversand_betreff_bestellung;
        $textVorlage = $typ === 'storno' ? $s->bestellversand_text_storno : $s->bestellversand_text_bestellung;
        if (blank($betreffVorlage) && blank($textVorlage)) {
            return $standard;
        }

        $werte = $this->platzhalterWerte($team, $orderId, $besteller);

        return [
            'to' => $standard['to'],
            'subject' => filled($betreffVorlage) ? strtr($betreffVorlage, $werte) : $standard['subject'],
            'body' => filled($textVorlage) ? strtr($textVorlage, $werte) : $standard['body'],
        ];
    }

    /**
     * Storno-Wortlaut wie OrderService::cancellationMailtoData — aber ohne dessen Status-Sperre: die Mail wird
     * NACH dem Wechsel auf „storniert" geplant und versendet (dort liefert die mailto-Variante bewusst nichts).
     *
     * @return array{to:string, subject:string, body:string}
     */
    private function stornoStandard(Team $team, int $orderId): array
    {
        $d = $this->orders->dokument($team, $orderId);
        $kennung = $d['reference'] ?: ('#'.$d['id']);
        $body = ['Guten Tag,', '', 'bitte stornieren Sie unsere Bestellung '.$kennung.' vollständig.', 'Lieferant: '.($d['lieferant']['name'] ?? 'Lieferant')];
        if ($d['desired_delivery_date']) {
            $body[] = 'Geplanter Liefertermin: '.$d['desired_delivery_date'];
        }
        array_push($body, '', 'Bitte bestätigen Sie uns die Stornierung kurz schriftlich.', '', 'Vielen Dank.');

        return ['to' => (string) ($d['lieferant']['email_order'] ?? ''), 'subject' => 'Stornierung unserer Bestellung '.$kennung, 'body' => implode("\n", $body)];
    }

    /** @return array<string,string> */
    public function platzhalterWerte(Team $team, int $orderId, ?string $besteller = null): array
    {
        $d = $this->orders->dokument($team, $orderId);
        $zeilen = [];
        foreach ($d['zeilen'] as $l) {
            $menge = rtrim(rtrim(number_format($l['qty_packs'], 2, ',', '.'), '0'), ',');
            $geb = trim(($l['packaging_unit'] ?? '').' '.($l['designation'] ?? ''));
            $zeilen[] = "- {$menge}× {$geb}".($l['article_number'] ? " (Art. {$l['article_number']})" : '');
        }

        return [
            '{lieferant}' => (string) ($d['lieferant']['name'] ?? ''),
            '{referenz}' => (string) ($d['reference'] ?: ('#'.$d['id'])),
            '{positionen}' => implode("\n", $zeilen),
            '{liefertermin}' => $d['desired_delivery_date'] ? \Carbon\Carbon::parse($d['desired_delivery_date'])->format('d.m.Y') : 'nach Absprache',
            '{summe}' => number_format((float) $d['total_net'], 2, ',', '.').' €',
            '{team}' => (string) $team->name,
            '{besteller}' => (string) ($besteller ?? ''),
        ];
    }

    /** Fehlgeschlagenen Versand erneut einplanen. */
    public function erneutSenden(Team $team, int $mailId): void
    {
        $mail = FoodAlchemistOrderMail::where('team_id', $team->id)->findOrFail($mailId);
        if ($mail->status === 'versendet') {
            throw new RuntimeException('Diese Mail wurde bereits versendet.');
        }
        $mail->forceFill(['status' => 'geplant', 'fehler' => null])->save();
        BestellMailSendenJob::dispatch((int) $mail->id);
    }

    /** @return Collection<int, FoodAlchemistOrderMail> */
    public function protokoll(Team $team, int $orderId): Collection
    {
        return FoodAlchemistOrderMail::where('team_id', $team->id)->where('order_id', $orderId)
            ->orderByDesc('id')->get();
    }

    /** @return array{0: ?string, 1: ?string} PDF-Inhalt + Dateiname (null, wenn DomPDF fehlt) */
    private function pdf(Team $team, int $orderId): array
    {
        if (! class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
            return [null, null];
        }
        $dok = $this->orders->dokument($team, $orderId);
        $inhalt = \Barryvdh\DomPDF\Facade\Pdf::loadView('foodalchemist::dokumente.bestellung', ['dok' => $dok, 'istPdf' => true])->output();

        return [$inhalt, 'Bestellung-'.$dok['id'].'.pdf'];
    }
}
