<?php

declare(strict_types=1);

namespace DockerVol\Helper;

use Symfony\Component\Process\Process;

final class ArchiveValidator
{
    private const TAR_BLOCK_SIZE = 512;
    private const REGULAR_FILE_TYPES = ["\0", '0'];
    private const DIRECTORY_FILE_TYPE = '5';
    private const UNSAFE_ENTRY_TYPES = [
        '1' => 'hardlink',
        '2' => 'symlink',
        '3' => 'character device',
        '4' => 'block device',
        '6' => 'fifo',
    ];

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

    /**
     * Returns null when all tar entries are safe to extract, or a user-facing failure reason.
     */
    public static function validateEntriesForExtraction(string $archivePath): ?string
    {
        try {
            $entryCount = 0;
            self::readTarEntries($archivePath, function (string $name, string $type) use (&$entryCount): bool {
                $entryCount++;
                $failureReason = self::validateEntry($name, $type);
                if ($failureReason !== null) {
                    throw new \RuntimeException($failureReason);
                }

                return true;
            });

            return $entryCount > 0 ? null : 'Archive appears to be empty';
        } catch (\RuntimeException $exception) {
            return $exception->getMessage();
        }
    }

    public static function readFileFromArchive(string $archivePath, string $entryName): ?string
    {
        $content = null;

        self::readTarEntries(
            $archivePath,
            function (string $name, string $type, int $size, callable $readContent) use ($entryName, &$content): bool {
                if ($name !== $entryName || !in_array($type, self::REGULAR_FILE_TYPES, true)) {
                    return true;
                }

                $content = $readContent();

                return false;
            }
        );

        return $content;
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

    private static function validateEntry(string $name, string $type): ?string
    {
        if ($name === '') {
            return 'Archive contains an empty entry name';
        }

        if (str_contains($name, "\0")) {
            return "Archive contains an entry with a null byte in its name: {$name}";
        }

        if (str_starts_with($name, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $name) === 1) {
            return "Archive contains an absolute path: {$name}";
        }

        $normalizedName = str_replace('\\', '/', $name);
        foreach (explode('/', $normalizedName) as $segment) {
            if ($segment === '..') {
                return "Archive contains a parent directory traversal entry: {$name}";
            }
        }

        if (isset(self::UNSAFE_ENTRY_TYPES[$type])) {
            return sprintf('Archive contains an unsafe %s entry: %s', self::UNSAFE_ENTRY_TYPES[$type], $name);
        }

        if (!in_array($type, [...self::REGULAR_FILE_TYPES, self::DIRECTORY_FILE_TYPE], true)) {
            return sprintf('Archive contains an unsupported entry type "%s": %s', $type, $name);
        }

        return null;
    }

    /**
     * @param callable(string, string, int, callable(): string): bool $onEntry
     */
    private static function readTarEntries(string $archivePath, callable $onEntry): void
    {
        $handle = self::openArchiveForReading($archivePath);
        if ($handle === false) {
            throw new \RuntimeException('Failed to open archive for reading');
        }

        try {
            while (true) {
                $header = self::readBytes($handle, self::TAR_BLOCK_SIZE, str_ends_with($archivePath, '.tar.gz'));
                if ($header === '') {
                    break;
                }

                if (strlen($header) !== self::TAR_BLOCK_SIZE) {
                    throw new \RuntimeException('Invalid tar header');
                }

                if (trim($header, "\0") === '') {
                    break;
                }

                $name = self::readTarEntryName($header);
                $type = substr($header, 156, 1);
                $size = self::readTarEntrySize($header);
                $content = null;
                $readContent = function () use ($handle, $size, $archivePath, &$content): string {
                    if ($content === null) {
                        $content = self::readBytes($handle, $size, str_ends_with($archivePath, '.tar.gz'));
                    }

                    return $content;
                };

                $shouldContinue = $onEntry($name, $type, $size, $readContent);

                if ($content === null) {
                    self::skipBytes($handle, $size, str_ends_with($archivePath, '.tar.gz'));
                }

                self::skipPadding($handle, $size, str_ends_with($archivePath, '.tar.gz'));

                if (!$shouldContinue) {
                    break;
                }
            }
        } finally {
            str_ends_with($archivePath, '.tar.gz') ? gzclose($handle) : fclose($handle);
        }
    }

    /**
     * @return false|resource
     */
    private static function openArchiveForReading(string $archivePath)
    {
        return str_ends_with($archivePath, '.tar.gz') ? gzopen($archivePath, 'rb') : fopen($archivePath, 'rb');
    }

    /**
     * @param resource $handle
     */
    private static function readBytes($handle, int $length, bool $gzip): string
    {
        if ($length === 0) {
            return '';
        }

        $bytes = $gzip ? gzread($handle, $length) : fread($handle, $length);
        if ($bytes === false) {
            throw new \RuntimeException('Failed to read archive data');
        }

        return $bytes;
    }

    /**
     * @param resource $handle
     */
    private static function skipBytes($handle, int $length, bool $gzip): void
    {
        $remaining = $length;
        while ($remaining > 0) {
            $chunkSize = min($remaining, 1024 * 1024);
            $chunk = self::readBytes($handle, $chunkSize, $gzip);
            if ($chunk === '') {
                throw new \RuntimeException('Unexpected end of archive');
            }

            $remaining -= strlen($chunk);
        }
    }

    /**
     * @param resource $handle
     */
    private static function skipPadding($handle, int $size, bool $gzip): void
    {
        $padding = (self::TAR_BLOCK_SIZE - $size % self::TAR_BLOCK_SIZE) % self::TAR_BLOCK_SIZE;
        self::skipBytes($handle, $padding, $gzip);
    }

    private static function readTarEntryName(string $header): string
    {
        $name = rtrim(substr($header, 0, 100), "\0");
        $prefix = rtrim(substr($header, 345, 155), "\0");

        return $prefix !== '' ? $prefix . '/' . $name : $name;
    }

    private static function readTarEntrySize(string $header): int
    {
        $rawSize = trim(rtrim(substr($header, 124, 12), "\0"));
        if ($rawSize === '') {
            return 0;
        }

        if (preg_match('/^[0-7]+$/', $rawSize) !== 1) {
            throw new \RuntimeException('Invalid tar entry size');
        }

        return intval($rawSize, 8);
    }

    private static function isHostTarAvailable(): bool
    {
        static $available = null;

        if ($available !== null) {
            return $available;
        }

        $process = new Process(['sh', '-c', 'command -v tar']);
        $process->setTimeout(5);
        $process->run();

        return $available = $process->isSuccessful();
    }
}
