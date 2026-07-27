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

use PapiAI\Ingest\Exception\IngestException;
use PapiAI\Ingest\IngestRequest;
use PapiAI\Ingest\RepositoryDigest;

/**
 * Contract for turning a repository into a digest an LLM can read.
 *
 * **Deterministic by definition.** No implementation may call a model, sample, or otherwise vary
 * its output: the same repository state and the same request must produce the same digest, or the
 * fingerprint is worthless and consumers cannot cache. Summarising is the caller's job, downstream.
 *
 * Implementations differ in what they can reach (local filesystem, remote URL) and in what they
 * can see (plain text, parsed symbols), never in the shape of what they return.
 */
interface RepositoryIngestorInterface
{
    /**
     * Ingest a repository into a digest.
     *
     * The digest holds everything discovered at the requested depth; applying the token budget is
     * a rendering concern, so nothing is dropped here.
     *
     * @param IngestRequest $request What to ingest and how deep
     *
     * @return RepositoryDigest The tree, the files, and a fingerprint of the state they came from
     *
     * @throws IngestException When the source cannot be read, or the depth cannot be served
     */
    public function ingest(IngestRequest $request): RepositoryDigest;

    /**
     * Whether this ingestor can serve the request at all.
     *
     * Lets a caller route between adapters (local walker, remote CLI) without catching exceptions
     * to find out. A false answer is a routing signal, not an error.
     *
     * @param IngestRequest $request The request to test
     *
     * @return bool True when `ingest()` could be called with this request
     */
    public function supports(IngestRequest $request): bool;
}
