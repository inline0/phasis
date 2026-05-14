<?php

declare(strict_types=1);

namespace Phasis\Runtime;

enum CompletionType
{
    case Normal;
    case Return;
    case Throw;
    case Break;
    case Continue;
}
