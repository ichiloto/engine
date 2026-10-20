<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Rendering\Sprites\SpriteValidation;
use InvalidArgumentException;

/**
 * A named feature of a resource's artwork where visuals attach, such as an
 * aerial a signal pulses on. Each presentation owns its own place for it:
 * `point` is where the feature is on the artwork, normalized like the pivot,
 * and `cell` is the terminal cell, relative to a placement's anchor, that
 * shows it. Replacing or re-cropping the art moves only the point, so every
 * visual attached to the feature follows it.
 */
final readonly class WorldObjectAttachment
{
    public const int MAX_PER_RESOURCE = 16;

    public function __construct(
        public string $id,
        public int $cellX,
        public int $cellY,
        public float $pointX,
        public float $pointY,
    ) {}

    /** Stable lowercase identifiers, the same shape as resource ids. */
    public static function assertId(mixed $id): void
    {
        if (!is_string($id) || preg_match('/\A[a-z][a-z0-9._-]{0,63}\z/', $id) !== 1) {
            throw new InvalidArgumentException('Attachment ids must be stable lowercase identifiers of 1..64 characters.');
        }
    }

    /** @return list<self> */
    public static function readList(string $resourceId, mixed $data): array
    {
        if (!is_array($data) || !array_is_list($data) || $data === [] || count($data) > self::MAX_PER_RESOURCE) {
            throw new InvalidArgumentException("Resource '{$resourceId}' attachments must be a list of 1.." . self::MAX_PER_RESOURCE . ' named points.');
        }
        $attachments = [];
        foreach ($data as $entry) {
            $attachment = self::fromArray($resourceId, $entry);
            if (isset($attachments[$attachment->id])) {
                throw new InvalidArgumentException("Resource '{$resourceId}' attachment ids must be unique.");
            }
            $attachments[$attachment->id] = $attachment;
        }
        return array_values($attachments);
    }

    public static function fromArray(string $resourceId, mixed $data): self
    {
        if (!is_array($data) || array_diff(array_keys($data), ['id', 'cell', 'point']) !== [] || count($data) !== 3) {
            throw new InvalidArgumentException("Resource '{$resourceId}' attachments need id, cell and point.");
        }
        self::assertId($data['id']);
        $cell = $data['cell'];
        if (!is_array($cell) || count($cell) !== 2 || !is_int($cell['x'] ?? null) || !is_int($cell['y'] ?? null)) {
            throw new InvalidArgumentException("Resource '{$resourceId}' attachment '{$data['id']}' cell needs integer x/y offsets from the anchor.");
        }
        SpriteValidation::validateSigned32BitRange($cell['x']);
        SpriteValidation::validateSigned32BitRange($cell['y']);
        $point = $data['point'];
        if (!is_array($point) || count($point) !== 2
            || array_any(['x', 'y'], static fn(string $axis): bool => (!is_int($point[$axis] ?? null) && !is_float($point[$axis] ?? null))
                || !is_finite((float)$point[$axis]) || $point[$axis] < 0 || $point[$axis] > 1)) {
            throw new InvalidArgumentException("Resource '{$resourceId}' attachment '{$data['id']}' point needs normalized x/y in 0..1 of the artwork.");
        }
        return new self($data['id'], $cell['x'], $cell['y'], (float)$point['x'], (float)$point['y']);
    }

    /** @return array{id: string, cell: array{x: int, y: int}, point: array{x: float, y: float}} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'cell' => ['x' => $this->cellX, 'y' => $this->cellY],
            'point' => ['x' => $this->pointX, 'y' => $this->pointY]];
    }
}
