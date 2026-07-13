<?php
declare(strict_types=1);

namespace Tds\CorePanelApi\Support;

use Tds\Panel\Contract\UserContext;

/**
 * The principal for an unauthenticated request (and the current placeholder
 * binding until the JWT/JWKS auth port lands, which will populate a real
 * JwtUserContext from the verified token on each request).
 */
final class AnonymousUserContext implements UserContext
{
    public function isAuthenticated(): bool
    {
        return false;
    }

    public function userId(): ?int
    {
        return null;
    }

    public function isAdmin(): bool
    {
        return false;
    }

    /** @return string[] */
    public function permissions(): array
    {
        return [];
    }

    public function has(string $permission): bool
    {
        return false;
    }

    public function activeCompanyId(): ?int
    {
        return null;
    }
}
