<?php

declare(strict_types=1);

use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\UI\Modal\ModalPresentation;
use Ichiloto\Engine\UI\Modal\NoticeContent;
use Ichiloto\Engine\UI\Modal\NoticePageModel;
use Ichiloto\Engine\UI\Presentation\MenuModalPresentation;
use Ichiloto\Engine\UI\Presentation\MenuPresentationCatalog;

it('lets a non-blocking host acknowledge all terminal pages without losing text or notice identity', function () {
    $lines = array_map(static fn(int $i): string => 'Reward ' . $i . ': 1,234 G — café', range(1, 45));
    $notice = new NoticeContent('Rewards', implode("\n", $lines), actorId: 'actor.synthetic');
    $source = new ModalPresentation($notice->title, $notice->message, ['OK'], 0,
        singleConfirmation: true, notice: $notice, sourceMessage: $notice->message);
    $model = NoticePageModel::paginateTerminal($source, 46, 6, 2);
    $read = [];
    do {
        $view = $model->getModalPresentation();
        $read[] = $view->message;
        expect(count(explode("\n", $view->message)))->toBeLessThanOrEqual(4)
            ->and($view->notice)->toBe($notice)->and($view->singleConfirmation)->toBeTrue()
            ->and($view->sourceMessage)->toBe($notice->message)
            ->and($view->choices)->toBe([$model->hasNextPage ? 'Next' : 'OK']);
    } while ($model->turnPage());
    $last = $model->index;
    expect(explode("\n", implode("\n", $read)))->toBe($lines)
        ->and($model->turnPage())->toBeFalse()->and($model->index)->toBe($last)
        ->and($model->message)->toBe($read[$last]);
});

it('preserves a long terminal title in the reading text and handles blank acknowledgement bodies', function () {
    $title = 'A heading that wraps across the narrow terminal reading area';
    $source = new ModalPresentation($title, "First\r\n\r\nLast", ['OK'], 0, singleConfirmation: true);
    $model = NoticePageModel::paginateTerminal($source, 16, 3);
    expect($model->title)->toBe('Information')
        ->and($model->sourceMessage)->toBe($title . "\n\nFirst\r\n\r\nLast")
        ->and(implode("\n", $model->pages))->toContain("First\n\nLast");
    $blank = NoticePageModel::paginateTerminal(new ModalPresentation('', '', ['OK'], 0, singleConfirmation: true), 16, 3);
    expect($blank->pages)->toBe([''])->and($blank->hasNextPage)->toBeFalse()
        ->and($blank->progress)->toBe('')->and($blank->getModalPresentation()->choices)->toBe(['OK']);
});

it('preserves graphical paragraphs within the reading area for replaceable themes and canvas sizes', function (array $metrics, int $width, int $height) {
    $theme = new MenuPresentationCatalog(createTestDirectory('notice-page-theme-'), ['schema' => 'ichiloto.menu/1', 'metrics' => $metrics]);
    $text = str_repeat('A complete reward description retains every word. ', 80) . "\n\nFinal paragraph.";
    $source = new ModalPresentation('Campaign progress', $text, ['OK'], 0, singleConfirmation: true, sourceMessage: $text);
    $canvas = new PresentationCanvas($width, $height);
    $model = NoticePageModel::paginateCanvas($source, $canvas, $theme);
    expect($model->graphical)->toBeTrue()->and(implode('', $model->pages))->toBe($text);
    do {
        $view = $model->getModalPresentation();
        $reading = MenuModalPresentation::measureReadingArea($canvas, $view, $theme);
        $composed = MenuModalPresentation::compose($canvas, $view, $theme);
        $body = array_find($composed->textLayers, static fn($layer): bool => $layer->id === 'menu-modal-message');
        expect($body)->not->toBeNull()
            ->and($body->grid->columns)->toBe($reading['messageColumns'])
            ->and($body->grid->rows)->toBeLessThanOrEqual($reading['maximumRows'])
            ->and($view->sourceMessage)->toBe($text);
    } while ($model->turnPage());
})->with([[[], 1350, 720], [[], 900, 540], [['cellWidth' => 8, 'cellHeight' => 18, 'panelPadding' => 16], 1100, 640]]);

it('refuses impossible reading areas and choice menus without opening a window', function () {
    $source = new ModalPresentation('Notice', 'Body', ['OK'], 0, singleConfirmation: true);
    expect(fn() => NoticePageModel::paginateTerminal($source, 0, 6))->toThrow(InvalidArgumentException::class)
        ->and(fn() => NoticePageModel::paginateTerminal($source, 20, 0))->toThrow(InvalidArgumentException::class)
        ->and(fn() => NoticePageModel::paginateTerminal($source, 20, 6, -1))->toThrow(InvalidArgumentException::class)
        ->and(fn() => NoticePageModel::paginateTerminal($source, 20, 6, 6))->toThrow(RuntimeException::class)
        ->and(fn() => NoticePageModel::paginateTerminal(new ModalPresentation('Choose', 'Body', ['Yes', 'No'], 0), 20, 6))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn() => NoticePageModel::paginateTerminal(new ModalPresentation('Amount', 'Body', ['OK'], 0,
            singleConfirmation: true, quantity: new \Ichiloto\Engine\UI\Modal\QuantityPresentation(1, 5, 1)), 20, 6))
        ->toThrow(InvalidArgumentException::class);
});
