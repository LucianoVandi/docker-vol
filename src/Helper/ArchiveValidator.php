<?php

declare(strict_types=1);

namespace DockerVol\Helper;

use Symfony\Component\Process\Process;

final class ArchiveValidator
{
    /**
     * Returns null when the archive passes lightweight validation, or a user-facing failure reason.
     */
    public static function validateLightweight(string $archivePath): ?string
    {
        if (!self::hasValidArchiveExtension($archivePath)) {
            return 'Invalid file extension (expected .tar or .tar.gz)';
        }

        if (!is_file($archivePath)) {
            return 'Archive file does not exist';
        }

        if (!is_readable($archivePath)) {
            return 'File is not readable';
        }

        $fileSize = filesize($archivePath);
        if ($fileSize === false || $fileSize === 0) {
            return 'Archive file is empty';
        }

        if (str_ends_with($archivePath, '.tar.gz')) {
            return self::hasValidGzipHeader($archivePath) ? null : 'Invalid gzip header';
        }

        return self::hasPlausibleTarHeader($archivePath) ? null : 'Invalid tar header';
    }

    /**
     * @return array{available: bool, successful: bool, output: string, error: string}
     */
    public static function listContentsWithHostTar(string $archivePath): array
    {
        if (!self::isHostTarAvailable()) {
            return [
                'available' => false,
                'successful' => false,
                'output' => '',
                'error' => 'tar command is not available on host',
            ];
        }

        $listFlag = str_ends_with($archivePath, '.tar.gz') ? 'tzf' : 'tf';
        $process = new Process(['tar', $listFlag, $archivePath]);
        $process->setTimeout(60);
        $process->run();

        return [
            'available' => true,
            'successful' => $process->isSuccessful(),
            'output' => $process->getOutput(),
            'error' => $process->getErrorOutput(),
        ];
    }

    public static function hasValidArchiveExtension(string $archivePath): bool
    {
        return str_ends_with($archivePath, '.tar') || str_ends_with($archivePath, '.tar.gz');
    }

    private static function hasValidGzipHeader(string $archivePath): bool
    {
        $handle = fopen($archivePath, 'rb');
        if ($handle === false) {
            return false;
        }

        try {
            return fread($handle, 2) === "\x1f\x8b";
        } finally {
            fclose($handle);
        }
    }

    private static function hasPlausibleTarHeader(string $archivePath): bool
    {
        $handle = fopen($archivePath, 'rb');
        if ($handle === false) {
            return false;
        }

        try {
            $header = fread($handle, 512);
        } finally {
            fclose($handle);
        }

        if (!is_string($header) || strlen($header) < 512 || trim($header, "\0") === '') {
            return false;
        }

        $fileName = rtrim(substr($header, 0, 100), "\0");
        $checksum = rtrim(substr($header, 148, 8), "\0 ");
        $magic = substr($header, 257, 5);

        if ($magic === 'ustar') {
            return true;
        }

        return $fileName !== '' && preg_match('/^[0-7]+$/', $checksum) === 1;
    }

    private static function isHostTarAvailable(): bool
    {
        $process = new Process(['sh', '-c', 'command -v tar']);
        $process->setTimeout(5);
        $process->run();

        return $process->isSuccessful();
    }
}
