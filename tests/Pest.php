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

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
*/

// Uses the default PHPUnit test case for all tests in this directory

/*
|--------------------------------------------------------------------------
| Fixtures
|--------------------------------------------------------------------------
|
| Repositories are built on disk at run time rather than committed, so a fixture
| never has to be a git repository nested inside this one.
|
*/

/**
 * Create a throwaway directory containing the given files.
 *
 * @param array<string, string> $files Relative path to contents
 *
 * @return string Absolute path to the new directory
 */
function makeFixture(array $files): string
{
    $root = sys_get_temp_dir() . '/papi-ingest-' . bin2hex(random_bytes(6));
    mkdir($root, 0o777, true);

    foreach ($files as $path => $contents) {
        $absolute = $root . '/' . $path;
        $directory = dirname($absolute);

        if (!is_dir($directory)) {
            mkdir($directory, 0o777, true);
        }

        file_put_contents($absolute, $contents);
    }

    return $root;
}

/**
 * Turn a fixture directory into a git repository with everything committed.
 *
 * @param string $root Absolute path to the fixture
 */
function initGitFixture(string $root): void
{
    $commands = [
        ['git', '-C', $root, 'init', '--quiet', '--initial-branch=main'],
        ['git', '-C', $root, 'add', '-A'],
        [
            'git', '-C', $root,
            '-c', 'user.email=test@example.com',
            '-c', 'user.name=Ingest Fixture',
            '-c', 'commit.gpgsign=false',
            'commit', '--quiet', '-m', 'fixture',
        ],
    ];

    foreach ($commands as $command) {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        if (is_resource($process)) {
            stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }
    }
}

/**
 * Recursively delete a fixture directory.
 *
 * @param string $root Absolute path to remove
 */
function removeFixture(?string $root): void
{
    if ($root === null || !is_dir($root)) {
        return;
    }

    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($entries as $entry) {
        if (!$entry instanceof SplFileInfo) {
            continue;
        }

        if ($entry->isDir() && !$entry->isLink()) {
            rmdir($entry->getPathname());

            continue;
        }

        unlink($entry->getPathname());
    }

    rmdir($root);
}

/**
 * Whether git is available to run fixture repositories.
 */
function gitIsAvailable(): bool
{
    $process = proc_open(['git', '--version'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

    if (!is_resource($process)) {
        return false;
    }

    // Drain before closing: closing a pipe git is still writing to kills it with a broken pipe,
    // and a non-zero exit would read as "git is missing".
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return proc_close($process) === 0;
}
