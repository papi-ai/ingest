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

use PapiAI\Ingest\Contracts\SymbolExtractorInterface;
use PapiAI\Ingest\Depth;
use PapiAI\Ingest\Exception\SourceNotFoundException;
use PapiAI\Ingest\Exception\UnsupportedDepthException;
use PapiAI\Ingest\IngestedFile;
use PapiAI\Ingest\IngestRequest;
use PapiAI\Ingest\NativeIngestor;
use PapiAI\Ingest\Testing\IngestorContract;

/**
 * Stands in for a real parser so Api depth can be tested without one.
 */
class StubSymbolExtractor implements SymbolExtractorInterface
{
    public function extract(string $path, string $source): ?string
    {
        return sprintf('SYMBOLS(%s)', $path);
    }

    public function supports(string $path): bool
    {
        return str_ends_with($path, '.php');
    }
}

/** @return list<string> */
function pathsOf(PapiAI\Ingest\RepositoryDigest $digest): array
{
    $paths = array_map(static fn (IngestedFile $file): string => $file->path, $digest->files);
    sort($paths, SORT_STRING);

    return $paths;
}

describe('NativeIngestor', function () {
    beforeEach(function () {
        $this->ingestor = new NativeIngestor();
        $this->root = makeFixture([
            'README.md' => '# Fixture',
            'src/Agent.php' => '<?php class Agent {}',
            'src/Tool.php' => '<?php class Tool {}',
            'vendor/lib/Dep.php' => '<?php class Dep {}',
        ]);
    });

    afterEach(function () {
        removeFixture($this->root);
    });

    it('discovers files and applies the default excludes', function () {
        $digest = $this->ingestor->ingest(new IngestRequest($this->root));

        expect(pathsOf($digest))->toBe(['README.md', 'src/Agent.php', 'src/Tool.php']);
    });

    it('supports local directories and refuses remote sources', function () {
        expect($this->ingestor->supports(new IngestRequest($this->root)))->toBeTrue();
        expect($this->ingestor->supports(new IngestRequest('https://github.com/papi-ai/papi-core')))->toBeFalse();
    });

    it('throws for a missing path rather than returning an empty digest', function () {
        expect(fn () => $this->ingestor->ingest(new IngestRequest($this->root . '/nope')))
            ->toThrow(SourceNotFoundException::class);
    });

    it('throws for a remote source it cannot reach', function () {
        expect(fn () => $this->ingestor->ingest(new IngestRequest('https://github.com/papi-ai/papi-core')))
            ->toThrow(SourceNotFoundException::class);
    });

    it('reads nothing at Tree depth', function () {
        $digest = $this->ingestor->ingest(new IngestRequest($this->root, Depth::Tree));

        foreach ($digest->files as $file) {
            expect($file->content)->toBeNull();
            expect($file->symbols)->toBeNull();
            expect($file->tokens)->toBe(0);
        }
    });

    it('reads contents at Full depth and estimates their tokens', function () {
        $digest = $this->ingestor->ingest(new IngestRequest($this->root, Depth::Full));
        $agent = current(array_filter($digest->files, fn (IngestedFile $f) => $f->path === 'src/Agent.php'));

        expect($agent->content)->toBe('<?php class Agent {}');
        expect($agent->tokens)->toBe(5);
    });

    it('still reports size and mtime at Tree depth, so a prioritiser has something to work with', function () {
        $digest = $this->ingestor->ingest(new IngestRequest($this->root, Depth::Tree));

        foreach ($digest->files as $file) {
            expect($file->size)->toBeGreaterThan(0);
            expect($file->mtime)->toBeGreaterThan(0);
        }
    });

    describe('Api depth', function () {
        it('throws when asked for Api depth with no extractor, naming the fix', function () {
            expect(fn () => $this->ingestor->ingest(new IngestRequest($this->root, Depth::Api)))
                ->toThrow(UnsupportedDepthException::class, 'papi-ai/ingest-php-symbols');
        });

        it('fills symbols from the injected extractor', function () {
            $ingestor = new NativeIngestor(new StubSymbolExtractor());
            $digest = $ingestor->ingest(new IngestRequest($this->root, Depth::Api));
            $agent = current(array_filter($digest->files, fn (IngestedFile $f) => $f->path === 'src/Agent.php'));

            expect($agent->symbols)->toBe('SYMBOLS(src/Agent.php)');
            expect($agent->content)->toBeNull();
        });

        it('leaves files the extractor does not handle without symbols', function () {
            $ingestor = new NativeIngestor(new StubSymbolExtractor());
            $digest = $ingestor->ingest(new IngestRequest($this->root, Depth::Api));
            $readme = current(array_filter($digest->files, fn (IngestedFile $f) => $f->path === 'README.md'));

            expect($readme->symbols)->toBeNull();
            expect(pathsOf($digest))->toContain('README.md');
        });
    });

    describe('files it will not read', function () {
        it('lists a binary but never reads it, and says so', function () {
            $root = makeFixture(['logo.png' => "\x89PNG\r\n\x1a\n binary", 'a.php' => '<?php']);
            $digest = (new NativeIngestor())->ingest(new IngestRequest($root, Depth::Full));
            $logo = current(array_filter($digest->files, fn (IngestedFile $f) => $f->path === 'logo.png'));

            expect($logo->content)->toBeNull();
            expect($digest->notes)->toContain('logo.png listed but not read: binary file.');
            expect(pathsOf($digest))->toContain('logo.png');

            removeFixture($root);
        });

        it('spots binary content behind an innocent extension', function () {
            $root = makeFixture(['data.txt' => "text\0binary"]);
            $digest = (new NativeIngestor())->ingest(new IngestRequest($root, Depth::Full));

            expect($digest->notes)->toContain('data.txt listed but not read: binary content.');

            removeFixture($root);
        });

        it('lists an oversized file and reports the cap it broke', function () {
            $root = makeFixture(['big.txt' => str_repeat('x', 2000)]);
            $digest = (new NativeIngestor())->ingest(new IngestRequest($root, Depth::Full, maxFileSize: 1000));

            expect($digest->notes)->toContain('big.txt listed but not read: 2000 bytes exceeds the 1000 byte cap.');

            removeFixture($root);
        });
    });

    describe('filters', function () {
        it('honours include patterns', function () {
            $digest = $this->ingestor->ingest(new IngestRequest($this->root, include: ['src/*']));

            expect(pathsOf($digest))->toBe(['src/Agent.php', 'src/Tool.php']);
        });

        it('honours exclude patterns', function () {
            $digest = $this->ingestor->ingest(new IngestRequest($this->root, exclude: ['*.md']));

            expect(pathsOf($digest))->toBe(['src/Agent.php', 'src/Tool.php']);
        });
    });

    describe('prioritiser', function () {
        it('renders in path order when none is given', function () {
            $digest = $this->ingestor->ingest(new IngestRequest($this->root));

            expect($digest->files[0]->path)->toBe('README.md');
        });

        it('renders the highest score first', function () {
            $digest = $this->ingestor->ingest(new IngestRequest(
                $this->root,
                prioritiser: fn (IngestedFile $file): float => $file->path === 'src/Tool.php' ? 10.0 : 0.0,
            ));

            expect($digest->files[0]->path)->toBe('src/Tool.php');
        });

        it('breaks ties by path so output stays reproducible', function () {
            $request = new IngestRequest($this->root, prioritiser: fn (IngestedFile $file): float => 1.0);

            expect(array_map(fn (IngestedFile $f) => $f->path, $this->ingestor->ingest($request)->files))
                ->toBe(['README.md', 'src/Agent.php', 'src/Tool.php']);
        });
    });

    describe('empty and awkward sources', function () {
        it('yields an empty digest for an empty directory, not an error', function () {
            $root = makeFixture([]);
            $digest = (new NativeIngestor())->ingest(new IngestRequest($root));

            expect($digest->isEmpty())->toBeTrue();
            expect($digest->tree)->toContain('/');

            removeFixture($root);
        });

        it('does not follow symlinks', function () {
            $root = makeFixture(['a.php' => '<?php']);
            $outside = makeFixture(['secret.php' => '<?php // outside the root']);
            symlink($outside, $root . '/linked');

            $digest = (new NativeIngestor())->ingest(new IngestRequest($root, respectGitignore: false));

            expect(pathsOf($digest))->toBe(['a.php']);

            unlink($root . '/linked');
            removeFixture($root);
            removeFixture($outside);
        });
    });

    it('honours the shared ingestor contract', function () {
        expect(IngestorContract::check($this->ingestor, $this->root, ['README.md', 'src/Agent.php', 'src/Tool.php']))
            ->toBe([]);
    });
});
