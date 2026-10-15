<?php

/**
 * Ported from React 19.3.0's Fizz server renderer (react-dom-server).
 *
 * Copyright (c) Meta Platforms, Inc. and affiliates.
 *
 * This source code is licensed under the MIT license found in the
 * LICENSE file in the root directory of this source tree
 * (third-party/react/LICENSE in Phasis).
 */

declare(strict_types=1);

namespace Phasis\BuiltIn\Jsx\React\Fizz;

/** A JS Set of tasks (abort sets), iterated in insertion order. */
final class TaskSet
{
    /** @var array<int, Task> */
    private array $tasks = [];

    public function add(Task $task): void
    {
        $this->tasks[spl_object_id($task)] ??= $task;
    }

    /** Remove a task; true when it was present, like Set.prototype.delete. */
    public function delete(Task $task): bool
    {
        $id = spl_object_id($task);
        if (!isset($this->tasks[$id])) {
            return false;
        }
        unset($this->tasks[$id]);
        return true;
    }

    /** @return list<Task> A snapshot, so callbacks may change the set. */
    public function all(): array
    {
        return array_values($this->tasks);
    }

    public function clear(): void
    {
        $this->tasks = [];
    }
}
