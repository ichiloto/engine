<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Presentation\Canvas;

use Ichiloto\Engine\Rendering\Presentation\PresentationLayerPolicy;
use Ichiloto\Engine\Rendering\Presentation\PresentationSpritePivot;
use InvalidArgumentException;

/** A map-owned still image group for an overview; no camera, actors or gameplay state. */
final readonly class CanvasWorldSubject
{
    public array $images;

    /** Child images retain local pixel coordinates; dimensions place the group in physical cells. */
    public function __construct(public string $id, public int $x, public int $y,
        public float $widthCells, public float $heightCells, public int $width, public int $height,
        public PresentationSpritePivot $pivot, array $images, public int $layer = 0,
        public float $liftCells = 0, public int $quarterTurns = 0)
    {
        CanvasValidation::id($id);
        if (min($x, $y) < 0 || max($x, $y) >= 16384 || min($width, $height) < 1 || max($width, $height) > 16384
            || !is_finite($widthCells) || !is_finite($heightCells) || min($widthCells, $heightCells) <= 0
            || max($widthCells, $heightCells) > 1024 || !is_finite($liftCells) || $liftCells < 0 || $liftCells > 1024
            || !PresentationLayerPolicy::isFieldWorldLayer($layer) || $quarterTurns < 0 || $quarterTurns > 3) {
            throw new InvalidArgumentException('Overview subjects require bounded map anchors, positive dimensions, layer -1..999 and valid presentation lift/turn.');
        }
        $this->images = CanvasValidation::orderedList($images, CanvasImage::class, 1024);
        foreach ($this->images as $image) {
            $image->destination->assertWithin($width, $height);
            $image->clipRect?->assertWithin($width, $height);
        }
        if ($quarterTurns !== 0 && count($images) !== 1) {
            throw new InvalidArgumentException('A turned overview subject requires one image.');
        }
    }

    public function toArray(): array
    {
        return ['id' => $this->id, 'x' => $this->x, 'y' => $this->y, 'widthCells' => $this->widthCells,
            'heightCells' => $this->heightCells, 'width' => $this->width, 'height' => $this->height,
            'pivot' => $this->pivot->toArray(), 'layer' => $this->layer, 'liftCells' => $this->liftCells,
            'quarterTurns' => $this->quarterTurns,
            'images' => array_map(static fn(CanvasImage $image) => $image->toArray(), $this->images)];
    }

    public static function fromArray(array $data): self
    {
        CanvasCompositeValues::validateKeys($data,
            ['id', 'x', 'y', 'widthCells', 'heightCells', 'width', 'height', 'pivot', 'images', 'layer'],
            ['liftCells', 'quarterTurns']);
        foreach (['x', 'y', 'width', 'height', 'layer'] as $key) {
            if (!is_int($data[$key])) { throw new InvalidArgumentException('Overview anchors, pixel dimensions and layer require integers.'); }
        }
        if (!is_string($data['id']) || !is_array($data['pivot']) || !is_int($data['quarterTurns'] ?? 0)) {
            throw new InvalidArgumentException('Overview subject requires a string id, a pivot and integer turn.');
        }
        $images = array_map(static fn($image) => $image instanceof CanvasImage ? $image
            : (is_array($image) ? CanvasImage::fromArray($image)
                : throw new InvalidArgumentException('Overview images require canvas image descriptors.')),
            CanvasCompositeValues::getList($data['images'], 0, 1024));
        return new self($data['id'], $data['x'], $data['y'],
            CanvasCompositeValues::getNumber($data['widthCells'], PHP_FLOAT_MIN, 1024),
            CanvasCompositeValues::getNumber($data['heightCells'], PHP_FLOAT_MIN, 1024),
            $data['width'], $data['height'], PresentationSpritePivot::fromArray($data['pivot']), $images, $data['layer'],
            CanvasCompositeValues::getNumber($data['liftCells'] ?? 0, 0, 1024), $data['quarterTurns'] ?? 0);
    }
}
