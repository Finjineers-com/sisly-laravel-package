<?php

namespace Sisly\Coach\Enums;

enum Mood: string
{
    case Excited = 'Excited';
    case Happy   = 'Happy';
    case Calm    = 'Calm';
    case Anxious = 'Anxious';
    case Sad     = 'Sad';

    /** Hex colour for UI rendering — locked per brand spec. */
    public function color(): string
    {
        return match($this) {
            self::Excited => '#2FB3A6',
            self::Happy   => '#F5C542',
            self::Calm    => '#4A90D9',
            self::Anxious => '#B8A0E8',
            self::Sad     => '#E8694A',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
