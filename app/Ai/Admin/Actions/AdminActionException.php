<?php

namespace App\Ai\Admin\Actions;

use RuntimeException;

/**
 * An admin action refused a raw input or hit a business rule. The message is
 * operator-facing Arabic: the MCP adapter returns it as a tool error, and
 * the assistant adapter returns it to the model so it can self-correct —
 * at pause time (invalid calls skip the approval card entirely) and again
 * at execution time after the admin's decision.
 */
class AdminActionException extends RuntimeException {}
