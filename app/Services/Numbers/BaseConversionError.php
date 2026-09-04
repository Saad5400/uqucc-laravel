<?php

namespace App\Services\Numbers;

use InvalidArgumentException;

/**
 * A number that could not be read in its base, or a base outside the
 * supported range. The message is bilingual (Arabic first) and safe to show
 * verbatim on every surface — the web tool and the Telegram bot command.
 */
class BaseConversionError extends InvalidArgumentException {}
