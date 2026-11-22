<?php

namespace App\Support;

use RuntimeException;

/**
 * The code sandbox refused to start a run because it could not prove the run
 * would be isolated (see {@see SandboxEgressGuard}). Callers tell the user the
 * feature is off; they never fall back to running the code.
 */
class CodeSandboxUnavailable extends RuntimeException {}
