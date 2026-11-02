<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Messaging\Storybook;

use InvalidArgumentException;

/** Renderer-neutral authored pages; hydration has no scene or progress effects. */
final class StorybookDefinition
{
    public const string ID_PATTERN = '/\A[a-z0-9][a-z0-9._-]*\z/D';
    public const array FIELDS = ['id', 'title', 'narrator', 'pages'];

    public int $pageCount {
        get => count($this->pages);
    }

    /**
     * @param non-empty-list<StorybookPage> $pages
     * @param array<string, StorybookPage> $pagesById
     * @param list<string> $diagnostics
     */
    private function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly string $narrator,
        public readonly array $pages,
        private readonly array $pagesById,
        public readonly array $diagnostics,
    ) {}

    public static function fromArray(array $source, string $context = 'Storybook'): self
    {
        if (array_diff(array_keys($source), self::FIELDS) !== []) {
            throw new InvalidArgumentException("{$context} accepts id, title, narrator and pages.");
        }
        $id = $source['id'] ?? null;
        if (!is_string($id) || preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new InvalidArgumentException("{$context} requires a stable lowercase id.");
        }
        foreach (['title', 'narrator'] as $field) {
            $value = $source[$field] ?? null;
            if (!is_string($value) || trim($value) === '' || str_contains($value, "\0") || preg_match('//u', $value) !== 1) {
                throw new InvalidArgumentException("{$context} {$field} requires nonempty UTF-8 display text.");
            }
        }
        $entries = $source['pages'] ?? null;
        if (!is_array($entries) || !array_is_list($entries) || $entries === []) {
            throw new InvalidArgumentException("{$context} pages require a nonempty ordered list.");
        }
        $pages = $byId = $diagnostics = [];
        foreach ($entries as $index => $entry) {
            if (!is_array($entry)) {
                throw new InvalidArgumentException("{$context} page {$index} must be a record.");
            }
            $page = StorybookPage::fromArray($entry, "{$context} {$id} page {$index}");
            if (isset($byId[$page->id])) {
                throw new InvalidArgumentException("{$context} repeats page id '{$page->id}'.");
            }
            $pages[] = $byId[$page->id] = $page;
            array_push($diagnostics, ...$page->diagnostics);
        }
        return new self($id, $source['title'], $source['narrator'], $pages, $byId, $diagnostics);
    }

    public function getPage(string $id): StorybookPage
    {
        return $this->pagesById[$id] ?? throw new InvalidArgumentException("Storybook '{$this->id}' has no page '{$id}'.");
    }
}
