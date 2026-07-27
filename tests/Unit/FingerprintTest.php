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

use PapiAI\Ingest\IngestRequest;
use PapiAI\Ingest\NativeIngestor;

/**
 * The fingerprint exists so consumers can cache a digest. That makes both halves of its promise
 * load-bearing: it must move when included content moves, and it must sit still otherwise.
 */
describe('fingerprint', function () {
    beforeEach(function () {
        $this->ingestor = new NativeIngestor();
        $this->root = makeFixture([
            'a.php' => '<?php // one',
            'b.php' => '<?php // two',
            'notes.md' => 'notes',
        ]);
        $this->fingerprint = fn (array $include = []) => $this->ingestor
            ->ingest(new IngestRequest($this->root, include: $include))
            ->fingerprint;
    });

    afterEach(function () {
        removeFixture($this->root);
    });

    it('is stable across ingests of unchanged state', function () {
        expect(($this->fingerprint)())->toBe(($this->fingerprint)());
    });

    it('changes when an included file changes', function () {
        $before = ($this->fingerprint)();
        file_put_contents($this->root . '/a.php', '<?php // edited');

        expect(($this->fingerprint)())->not->toBe($before);
    });

    it('does not change when a file is merely touched', function () {
        $before = ($this->fingerprint)();
        touch($this->root . '/a.php', time() + 3600);

        expect(($this->fingerprint)())->toBe($before);
    });

    it('does not change when a file outside the include set changes', function () {
        $before = ($this->fingerprint)(['*.php']);
        file_put_contents($this->root . '/notes.md', 'completely different notes');

        expect(($this->fingerprint)(['*.php']))->toBe($before);
    });

    it('changes when an included file is added', function () {
        $before = ($this->fingerprint)();
        file_put_contents($this->root . '/c.php', '<?php // three');

        expect(($this->fingerprint)())->not->toBe($before);
    });

    it('changes when an included file is removed', function () {
        $before = ($this->fingerprint)();
        unlink($this->root . '/b.php');

        expect(($this->fingerprint)())->not->toBe($before);
    });
});

describe('fingerprint inside a git repository', function () {
    beforeEach(function () {
        if (!gitIsAvailable()) {
            $this->markTestSkipped('git is not available');
        }

        $this->ingestor = new NativeIngestor();
        $this->root = makeFixture([
            'a.php' => '<?php // one',
            'b.php' => '<?php // two',
        ]);
        initGitFixture($this->root);
        $this->fingerprint = fn () => $this->ingestor->ingest(new IngestRequest($this->root))->fingerprint;
    });

    afterEach(function () {
        removeFixture($this->root);
    });

    it('reuses git blob hashes without changing the answer', function () {
        expect(($this->fingerprint)())->toBe(($this->fingerprint)());
    });

    it('still moves when a tracked file is edited', function () {
        $before = ($this->fingerprint)();
        file_put_contents($this->root . '/a.php', '<?php // edited');

        expect(($this->fingerprint)())->not->toBe($before);
    });

    it('still sits still when a tracked file is only touched', function () {
        $before = ($this->fingerprint)();
        touch($this->root . '/a.php', time() + 3600);

        expect(($this->fingerprint)())->toBe($before);
    });

    it('moves when an untracked file appears', function () {
        $before = ($this->fingerprint)();
        file_put_contents($this->root . '/c.php', '<?php // new and untracked');

        expect(($this->fingerprint)())->not->toBe($before);
    });
});

describe('git-aware discovery', function () {
    beforeEach(function () {
        if (!gitIsAvailable()) {
            $this->markTestSkipped('git is not available');
        }

        $this->ingestor = new NativeIngestor();
        $this->root = makeFixture([
            '.gitignore' => "ignored.txt\nbuild/\n",
            'a.php' => '<?php',
            'ignored.txt' => 'should never be ingested',
            'build/artifact.txt' => 'generated',
        ]);
        initGitFixture($this->root);
        $this->paths = fn (bool $respect) => array_map(
            static fn ($file) => $file->path,
            $this->ingestor->ingest(new IngestRequest($this->root, respectGitignore: $respect))->files,
        );
    });

    afterEach(function () {
        removeFixture($this->root);
    });

    it('defers to git, so nested and global ignore rules are honoured for free', function () {
        $paths = ($this->paths)(true);

        expect($paths)->not->toContain('ignored.txt');
        expect($paths)->not->toContain('build/artifact.txt');
        expect($paths)->toContain('a.php');
    });

    it('finds untracked files that are not ignored', function () {
        file_put_contents($this->root . '/fresh.php', '<?php // never committed');

        expect(($this->paths)(true))->toContain('fresh.php');
    });

    it('walks the directory instead when told not to respect gitignore', function () {
        $paths = ($this->paths)(false);

        expect($paths)->toContain('ignored.txt');
        expect($paths)->toContain('build/artifact.txt');
    });
});
