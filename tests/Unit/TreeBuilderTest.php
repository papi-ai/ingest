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

use PapiAI\Ingest\TreeBuilder;

describe('TreeBuilder', function () {
    beforeEach(function () {
        $this->builder = new TreeBuilder();
    });

    it('renders just the root when there are no files', function () {
        expect($this->builder->build([], 'repo'))->toBe('repo/');
    });

    it('renders a flat list', function () {
        expect($this->builder->build(['b.md', 'a.php'], 'repo'))->toBe(
            "repo/\n" .
            "├── a.php\n" .
            '└── b.md'
        );
    });

    it('nests directories and marks the last entry of each level', function () {
        $tree = $this->builder->build(['src/Agent.php', 'src/Tool.php', 'README.md'], 'repo');

        expect($tree)->toBe(
            "repo/\n" .
            "├── src/\n" .
            "│   ├── Agent.php\n" .
            "│   └── Tool.php\n" .
            '└── README.md'
        );
    });

    it('puts directories before files at every level', function () {
        $tree = $this->builder->build(['z.php', 'a/b.php'], 'repo');

        expect($tree)->toBe(
            "repo/\n" .
            "├── a/\n" .
            "│   └── b.php\n" .
            '└── z.php'
        );
    });

    it('renders the same tree whatever order the paths arrive in', function () {
        $paths = ['src/b.php', 'a.md', 'src/deep/c.php'];

        expect($this->builder->build($paths, 'repo'))
            ->toBe($this->builder->build(array_reverse($paths), 'repo'));
    });
});
