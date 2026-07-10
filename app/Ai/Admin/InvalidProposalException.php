<?php

namespace App\Ai\Admin;

use RuntimeException;

/**
 * A proposal (or its re-validation at confirm time) failed a business rule.
 * The message is operator-facing Arabic: the propose_* tools return it to
 * the model verbatim so it can self-correct, and the executor surfaces it on
 * a failed action card.
 */
class InvalidProposalException extends RuntimeException {}
