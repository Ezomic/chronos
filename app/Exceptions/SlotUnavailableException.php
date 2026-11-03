<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * The slot a visitor picked is no longer free: someone else took it, or the
 * owner's calendar changed since the page was loaded.
 */
class SlotUnavailableException extends RuntimeException {}
