<?php

namespace App\Enums;

enum CorrectionStatus: string
{
    case Pending = 'pending';
    case Applied = 'applied';
    case Superseded = 'superseded';
}
