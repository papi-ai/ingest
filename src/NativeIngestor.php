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

namespace PapiAI\Ingest;

use Closure;
use FilesystemIterator;
use PapiAI\Core\Contracts\TokenEstimatorInterface;
use PapiAI\Core\HeuristicTokenEstimator;
use PapiAI\Ingest\Contracts\RepositoryIngestorInterface;
use PapiAI\Ingest\Contracts\SymbolExtractorInterface;
use PapiAI\Ingest\Exception\SourceNotFoundException;
use PapiAI\Ingest\Exception\UnsupportedDepthException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Ingests a local directory, with no dependencies beyond papi-core.
 *
 * Inside a git repository, discovery defers to git itself
 * (`git ls-files --cached --others --exclude-standard`), which returns tracked files plus
 * untracked ones that are not ignored. That is both faster than walking and exactly right about
 * `.gitignore`, including nested ignore files and global excludes, which a hand-rolled matcher
 * never quite is. Outside a repository, or with `respectGitignore` off, it walks the directory.
 *
 * Symbol extraction for `Depth::Api` is composed rather than inherited: pass a
 * {@see SymbolExtractorInterface} and the same discovery, filtering and rendering serve every
 * language. Ask for `Api` without one and it throws rather than quietly handing back a `Tree`.
 */
class NativeIngestor implements RepositoryIngestorInterface
{
    public function __construct(
        private readonly ?SymbolExtractorInterface $extractor = null,
        private readonly TokenEstimatorInterface $estimator = new HeuristicTokenEstimator(),
    ) {
    }

    public function supports(IngestRequest $request): bool
    {
        return !$request->isRemote() && is_dir($request->source);
    }

    public function ingest(IngestRequest $request): RepositoryDigest
    {
        $root = $request->isRemote() ? false : realpath($request->source);

        if ($root === false || !is_dir($root)) {
            throw SourceNotFoundException::forSource($request->source);
        }

        if ($request->depth === Depth::Api && $this->extractor === null) {
            throw UnsupportedDepthException::needsExtractor($request->depth, 'papi-ai/ingest-php-symbols');
        }

        $filter = new PathFilter($request->include, $request->exclude);
        $paths = array_values(array_filter(
            $this->discover($root, $request->respectGitignore),
            static fn (string $path): bool => $filter->accepts($path),
        ));
        sort($paths, SORT_STRING);

        $notes = [];
        $files = [];

        foreach ($paths as $path) {
            $files[] = $this->readFile($root, $path, $request, $notes);
        }

        $tree = (new TreeBuilder())->build($paths, basename($root));
        $bodyTokens = array_sum(array_map(static fn (IngestedFile $file): int => $file->tokens, $files));

        return new RepositoryDigest(
            $this->fingerprint($root, $paths),
            $tree,
            $this->prioritise($files, $request->prioritiser),
            $notes,
            $this->estimator->estimateTokens($tree) + (int) $bodyTokens,
            $request->depth,
            $request->tokenBudget,
            $this->estimator,
        );
    }

    /**
     * Run a git command against the root, returning its output lines.
     *
     * Isolated so tests can drive the non-git path without a fixture repository. Returns null when
     * git is unavailable, or the directory is not a repository, which is the signal to fall back
     * to walking.
     *
     * @param string       $root      Absolute path to the ingestion root
     * @param list<string> $arguments Git arguments, without the leading "git"
     * @param string       $separator Line separator to split output on
     *
     * @return list<string>|null Output lines, or null when the command failed
     */
    protected function git(string $root, array $arguments, string $separator = "\n"): ?array
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open(array_merge(['git', '-C', $root], $arguments), $descriptors, $pipes);

        if (!is_resource($process)) {
            return null;
        }

        $stdout = stream_get_contents($pipes[1]);
        // Drain stderr rather than closing it unread: git would take a broken pipe and exit
        // non-zero, which reads here as "not a repository" and silently changes discovery.
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        if (proc_close($process) !== 0) {
            return null;
        }

        return array_values(array_filter(explode($separator, $stdout === false ? '' : $stdout), 'strlen'));
    }

    /**
     * Discover candidate paths, preferring git's own answer about what belongs.
     *
     * @return list<string> Paths relative to the root
     */
    private function discover(string $root, bool $respectGitignore): array
    {
        if ($respectGitignore) {
            $tracked = $this->git($root, ['ls-files', '--cached', '--others', '--exclude-standard']);

            if ($tracked !== null) {
                // git lists staged deletions too, so drop anything no longer on disk.
                return array_values(array_filter(
                    $tracked,
                    static fn (string $path): bool => is_file($root . '/' . $path),
                ));
            }
        }

        return $this->walk($root);
    }

    /**
     * Walk the directory tree, never following symlinks.
     *
     * @return list<string> Paths relative to the root
     */
    private function walk(string $root): array
    {
        $paths = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($iterator as $entry) {
            if (!$entry instanceof SplFileInfo || $entry->isLink() || !$entry->isFile()) {
                continue;
            }

            $paths[] = str_replace('\\', '/', substr($entry->getPathname(), strlen($root) + 1));
        }

        return $paths;
    }

    /**
     * Build one file's entry, reading it only when the depth calls for it.
     *
     * @param list<string> $notes Collected reasons a file was listed but not read, by reference
     */
    private function readFile(string $root, string $path, IngestRequest $request, array &$notes): IngestedFile
    {
        $absolute = $root . '/' . $path;
        $size = (int) @filesize($absolute);
        $mtime = (int) @filemtime($absolute);
        $content = null;
        $symbols = null;

        if ($request->depth->readsContents()) {
            $raw = $this->readContents($path, $absolute, $size, $request, $notes);

            if ($raw !== null) {
                if ($request->depth === Depth::Full) {
                    $content = $raw;
                } elseif ($this->extractor !== null && $this->extractor->supports($path)) {
                    $symbols = $this->extractor->extract($path, $raw);
                }
            }
        }

        $body = $content ?? $symbols;

        return new IngestedFile(
            $path,
            $size,
            $mtime,
            $body === null ? 0 : $this->estimator->estimateTokens($body),
            $content,
            $symbols,
        );
    }

    /**
     * Read a file, or explain in the notes why it was left out.
     *
     * @param list<string> $notes Collected reasons, by reference
     *
     * @return string|null The contents, or null when the file was deliberately not read
     */
    private function readContents(string $path, string $absolute, int $size, IngestRequest $request, array &$notes): ?string
    {
        if (PathFilter::looksBinary($path)) {
            $notes[] = sprintf('%s listed but not read: binary file.', $path);

            return null;
        }

        if ($size > $request->maxFileSize) {
            $notes[] = sprintf('%s listed but not read: %d bytes exceeds the %d byte cap.', $path, $size, $request->maxFileSize);

            return null;
        }

        $raw = @file_get_contents($absolute);

        if ($raw === false) {
            $notes[] = sprintf('%s listed but not read: unreadable.', $path);

            return null;
        }

        if (PathFilter::hasBinaryContent($raw)) {
            $notes[] = sprintf('%s listed but not read: binary content.', $path);

            return null;
        }

        return $raw;
    }

    /**
     * Fingerprint the exact state of the included files.
     *
     * Content-addressed, not mtime-addressed. `touch` must not change the answer, or every
     * consumer's cache is busted for nothing; equally, an edit that happens to preserve the mtime
     * must change it. Inside a repository the tracked, clean files contribute the blob hashes git
     * has already computed, so only the dirty ones are hashed here.
     *
     * @param list<string> $paths Included paths, in path order
     */
    private function fingerprint(string $root, array $paths): string
    {
        $blobs = $this->gitBlobs($root);
        $dirty = $this->gitDirty($root);
        $parts = [];

        foreach ($paths as $path) {
            $parts[] = $path . "\0" . $this->contentHash($root, $path, $blobs, $dirty);
        }

        return hash('sha256', implode("\n", $parts));
    }

    /**
     * @param array<string, string> $blobs Tracked path to git blob hash
     * @param array<string, true>   $dirty Paths whose working copy differs from the index
     */
    private function contentHash(string $root, string $path, array $blobs, array $dirty): string
    {
        if (isset($blobs[$path]) && !isset($dirty[$path])) {
            return $blobs[$path];
        }

        $hash = @hash_file('xxh128', $root . '/' . $path);

        return $hash === false ? 'unreadable' : $hash;
    }

    /**
     * Blob hashes git already knows, keyed by path.
     *
     * @return array<string, string>
     */
    private function gitBlobs(string $root): array
    {
        $lines = $this->git($root, ['ls-files', '--stage']);

        if ($lines === null) {
            return [];
        }

        $blobs = [];

        foreach ($lines as $line) {
            // "100644 <sha> 0\t<path>"
            $parts = explode("\t", $line, 2);
            $fields = explode(' ', $parts[0]);

            if (isset($parts[1], $fields[1])) {
                $blobs[$parts[1]] = $fields[1];
            }
        }

        return $blobs;
    }

    /**
     * Paths whose working copy has moved on from the index, so their blob hash is stale.
     *
     * Uses NUL-separated output: a quoted path here would be read as clean and silently freeze the
     * fingerprint, which is the one failure this must not have.
     *
     * @return array<string, true>
     */
    private function gitDirty(string $root): array
    {
        $entries = $this->git($root, ['status', '--porcelain', '-z'], "\0");

        if ($entries === null) {
            return [];
        }

        $dirty = [];

        foreach ($entries as $entry) {
            if (strlen($entry) > 3) {
                $dirty[substr($entry, 3)] = true;
            }
        }

        return $dirty;
    }

    /**
     * Order files for rendering: highest score first, ties broken by path.
     *
     * @param list<IngestedFile>                 $files       Files in path order
     * @param (Closure(IngestedFile): float)|null $prioritiser Consumer's ranking, if any
     *
     * @return list<IngestedFile>
     */
    private function prioritise(array $files, ?Closure $prioritiser): array
    {
        if ($prioritiser === null) {
            return $files;
        }

        /** @var list<array{score: float, file: IngestedFile}> $scored */
        $scored = array_map(
            static fn (IngestedFile $file): array => ['score' => $prioritiser($file), 'file' => $file],
            $files,
        );

        usort(
            $scored,
            static fn (array $a, array $b): int => ($b['score'] <=> $a['score']) ?: strcmp($a['file']->path, $b['file']->path),
        );

        return array_map(static fn (array $entry): IngestedFile => $entry['file'], $scored);
    }
}
