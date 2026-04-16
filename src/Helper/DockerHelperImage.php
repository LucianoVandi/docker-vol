<?php

declare(strict_types=1);

namespace DockerVol\Helper;

final class DockerHelperImage
{
    public const DEFAULT_IMAGE = 'alpine:3.20';

    public static function name(): string
    {
        $configuredImage = getenv('DOCKERVOL_HELPER_IMAGE');
        if (is_string($configuredImage) && trim($configuredImage) !== '') {
            return trim($configuredImage);
        }

        return self::DEFAULT_IMAGE;
    }
}
