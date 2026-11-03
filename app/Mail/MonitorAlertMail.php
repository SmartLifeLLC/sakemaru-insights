<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** 監視通知メール（本文は Slack と同じビルダーで作った伏字済みテキスト） */
class MonitorAlertMail extends Mailable
{
    public function __construct(public readonly string $subjectLine, public readonly string $bodyText, private readonly ?string $fromAddress = null, private readonly ?string $fromName = null) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->fromAddress ? new Address($this->fromAddress, $this->fromName ?: '算盤 監視') : null,
            subject: $this->subjectLine,
        );
    }

    public function content(): Content
    {
        $html = '<div style="font-family:-apple-system,\'Noto Sans JP\',sans-serif;font-size:14px;line-height:1.7;color:#1a283b">'
            .nl2br(e($this->bodyText), false)
            .'<hr style="border:none;border-top:1px solid #dbe1e9;margin:16px 0"><div style="color:#617186;font-size:12px">このメールは 算盤 監視 から自動送信されています。返信は確認されません。</div></div>';

        return new Content(htmlString: $html);
    }
}
