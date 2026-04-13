<?php

declare(strict_types=1);

namespace PhpJs\Runtime;

enum CompletionType
{
    case Normal;
    case Return;
    case Throw;
    case Break;
    case Continue;
}
