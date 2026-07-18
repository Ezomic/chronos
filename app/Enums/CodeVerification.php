<?php

declare(strict_types=1);

namespace App\Enums;

enum CodeVerification
{
    case Valid;
    case Incorrect;
    case Expired;
}
