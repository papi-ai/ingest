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

use PapiAI\Core\Contracts\TokenEstimatorInterface;
use PapiAI\Core\HeuristicTokenEstimator;

/**
 * Everything an ingestor found, plus the renderers that turn it into a prompt.
 *
 * Ingestion is not lossy: the digest carries every file discovered at the requested depth, and the
 * token budget is applied when rendering. That split is what makes the fingerprint useful, since a
 * cached digest can be re-rendered at any budget without touching the filesystem again.
 *
 * `files` arrives in render order: highest priority first, ties broken by path, so output is
 * reproducible.
 */
final class RepositoryDigest
{
    /**
     * The rule gitingest puts around each file block. Matching it byte for byte is deliberate:
     * digests stay interchangeable with the wider ecosystem.
     */
    public const SEPARATOR = '================================================';

    /**
     * The line gitingest opens its tree block with.
     */
    public const TREE_HEADER = 'Directory structure:';

    /**
     * Prefix for anything the renderer needs to tell the reader about, so a model can tell a note
     * from the repository's own content.
     */
    public const NOTE_PREFIX = 'NOTE: ';

    /**
     * @param string             $fingerprint  Changes when any included file changes, and not otherwise
     * @param string             $tree         Rendered directory listing
     * @param list<IngestedFile> $files        Discovered files, already in render order
     * @param list<string>       $notes        What was skipped or truncated, and why
     * @param int                $totalTokens  Estimated tokens for the whole digest, unbudgeted
     * @param Depth              $depth        The depth this digest was built at
     * @param int|null           $tokenBudget  Default budget carried from the request, null for none
     * @param TokenEstimatorInterface $estimator Sizes rendered output
     */
    public function __construct(
        public readonly string $fingerprint,
        public readonly string $tree,
        public readonly array $files,
        public readonly array $notes = [],
        public readonly int $totalTokens = 0,
        public readonly Depth $depth = Depth::Tree,
        public readonly ?int $tokenBudget = null,
        public readonly TokenEstimatorInterface $estimator = new HeuristicTokenEstimator(),
    ) {
    }

    /**
     * Render at the budget the request asked for, or in full when it asked for none.
     *
     * The everyday call.
     */
    public function toPrompt(): string
    {
        return $this->renderer()->render($this, $this->tokenBudget);
    }

    /**
     * Render within an explicit budget, ignoring the one the request carried.
     *
     * @param int $budget Maximum estimated tokens for the whole prompt
     */
    public function toPromptWithin(int $budget): string
    {
        return $this->renderer()->render($this, $budget);
    }

    /**
     * Render everything, whatever budget the request carried.
     */
    public function toFullPrompt(): string
    {
        return $this->renderer()->render($this, null);
    }

    /**
     * Whether anything was discovered at all.
     */
    public function isEmpty(): bool
    {
        return $this->files === [];
    }

    private function renderer(): DigestRenderer
    {
        return new DigestRenderer($this->estimator);
    }
}
