<?php

declare(strict_types=1);

namespace Phasis\Tests\Oracle;

class SuiteResult
{
    public int $pass = 0;
    public int $fail = 0;
    public int $skip = 0;
    /** @var list<TestResult> */
    public array $failures = [];
    /** @var array<string, array{pass: int, fail: int, skip: int}> */
    public array $categories = [];

    public function add(TestResult $result): void
    {
        match ($result->status) {
            TestStatus::Pass => $this->pass++,
            TestStatus::Fail => $this->fail++,
            TestStatus::Skip => $this->skip++,
        };

        if ($result->status === TestStatus::Fail) {
            $this->failures[] = $result;
        }

        // Track per-category
        $category = $this->extractCategory($result->testPath);
        if (!isset($this->categories[$category])) {
            $this->categories[$category] = ['pass' => 0, 'fail' => 0, 'skip' => 0];
        }
        match ($result->status) {
            TestStatus::Pass => $this->categories[$category]['pass']++,
            TestStatus::Fail => $this->categories[$category]['fail']++,
            TestStatus::Skip => $this->categories[$category]['skip']++,
        };
    }

    public function total(): int
    {
        return $this->pass + $this->fail + $this->skip;
    }

    public function passRate(): float
    {
        $attempted = $this->pass + $this->fail;
        return $attempted > 0 ? ($this->pass / $attempted) * 100 : 0.0;
    }

    private function extractCategory(string $path): string
    {
        // Extract path relative to test262/test/
        if (preg_match('#test262/test/(.+?)/#', $path, $m)) {
            $parts = explode('/', $m[1]);
            return implode('/', array_slice($parts, 0, 2));
        }
        return 'unknown';
    }

    public function report(): string
    {
        $lines = [];
        $lines[] = 'test262 Compliance Report';
        $lines[] = str_repeat('=', 40);
        $lines[] = '';

        $attempted = $this->pass + $this->fail;
        $lines[] = sprintf(
            'Overall: %d / %d (%.1f%%)',
            $this->pass,
            $attempted,
            $this->passRate(),
        );
        $lines[] = sprintf('Skipped: %d', $this->skip);
        $lines[] = '';

        if (!empty($this->categories)) {
            $lines[] = 'By Category:';
            ksort($this->categories);
            foreach ($this->categories as $cat => $counts) {
                $catAttempted = $counts['pass'] + $counts['fail'];
                $catRate = $catAttempted > 0 ? ($counts['pass'] / $catAttempted) * 100 : 0;
                $lines[] = sprintf(
                    '  %-40s %d / %d (%.1f%%)',
                    $cat,
                    $counts['pass'],
                    $catAttempted,
                    $catRate,
                );
            }
        }

        return implode("\n", $lines);
    }

    /** @return array<string, mixed> */
    public function toJson(): array
    {
        return [
            'pass' => $this->pass,
            'fail' => $this->fail,
            'skip' => $this->skip,
            'passRate' => round($this->passRate(), 2),
            'categories' => $this->categories,
        ];
    }
}
