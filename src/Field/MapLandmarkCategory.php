<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

/** What a place offers, independently of the route that enters it. */
enum MapLandmarkCategory: string
{
    case INN = 'inn';
    case SHOP = 'shop';
    case HOME = 'home';
    case ORGANIZATION = 'organization';
}
