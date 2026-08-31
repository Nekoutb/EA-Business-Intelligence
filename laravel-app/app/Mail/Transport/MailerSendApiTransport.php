<?php

namespace App\Mail\Transport;

use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\MessageConverter;

/**
 * Mail transport that delivers through the MailerSend HTTP API instead
 * of SMTP. Chosen over SMTP for the same reason the daily-report command
 * prefers it: the API answers with a per-message status and x-message-id,
 * so a failed send throws HERE with MailerSend's reason (unverified
 * domain, suppressed recipient, quota) instead of vanishing after SMTP
 * acceptance. Activated with MAIL_MAILER=mailersend + MAILERSEND_API_KEY
 * in .env — every Mail:: send in the app then flows through it.
 */
class MailerSendApiTransport extends AbstractTransport
{
    public function __construct(private string $apiKey)
    {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());

        $addr = fn ($a) => array_filter([
            'email' => $a->getAddress(),
            'name'  => $a->getName() !== '' ? $a->getName() : null,
        ]);

        $from = $email->getFrom()[0] ?? null;
        if (!$from) {
            throw new \RuntimeException('MailerSend API: message has no From address (set MAIL_FROM_ADDRESS).');
        }

        $payload = [
            'from'    => $addr($from),
            'to'      => array_map($addr, $email->getTo()),
            'subject' => (string) $email->getSubject(),
        ];
        if ($email->getCc())  $payload['cc']  = array_map($addr, $email->getCc());
        if ($email->getBcc()) $payload['bcc'] = array_map($addr, $email->getBcc());
        if ($email->getReplyTo()) $payload['reply_to'] = $addr($email->getReplyTo()[0]);
        if ($email->getHtmlBody() !== null) $payload['html'] = (string) $email->getHtmlBody();
        if ($email->getTextBody() !== null) $payload['text'] = (string) $email->getTextBody();

        $resp = Http::withToken($this->apiKey)
            ->acceptJson()
            ->timeout(30)
            ->post('https://api.mailersend.com/v1/email', $payload);

        if (!in_array($resp->status(), [200, 202], true)) {
            throw new \RuntimeException(
                'MailerSend API: HTTP ' . $resp->status() . ' — ' . mb_substr((string) $resp->body(), 0, 500)
            );
        }
    }

    public function __toString(): string
    {
        return 'mailersend';
    }
}
