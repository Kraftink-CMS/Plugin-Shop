<?php

namespace KraftInk\Plugins\Shop\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class DocumentMail extends Mailable
{
    use Queueable;

    public function __construct(public string $number, public string $documentType, protected string $documentBody)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('shop::commerce.documents.mail.subject', [
                'document' => __('shop::commerce.documents.mail.types.'.$this->documentType),
                'number' => $this->number,
            ]),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'shop::emails.receipts.document', text: 'shop::emails.receipts.document-text');
    }

    public function attachments(): array
    {
        $filename = preg_replace('/[^a-zA-Z0-9_-]/', '-', $this->number).'.html';

        return [Attachment::fromData(fn () => $this->documentBody, $filename)->withMime('text/html')];
    }
}
