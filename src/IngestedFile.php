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

/**
 * One file as it appears in a digest.
 *
 * Immutable. What `content` and `symbols` hold depends on the depth the digest was built at:
 * both are null at `Depth::Tree`, `symbols` is filled at `Depth::Api`, `content` at `Depth::Full`.
 * A file that was discovered but deliberately not read (binary, oversized) keeps its path, size
 * and token estimate so it still appears in the tree, with a note explaining the omission.
 */
final class IngestedFile
{
    /**
     * @param string      $path    Path relative to the ingestion root, always forward-slashed
     * @param int         $size    Size in bytes
     * @param int         $mtime   Last modification time as a Unix timestamp
     * @param int         $tokens  Estimated tokens this file contributes at the digest's depth
     * @param string|null $content Full text, or null when not read at this depth
     * @param string|null $symbols Symbol block, or null when not extracted at this depth
     */
    public function __construct(
        public readonly string $path,
        public readonly int $size,
        public readonly int $mtime,
        public readonly int $tokens,
        public readonly ?string $content = null,
        public readonly ?string $symbols = null,
    ) {
    }

    /**
     * The text this file contributes to a rendered prompt at the given depth.
     *
     * @return string|null The renderable body, or null when the file has nothing to add
     */
    public function bodyFor(Depth $depth): ?string
    {
        return match ($depth) {
            Depth::Tree => null,
            Depth::Api => $this->symbols,
            Depth::Full => $this->content,
        };
    }
}
