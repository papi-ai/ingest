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

namespace PapiAI\Ingest\Contracts;

/**
 * Contract for reducing one source file to the symbols it declares.
 *
 * This is what makes `Depth::Api` possible: the shape of a file (what it declares, what it
 * extends, what it calls for) without its bodies. Deliberately one method, so an ingestor can
 * compose an extractor without depending on a parser, and a language implementation can be added
 * without touching discovery, filtering, or rendering.
 *
 * Implementations are per language and must stay deterministic. The reference implementation for
 * PHP is `papi-ai/ingest-php-symbols`, built on nikic/php-parser.
 */
interface SymbolExtractorInterface
{
    /**
     * Extract a compact symbol block for a single file.
     *
     * @param string $path   Path relative to the ingestion root, used to decide language and for labelling
     * @param string $source The file's full text
     *
     * @return string|null The symbol block, or null when this extractor does not handle the file
     */
    public function extract(string $path, string $source): ?string;

    /**
     * Whether this extractor handles the given path, judged on the path alone.
     *
     * Lets an ingestor skip reading a file it could not summarise anyway.
     *
     * @param string $path Path relative to the ingestion root
     *
     * @return bool True when `extract()` could return a block for this path
     */
    public function supports(string $path): bool;
}
