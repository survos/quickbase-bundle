<?php

declare(strict_types=1);

namespace Survos\QuickbaseBundle\Grist;

/**
 * What can be done about a Quickbase feature that this converter does not carry across.
 *
 * Quickbase is an application platform, not only a table store: reports, forms, roles,
 * dashboards and automations are part of what people built, and none of them is in the
 * record-store contract that this conversion goes through. Moving the tables and saying
 * nothing about the rest would read as "it all came over".
 *
 * The three answers are genuinely different work, so they are different cases rather than
 * one "unsupported" bucket:
 */
enum Verdict: string
{
    /** Grist has this. Build it once in the document; nothing has to be written. */
    case Rebuild = 'rebuild';

    /**
     * Grist ships no such view, but the data is all there and Grist can host the replacement:
     * a custom widget is any URL added to a page, talking to the document through the Grist
     * Plugin API -- https://support.getgrist.com/widget-custom/ -- so it reads and writes the
     * selected records and the board lives inside the document rather than beside it.
     *
     * Kanban is the clear case: no Grist view for it, and a board over a Choice column is a
     * small component either way. The same component serves as an app-side view over the REST
     * API if it is wanted outside Grist too.
     */
    case AppSide = 'app-side';

    /** Not reproducible outside Quickbase. Say so rather than implying a workaround exists. */
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::Rebuild => 'rebuild in Grist',
            self::AppSide => 'build a custom widget (or an app-side view)',
            self::None => 'no equivalent',
        };
    }
}
