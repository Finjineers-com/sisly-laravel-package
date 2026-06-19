<?php

namespace Sisly\Coach\Enums;

enum ContentType: string
{
    case Meditation  = 'Meditation';
    case DoWithMe    = 'DoWithMe';
    case Affirmation = 'Affirmation';
    case Sound       = 'Sound';
    case Podcast     = 'Podcast';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
