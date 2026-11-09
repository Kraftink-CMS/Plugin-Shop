<?php

namespace KraftInk\Plugins\Shop\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class CardMail extends Mailable
{
    use Queueable;

    public function __construct(public string $event, public string $value, public string $lastFour)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('shop::commerce.giftcards.mail.subject'));
    }

    public function content(): Content
    {
        return new Content(view: 'shop::emails.giftcards.card', text: 'shop::emails.giftcards.card-text');
    }
}
