<?php

namespace App\Ai\Admin\Actions;

use RuntimeException;

/**
 * An admin action refused a raw input or hit a business rule. The message is
 * operator-facing Arabic: the MCP adapter returns it as a tool error, the
 * assistant adapter returns it to the model so it can self-correct, and the
 * {@see \App\Ai\Admin\ProposalExecutor} surfaces it on a failed action card.
 */
class AdminActionException extends RuntimeException {}
