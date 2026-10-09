<?php

declare(strict_types=1);

namespace App\Ai\Editor;

/** Jeden nález sebekontroly (typ, závažnost, vysvětlení). */
final readonly class ReviewIssue
{
    public function __construct(
        public ReviewIssueType $type,
        public ReviewSeverity $severity,
        public string $note,
    ) {}
}
