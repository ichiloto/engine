<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Messaging\Dialogue\Presentation;

use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Scenes\Interfaces\SceneInterface;
use Ichiloto\Engine\UI\Interfaces\ModalPresentationProviderInterface;
use Ichiloto\Engine\UI\Presentation\MenuCanvas;
use Ichiloto\Engine\UI\Presentation\MenuModalPresentation;
use Ichiloto\Engine\Util\Debug;
use Throwable;

/** Session-local optional artwork; the existing scene UI owns modal registration and cleanup. */
final class DialogueScenePresentation
{
    private bool $loaded = false;
    private ?DialoguePresentationCatalog $catalogue = null;
    private array $diagnostics = [];

    public function __construct(private readonly string $assetRoot) {}

    public function compose(SceneInterface $scene, ?PresentationCanvas $base, int $width, int $height,
        bool $supportsOverlay, bool $supportsCanvas = true, bool $supportsImageTone = true): ?DialogueSurface
    {
        $fallback = null;
        try {
            if (!file_exists($this->assetRoot . '/' . DialoguePresentationCatalog::FILE)) { return null; }
            $active = $scene->getUI()->getActivePresentations();
            $owner = $active[0] ?? null;
            if ($owner === null) { return null; }
            // An opaque menu must not conceal the terminal dialogue if graphical composition fails.
            if ($base !== null && $owner instanceof DialoguePresentationProviderInterface) {
                $fallback = new DialogueSurface(null, false);
            }
            if (!$supportsCanvas) {
                $this->report('Renderer lacks graphical dialogue capabilities; terminal presentation retained.');
                return $fallback;
            }
            if (!$this->loaded) {
                $this->loaded = true;
                $this->catalogue = DialoguePresentationCatalog::load($this->assetRoot);
            }
            $catalogue = $this->catalogue;
            if ($catalogue?->theme === null) { return $fallback; }
            $excluded = ['ui:' . spl_object_id($owner)];
            if ($owner instanceof DialoguePresentationProviderInterface) {
                $line = $owner->getDialogueSnapshot();
                $skit = $line->context->skitId !== null;
                if ($base === null && !$skit && !$supportsOverlay) {
                    $this->report('Renderer lacks canvas_overlay; field dialogue retains terminal presentation.');
                    return null;
                }
                if ($skit && !isset($catalogue->skits[$line->context->skitId]['background'])) {
                    $this->report('Skit ' . $line->context->skitId . ' has no contextual background binding; using theme backing.');
                }
                if ($skit && !$supportsImageTone && $catalogue->skitStage->inactiveBrightness !== 1.0) {
                    $this->report('Renderer lacks canvas_image_tone; skit speaker emphasis retains scale without dimming.');
                }
                $canvas = DialogueCanvasPresentation::compose($line, $catalogue,
                    $base?->width ?? $width, $base?->height ?? $height, $supportsImageTone);
                if ($base !== null && !$skit) { $canvas = MenuCanvas::overlay($base, $canvas, $catalogue->theme); }
                return new DialogueSurface($canvas, $base === null && !$skit, $excluded);
            }
            // Existing menu owners already compose their local choice/alert surfaces.
            if ($base !== null || !$supportsOverlay || !$owner instanceof ModalPresentationProviderInterface) { return null; }
            $modal = $owner->getModalPresentation();
            if ($modal === null) { return null; }
            $canvas = MenuModalPresentation::compose(new PresentationCanvas($width, $height), $modal, $catalogue->theme);
            return new DialogueSurface($canvas, true, $excluded);
        } catch (Throwable $error) {
            $this->report('Dialogue presentation degraded to terminal: ' . $error->getMessage());
            return $fallback;
        }
    }

    private function report(string $message): void
    {
        if (!isset($this->diagnostics[$message])) { Debug::warn($message); $this->diagnostics[$message] = true; }
    }
}
