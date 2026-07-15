<?php

namespace App\Services\Logic;

use InvalidArgumentException;

/**
 * A propositional formula that could not be parsed or tabulated. The message
 * is bilingual (Arabic first) and safe to show verbatim on every surface —
 * the web tool, the Telegram bot, and the AI assistant tool.
 */
class FormulaError extends InvalidArgumentException {}
