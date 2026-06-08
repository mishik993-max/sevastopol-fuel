<?php

namespace App\Enums;

enum Freshness: string
{
    case Fresh = 'fresh';
    case Stale = 'stale';
    case Expired = 'expired';
    case Unknown = 'unknown';
}
