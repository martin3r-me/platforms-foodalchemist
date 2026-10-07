<?php

namespace Platform\FoodAlchemist\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Spec 63: Bestellung bzw. Storno an den Lieferanten. Text kommt aus OrderService::mailtoData /
 * cancellationMailtoData (derselbe Wortlaut wie beim Mailprogramm-Weg), das Bestell-PDF hängt an.
 * Versendet über den Mailer der Host-App (config mail.default).
 */
class BestellungMail extends Mailable
{
    use Queueable;

    public function __construct(
        public string $betreff,
        public string $text,
        public ?string $pdf = null,
        public ?string $pdfName = null,
        public ?string $absenderName = null,
        public ?string $antwortAn = null,
    ) {
    }

    public function envelope(): Envelope
    {
        $from = config('mail.from.address');

        return new Envelope(
            from: $from ? new Address($from, $this->absenderName ?: config('mail.from.name')) : null,
            replyTo: $this->antwortAn ? [new Address($this->antwortAn)] : [],
            subject: $this->betreff,
        );
    }

    public function content(): Content
    {
        return new Content(text: 'foodalchemist::mail.bestellung-text', with: ['text' => $this->text]);
    }

    public function attachments(): array
    {
        if ($this->pdf === null) {
            return [];
        }

        return [Attachment::fromData(fn () => $this->pdf, $this->pdfName ?? 'Bestellung.pdf')->withMime('application/pdf')];
    }
}
