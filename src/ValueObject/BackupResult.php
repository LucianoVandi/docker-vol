<?php

declare(strict_types=1);

namespace DockerVol\ValueObject;

final readonly class BackupResult extends AbstractResult
{
    protected static function getDefaultSuccessMessage(): string
    {
        return 'Backup completed successfully';
    }
}
