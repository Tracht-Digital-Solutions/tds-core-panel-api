<?php
declare(strict_types=1);

namespace Tds\CorePanelApi\Tests;

use PHPUnit\Framework\TestCase;
use Tds\CorePanelApi\Bootstrap;
use Tds\Panel\Contract\Mailer;
use Tds\Panel\Contract\UserContext;

/**
 * The core services extensions resolve from the app container. Verifies the
 * bindings exist and the unconfigured defaults are the safe no-op / anonymous
 * ones (so a module can call them without a DB/SMTP present).
 */
final class ServiceContainerTest extends TestCase
{
    public function testMailerDefaultsToNoOpWhenUnconfigured(): void
    {
        unset($_ENV['MAIL_DSN']);
        $container = Bootstrap::createApp(dirname(__DIR__))->getContainer();

        $mailer = $container->get(Mailer::class);
        self::assertInstanceOf(Mailer::class, $mailer);
        self::assertFalse($mailer->isConfigured(), 'no MAIL_DSN → no-op mailer');
    }

    public function testUserContextDefaultsToAnonymous(): void
    {
        $context = Bootstrap::createApp(dirname(__DIR__))->getContainer()->get(UserContext::class);

        self::assertInstanceOf(UserContext::class, $context);
        self::assertFalse($context->isAuthenticated());
        self::assertNull($context->userId());
        self::assertFalse($context->has('anything'));
    }
}
