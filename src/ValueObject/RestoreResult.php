<?php

declare(strict_types=1);

namespace DockerVol\ValueObject;

final readonly class RestoreResult extends AbstractResult
{
    protected static function getDefaultSuccessMessage(): string
    {
        return 'Restore completed successfully';
    }
}
