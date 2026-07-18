<?php
declare(strict_types=1);

namespace Tds\CorePanelApi;

use Tds\Ext\Lexware\LexwareModule;
use Tds\Ext\TimeTracker\TimeTrackerModule;
use Tds\Panel\Contract\Module;

/**
 * The enabled-module list for THIS API build — the backend twin of the
 * frontend product's `astro.config` extension list. The base composes exactly
 * these through the ModuleRegistry (dependency-ordered, collision-checked).
 *
 * The base MUST work with an empty list; extensions are purely additive. The
 * admin and customer API targets differ only in what this returns.
 */
final class Modules
{
    /** @return Module[] */
    public static function enabled(): array
    {
        return [
            new TimeTrackerModule(),
            new LexwareModule(),
        ];
    }
}
