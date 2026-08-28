<?php

declare(strict_types=1);

namespace Survos\QuickbaseBundle\Grist;

/** One thing the source application has that the converted document will not. */
final readonly class UnsupportedFeature
{
    public function __construct(
        public string $scope,
        public string $kind,
        public string $name,
        public Verdict $verdict,
        public string $note,
    ) {
    }

    /**
     * What a Quickbase report type costs when the tables move to Grist.
     *
     * Grist's built-in widget set covers most of it. Kanban, Gantt/timeline and maps are the
     * ones it does not have -- but a Grist page can host a *custom* widget, which is just a URL
     * talking to the document over the Plugin API, so these stay inside the document and become
     * a component to write rather than a dead end. See https://support.getgrist.com/widget-custom/
     */
    public static function forReport(string $table, string $name, string $type): self
    {
        [$verdict, $note] = match (strtolower($type)) {
            'table' => [Verdict::Rebuild, 'A Grist table widget with the same columns, sort and filter.'],
            'summary' => [Verdict::Rebuild, 'A Grist summary table grouped by the same columns.'],
            'chart' => [Verdict::Rebuild, 'A Grist chart widget over the same source table.'],
            'calendar' => [Verdict::Rebuild, 'Grist has a calendar widget; point it at the date column.'],
            'map' => [Verdict::AppSide, 'Grist ships no map view, but a custom widget can be one: a hosted URL on a Grist page, reading the selected rows through the Plugin API.'],
            'kanban' => [Verdict::AppSide, 'Grist has no Kanban view. A custom widget over the Choice column gives one inside the document -- any URL on a Grist page, reading and writing through the Plugin API.'],
            'timeline', 'gantt' => [Verdict::AppSide, 'Grist has no timeline/Gantt view; a custom widget over the start and end date columns, hosted on a Grist page.'],
            default => [Verdict::AppSide, sprintf('No Grist view of type "%s"; a custom widget on a Grist page is the usual replacement.', $type)],
        };

        return new self($table, 'report', $name, $verdict, $note);
    }
}
