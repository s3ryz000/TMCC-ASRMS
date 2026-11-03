<?php

namespace App\Support;

/**
 * The account roles (Phase 2). TMCC's 27 Oct 2026 letter asks for individual
 * accounts for faculty, program coordinators and deans next to the
 * Registrar's Office and MIS. Registrar staff keep the "staff" role; the
 * College Registrar is a staff account with the approve-grades permission.
 */
final class Roles
{
    public const ADMIN = 'admin';
    public const STAFF = 'staff';
    public const STUDENT = 'student';
    public const FACULTY = 'faculty';
    public const COORDINATOR = 'coordinator';
    public const DEAN = 'dean';

    /** Faculty, program coordinators and deans: the academic side of the grade chain. */
    public const ACADEMIC = [self::FACULTY, self::COORDINATOR, self::DEAN];

    /** Every role an account can have. */
    public const ALL = [self::ADMIN, self::STAFF, self::STUDENT, ...self::ACADEMIC];

    /** What the admin may create in User Management (students are created by the registrar). */
    public const CREATABLE = [self::STAFF, self::ADMIN, ...self::ACADEMIC];

    public const LABELS = [
        self::ADMIN => 'Admin',
        self::STAFF => 'Registrar Staff',
        self::STUDENT => 'Student',
        self::FACULTY => 'Faculty',
        self::COORDINATOR => 'Program Coordinator',
        self::DEAN => 'Dean',
    ];
}
