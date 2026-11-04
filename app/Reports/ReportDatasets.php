<?php

declare(strict_types=1);

namespace App\Reports;

use App\Reports\Contract\ReportDataset;
use App\Reports\Datasets\UsageDataset;

/**
 * The datasets this service offers the report builder. routes/api.php gives
 * each one a literal query route; each needs its own pizzasys auth rule.
 */
final class ReportDatasets
{
    /** @var list<class-string<ReportDataset>> */
    public const DATASETS = [
        UsageDataset::class,
    ];

    public static function make(string $key): ReportDataset
    {
        foreach (self::DATASETS as $dataset) {
            if ($dataset::KEY === $key) {
                return new $dataset();
            }
        }

        abort(404, 'Unknown dataset.');
    }
}
