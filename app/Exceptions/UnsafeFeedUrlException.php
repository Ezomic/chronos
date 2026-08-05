<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A subscribed feed URL points somewhere Chronos will not fetch from. The
 * message is written to be shown to the user.
 */
class UnsafeFeedUrlException extends RuntimeException {}
