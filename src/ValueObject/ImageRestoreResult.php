<?php

declare(strict_types=1);

namespace DockerVol\ValueObject;

final readonly class ImageRestoreResult extends AbstractResult
{
    protected static function getDefaultSuccessMessage(): string
    {
        return 'Image restore completed successfully';
    }
}
