<?php

namespace Sisly\Coach\Enums;

enum SafetyVerdict: string
{
    case Ok       = 'ok';
    case Checking = 'checking';
    case Flagged  = 'flagged';
}
