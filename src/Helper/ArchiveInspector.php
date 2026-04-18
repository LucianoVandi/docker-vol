<?php

declare(strict_types=1);

namespace DockerVol\Helper;

use DockerVol\Contract\DockerServiceInterface;

final class ArchiveInspector
{
    /**
     * @return array{successful: bool, output: string, error: string}
     */
    public static function validateDeep(
        string $archivePath,
        DockerServiceInterface $dockerService,
        string $hostBackupDir,
        string $helperImage
    ): array {
        $archiveFilename = basename($archivePath);
        $testCommand = ArchiveNamer::isCompressed($archiveFilename)
            ? ['tar', 'tzf', "/backup/{$archiveFilename}"]
            : ['tar', 'tf', "/backup/{$archiveFilename}"];

        $hostTarResult = ArchiveValidator::listContentsWithHostTar($archivePath);
        if ($hostTarResult['available']) {
            return [
                'successful' => $hostTarResult['successful'],
                'output' => $hostTarResult['output'],
                'error' => $hostTarResult['error'],
            ];
        }

        $process = $dockerService->runContainer([
            '--rm',
            '--mount', "type=bind,source={$hostBackupDir},target=/backup,readonly",
            $helperImage,
            ...$testCommand,
        ]);

        return [
            'successful' => $process->isSuccessful(),
            'output' => $process->getOutput(),
            'error' => $process->getErrorOutput(),
        ];
    }

    /**
     * @return string[]
     */
    public static function imageRepoTags(string $archivePath): array
    {
        $manifestJson = ArchiveValidator::readFileFromArchive($archivePath, 'manifest.json');
        if ($manifestJson === null) {
            return [];
        }

        $manifest = json_decode($manifestJson, true);
        if (!is_array($manifest)) {
            return [];
        }

        $repoTags = [];
        foreach ($manifest as $entry) {
            if (!is_array($entry) || !isset($entry['RepoTags']) || !is_array($entry['RepoTags'])) {
                continue;
            }

            foreach ($entry['RepoTags'] as $repoTag) {
                if (is_string($repoTag) && $repoTag !== '<none>:<none>' && $repoTag !== '') {
                    $repoTags[] = $repoTag;
                }
            }
        }

        return array_values(array_unique($repoTags));
    }

    public static function lightweightFailureReason(string $archivePath): ?string
    {
        return ArchiveValidator::validateLightweight($archivePath);
    }

    public static function extractionFailureReason(string $archivePath): ?string
    {
        return ArchiveValidator::validateEntriesForExtraction($archivePath);
    }

    public static function imageManifestFailureReason(string $archivePath): ?string
    {
        $manifestJson = ArchiveValidator::readFileFromArchive($archivePath, 'manifest.json');
        if ($manifestJson === null) {
            return 'Archive does not contain manifest.json';
        }

        try {
            $manifest = json_decode($manifestJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return 'manifest.json contains invalid JSON';
        }

        if (!is_array($manifest) || $manifest === []) {
            return 'manifest.json must be a non-empty JSON array';
        }

        foreach ($manifest as $index => $entry) {
            if (!is_array($entry)) {
                return "manifest.json entry {$index} is not an object";
            }

            if (!isset($entry['Config']) || !is_string($entry['Config']) || $entry['Config'] === '') {
                return "manifest.json entry {$index} is missing required 'Config' field";
            }

            if (!isset($entry['Layers']) || !is_array($entry['Layers'])) {
                return "manifest.json entry {$index} is missing required 'Layers' field";
            }
        }

        return null;
    }
}
