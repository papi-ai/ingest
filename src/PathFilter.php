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
 * Decides which discovered paths belong in a digest.
 *
 * Include patterns narrow, exclude patterns then remove, and a default exclude list keeps the
 * noise out without the caller having to remember it. Patterns are shell globs matched against the
 * path relative to the ingestion root, so `*` spans directory separators: `src/*` catches
 * `src/a/b.php`, and `*.php` catches a PHP file anywhere.
 *
 * A pattern ending in `/` is treated as "everything under here".
 */
final class PathFilter
{
    /**
     * Directories and files that are never worth spending tokens on.
     *
     * @var list<string>
     */
    public const DEFAULT_EXCLUDES = [
        '.git/',
        'vendor/',
        'node_modules/',
        'composer.lock',
        'package-lock.json',
        'yarn.lock',
        'pnpm-lock.yaml',
        '.env',
        '.env.*',
        '*.min.js',
        '*.min.css',
        '.DS_Store',
    ];

    /**
     * Extensions we refuse to read on sight, before any content sniffing.
     *
     * @var list<string>
     */
    public const BINARY_EXTENSIONS = [
        'png', 'jpg', 'jpeg', 'gif', 'bmp', 'ico', 'webp', 'tiff', 'svgz',
        'pdf', 'zip', 'gz', 'tar', 'bz2', 'xz', '7z', 'rar',
        'mp3', 'mp4', 'wav', 'avi', 'mov', 'mkv', 'webm', 'ogg', 'flac',
        'ttf', 'otf', 'woff', 'woff2', 'eot',
        'so', 'dll', 'dylib', 'exe', 'bin', 'class', 'jar', 'pyc', 'o', 'a',
        'sqlite', 'db', 'phar',
    ];

    /** @var list<string> */
    private readonly array $include;

    /** @var list<string> */
    private readonly array $exclude;

    /**
     * @param list<string> $include     Patterns to keep; empty keeps everything
     * @param list<string> $exclude     Patterns to drop, applied after include
     * @param bool         $useDefaults Also apply DEFAULT_EXCLUDES
     */
    public function __construct(array $include = [], array $exclude = [], bool $useDefaults = true)
    {
        $normalise = static fn (string $pattern): string => self::normalise($pattern);

        $this->include = array_map($normalise, $include);
        $this->exclude = array_map(
            $normalise,
            $useDefaults ? array_merge($exclude, self::DEFAULT_EXCLUDES) : $exclude,
        );
    }

    /**
     * Whether this path survives the include and exclude rules.
     *
     * @param string $path Path relative to the ingestion root, forward-slashed
     */
    public function accepts(string $path): bool
    {
        if ($this->include !== [] && !$this->matchesAny($path, $this->include)) {
            return false;
        }

        return !$this->matchesAny($path, $this->exclude);
    }

    /**
     * Whether the extension alone marks this as something we should not read.
     *
     * @param string $path Path relative to the ingestion root
     */
    public static function looksBinary(string $path): bool
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return $extension !== '' && in_array($extension, self::BINARY_EXTENSIONS, true);
    }

    /**
     * Whether the contents contain a null byte, the usual tell for binary data.
     *
     * Catches extensionless binaries that {@see looksBinary()} cannot.
     *
     * @param string $contents The bytes read from disk
     */
    public static function hasBinaryContent(string $contents): bool
    {
        return str_contains(substr($contents, 0, 8000), "\0");
    }

    /**
     * Expand a directory-shaped pattern into one that matches its contents.
     */
    private static function normalise(string $pattern): string
    {
        return str_ends_with($pattern, '/') ? $pattern . '*' : $pattern;
    }

    /**
     * @param list<string> $patterns Normalised glob patterns
     */
    private function matchesAny(string $path, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (fnmatch($pattern, $path)) {
                return true;
            }
        }

        return false;
    }
}
