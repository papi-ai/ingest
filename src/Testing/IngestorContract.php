<?php

/*
 * This file is part of PapiAI,
 * A simple but powerful PHP library for building AI agents.
 *
 * (c) Marcello Duarte <marcello.duarte@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PapiAI\Ingest\Testing;

use PapiAI\Ingest\Contracts\RepositoryIngestorInterface;
use PapiAI\Ingest\Depth;
use PapiAI\Ingest\IngestedFile;
use PapiAI\Ingest\IngestRequest;
use PapiAI\Ingest\RepositoryDigest;

/**
 * The behaviour every ingestor owes its callers, checkable from any test framework.
 *
 * Returns a list of failures rather than asserting, so this ships in `src/` without dragging a
 * test framework into the package's runtime dependencies. Adapter packages pull `papi-ai/ingest`
 * in as a dev dependency and assert the list is empty.
 *
 * Scoped to `Depth::Tree` on purpose: it is the one depth every adapter must serve, whatever it
 * can or cannot see inside a file.
 */
final class IngestorContract
{
    /**
     * Check an ingestor against a fixture directory.
     *
     * @param RepositoryIngestorInterface $ingestor      The implementation under test
     * @param string                      $root          Path to a fixture repository
     * @param list<string>                $expectedPaths Paths that must be discovered, relative to the root
     *
     * @return list<string> Failures, empty when the ingestor honours the contract
     */
    public static function check(RepositoryIngestorInterface $ingestor, string $root, array $expectedPaths): array
    {
        $failures = [];
        $request = new IngestRequest($root, Depth::Tree);

        if (!$ingestor->supports($request)) {
            return ['supports() returned false for a local fixture repository, so nothing else could be checked.'];
        }

        $digest = $ingestor->ingest($request);
        $found = array_map(static fn (IngestedFile $file): string => $file->path, $digest->files);
        sort($found, SORT_STRING);
        sort($expectedPaths, SORT_STRING);

        if ($found !== $expectedPaths) {
            $failures[] = sprintf(
                'Discovered the wrong files. Expected [%s], got [%s].',
                implode(', ', $expectedPaths),
                implode(', ', $found),
            );
        }

        foreach ($digest->files as $file) {
            if ($file->content !== null || $file->symbols !== null) {
                $failures[] = sprintf('At Tree depth %s carried a body, which costs tokens for nothing.', $file->path);

                break;
            }
        }

        $prompt = $digest->toPrompt();

        if (!str_contains($prompt, RepositoryDigest::TREE_HEADER)) {
            $failures[] = sprintf('The rendered prompt is missing the "%s" header.', RepositoryDigest::TREE_HEADER);
        }

        $again = $ingestor->ingest($request);

        if ($again->fingerprint !== $digest->fingerprint) {
            $failures[] = 'Two ingests of unchanged state produced different fingerprints, so consumers cannot cache.';
        }

        if ($again->toPrompt() !== $prompt) {
            $failures[] = 'Two ingests of unchanged state rendered differently, so the ingestor is not deterministic.';
        }

        return $failures;
    }
}
