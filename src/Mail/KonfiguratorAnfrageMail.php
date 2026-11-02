<?php

namespace Platform\FoodAlchemist\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailable;

/**
 * Spec 94: neue Anfrage aus einem Konfigurator-Link an die verantwortliche Person. Reiner Text (Auswahl, Gäste, Preis,
 * Kontakt, Link zum Angebot); Antworten gehen an den Kunden. Versendet über den Mailer der Host-App, keine Core-Änderung.
 */
class KonfiguratorAnfrageMail extends Mailable
{
    use Queueable;

    public function __construct(public string $betreff, public string $text, public ?string $antwortAn = null)
    {
    }

    public function envelope(): Envelope
    {
        $from = config('mail.from.address');

        return new Envelope(
            from: $from ? new Address($from, config('mail.from.name')) : null,
            replyTo: $this->antwortAn ? [new Address($this->antwortAn)] : [],
            subject: $this->betreff,
        );
    }

    public function content(): Content
    {
        return new Content(text: 'foodalchemist::mail.bestellung-text', with: ['text' => $this->text]);
    }
}
