<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Messaging\Dialogue\Presentation;

use Ichiloto\Engine\IO\ActionHints;
use Ichiloto\Engine\UI\Presentation\ControlGlyphPresentation;
use Ichiloto\Engine\UI\Presentation\MenuCanvas;
use RuntimeException;

/** Fixed reading area shared by the page owner and graphical composition. */
final readonly class DialoguePageLayout
{
    public const int CONTENT_ROWS = 3;
    public const int AUTO_HINT_GAP = 8;
    public const int ADVANCE_GAP = 16;
    public const int READY_SPACE = 24;
    public int $columns;
    public int $proseRows;
    public int $helpHeight;
    public int $bodyHeight;
    public int $margin;
    public int $padding;
    public int $gutter;
    public int $autoButtonWidth;
    public bool $narrowField;
    public ?string $portrait;

    public function __construct(DialoguePresentationCatalog $catalogue, string $speaker,
        DialogueContext $context, string $help, int $width, bool $interactive = true)
    {
        $theme = $catalogue->theme ?? throw new RuntimeException('Dialogue has no graphical theme.');
        $m = $theme->metrics;
        $compact = $width < 1000;
        $skit = $context->skitId !== null;
        $margin = $compact ? 20 : ($skit ? 72 : 40);
        $this->portrait = $interactive && !$skit && $speaker !== '' ? $catalogue->getArtwork(
            $catalogue->resolveSpeakerId($context->actorId, $speaker), $context->emotion, 'portrait') : null;
        $gutter = $this->portrait === null ? 0 : ($compact ? 132 : 200);
        $this->autoButtonWidth = max(120, 9 * $m->cellWidth + 2 * $theme->rows->metrics->padding);
        $footerWidth = $this->autoButtonWidth + self::AUTO_HINT_GAP + self::ADVANCE_GAP + self::READY_SPACE;
        if ($interactive && !$skit) {
            $controls = new ControlGlyphPresentation($theme, 'dialogue-footer');
            $footerWidth += $m->cellWidth * ($controls->measureCells(ActionHints::resolveControl('dialogue_auto'))
                + $controls->measureCells(ActionHints::resolveControl('confirm')));
        }
        $this->narrowField = $interactive && !$skit
            && $width - 2 * ($margin + $m->panelPadding) - $gutter < $footerWidth;
        $this->margin = $this->narrowField ? 12 : $margin;
        $this->padding = $this->narrowField ? min(16, $m->panelPadding) : $m->panelPadding;
        $this->gutter = $this->narrowField && $this->portrait !== null ? 72 : $gutter;
        $this->columns = (int)floor(($width - 2 * ($this->margin + $this->padding) - $this->gutter) / $m->cellWidth);
        if ($this->columns < 1) { throw new RuntimeException('Dialogue requires a positive reading width.'); }
        $helpRows = $help === '' ? 0 : count(MenuCanvas::wrap($help, $this->columns));
        if ($helpRows >= self::CONTENT_ROWS) { throw new RuntimeException('Dialogue help exceeds its fixed reading area.'); }
        $this->proseRows = self::CONTENT_ROWS - $helpRows;
        $this->helpHeight = $helpRows * $m->cellHeight;
        $this->bodyHeight = self::CONTENT_ROWS * $m->cellHeight + 2 * $this->padding
            + ($interactive ? $m->rowHeight + 12 : 20);
    }
}
