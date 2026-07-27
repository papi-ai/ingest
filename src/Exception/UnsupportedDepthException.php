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

namespace PapiAI\Ingest\Exception;

use PapiAI\Ingest\Depth;

/**
 * Thrown when a depth was asked for that this ingestor cannot produce.
 *
 * Notably `Depth::Api` without a symbol extractor. Quietly falling back to `Tree` would hand the
 * caller a digest that looks complete and is missing the thing they asked for, so it fails loudly
 * and names the package that fixes it.
 */
class UnsupportedDepthException extends IngestException
{
    /**
     * @param Depth  $depth      The depth that could not be served
     * @param string $suggestion Package or action that would make it available
     */
    public static function needsExtractor(Depth $depth, string $suggestion): self
    {
        return new self(sprintf(
            'Depth "%s" needs a symbol extractor, and none was provided. Install %s and pass its extractor to the ingestor.',
            $depth->value,
            $suggestion,
        ));
    }
}
