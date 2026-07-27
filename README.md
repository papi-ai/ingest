# papi-ai/ingest

Deterministic repository ingestion for [PapiAI](https://papi-ai.org): turn a codebase into the
largest useful picture that fits a token budget.

Agent apps keep hand-rolling tree scans and file reads, which gives the model shallow context and
gives you an unbounded token bill. This is that job done once: discover, filter, rank, and render,
with nothing hidden from the reader.

**Deterministic by definition.** No model is called during ingestion. The same repository state and
the same request always produce the same digest, which is what makes the fingerprint worth caching
against.

## Install

```bash
composer require papi-ai/ingest
```

## Usage

```php
use PapiAI\Ingest\Depth;
use PapiAI\Ingest\IngestRequest;
use PapiAI\Ingest\NativeIngestor;

$digest = (new NativeIngestor())->ingest(new IngestRequest(
    source: '/path/to/repo',
    depth: Depth::Full,
    tokenBudget: 4000,
));

echo $digest->toPrompt();     // tree, then as many files as fit, then what was left out
echo $digest->fingerprint;    // cache key: moves when included content moves, and not otherwise
```

### Depth

| Depth | What each file contributes |
|---|---|
| `Depth::Tree` | Nothing. Paths only, the cheapest orientation |
| `Depth::Api` | A symbol block: what it declares, extends, and reaches for |
| `Depth::Full` | The whole file |

`Api` is the level agent consumers usually want: the entire system stays legible for a fraction of
`Full`'s tokens. It needs a symbol extractor for the language, so install
[`papi-ai/ingest-php-symbols`](https://github.com/papi-ai/ingest-php-symbols) and pass it in:

```php
$ingestor = new NativeIngestor(new PhpSymbolExtractor());
$digest = $ingestor->ingest(new IngestRequest($repo, Depth::Api));
```

Asking for `Api` without an extractor throws. It will not quietly hand you a `Tree` and let you
wonder where the symbols went.

### Prioritising what survives the budget

The budget renders the tree first, then files in priority order, skipping any that do not fit so one
huge file cannot starve everything below it. A prioritiser is how you say what matters right now:

```php
$recent = time() - 86400;

new IngestRequest(
    source: $repo,
    depth: Depth::Api,
    tokenBudget: 4000,
    prioritiser: fn (IngestedFile $file): float => $file->mtime > $recent ? 10.0 : 0.0,
);
```

Map plus heat: the whole shape of the system, with the parts you are working on rendered first.

### Rendering

| Call | Budget used |
|---|---|
| `toPrompt()` | The one the request carried, or everything if it carried none |
| `toPromptWithin(int $budget)` | This one, overriding the request |
| `toFullPrompt()` | None. Everything, whatever the request said |

Whatever gets dropped is named in a trailer line, always. A prompt that quietly omits half a
repository is worse than one that admits it, because the reader cannot tell the difference.

## Discovery

Inside a git repository, discovery defers to git itself
(`git ls-files --cached --others --exclude-standard`): tracked files plus untracked ones that are
not ignored. That is faster than walking and exactly right about `.gitignore`, including nested
ignore files and global excludes, which a hand-rolled matcher never quite is. Outside a repository,
or with `respectGitignore: false`, it walks the directory and never follows symlinks.

`vendor/`, `node_modules/`, `.git/`, lock files, `.env*` and binaries are excluded by default.
Binaries and oversized files still appear in the tree, with a note saying why they were not read.

## Interchange

File blocks use gitingest's framing (a 48-character rule, then `FILE: <path>`), and the tree opens
with `Directory structure:`, so digests are interchangeable with what people already paste into
models.

## Related packages

| Package | Adds |
|---|---|
| `papi-ai/ingest-php-symbols` | `Depth::Api` for PHP, via nikic/php-parser |
| `papi-ai/ingest-gitingest` | Remote repositories, via the gitingest CLI |

## License

MIT, Marcello Duarte
