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

/**
 * What to ingest, how deep, and how much of it to render.
 *
 * Immutable. Everything an ingestor needs arrives in one value object rather than an options
 * array, so the shape is checked once here instead of at every call site.
 *
 * The prioritiser is where a consumer expresses what matters right now: return a higher float and
 * the file renders sooner, so it survives a tight budget. Boosting recently modified files, or
 * files near the task in hand, is the common use. Map plus heat.
 */
final class IngestRequest
{
    /**
     * Files larger than this are listed and noted, never read.
     */
    public const DEFAULT_MAX_FILE_SIZE = 512_000;

    /**
     * @param string                            $source           Local path, or a remote repository URL
     * @param Depth                             $depth            How much of each file to carry
     * @param int|null                          $tokenBudget      Default budget for rendering, null for no limit
     * @param list<string>                      $include          Glob patterns to keep; empty means keep everything
     * @param list<string>                      $exclude          Glob patterns to drop, applied after include
     * @param bool                              $respectGitignore Honour .gitignore when discovering files
     * @param (Closure(IngestedFile): float)|null $prioritiser    Higher scores render first; ties break by path
     * @param int                               $maxFileSize      Byte ceiling above which a file is listed but not read
     */
    public function __construct(
        public readonly string $source,
        public readonly Depth $depth = Depth::Tree,
        public readonly ?int $tokenBudget = null,
        public readonly array $include = [],
        public readonly array $exclude = [],
        public readonly bool $respectGitignore = true,
        public readonly ?Closure $prioritiser = null,
        public readonly int $maxFileSize = self::DEFAULT_MAX_FILE_SIZE,
    ) {
    }

    /**
     * Whether the source points at a remote repository rather than the local filesystem.
     *
     * Used by `supports()` to route a request to the adapter that can serve it.
     */
    public function isRemote(): bool
    {
        return preg_match('#^(https?|git|ssh)://#i', $this->source) === 1
            || preg_match('#^[\w.-]+@[\w.-]+:#', $this->source) === 1;
    }
}
