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
 * How much of each file a digest carries.
 *
 * The three levels trade tokens for detail. `Tree` is the cheapest orientation, `Full` is the
 * whole text, and `Api` sits between them: enough structure to reason about the system without
 * paying for its bodies.
 */
enum Depth: string
{
    /**
     * Paths only. No file contents at all.
     */
    case Tree = 'tree';

    /**
     * Per file, a compact symbol block: what it declares and what it reaches for.
     *
     * The ontology level, and the one agent consumers should default to: the whole system stays
     * legible at a fraction of Full's tokens. Requires a symbol extractor for the language in
     * question (see papi-ai/ingest-php-symbols).
     */
    case Api = 'api';

    /**
     * Complete file contents.
     */
    case Full = 'full';

    /**
     * Whether this depth needs file contents to be read from disk.
     */
    public function readsContents(): bool
    {
        return $this !== self::Tree;
    }
}
