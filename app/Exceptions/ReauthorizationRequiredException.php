<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * The provider rejected our refresh token outright: it was revoked, or it
 * expired. No amount of retrying brings it back, only the user re-consenting.
 */
class ReauthorizationRequiredException extends RuntimeException {}
