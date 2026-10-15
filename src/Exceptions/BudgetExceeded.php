<?php

declare(strict_types=1);

namespace Phasis\Exceptions;

/**
 * Thrown when evaluation runs past the budget an embedder set with
 * Engine::setExecutionBudget(). It is not a JavaScript error, so no try
 * statement in the evaluated code can catch it.
 */
final class BudgetExceeded extends \RuntimeException
{
}
