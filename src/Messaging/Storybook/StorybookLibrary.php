<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Messaging\Storybook;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Project-owned books selected by stable identity, without opening a reader. */
final readonly class StorybookLibrary
{
    public const string DIRECTORY = 'Data/Storybooks';

    public function __construct(public string $assetRoot) {}

    /**
     * Lists selectable source names without evaluating PHP or inspecting pictures.
     * load() validates the chosen definition before it can be presented.
     * @return list<string>
     */
    public function findStorybookIds(): array
    {
        $root = realpath($this->assetRoot);
        if ($root === false) {
            return [];
        }
        $ids = [];
        $directory = $root . '/' . self::DIRECTORY;
        if (!is_dir($directory)) {
            return [];
        }
        foreach (scandir($directory) ?: [] as $entry) {
            if (!str_ends_with($entry, '.php')) {
                continue;
            }
            $path = $directory . '/' . $entry;
            $id = basename($entry, '.php');
            $file = realpath($path);
            if (preg_match(StorybookDefinition::ID_PATTERN, $id) === 1
                && $file !== false && is_file($file)
                && str_starts_with($file, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
                $ids[] = $id;
            }
        }
        sort($ids, SORT_STRING);
        return $ids;
    }

    public function findById(string $id): ?StorybookDefinition
    {
        if (preg_match(StorybookDefinition::ID_PATTERN, $id) !== 1) {
            throw new InvalidArgumentException('Storybook references require a stable lowercase id.');
        }
        $root = realpath($this->assetRoot);
        if ($root === false) {
            return null;
        }
        $path = $root . '/' . self::DIRECTORY . '/' . $id . '.php';
        clearstatcache(true, $path);
        $file = realpath($path);
        if ($file === false || !is_file($file)) {
            return null;
        }
        if (!str_starts_with($file, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
            throw new InvalidArgumentException("Storybook '{$id}' source must remain inside its asset root.");
        }
        try {
            $data = (static fn(string $source): mixed => require $source)($file);
            if (!is_array($data)) {
                throw new InvalidArgumentException('Storybook source must return an array.');
            }
            $book = StorybookDefinition::fromArray($data, "Storybook source {$path}");
            if ($book->id !== $id) {
                throw new InvalidArgumentException("Source name '{$id}' declares storybook '{$book->id}'.");
            }
            return $book;
        } catch (Throwable $error) {
            throw new RuntimeException("Cannot load storybook source {$path}: {$error->getMessage()}", previous: $error);
        }
    }

    /** Loads current authored text; no cache freezes a changed book or picture reference. */
    public function load(string $id): StorybookDefinition
    {
        return $this->findById($id) ?? throw new RuntimeException("Storybook '{$id}' was not found.");
    }
}
