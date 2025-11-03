<?php

declare(strict_types=1);

namespace DockerVol\Enum;

enum OperationStatus: string
{
    case SUCCESS = 'success';
    case FAILED = 'failed';
    case SKIPPED = 'skipped';
}
