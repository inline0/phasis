<?php

declare(strict_types=1);

namespace Phasis\Hydration;

/** An immutable server render and the exact public data captured for its hydration. */
final readonly class RenderedRoot
{
    /**
     * @internal Constructed by Engine::renderForHydration after a successful render.
     * @param string $html React's rendered element-root children.
     * @param string $payload The captured, script-safe hydration JSON.
     * @param HydrationOptions $options The identities used by this render.
     */
    public function __construct(
        public string $html,
        public string $payload,
        public HydrationOptions $options,
    ) {
    }

    /** Return a div root followed by its inert state tag, outside React-managed children. */
    public function toHtml(): string
    {
        $id = htmlspecialchars($this->options->rootId, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        return '<div id="' . $id . '">' . $this->html . '</div>'
            . '<script type="application/json" data-phasis-state="' . $id . '">' . $this->payload . '</script>';
    }
}
