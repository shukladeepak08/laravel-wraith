<?php

declare(strict_types=1);

namespace SdPayHub\Wraith\Baseline;

use SdPayHub\Wraith\Results\Finding;

/**
 * Stable identity for a finding used by baseline / --diff.
 */
final class FindingFingerprint
{
    public static function for(Finding $finding): string
    {
        $meta = $finding->meta();
        ksort($meta);

        return hash('sha1', implode("\0", [
            $finding->code(),
            $finding->category(),
            $finding->description(),
            json_encode($meta),
        ]));
    }
}
