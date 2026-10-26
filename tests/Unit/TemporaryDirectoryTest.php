<?php

it('removes nested owned fixtures without following links outside them', function () {
    if (PHP_OS_FAMILY === 'Windows') {
        $this->markTestSkipped('Creating symlinks requires privileges on Windows.');
    }

    $root = createTestDirectory('ichiloto-cleanup-test-');
    mkdir($root . '/nested');
    file_put_contents($root . '/nested/stub.txt', 'fixture');
    $fixtures = dirname(__DIR__) . '/Fixtures';
    symlink($fixtures, $root . '/nested/shared-fixtures');

    cleanUpTestDirectories();

    expect(is_dir($root))->toBeFalse()
        ->and(is_dir($fixtures))->toBeTrue();
});

it('cleans every registered root and tolerates fixtures already removed', function () {
    $first = createTestDirectory('ichiloto-cleanup-test-');
    $second = createTestDirectory('ichiloto-cleanup-test-');
    rmdir($first);

    cleanUpTestDirectories();
    cleanUpTestDirectories();

    expect(is_dir($first))->toBeFalse()
        ->and(is_dir($second))->toBeFalse();
});
