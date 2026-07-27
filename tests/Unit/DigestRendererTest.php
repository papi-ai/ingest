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

use PapiAI\Core\HeuristicTokenEstimator;
use PapiAI\Ingest\Depth;
use PapiAI\Ingest\DigestRenderer;
use PapiAI\Ingest\IngestedFile;
use PapiAI\Ingest\RepositoryDigest;

/**
 * @param list<IngestedFile> $files
 * @param list<string>       $notes
 */
function digestOf(array $files, Depth $depth = Depth::Full, array $notes = [], ?int $budget = null): RepositoryDigest
{
    return new RepositoryDigest('fp', "repo/\n└── a.php", $files, $notes, 0, $depth, $budget);
}

describe('DigestRenderer', function () {
    beforeEach(function () {
        $this->renderer = new DigestRenderer();
        $this->small = new IngestedFile('a.php', 40, 0, 10, str_repeat('a', 40));
        $this->large = new IngestedFile('b.php', 4000, 0, 1000, str_repeat('b', 4000));
    });

    it('always renders the tree, whatever the budget', function () {
        $rendered = $this->renderer->render(digestOf([$this->large]), 1);

        expect($rendered)->toContain(RepositoryDigest::TREE_HEADER);
        expect($rendered)->toContain('└── a.php');
    });

    it('renders every body when the budget is null', function () {
        $rendered = $this->renderer->render(digestOf([$this->small, $this->large]), null);

        expect($rendered)->toContain('FILE: a.php');
        expect($rendered)->toContain('FILE: b.php');
        expect($rendered)->not->toContain('omitted');
    });

    it('uses gitingest block framing so digests interchange', function () {
        $rendered = $this->renderer->render(digestOf([$this->small]), null);

        expect($rendered)->toContain(
            RepositoryDigest::SEPARATOR . "\nFILE: a.php\n" . RepositoryDigest::SEPARATOR . "\n"
        );
    });

    it('drops what does not fit and names it', function () {
        $rendered = $this->renderer->render(digestOf([$this->small, $this->large]), 100);

        expect($rendered)->toContain('FILE: a.php');
        expect($rendered)->not->toContain('FILE: b.php');
        expect($rendered)->toContain('1 of 2 file(s) omitted to fit a 100 token budget: b.php');
    });

    it('skips an oversized file rather than stopping, so it cannot starve the rest', function () {
        // Large first: a renderer that stopped at the first misfit would lose the small file too.
        $rendered = $this->renderer->render(digestOf([$this->large, $this->small]), 100);

        expect($rendered)->toContain('FILE: a.php');
        expect($rendered)->not->toContain('FILE: b.php');
    });

    it('renders no bodies at all at Tree depth', function () {
        $rendered = $this->renderer->render(digestOf([$this->small], Depth::Tree), null);

        expect($rendered)->not->toContain('FILE: a.php');
        expect($rendered)->toContain(RepositoryDigest::TREE_HEADER);
    });

    it('renders symbol blocks at Api depth and ignores full content', function () {
        $file = new IngestedFile('a.php', 40, 0, 5, 'FULL BODY', 'class A {}');
        $rendered = $this->renderer->render(digestOf([$file], Depth::Api), null);

        expect($rendered)->toContain('class A {}');
        expect($rendered)->not->toContain('FULL BODY');
    });

    it('surfaces the digest notes', function () {
        $rendered = $this->renderer->render(digestOf([], Depth::Full, ['logo.png listed but not read: binary file.']), null);

        expect($rendered)->toContain(RepositoryDigest::NOTE_PREFIX . 'logo.png listed but not read: binary file.');
    });

    it('caps the named omissions but never the count', function () {
        $files = [];

        for ($i = 0; $i < 25; ++$i) {
            $files[] = new IngestedFile(sprintf('f%02d.php', $i), 4000, 0, 1000, str_repeat('x', 4000));
        }

        // Room for the notice, but not for any of the files.
        $rendered = $this->renderer->render(digestOf($files), 200);

        expect($rendered)->toContain('25 of 25 file(s) omitted');
        expect($rendered)->toContain('and 5 more');
    });

    it('falls back to the count alone when even naming the omissions will not fit', function () {
        $files = [];

        for ($i = 0; $i < 25; ++$i) {
            $files[] = new IngestedFile(sprintf('src/very/long/path/f%02d.php', $i), 4000, 0, 1000, str_repeat('x', 4000));
        }

        $rendered = $this->renderer->render(digestOf($files), 30);

        expect($rendered)->toContain('25 of 25 file(s) omitted to fit a 30 token budget.');
        expect($rendered)->not->toContain('f00.php:');
    });

    it('renders identically for identical input', function () {
        $digest = digestOf([$this->small, $this->large]);

        expect($this->renderer->render($digest, 200))->toBe($this->renderer->render($digest, 200));
    });

    it('pays for the omission notice out of the budget, not on top of it', function () {
        // Regression: with enough omissions the notice is hundreds of tokens on its own, and
        // counting it after selection pushed the total past the very budget it reported.
        $estimator = new HeuristicTokenEstimator();
        $files = [];

        for ($i = 0; $i < 60; ++$i) {
            $files[] = new IngestedFile(
                sprintf('src/Some/Rather/Long/Path/File%02d.php', $i),
                800,
                0,
                200,
                str_repeat('x', 800),
            );
        }

        foreach ([100, 500, 1000, 2000] as $budget) {
            $rendered = $this->renderer->render(digestOf($files), $budget);

            expect($estimator->estimateTokens($rendered))->toBeLessThanOrEqual($budget);
            expect($rendered)->toContain('omitted to fit a ' . $budget . ' token budget');
        }
    });

    it('still renders the tree when even that will not fit, and the overrun is visible', function () {
        $rendered = $this->renderer->render(digestOf([$this->large]), 1);

        expect($rendered)->toContain('└── a.php');
        expect($rendered)->toContain('omitted');
    });
});

describe('RepositoryDigest rendering entry points', function () {
    beforeEach(function () {
        $this->file = new IngestedFile('a.php', 4000, 0, 1000, str_repeat('a', 4000));
    });

    it('toPrompt honours the budget the request carried', function () {
        expect(digestOf([$this->file], Depth::Full, [], 20)->toPrompt())->toContain('omitted');
    });

    it('toPrompt renders everything when the request carried no budget', function () {
        expect(digestOf([$this->file])->toPrompt())->toContain('FILE: a.php');
    });

    it('toPromptWithin overrides the budget the request carried', function () {
        expect(digestOf([$this->file], Depth::Full, [], 100_000)->toPromptWithin(20))->toContain('omitted');
    });

    it('toFullPrompt ignores the budget the request carried', function () {
        expect(digestOf([$this->file], Depth::Full, [], 20)->toFullPrompt())->toContain('FILE: a.php');
    });

    it('knows when it found nothing', function () {
        expect(digestOf([])->isEmpty())->toBeTrue();
        expect(digestOf([$this->file])->isEmpty())->toBeFalse();
    });
});
