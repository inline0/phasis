<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Module;

/** Writes small file trees into a temporary directory for module tests. */
trait TempProject
{
    private ?string $projectRoot = null;

    /** @param array<string, string> $files Relative path => contents. */
    private function project(array $files): string
    {
        $root = (string) realpath(sys_get_temp_dir()) . '/phasis-test-' . bin2hex(random_bytes(6));
        foreach ($files as $path => $contents) {
            $file = $root . '/' . $path;
            if (!is_dir(dirname($file))) {
                mkdir(dirname($file), 0777, true);
            }
            file_put_contents($file, $contents);
        }
        $this->projectRoot = $root;
        return $root;
    }

    protected function tearDown(): void
    {
        if ($this->projectRoot !== null && is_dir($this->projectRoot)) {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->projectRoot, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($items as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($this->projectRoot);
        }
        $this->projectRoot = null;
    }
}
