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

use PapiAI\Ingest\PathFilter;

describe('PathFilter', function () {
    it('keeps everything when no patterns are given', function () {
        $filter = new PathFilter();

        expect($filter->accepts('src/Agent.php'))->toBeTrue();
        expect($filter->accepts('README.md'))->toBeTrue();
    });

    it('applies the default excludes without being asked', function () {
        $filter = new PathFilter();

        expect($filter->accepts('vendor/papi-ai/core/src/Agent.php'))->toBeFalse();
        expect($filter->accepts('node_modules/left-pad/index.js'))->toBeFalse();
        expect($filter->accepts('.git/config'))->toBeFalse();
        expect($filter->accepts('composer.lock'))->toBeFalse();
        expect($filter->accepts('.env.local'))->toBeFalse();
        expect($filter->accepts('app.min.js'))->toBeFalse();
    });

    it('can be told to skip the defaults', function () {
        $filter = new PathFilter([], [], false);

        expect($filter->accepts('vendor/a/b.php'))->toBeTrue();
    });

    it('narrows to the include patterns when given', function () {
        $filter = new PathFilter(['src/*']);

        expect($filter->accepts('src/deep/Agent.php'))->toBeTrue();
        expect($filter->accepts('tests/AgentTest.php'))->toBeFalse();
    });

    it('matches an extension anywhere in the tree', function () {
        $filter = new PathFilter(['*.php']);

        expect($filter->accepts('src/deep/Agent.php'))->toBeTrue();
        expect($filter->accepts('README.md'))->toBeFalse();
    });

    it('applies excludes after includes', function () {
        $filter = new PathFilter(['src/*'], ['src/Generated/*']);

        expect($filter->accepts('src/Agent.php'))->toBeTrue();
        expect($filter->accepts('src/Generated/Stub.php'))->toBeFalse();
    });

    it('reads a trailing slash as everything underneath', function () {
        $filter = new PathFilter([], ['build/']);

        expect($filter->accepts('build/report.html'))->toBeFalse();
        expect($filter->accepts('builder.php'))->toBeTrue();
    });

    it('spots binaries by extension, case insensitively', function () {
        expect(PathFilter::looksBinary('assets/logo.PNG'))->toBeTrue();
        expect(PathFilter::looksBinary('lib/ext.so'))->toBeTrue();
        expect(PathFilter::looksBinary('src/Agent.php'))->toBeFalse();
        expect(PathFilter::looksBinary('LICENSE'))->toBeFalse();
    });

    it('spots binaries by content when the extension says nothing', function () {
        expect(PathFilter::hasBinaryContent("text\0more"))->toBeTrue();
        expect(PathFilter::hasBinaryContent("plain text\nlines"))->toBeFalse();
    });
});
