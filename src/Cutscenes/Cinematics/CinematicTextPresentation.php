<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Cutscenes\Cinematics;

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Messaging\Dialogue\Presentation\DialogueContext;
use Ichiloto\Engine\Messaging\Dialogue\Presentation\DialoguePagination;
use Ichiloto\Engine\Messaging\Dialogue\Presentation\DialoguePaginationBuilder;
use Ichiloto\Engine\Messaging\Dialogue\Presentation\DialogueScenePresentation;
use Ichiloto\Engine\Messaging\Dialogue\Presentation\DialogueSnapshot;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\UI\Windows\Enumerations\HorizontalAlignment;
use Ichiloto\Engine\UI\Windows\Enumerations\WindowHeightPolicy;
use Ichiloto\Engine\UI\Windows\Enumerations\WindowPosition;
use Ichiloto\Engine\UI\Windows\Window;
use Ichiloto\Engine\UI\Windows\WindowAlignment;
use InvalidArgumentException;

/** The cinematic operation owns time; both renderers project the same timed text. */
final class CinematicTextPresentation
{
    private float $duration = 0.0;
    private float $elapsed = 0.0;

    public function __construct(private readonly string $kind, private readonly string $text,
        private readonly string $title = '') {}

    public function setDuration(float $seconds): void
    {
        if (!is_finite($seconds) || $seconds < 0) {
            throw new InvalidArgumentException('Cinematic text duration must be finite non-negative seconds.');
        }
        $this->duration = $seconds;
    }

    public function advance(float $deltaSeconds): void
    {
        $this->elapsed = min($this->duration, $this->elapsed + max(0.0, $deltaSeconds));
    }

    public function getCanvas(DialogueScenePresentation $dialogue, int $width, int $height): ?PresentationCanvas
    {
        $context = new DialogueContext();
        $layout = $dialogue->getPageLayout($this->title, $context, '', $width, interactive: false);
        if ($layout === null) { return null; }
        $pages = DialoguePaginationBuilder::buildPagination($this->text, $this->title, '', $context,
            $layout->columns + 4, $layout->proseRows + 2, $layout, $this->getPosition());
        $snapshot = $pages->getSnapshot($this->getPageIndex($pages));
        return $dialogue->composeSnapshot(new DialogueSnapshot($snapshot->speaker, $snapshot->page,
            $snapshot->visibleText, false, $snapshot->pageIndex, $snapshot->pageCount, false,
            $snapshot->position, $context, interactive: false, textAlignment: $this->getTextAlignment()), $width, $height);
    }

    public function renderTerminal(int $width, int $height): void
    {
        $titleCard = $this->kind === 'title_card';
        $hasBodyTitle = $titleCard && $this->title !== '' && $height >= 4;
        $pages = DialoguePaginationBuilder::buildPagination($this->text, $this->title, '', new DialogueContext(),
            $width, $height - ($hasBodyTitle ? 1 : 0), position: $this->getPosition());
        $windowHeight = min($height, $pages->windowHeight + ($hasBodyTitle ? 1 : 0));
        $window = new Window(title: $titleCard ? '' : $this->title,
            position: new Vector2(max(0, intdiv($width - $pages->windowWidth, 2)),
                $titleCard ? max(0, intdiv($height - $windowHeight, 2)) : 0),
            width: $pages->windowWidth, height: $windowHeight,
            alignment: $titleCard ? WindowAlignment::topCenter() : WindowAlignment::topLeft(),
            heightPolicy: WindowHeightPolicy::FIXED);
        $window->setContent([...($hasBodyTitle ? [$this->title] : []),
            ...explode("\n", $pages->pages[$this->getPageIndex($pages)])]);
        $window->render();
    }

    private function getPageIndex(DialoguePagination $pagination): int
    {
        if ($this->duration <= 0) { return 0; }
        // Longer pages retain their share of the existing reading-time floor.
        $weights = array_map(static fn(string $page): int => max(1, CinematicTextTimingPolicy::getWordCount($page)), $pagination->pages);
        $progress = $this->elapsed / $this->duration * array_sum($weights);
        $end = 0;
        foreach ($weights as $index => $weight) {
            $end += $weight;
            if ($progress < $end) { return $index; }
        }
        return count($pagination->pages) - 1;
    }

    private function getPosition(): WindowPosition
    {
        return $this->kind === 'title_card' ? WindowPosition::MIDDLE : WindowPosition::TOP;
    }

    private function getTextAlignment(): HorizontalAlignment
    {
        return $this->kind === 'title_card' ? HorizontalAlignment::CENTER : HorizontalAlignment::LEFT;
    }
}
