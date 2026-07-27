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

/**
 * Renders a flat list of paths as the directory tree that opens a digest.
 *
 * Uses the same box-drawing shape gitingest emits, and sorts directories before files with each
 * group in path order, so the same repository state always renders identically.
 */
final class TreeBuilder
{
    /**
     * Render paths as a tree.
     *
     * @param list<string> $paths Paths relative to the ingestion root, forward-slashed
     * @param string       $root  Name to show at the top of the tree
     *
     * @return string The rendered tree, without a trailing newline
     */
    public function build(array $paths, string $root): string
    {
        if ($paths === []) {
            return $root . '/';
        }

        return $root . "/\n" . implode("\n", $this->renderLevel($this->nest($paths), ''));
    }

    /**
     * Turn flat paths into a nested map, with files as null leaves.
     *
     * @param list<string> $paths Paths relative to the ingestion root
     *
     * @return array<string, mixed> Nested directory map
     */
    private function nest(array $paths): array
    {
        $tree = [];

        foreach ($paths as $path) {
            $segments = explode('/', $path);
            $leaf = array_pop($segments);
            $cursor = &$tree;

            foreach ($segments as $segment) {
                if (!isset($cursor[$segment]) || !is_array($cursor[$segment])) {
                    $cursor[$segment] = [];
                }

                $cursor = &$cursor[$segment];
            }

            $cursor[$leaf] = null;
            unset($cursor);
        }

        return $tree;
    }

    /**
     * Render one level of the nested map.
     *
     * @param array<string, mixed> $level  The directory to render
     * @param string               $prefix Continuation characters inherited from ancestors
     *
     * @return list<string> Rendered lines
     */
    private function renderLevel(array $level, string $prefix): array
    {
        $names = $this->sortNames($level);
        $lines = [];
        $last = count($names) - 1;

        foreach ($names as $index => $name) {
            $isLast = $index === $last;
            $child = $level[$name];
            $isDirectory = is_array($child);

            $lines[] = $prefix . ($isLast ? '└── ' : '├── ') . $name . ($isDirectory ? '/' : '');

            if ($isDirectory) {
                /** @var array<string, mixed> $child */
                $lines = array_merge($lines, $this->renderLevel($child, $prefix . ($isLast ? '    ' : '│   ')));
            }
        }

        return $lines;
    }

    /**
     * Directories first, then files, each group in path order.
     *
     * @param array<string, mixed> $level The directory to sort
     *
     * @return list<string> Sorted entry names
     */
    private function sortNames(array $level): array
    {
        $directories = [];
        $files = [];

        foreach ($level as $name => $child) {
            if (is_array($child)) {
                $directories[] = (string) $name;

                continue;
            }

            $files[] = (string) $name;
        }

        sort($directories, SORT_STRING);
        sort($files, SORT_STRING);

        return array_merge($directories, $files);
    }
}
