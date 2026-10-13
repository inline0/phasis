<?php

declare(strict_types=1);

namespace Phasis\Ast\Jsx;

use Phasis\Ast\Node;
use Phasis\Lexer\SourceLocation;

/**
 * `name`, `name="value"`, `name={expr}` or `name=<el />`. A null value means the bare form, which is `true`.
 */
class JsxAttribute extends Node
{
    public function __construct(
        SourceLocation $location,
        public readonly Node $name,
        public readonly ?Node $value,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'JsxAttribute';
    }
}
