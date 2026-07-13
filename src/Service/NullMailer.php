<?php
declare(strict_types=1);

namespace Tds\CorePanelApi\Service;

use Tds\Panel\Contract\Email;
use Tds\Panel\Contract\Mailer;

/**
 * No-op {@see Mailer} bound when the base has no SMTP configured. Lets a module
 * call the mailer unconditionally; `isConfigured()` is false so it can skip or
 * annotate the notification (mirrors the existing services' Null* pattern).
 */
final class NullMailer implements Mailer
{
    public function send(Email $email): void
    {
        // intentionally does nothing
    }

    public function isConfigured(): bool
    {
        return false;
    }
}
