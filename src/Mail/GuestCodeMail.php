<?php

namespace KraftInk\Plugins\Shop\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class GuestCodeMail extends Mailable
{
    public function __construct(public string $code)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('shop::messages.guests.email_subject'));
    }

    public function content(): Content
    {
        return new Content(view: 'shop::emails.guests.code', text: 'shop::emails.guests.code-text');
    }
}
