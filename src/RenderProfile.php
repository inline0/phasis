<?php

declare(strict_types=1);

namespace Phasis;

/**
 * How Engine::render() serializes a component.
 *
 * React: byte-identical to react-dom@19.3.0's prerender, hydratable by
 * React 19.3 on the client. Html: byte-identical to
 * preact-render-to-string 6.8.0, for server-only HTML.
 */
enum RenderProfile
{
    case React;
    case Html;
}
