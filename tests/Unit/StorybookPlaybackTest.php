<?php

declare(strict_types=1);

use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Messaging\Storybook\StorybookDefinition;
use Ichiloto\Engine\Messaging\Storybook\StorybookPlayback;

function makePlaybackBook(string $text = 'First paragraph. Second paragraph. Third paragraph.'): StorybookDefinition
{
    return StorybookDefinition::fromArray(['id' => 'journey', 'title' => 'A journey', 'narrator' => 'Traveller',
        'pages' => [['id' => 'first', 'text' => $text, 'picture' => 'Graphics/first.png'],
            ['id' => 'second', 'text' => 'An ending.', 'picture' => 'Graphics/second.png']]]);
}

it('retains each authored page and illustration throughout long-text continuations', function () {
    $book = makePlaybackBook();
    $reader = new StorybookPlayback($book, 17, 1);
    $parts = [];
    $first = $reader->page;
    expect($reader->hasPrevious)->toBeFalse()->and($reader->continuationCount)->toBeGreaterThan(1);
    while ($reader->pageNumber === 1) {
        expect($reader->page)->toBe($first)->and($reader->page->picture)->toBe('Graphics/first.png')
            ->and(count(explode("\n", $reader->readingText)))->toBeLessThanOrEqual(1);
        $parts[] = $reader->readingText;
        $reader->advance();
    }
    expect(preg_replace('/\s+/u', '', implode('', $parts)))->toBe(preg_replace('/\s+/u', '', $first->text))
        ->and($reader->page)->toBe($book->pages[1])->and($reader->continuationNumber)->toBe(1)
        ->and($reader->page->picture)->toBe('Graphics/second.png')->and($reader->hasNext)->toBeFalse()
        ->and($reader->isClosed)->toBeFalse();
    $reader->advance();
    expect($reader->isClosed)->toBeTrue()->and($reader->hasNext)->toBeFalse()->and($reader->hasPrevious)->toBeFalse();
});

it('moves back through text and authored pages without wrapping the book or changing source', function () {
    $book = makePlaybackBook();
    $reader = new StorybookPlayback($book, 17, 1);
    $originalText = $book->pages[0]->text;
    $reader->goBack();
    expect($reader->pageNumber)->toBe(1)->and($reader->continuationNumber)->toBe(1);
    $previousText = '';
    while ($reader->pageNumber === 1) {
        $previousText = $reader->readingText;
        $reader->advance();
    }
    $reader->goBack();
    expect($reader->pageNumber)->toBe(1)->and($reader->continuationNumber)->toBe($reader->continuationCount)
        ->and($reader->readingText)->toBe($previousText);
    $reader->goBack();
    expect($reader->continuationNumber)->toBe($reader->continuationCount - 1)
        ->and($book->pages[0]->text)->toBe($originalText);
});

it('keeps early close terminal and starts a repeated telling with independent reader state', function () {
    $book = makePlaybackBook();
    $reader = new StorybookPlayback($book, 17, 1);
    $reader->advance();
    $before = [$reader->pageNumber, $reader->continuationNumber, $reader->readingText];
    $reader->close();
    $reader->advance();
    $reader->goBack();
    $reader->close();
    expect([$reader->pageNumber, $reader->continuationNumber, $reader->readingText])->toBe($before)
        ->and($reader->isClosed)->toBeTrue();
    $repeated = new StorybookPlayback($book, 17, 1);
    expect($repeated->isClosed)->toBeFalse()->and($repeated->pageNumber)->toBe(1)
        ->and($repeated->continuationNumber)->toBe(1);
});

it('fits paragraph breaks wide and combining glyphs in one shared finite reading capacity', function (string $text, int $columns, int $rows) {
    $book = makePlaybackBook($text);
    $reader = new StorybookPlayback($book, $columns, $rows);
    $parts = [];
    while ($reader->pageNumber === 1) {
        $parts[] = $reader->readingText;
        $lines = explode("\n", $reader->readingText);
        expect(count($lines))->toBeLessThanOrEqual($rows);
        foreach ($lines as $line) {
            expect(TerminalText::displayWidth($line))->toBeLessThanOrEqual($columns)
                ->and(mb_strlen(TerminalText::stripAnsi($line)))->toBeLessThanOrEqual($columns);
        }
        $reader->advance();
    }
    expect(preg_replace('/\s+/u', '', implode('', $parts)))->toBe(preg_replace('/\s+/u', '', $text))
        ->and($book->pages[0]->text)->toBe($text);
})->with([
    ["Opening.\n\nMiddle.\n\nEnd.", 12, 2],
    [str_repeat('日本語', 7), 8, 2],
    [str_repeat("e\u{301}", 15), 7, 2],
    ["Repeated words. Repeated words.\n\nRepeated words.", 10, 1],
]);

it('refuses nonpositive reading capacities', function (int $columns, int $rows) {
    expect(fn() => new StorybookPlayback(makePlaybackBook(), $columns, $rows))->toThrow(InvalidArgumentException::class);
})->with([[0, 3], [4, 0], [-1, 2], [5, -1]]);

it('refuses an unreadable next page before moving the owner position', function () {
    $book = StorybookDefinition::fromArray(['id' => 'narrow', 'title' => 'A narrow reader', 'narrator' => 'Traveller',
        'pages' => [['id' => 'first', 'text' => 'X'], ['id' => 'second', 'text' => '界']]]);
    $reader = new StorybookPlayback($book, 1, 1);
    expect(fn() => $reader->advance())->toThrow(InvalidArgumentException::class, 'cannot fit a narration symbol')
        ->and($reader->page)->toBe($book->pages[0])->and($reader->pageNumber)->toBe(1)
        ->and($reader->continuationNumber)->toBe(1)->and($reader->readingText)->toBe('X')
        ->and($reader->isClosed)->toBeFalse();
});
