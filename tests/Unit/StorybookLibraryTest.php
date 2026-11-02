<?php

declare(strict_types=1);

use Ichiloto\Engine\Messaging\Storybook\StorybookLibrary;

/** A synthetic project source, independent of Game content or artwork. */
function writeStorybookLibraryFixture(string $root, string $id, ?array $data = null): string
{
    $directory = $root . '/' . StorybookLibrary::DIRECTORY;
    if (!is_dir($directory)) {
        mkdir($directory, 0700, true);
    }
    $path = $directory . '/' . $id . '.php';
    $data ??= ['id' => $id, 'title' => 'A journey', 'narrator' => 'A traveller',
        'pages' => [['id' => 'opening', 'text' => "First words.\n\nLast words."]]];
    file_put_contents($path, '<?php return ' . var_export($data, true) . ';');
    return $path;
}

it('discovers stable source identities without executing source or loading artwork', function () {
    $root = createTestDirectory('storybook-library-');
    $library = new StorybookLibrary($root);
    expect($library->findStorybookIds())->toBe([])->and($library->findById('missing'))->toBeNull();
    writeStorybookLibraryFixture($root, 'z-tale');
    $unread = writeStorybookLibraryFixture($root, 'a-tale');
    file_put_contents($unread, '<?php throw new RuntimeException("Not evaluated during discovery");');
    writeStorybookLibraryFixture($root, 'Wrong Name');
    mkdir($root . '/' . StorybookLibrary::DIRECTORY . '/nested');
    file_put_contents($root . '/' . StorybookLibrary::DIRECTORY . '/notes.txt', 'Not a book');
    expect($library->findStorybookIds())->toBe(['a-tale', 'z-tale'])
        ->and(fn() => $library->load('a-tale'))->toThrow(RuntimeException::class, 'Not evaluated during discovery')
        ->and($library->load('z-tale')->id)->toBe('z-tale');
});

it('loads exact current text from its explicit project root without changing the source', function () {
    $root = createTestDirectory('storybook-library-first-');
    $other = createTestDirectory('storybook-library-second-');
    $firstPath = writeStorybookLibraryFixture($root, 'river-tale');
    writeStorybookLibraryFixture($other, 'river-tale', ['id' => 'river-tale', 'title' => 'Another project',
        'narrator' => 'Another traveller', 'pages' => [['id' => 'first', 'text' => 'Different words.']]]);
    $before = file_get_contents($firstPath);
    $library = new StorybookLibrary($root);
    expect($library->load('river-tale')->pages[0]->text)->toBe("First words.\n\nLast words.")
        ->and(file_get_contents($firstPath))->toBe($before)
        ->and(new StorybookLibrary($other)->load('river-tale')->title)->toBe('Another project');
    writeStorybookLibraryFixture($root, 'river-tale', ['id' => 'river-tale', 'title' => 'Revised',
        'narrator' => 'A traveller', 'pages' => [['id' => 'opening', 'text' => "  Revised exactly…\n",
            'picture' => 'Graphics/missing.png']]]);
    expect($library->load('river-tale')->pages[0]->text)->toBe("  Revised exactly…\n")
        ->and($library->load('river-tale')->pages[0]->picture)->toBe('Graphics/missing.png');
    unlink($firstPath);
    expect($library->findById('river-tale'))->toBeNull()
        ->and(fn() => $library->load('river-tale'))->toThrow(RuntimeException::class, 'not found');
});

it('refuses unsafe selected references before source evaluation', function (string $id) {
    $library = new StorybookLibrary(createTestDirectory('storybook-library-path-'));
    expect(fn() => $library->load($id))->toThrow(InvalidArgumentException::class);
})->with(['', '../outside', 'nested/book', '/absolute', 'file:book', "book\0", 'Book', "book\n"]);

it('reports malformed selected source and mismatched identity with its source location', function (string $kind) {
    $root = createTestDirectory('storybook-library-invalid-');
    $path = writeStorybookLibraryFixture($root, 'tale');
    if ($kind === 'identity') {
        writeStorybookLibraryFixture($root, 'tale', ['id' => 'different', 'title' => 'Tale',
            'narrator' => 'Traveller', 'pages' => [['id' => 'page', 'text' => 'Words']]]);
    } elseif ($kind === 'narration') {
        writeStorybookLibraryFixture($root, 'tale', ['id' => 'tale', 'title' => 'Tale',
            'narrator' => 'Traveller', 'pages' => [['id' => 'page', 'text' => '']]]);
    } else {
        file_put_contents($path, match ($kind) {
            'return value' => '<?php return 42;',
            'exception' => '<?php throw new LogicException("Source failed");',
            'syntax' => '<?php return [;',
        });
    }
    expect(fn() => new StorybookLibrary($root)->load('tale'))->toThrow(RuntimeException::class, $path);
})->with(['identity', 'narration', 'return value', 'exception', 'syntax']);

it('excludes and refuses source symlinks outside the selected project asset root', function () {
    $root = createTestDirectory('storybook-library-contained-');
    $outside = createTestDirectory('storybook-library-outside-');
    $target = writeStorybookLibraryFixture($outside, 'external');
    writeStorybookLibraryFixture($root, 'local');
    symlink($target, $root . '/' . StorybookLibrary::DIRECTORY . '/external.php');
    $library = new StorybookLibrary($root);
    expect($library->findStorybookIds())->toBe(['local'])
        ->and(fn() => $library->load('external'))->toThrow(InvalidArgumentException::class, 'inside its asset root')
        ->and(is_file($target))->toBeTrue();
});
