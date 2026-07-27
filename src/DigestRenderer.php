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
 * Turns a digest into the largest useful picture that fits a token budget.
 *
 * Lives here rather than in each adapter on purpose: "as much as fits in N tokens" must mean the
 * same thing whatever produced the digest.
 *
 * The rules, in order:
 *
 *   1. The tree always renders. It is the cheapest orientation and the only thing that shows what
 *      exists, so it is never the thing sacrificed to a budget.
 *   2. Files render in the order the digest holds them, which is priority first, then path.
 *   3. A file that does not fit is skipped and the next one is tried, rather than ending the
 *      render. One large file therefore cannot starve everything below it.
 *   4. Whatever was left out is named. **Never silent truncation**: a prompt that quietly omits
 *      half a repository is worse than one that admits it, because the reader cannot tell.
 *   5. That notice is itself paid for out of the budget, so a long list of omissions cannot push
 *      the total past the number it is reporting on.
 *
 * The only output that can exceed the budget is a tree, plus its notice, that was already too big
 * on its own. Orientation is worth more than an empty string, and the overrun is visible.
 */
final class DigestRenderer
{
    /**
     * How many omitted paths to name individually before falling back to a count.
     */
    private const MAX_NAMED_OMISSIONS = 20;

    public function __construct(
        private readonly TokenEstimatorInterface $estimator = new HeuristicTokenEstimator(),
    ) {
    }

    /**
     * Render a digest as prompt text.
     *
     * @param RepositoryDigest $digest The digest to render
     * @param int|null         $budget Maximum estimated tokens, or null for everything
     *
     * @return string The rendered prompt
     */
    public function render(RepositoryDigest $digest, ?int $budget): string
    {
        $head = RepositoryDigest::TREE_HEADER . "\n" . $digest->tree;
        $spent = $this->estimator->estimateTokens($head);

        $selected = [];
        $omitted = [];

        foreach ($digest->files as $file) {
            $body = $file->bodyFor($digest->depth);

            if ($body === null) {
                continue;
            }

            $block = $this->block($file->path, $body);
            $cost = $this->estimator->estimateTokens($block);

            if ($budget !== null && $spent + $cost > $budget) {
                $omitted[] = $file->path;

                continue;
            }

            $spent += $cost;
            $selected[] = ['block' => $block, 'cost' => $cost, 'path' => $file->path];
        }

        // The trailer costs tokens too, and it grows with every file left out. Counting it only
        // after selection would let a long list of omissions push the total past the very budget
        // it is reporting on, so give back files until the whole thing fits.
        while ($budget !== null && $selected !== [] && $spent + $this->trailerCost($digest, $omitted, $budget) > $budget) {
            $dropped = array_pop($selected);
            $spent -= $dropped['cost'];
            $omitted[] = $dropped['path'];
        }

        $trailer = $this->trailer($digest, $omitted, $budget);

        // Nothing left to give back and still over: name fewer things rather than overrun. The
        // count stays exact, so the reader still knows precisely what they are not seeing.
        if ($budget !== null && $selected === [] && $spent + $this->cost($trailer) > $budget) {
            $trailer = $this->trailer($digest, $omitted, $budget, true);
        }

        $parts = [$head];

        if ($selected !== []) {
            $parts[] = implode('', array_column($selected, 'block'));
        }

        if ($trailer !== '') {
            $parts[] = $trailer;
        }

        return implode("\n\n", $parts) . "\n";
    }

    /**
     * What the trailer will cost, including the blank line joining it on.
     *
     * @param list<string> $omitted Paths dropped so far
     */
    private function trailerCost(RepositoryDigest $digest, array $omitted, ?int $budget): int
    {
        return $this->cost($this->trailer($digest, $omitted, $budget));
    }

    private function cost(string $part): int
    {
        return $part === '' ? 0 : $this->estimator->estimateTokens($part) + 1;
    }

    /**
     * One file block, in gitingest's format so digests interchange.
     */
    private function block(string $path, string $body): string
    {
        return sprintf(
            "%s\nFILE: %s\n%s\n%s\n",
            RepositoryDigest::SEPARATOR,
            $path,
            RepositoryDigest::SEPARATOR,
            $body,
        );
    }

    /**
     * The lines that admit what the reader is not seeing.
     *
     * @param list<string> $omitted Paths dropped to fit the budget
     * @param bool         $compact Report the count alone, when naming the paths will not fit
     */
    private function trailer(RepositoryDigest $digest, array $omitted, ?int $budget, bool $compact = false): string
    {
        $lines = [];

        foreach ($digest->notes as $note) {
            $lines[] = RepositoryDigest::NOTE_PREFIX . $note;
        }

        if ($omitted !== []) {
            sort($omitted, SORT_STRING);

            $lines[] = RepositoryDigest::NOTE_PREFIX . sprintf(
                '%d of %d file(s) omitted to fit a %d token budget%s',
                count($omitted),
                count($digest->files),
                (int) $budget,
                $compact ? '.' : ': ' . $this->nameOmissions($omitted),
            );
        }

        return implode("\n", $lines);
    }

    /**
     * Name the omitted paths, capping the list but never the count.
     *
     * @param list<string> $omitted Paths dropped to fit the budget
     */
    private function nameOmissions(array $omitted): string
    {
        if (count($omitted) <= self::MAX_NAMED_OMISSIONS) {
            return implode(', ', $omitted);
        }

        $named = array_slice($omitted, 0, self::MAX_NAMED_OMISSIONS);

        return sprintf(
            '%s and %d more',
            implode(', ', $named),
            count($omitted) - self::MAX_NAMED_OMISSIONS,
        );
    }
}
