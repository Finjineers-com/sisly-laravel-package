<?php

namespace Sisly\Coach\Enums;

enum Locale: string
{
    case English = 'en';
    case Arabic  = 'ar';

    public function apiLabel(): string
    {
        return match($this) {
            self::English => 'english',
            self::Arabic  => 'arabic',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
