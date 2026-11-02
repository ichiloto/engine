<?php

declare(strict_types=1);

use Ichiloto\Engine\Messaging\Storybook\StorybookDefinition;
use Ichiloto\Engine\Messaging\Storybook\StorybookPage;
use Ichiloto\Engine\Rendering\Sprites\PngAssetPreflight;
use Ichiloto\Engine\Util\Debug;
use function Tests\Support\Rendering\createPaddedPngBytes;

require_once __DIR__ . '/../Support/Rendering/PngFreshnessFixtures.php';

function makeSyntheticStorybookSource(): array
{
    return ['id' => 'river-tale', 'title' => 'The River Tale', 'narrator' => 'An old traveller',
        'pages' => [
            ['id' => 'z-opening', 'text' => "  First paragraph.\n\nA whispered ‘question’…\n"],
            ['id' => 'a-ending', 'text' => "The same words.\nThe same words."],
        ]];
}

it('retains authored page order narration and display labels without source or gameplay effects', function () {
    $source = makeSyntheticStorybookSource();
    $before = $source;
    $book = StorybookDefinition::fromArray($source);
    expect($source)->toBe($before)->and($book->id)->toBe('river-tale')
        ->and($book->title)->toBe($source['title'])->and($book->narrator)->toBe($source['narrator'])
        ->and($book->pageCount)->toBe(2)
        ->and(array_map(static fn(StorybookPage $page): string => $page->id, $book->pages))->toBe(['z-opening', 'a-ending'])
        ->and($book->getPage('z-opening')->text)->toBe($source['pages'][0]['text'])
        ->and($book->getPage('a-ending')->text)->toBe($source['pages'][1]['text'])
        ->and($book->diagnostics)->toBe([])
        ->and($book->pages[0]->picture)->toBeNull()
        ->and($book->pages[0]->getPictureSize('/missing-assets'))->toBeNull()
        ->and(fn() => $book->getPage('removed'))->toThrow(InvalidArgumentException::class);
    $source['title'] = 'Another display title';
    $source['narrator'] = 'A different display name';
    $renamed = StorybookDefinition::fromArray($source);
    expect($renamed->id)->toBe($book->id)->and($renamed->pages[0]->id)->toBe($book->pages[0]->id);
});

it('refuses ambiguous or incomplete narration before a reader can lose or reorder it', function (Closure $change) {
    $source = makeSyntheticStorybookSource();
    $change($source);
    expect(fn() => StorybookDefinition::fromArray($source))->toThrow(InvalidArgumentException::class);
})->with([
    'missing book identity' => [function (&$s) { unset($s['id']); }],
    'unsafe identity' => [function (&$s) { $s['id'] = '../river-tale'; }],
    'missing title' => [function (&$s) { unset($s['title']); }],
    'empty narrator' => [function (&$s) { $s['narrator'] = ' '; }],
    'malformed title' => [function (&$s) { $s['title'] = [42]; }],
    'empty pages' => [function (&$s) { $s['pages'] = []; }],
    'keyed pages' => [function (&$s) { $s['pages'] = ['second' => $s['pages'][1], 'first' => $s['pages'][0]]; }],
    'non-record page' => [function (&$s) { $s['pages'][0] = 'unreadable'; }],
    'missing page identity' => [function (&$s) { unset($s['pages'][0]['id']); }],
    'duplicate page identity' => [function (&$s) { $s['pages'][1]['id'] = $s['pages'][0]['id']; }],
    'missing narration' => [function (&$s) { unset($s['pages'][0]['text']); }],
    'empty narration' => [function (&$s) { $s['pages'][0]['text'] = " \n"; }],
    'invalid unicode' => [function (&$s) { $s['pages'][0]['text'] = "bad\xFF"; }],
    'nul narration' => [function (&$s) { $s['pages'][0]['text'] = "bad\0text"; }],
    'undeclared outcome field' => [function (&$s) { $s['giveGold'] = 99; }],
    'undeclared page outcome' => [function (&$s) { $s['pages'][0]['recordEvent'] = 'read-the-tale'; }],
]);

it('keeps narration readable with diagnostics for an unusable optional picture reference', function (mixed $picture) {
    $source = makeSyntheticStorybookSource();
    $source['pages'][0]['picture'] = $picture;
    $before = $source;
    $book = StorybookDefinition::fromArray($source);
    expect($source)->toBe($before)->and($book->pages[0]->text)->toBe($source['pages'][0]['text'])
        ->and($book->pages[0]->picture)->toBeNull()->and($book->pages[0]->diagnostics)->toHaveCount(1)
        ->and($book->diagnostics)->toBe($book->pages[0]->diagnostics)
        ->and($book->pages[0]->getPictureSize('/missing-assets'))->toBeNull()
        ->and($book->getPage('a-ending')->text)->toBe($source['pages'][1]['text']);
})->with([
    'number' => [27], 'record' => [['image' => 'Graphics/page.png']], 'empty' => [''],
    'absolute' => ['/private/page.png'], 'parent' => ['Graphics/../page.png'],
    'uri' => ['file:///private/page.png'], 'backslash' => ['Graphics\\page.png'],
    'not png' => ['Graphics/page.jpg'], 'invalid unicode' => ["Graphics/\xFF.png"],
    'nul' => ["Graphics/page\0.png"],
]);

it('reads current optional picture facts and preserves text after missing corrupt or replaced artwork', function () {
    $root = sys_get_temp_dir() . '/storybook-picture-' . bin2hex(random_bytes(8));
    mkdir($root);
    $statics = [];
    foreach ([PngAssetPreflight::class, Debug::class] as $class) { $statics[$class] = new ReflectionClass($class)->getStaticProperties(); }
    try {
        Debug::configure(['log_directory' => $root]);
        $source = makeSyntheticStorybookSource();
        $source['pages'][0]['picture'] = 'page.png';
        $book = StorybookDefinition::fromArray($source);
        $page = $book->pages[0];
        expect($page->getPictureSize($root))->toBeNull()->and($page->text)->toBe($source['pages'][0]['text']);
        file_put_contents($root . '/page.png', 'corrupt');
        expect($page->getPictureSize($root))->toBeNull()->and($book->pageCount)->toBe(2);
        file_put_contents($root . '/page.png', createPaddedPngBytes(12, 8));
        expect($page->getPictureSize($root))->toBe(['width' => 12, 'height' => 8]);
        file_put_contents($root . '/page.png', createPaddedPngBytes(7, 15));
        expect($page->getPictureSize($root))->toBe(['width' => 7, 'height' => 15])
            ->and($page->text)->toBe($source['pages'][0]['text'])
            ->and($page->picture)->toBe('page.png');
    } finally {
        foreach ($statics as $class => $properties) {
            foreach ($properties as $name => $value) { new ReflectionProperty($class, $name)->setValue(null, $value); }
        }
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
        rmdir($root);
    }
});
