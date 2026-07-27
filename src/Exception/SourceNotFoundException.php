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

/**
 * Thrown when the requested source cannot be reached.
 *
 * A missing local path is an error, not an empty digest: silently ingesting nothing would look
 * exactly like a repository with no files.
 */
class SourceNotFoundException extends IngestException
{
    /**
     * @param string $source The source that could not be read
     */
    public static function forSource(string $source): self
    {
        return new self(sprintf('Cannot ingest "%s": no such directory.', $source));
    }
}
