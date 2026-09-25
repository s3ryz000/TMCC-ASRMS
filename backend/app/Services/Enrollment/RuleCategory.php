<?php

namespace App\Services\Enrollment;

/**
 * Categories a rule violation can belong to. Callers use these to decide which
 * violations are fatal and which merely skip the subject.
 */
final class RuleCategory
{
    public const CURRICULUM     = 'curriculum';
    public const PREREQUISITE   = 'prerequisite';
    public const ALREADY_PASSED = 'already_passed';
    public const DUPLICATE      = 'duplicate';
}
