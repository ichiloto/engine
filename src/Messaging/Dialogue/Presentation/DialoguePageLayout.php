<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Messaging\Dialogue\Presentation;

use Ichiloto\Engine\UI\Presentation\MenuCanvas;
use RuntimeException;

/** Fixed reading area shared by the page owner and graphical composition. */
final readonly class DialoguePageLayout
{
    public const int CONTENT_ROWS = 3;
    public int $columns;
    public int $proseRows;
    public int $helpHeight;
    public int $bodyHeight;
    public int $margin;
    public int $gutter;
    public ?string $portrait;

    public function __construct(DialoguePresentationCatalog $catalogue, string $speaker,
        DialogueContext $context, string $help, int $width, bool $interactive = true)
    {
        $m = ($catalogue->theme ?? throw new RuntimeException('Dialogue has no graphical theme.'))->metrics;
        $compact = $width < 1000;
        $skit = $context->skitId !== null;
        $this->margin = $compact ? 20 : ($skit ? 72 : 40);
        $this->portrait = $interactive && !$skit && $speaker !== '' ? $catalogue->getArtwork(
            $catalogue->resolveSpeakerId($context->actorId, $speaker), $context->emotion, 'portrait') : null;
        $this->gutter = $this->portrait === null ? 0 : ($compact ? 132 : 200);
        $this->columns = (int)floor(($width - 2 * ($this->margin + $m->panelPadding) - $this->gutter) / $m->cellWidth);
        if ($this->columns < 1) { throw new RuntimeException('Dialogue requires a positive reading width.'); }
        $helpRows = $help === '' ? 0 : count(MenuCanvas::wrap($help, $this->columns));
        if ($helpRows >= self::CONTENT_ROWS) { throw new RuntimeException('Dialogue help exceeds its fixed reading area.'); }
        $this->proseRows = self::CONTENT_ROWS - $helpRows;
        $this->helpHeight = $helpRows * $m->cellHeight;
        $this->bodyHeight = self::CONTENT_ROWS * $m->cellHeight + 2 * $m->panelPadding
            + ($interactive ? $m->rowHeight + 12 : 20);
    }
}
