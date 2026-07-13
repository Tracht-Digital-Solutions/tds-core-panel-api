<?php
declare(strict_types=1);

namespace Tds\CorePanelApi\Service;

use Symfony\Component\Mailer\MailerInterface as SymfonyMailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email as MimeEmail;
use Tds\Panel\Contract\Email;
use Tds\Panel\Contract\Mailer;

/**
 * The core's SMTP {@see Mailer}, backed by Symfony Mailer. Owns the From
 * identity + transport (configured once in the base); modules only hand it an
 * {@see Email}. Bound in the container only when SMTP is configured — otherwise
 * the base binds {@see NullMailer}.
 */
final class SmtpMailer implements Mailer
{
    public function __construct(
        private readonly SymfonyMailerInterface $transport,
        private readonly string $fromEmail,
        private readonly string $fromName,
    ) {
    }

    public function send(Email $email): void
    {
        $message = (new MimeEmail())
            ->from(new Address($this->fromEmail, $this->fromName))
            ->to(new Address($email->toEmail, $email->toName))
            ->subject($email->subject)
            ->html($email->htmlBody);

        if ($email->textBody !== null) {
            $message->text($email->textBody);
        }
        if ($email->replyTo !== null) {
            $message->replyTo($email->replyTo);
        }

        $this->transport->send($message);
    }

    public function isConfigured(): bool
    {
        return true;
    }
}
