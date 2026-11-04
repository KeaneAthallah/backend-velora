<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a stocktake would set every counted line to zero while the
 * outlet still holds stock. The client must confirm the wipe with
 * `confirm_zero_count` before the count can be posted.
 */
class StocktakeConfirmationRequiredException extends RuntimeException {}
