<?php

declare(strict_types=1);

namespace Phasis\Hydration;

/** Public identities shared by one server render and its browser entry. */
final readonly class HydrationOptions
{
    /**
     * @param string $rootId The HTML element ID, unique within the document.
     * @param string $entryId A public component entry name, never a server path or import URL.
     * @param string $buildId The host's immutable deployment or asset-build identity.
     * @param string $identifierPrefix The React useId prefix, distinct for independent roots.
     */
    public function __construct(
        public string $rootId,
        public string $entryId,
        public string $buildId,
        public string $identifierPrefix = '',
    ) {
        foreach (['rootId' => $rootId, 'entryId' => $entryId, 'buildId' => $buildId] as $name => $value) {
            if ($value === '' || preg_match('//u', $value) !== 1 || preg_match('/[\x00-\x20\x7F]/', $value) === 1) {
                throw new \InvalidArgumentException("Hydration {$name} must be nonempty UTF-8 without whitespace or control characters.");
            }
        }
        if (preg_match('//u', $identifierPrefix) !== 1 || str_contains($identifierPrefix, "\0")) {
            throw new \InvalidArgumentException('Hydration identifierPrefix must be UTF-8 without null bytes.');
        }
    }
}
