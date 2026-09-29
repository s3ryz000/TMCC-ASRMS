# Weekly Report Audit — TMCC-ASRMS (Group 6)

**Audited:** `main` at `100e327` (GitHub `s3ryz000/TMCC-ASRMS`). The remote's only other branch, `midyear-dev`, is fully merged into `main`, so this covers everything on GitHub.
**Date:** 26 September 2026
**Method:** Read-only code inspection, the full backend test suite, the frontend test and build, and one behaviour probe. The probe (a script run outside the repository against a throwaway in-memory database) confirmed four behaviours that reading the code alone left uncertain. No project file was changed apart from creating this report.
**Line numbers** refer to `main@100e327`.

**Environment note:** The audit instructions assumed Windows/XAMPP with no Composer or Node. The audit actually ran in a Linux codespace with PHP 8.3.33, Composer and Node 24 all available. Backend dependencies were installed with `--ignore-platform-req=ext-gd --ignore-platform-req=ext-zip`, because PhpSpreadsheet needs extensions this PHP build lacks; the tests don't use them. Because Node was available, the frontend was also tested and built, not just read.

---

## 1. Summary table

| ID | Claim | Owner | Claimed date | Verdict | Evidence |
|---|---|---|---|---|---|
| A1 | Login / authentication | — | Done (every report) | **UNTESTED** | `routes/api.php:18-26`, `AuthController.php:45`, `AuthService.php:35`. No test calls login, logout or change-password. |
| A2 | Role-based access (student / registrar / admin) | — | Done (every report) | **VERIFIED** | `EnsureRole.php:14-27` on every route group (`routes/api.php:51,66,99,119,141`), plus controller guards (`AuthorizesRole.php:23`). Tested by `AdminRecordAccessTest` (22 tests). **Caveat:** admins could write student records until 26 Sep (see §5.2). |
| A3 | Student records CRUD | — | Done (every report) | **VERIFIED** | `StudentController.php:103` store, `:206` show, `:260` update, `:925` archive. Create is tested by `test_path_c_*` and read by `test_admin_can_list_students` / `test_staff_can_still_read_students`. Update and archive were traced from UI to database (`StaffEditStudentPage.jsx:653`, `ArchiveModal.jsx:27`). There is no hard delete; "delete" means archive. |
| A4 | Honors alerts | — | Done (every report) | **UNTESTED** | Computed in `AcademicStandingService.php:124,146,167`; raised at `StudentProfileController.php:359` and `StudentController.php:1018`; shown at `StudentDashboard.js:64-80`. No test. |
| A5 | Records requests: submit → review → release | — | Done (every report) | **UNTESTED** | `RecordRequestController.php:56` → `RequestController.php:84` / `:152` → `:278` / `:552`, with UI wired in three pages. No test. |
| A6 | Transcript exports | — | Done (every report) | **UNTESTED** | `OfficialTranscriptExportService.php:14` (Dompdf), reached from `StudentController.php:237`, `RequestController.php:384` and `RecordRequestController.php:163`. No test. |
| B1 | Curriculum validation on enrollment | Kevin | 21 Aug | **VERIFIED** | `CurriculumMembershipRule.php:23`, run for every path by `EnrollmentValidator.php:30`. Tests: `test_path_c_rejects_subject_from_another_program`, `test_path_c_rejects_subject_from_a_later_term`, `test_path_a_rejects_subject_outside_the_selected_term`. **Git:** the change that enforces this on every path landed 22 Aug (§4). |
| B2 | Prerequisite check before enrollment | Rome | 21 Aug | **VERIFIED** | `PrerequisitesSatisfiedRule.php:30`, with "passed" defined in `AcademicRecordQuery.php:28`. Tests: `test_path_a_blocks_unmet_prerequisite`, `test_path_a_has_no_same_batch_prerequisite_bypass`, `test_or_prerequisite_group_is_satisfied_by_one_member`, `test_unresolved_prerequisite_blocks_enrollment`, `test_path_d_blocks_move_into_term_with_unmet_prerequisites`. INC and Failed handling was traced but has no test (§3). |
| C1 | UI verification & regression testing of enrollment flows | All | 29 Aug | **NOT FOUND** | No UI, component or end-to-end tests. The only frontend test (`App.test.js`) cannot run. Backend-only tests don't count as UI testing. |
| C2 | Admin UI to create/edit Subjects and Programs | Ryle | 29 Aug | **NOT FOUND** | No write routes, controller methods or React screens for subjects or programs. Both are created only by seeders. |
| C3 | Student report card & transcript PDF viewer | Sam | 29 Aug | **PARTIAL** | Transcript PDF generation exists (`OfficialTranscriptExportService.php:14`). There is no in-app PDF viewer and no report-card PDF. |
| D1 | "One agreed contract between modules" | — | 12 Sep | **PARTIAL** | `AcademicRecordQuery` exists and the enrollment rules use it. Four other modules still carry their own copies of the "passed" query (§3). |
| D2 | Unit & integration tests for enrollment → grade → transcript | — | 12 Sep | **PARTIAL** | Enrollment is covered (20 tests). Grade entry and correction, GWA/honors, and transcript generation have no tests. |
| D3 | Integration checkpoint before any cross-module merge | — | 12 Sep | **NOT CODE-VERIFIABLE** | No PR template, CI workflow or `.github/` folder. Branch protection isn't visible from a clone. |
| E1 | Grade correction recomputes dependent values | — | 19 Sep | **UNTESTED** | Works as described (`StudentController.php:1179`, `:1334`; confirmed by the probe), but no test exercises it. |
| E2 | Transcript regenerates after a grade correction | — | 19 Sep | **VERIFIED** | Traced: the PDF is rendered on every request (`OfficialTranscriptExportService.php:14-42`) and never stored or cached. The service worker does not cache API calls. There is no automated test. |
| E3 | Prerequisite info in the Edit Student subject picker | Kevin | 19 Sep | **PARTIAL** | Shown only as a "blocked" reason for ineligible subjects (`AcademicProgressionStep4.jsx:616`). The manual picker is dead code (`StaffEditStudentPage.jsx:83,217`). |
| E4 | Full enrollment → grade → transcript path with passing unit & integration coverage | — | 19 Sep | **PARTIAL** | The suite passes: 66/66 today, 22/22 as of 19 Sep. The coverage stops at enrollment (see D2). |
| F1 | Registrar Curriculum Builder Module | Rome | Target 26 Sep; wrap-up 03 Oct | *Status: early* | Only the data model, seeders and one read endpoint exist. No builder API, UI or tests. Estimated ~10% complete (§3). |
| F2 | Client change requests from the 19 Sep walkthrough | — | In progress | **NOT CODE-VERIFIABLE** | No commits after 22 Aug relate to walkthrough feedback. The only TODOs are in two unrouted legacy pages. |
| G | Sep 5 report items / 29 Aug – 12 Sep | — | — | *No source file* | **Zero commits** on any GitHub branch between 22 Aug and 26 Sep (§4). |

---

## 2. Test run

### Backend — `main@100e327`

```
cd backend
php artisan test
```

**66 passed, 0 failed, 0 skipped — 262 assertions, 1.53 s**

| File | Tests | Added in |
|---|---|---|
| `tests/Feature/EnrollmentValidationTest.php` | 20 | `66bd121`, 22 Aug |
| `tests/Feature/AdminRecordAccessTest.php` | 22 | `6abd131`, 26 Sep |
| `tests/Feature/AdminPasswordResetTest.php` | 7 | `6abd131`, 26 Sep |
| `tests/Unit/QrCodeGeneratorTest.php` | 15 | `6abd131`, 26 Sep |
| `tests/Feature/ExampleTest.php`, `tests/Unit/ExampleTest.php` | 2 | framework scaffold, 27 Feb |

There were no failures. PHPUnit prints `WARN` lines because `@dataProvider` doc-comments are deprecated in PHPUnit 12. These are deprecation notices, not failures.

### Backend — as of the 19 Sep claim date

No commits exist between 22 Aug and 26 Sep, so the tree on 19 Sep was `66bd121`. That tree was exported with `git archive` and its suite run:

**22 passed, 0 failed, 0 skipped — 41 assertions.** That is the 20 enrollment tests plus 2 scaffold tests.

### Frontend — `main@100e327`

```
cd frontend
CI=true npx react-scripts test --watchAll=false
```

**1 suite failed to run, 0 tests executed:**

```
Cannot find module '@testing-library/jest-dom' from 'src/setupTests.js'
Test Suites: 1 failed, 1 total
Tests:       0 total
```

The only test file (`App.test.js:6-8`) is the unmodified Create React App placeholder. It looks for the text "learn react", which the app doesn't render, so it would fail even if its dependencies were installed.

`npm run build` compiles, with lint warnings in 14 files. The same build with `CI=true`, which CI servers set by default, **fails**, because those warnings are then treated as errors.

---

## 3. Details for every claim that isn't VERIFIED

### A1 — Login / authentication (UNTESTED)
- **Login flow:** username and password are checked against a bcrypt hash, then a Sanctum bearer token is issued (`AuthService.php:35-53`). All earlier tokens for the user are revoked, so each user can be logged in on one device at a time. Auth routes are rate-limited to 6 requests per minute (`routes/api.php:18`). The SPA logs the user out on a 401 (`frontend/src/lib/api/client.js:49`).
- **Coverage:** the only related test is `AdminRecordAccessTest::test_unauthenticated_requests_are_rejected`. No test calls `/api/auth/login`, `/logout` or `/change-password`.
- See §5.1 item 3 for a problem with the public `/api/auth/register` route in the same group.

### A4 — Honors alerts (UNTESTED)
- **Computation:** eligibility is worked out fresh on every read in `AcademicStandingService`. Dean's List is semester GPA ≤ 1.75 (`:124`) and President's List is annual GPA ≤ 1.75 (`:146`). Latin honors bands are 1.00–1.20, 1.21–1.45 and 1.46–1.75 (`:167-194`). All three also require no grade above 2.00 and no Failed, INC, FDA, DRP, Withdrawn, Cancelled or still-Enrolled subject (`:233`).
- **How the alert is raised:** it isn't pushed (no email or notification record). It is a `notifications[]` entry returned by `GET /student/academic-summary` (`StudentProfileController.php:359`) and shown on page load (`StudentDashboard.js:64-80`). Standing also appears in `StudentLayout.jsx:135` and, for staff, `AcademicProgressionStep4.jsx:489-497`. The staff-side `notifications[]` built at `StudentController.php:1018` is not rendered by any staff page.
- The honors result also gates award-certificate requests (`RecordRequestController.php:92-120`).
- **Coverage:** none. The probe confirmed Dean's List flips from not-eligible to eligible after a grade correction.

### A5 — Records requests (UNTESTED)
- **Flow:**
  - Submit: `POST /student/record-requests` (`RecordRequestController.php:56`), which rejects duplicate pending requests and award requests the student isn't eligible for.
  - Review: approve with an appointment slot (`RequestController.php:84`) or reject (`:152`).
  - Release: `:278` → `doRelease` (`:552`), which writes a `record_transactions` row.
- **UI:** `StudentRequestRecordPage.jsx:26-27`, `StaffPendingRequestsPage.jsx:175,211`, `StaffDocumentReleasePage.jsx:108`.
- Approve, reject and release are open to admins as well as staff (`routes/api.php:99`). That matches the previous session's decision to let admins keep document release.
- **Coverage:** none. See §5.1 item 5 for audit-log entries written before validation.

### A6 — Transcript exports (UNTESTED)
- **Output:** one Dompdf-rendered PDF (`OfficialTranscriptExportService.php:14-42`), reached three ways:
  - Staff/admin, by student: `StudentController.php:237`, UI at `ViewRecordsPage.jsx:122`.
  - Staff/admin, for a released transcript request: `RequestController.php:384`, UI at `StaffDocumentReleasePage.jsx:206`.
  - Student, for their own approved or released request: `RecordRequestController.php:163`, UI at `StudentRequestRecordPage.jsx:51`.
- Two code comments call the output "XLSX" (`StudentController.php:235`, `staffApi.js:108`), but the output is PDF.
- **Coverage:** none.

### B1 / B2 — notes on two VERIFIED claims
- **Curriculum rule:** it rejects a subject that is outside the program, or in the wrong year and semester (`CurriculumMembershipRule.php:23-39`). The single validator runs for all four paths:
  - new student (`StudentController.php:152`)
  - manual entry (`:552`)
  - term correction (`:637`)
  - guided next term (`AcademicProgressionService.php:975`)
- **INC and Failed grades:** "passed" means status Passed or Credited, plus two legacy forms (`AcademicRecordQuery.php:28-52`). INC, Failed, Withdrawn and FDA therefore never satisfy a prerequisite. The guided picker labels the two cases differently: "has INC status. Complete the prerequisite first" (`AcademicProgressionService.php:586-588`) versus "Required prerequisite: …". Failed, Withdrawn and FDA subjects are offered as retakes, which `RetakeEligibilityService.php:194` validates separately. **No test uses an INC or Failed grade**, so this behaviour is traced, not tested.
- **Untested path:** path B (the guided next-term flow, `POST …/enrollments/add-next-term`) has no functional test, only the admin-gets-403 check. This is the enrollment path the UI actually uses (§5.1 item 1).

### C1 — UI verification & regression testing (NOT FOUND)
- There is no frontend test beyond the non-running scaffold (§2).
- There is no Cypress, Playwright, Laravel Dusk or other browser test, and no test dependency in `frontend/package.json`.
- On 29 Aug the backend suite had 22 tests, all enrollment rules or scaffold. None exercises a UI.
- **Test/UI mismatch:** the UI-reachable enrollment flow (guided next term) is the one path with no functional test (§5.1 item 1).

### C2 — Admin UI to create/edit Subjects and Programs (NOT FOUND)
- **API:** `routes/api.php` has only reads: `GET /staff/subjects`, `/staff/programs`, `/staff/programs/{id}/curriculum`. No controller creates or updates a `Subject`, `Program` or `Curriculum` row.
- **UI:** no subject or program management screen exists under `frontend/src/pages`.
- **Where the data comes from:** `database/seeders/ProgramSeeder.php` (BSE, BSHM, BSTM) and `Bse`, `Bshm` and `BstmCurriculumSeeder.php`.
- **Validation:** there is none to check, because there is no input path.

### C3 — Report card & transcript PDF viewer (PARTIAL)
- **Exists:**
  - Server-side transcript PDF (Dompdf).
  - Client-side jsPDF award certificates (`StaffDocumentReleasePage.jsx:233`, `StudentRequestRecordPage.jsx:69`).
  - An HTML grades view for students (`components/student/ViewCopyOfGradesModal.jsx`, `GradesModal.jsx`).
- **Missing:**
  - An in-app PDF viewer. There is no `<iframe>`, `<embed>`, `<object>` or PDF library. All three transcript paths build a blob and trigger a file download (for example `ViewRecordsPage.jsx:132-138`).
  - A report-card PDF. No code produces a per-term report card.

### D1 — One agreed contract between modules (PARTIAL)
`App\Services\Enrollment\AcademicRecordQuery` describes itself as the single source of truth and is used by `EnrollmentValidator`. On `main`, the same logic is still duplicated here:

| Location | Duplicates |
|---|---|
| `AcademicProgressionService.php:1259` `getPassedSubjectIds()` / `:1285` `getIncSubjectIds()` | Line-for-line copies of `AcademicRecordQuery::passedSubjectIds()` / `incSubjectIds()`, minus the int cast. |
| `RetakeEligibilityService.php:281` `getPassedSubjectIds()`, plus its own constants at `:58`, `:63` | A third copy of the "passed" query. |
| `AcademicResidencyValidationService.php:238` | Inline copy of the "passed" query. |
| `AcademicLoadValidationService.php:171` | Inline copy of the "passed" query. |
| `AcademicProgressionService.php` (~`:500-560`, ~`:790-830`, ~`:1190-1200`) and `RetakeEligibilityService.php:194-247` | Four separate AND/OR prerequisite checks alongside `PrerequisitesSatisfiedRule`. |
| `StudentController.php:705` vs `AcademicProgressionService.php:21` | Two "final status" lists that disagree (the controller's omits `Cancelled`). |

Because the progression service uses its own INC query, `AcademicRecordQuery::incSubjectIds()` is never called on `main`.

*Note: an unpushed local branch in the audited clone removes the first row's duplicate pair. It isn't on GitHub and isn't counted here.*

### D2 — Tests for the enrollment → grade → transcript chain (PARTIAL)
| Link | Covered? | Tests |
|---|---|---|
| Enrollment validation (paths A, C, D) | Yes | 20 in `EnrollmentValidationTest` |
| Enrollment writes the grade placeholder | Yes | `test_path_a_writes_grade_and_audit_rows_too`, `test_path_c_writes_grade_rows` |
| Guided next-term enrollment (path B, used by the UI) | **No** | — |
| Grade entry / correction (`bulk-update`, `grades` CRUD) | **No** | — (only the admin-403 checks) |
| GWA / honors recomputation | **No** | — |
| Transcript PDF generation | **No** | — |
| Record request → release → student download | **No** | — |

No test exercises more than the first link of the chain.

### D3 — Integration checkpoint before cross-module merge (NOT CODE-VERIFIABLE)
- **Not found:** no `.github/` directory, so no PR template, CODEOWNERS or CI workflow. No contributing guide.
- **Supporting evidence:** GitHub merge commits show a PR workflow was used earlier (PRs #5–#17, Feb–Jun).
- **Merges around the checkpoint date:** none between 12 Sep and 26 Sep. The next merge is PR #1 on 26 Sep (`100e327`, a single feature branch).
- Branch protection settings cannot be seen from a clone.

### E1 — Grade correction recomputes dependent values (UNTESTED)
- **UI path:** the grade-edit panel in `AcademicProgressionStep4.jsx:58-59` calls `PUT …/grades/bulk-update` (`StudentController.php:1179`). That endpoint:
  - updates each grade and the matching enrollment's status
  - writes an `enrollment_audit_logs` row
  - recomputes the cached `students.GPA` (`:1334`)
- **Other grade endpoints:** `storeGrade` (`:837`), `updateGrade` (`:879`) and `destroyGrade` (`:911`) also recompute `students.GPA`.
- **Everything else is computed on read.** Honors, progression and prerequisite eligibility read the `grades` table each time (`AcademicStandingService`, `AcademicProgressionService`, `AcademicRecordQuery`), so none of them can go stale.
- **Probe result:** correcting a grade from 3.00 to 1.25 through `bulk-update` returned HTTP 200. The cached GPA went from 2.00 to 1.13 and Dean's List went from not-eligible to eligible.
- **Defects found:** see §5.1 items 4 and 7.

### E3 — Prerequisite info in the Edit Student subject picker (PARTIAL)
- **Live picker** (`AcademicProgressionStep4.jsx:578-620`): it has a "Notes" column that shows the backend's `blocked_reason` for **ineligible subjects only** (`:616`), for example "Required prerequisite: PROG1." or "… has INC status". Eligible subjects show no prerequisite information.
- **Manual picker** (`StaffEditStudentPage.jsx`): the curriculum list is fetched into `curriculumSubjects` (`:83`, `:217`), but that state is never rendered and `fetchCurriculum` is never called (lint: "assigned a value but never used").
- **API:** `GET /staff/programs/{id}/curriculum` (`StudentController.php:443`) loads only the older single `prerequisite` column, not the many-to-many `prerequisites` the enrollment rules use. A picker built on it would show incomplete prerequisites.

### E4 — Full path end to end with passing coverage (PARTIAL)
- **Passes:** yes, 66/66 today and 22/22 on 19 Sep.
- **Coverage:** only the enrollment link has tests (D2).
- **Assembled by hand:** the probe ran enrollment → grade → GWA → honors in one pass and it worked, but that isn't part of the suite.

### F1 — Registrar Curriculum Builder Module (status)
| Piece | Exists? |
|---|---|
| Data model | Yes: `curriculum` (program, subject, year/semester, legacy single prerequisite, `unresolved_prerequisites`, AND/OR `prerequisite_logic`) and `curriculum_prerequisites` (many-to-many). |
| Seed data | Yes: three program curricula seeded from code (`BseCurriculumSeeder`, `BshmCurriculumSeeder`, `BstmCurriculumSeeder`, `CurriculumSeederHelper`). |
| Read API | Yes: `GET /staff/programs/{id}/curriculum`. |
| Create/edit API, controller, form requests | **No** |
| React screens | **No** |
| Tests | **No** |
| Curricula other than the default | **No.** There is one curriculum per program: no curriculum/version entity, no effective school year, and uniqueness is on (program, subject, year, semester). Supporting a revised curriculum while older cohorts stay on the old one would need a schema change. |

**Estimate: about 10% complete.** The reusable schema and seeders exist; the builder itself (API, UI, validation, tests) has not started in the repository. No commit under the owner's identity has touched curriculum files since 24 May.

### F2 — Client change requests from the 19 Sep walkthrough (NOT CODE-VERIFIABLE)
- **Commits:** none after 22 Aug relate to walkthrough feedback. The 26 Sep commit is an alignment with the capstone scope and doesn't cite walkthrough items.
- **TODO/FIXME comments:** only in `pages/AdminDashboard.js:51,56,61` and `pages/RecordRequest.js:23`, which are early pages that `routes/Router.jsx` no longer imports.

### G — Sep 5 report / week of 29 Aug – 12 Sep
- **Source:** the Sep 5 report was not available. The only weekly report in the repository (`weekly report.pdf`) is Week 9 (March).
- **Git history for 29 Aug – 12 Sep:** no commits on any branch. The nearest are `66bd121` (22 Aug) and `6abd131` (26 Sep). There is therefore no code record of what changed that week.
- **Context:** the "misaligned assumptions at the module seams" described in the 12 Sep report match defects documented in the 22 Aug commit message and confirmed in the pre-fix code at `027b434`:
  - three disagreeing definitions of "already passed"
  - enrollment rows written without the grade rows that prerequisite checks read
  - a manual enrollment path that always errored (its closure referenced `$passedSubjectIds` without importing it)
  - student creation with no curriculum check

  Several seam issues listed in D1 and E3 are still open.

---

## 4. Git timeline notes

**Commit gap:** on `main`, the last commits before the reporting period are 24 May – 3 Jun (Rome Victor Sendin). After that there are only three:

| Commit | Author (git identity) | Date (UTC+8) | Content |
|---|---|---|---|
| `66bd121` | Samuel Badillo | **22 Aug** 07:18 | Shared enrollment rules and 20 tests |
| `6abd131` | Samuel Badillo | 26 Sep 07:11 | Admin/registrar role split, local QR, 401 fix, password reset and 44 tests |
| `100e327` | Rome Victor Sendin (GitHub merge) | 26 Sep 07:20 | Merge of PR #1 |

**No commits exist between 22 Aug and 26 Sep.** Everything reported closed on 29 Aug, 12 Sep and 19 Sep therefore either predates 22 Aug or is not in the repository.

**AI co-author trailers:** both `66bd121` and `6abd131` end with a `Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>` trailer.

**Identities:** git identities are `kevin` / `kevin20040504` (same email, 53 commits, last on 19 May), `Rome Victor Sendin` / `s3ryz000` (same email, 8 commits), and `Samuel Badillo` (2 commits). Authorship shows only the git identity that committed; it doesn't capture pairing or work done outside the repository.

**Owner and close-date checks:**
- **B1 (Kevin, 21 Aug):**
  - The owner's most recent commit is `16776b8`, 19 May.
  - On 21 Aug (tree `027b434`), only the guided next-term flow checked curriculum membership. `StudentController::store` enrolled any submitted subject with no check, and the manual path always errored.
  - Enforcement on every path landed on **22 Aug** in `66bd121` (Samuel Badillo), the morning after the close date.
- **B2 (Rome, 21 Aug):**
  - The owner's commits to the prerequisite logic in the guided flow (`AcademicProgressionService`, `AcademicProgressionStep4`) are dated 23–24 May (`6976a74`, `b0fb0b0`, `5724e0c`, `acbe214`), before the close date.
  - Extending the check to the other paths landed in `66bd121` on 22 Aug.
- **C2 (Ryle, 29 Aug):**
  - No commit in the repository is authored under a name or email matching this owner.
  - The last commit touching the `Subject`/`Program` models is `414c779` (kevin, 10 May).
- **C3 (Sam, 29 Aug):**
  - The transcript service was last changed on 19 May (`ca6138f`, kevin20040504).
  - Neither of the owner's two commits (22 Aug, 26 Sep) touches transcript or report-card viewer files.
- **E3 (Kevin, 19 Sep):**
  - `AcademicProgressionStep4.jsx` was last changed on 24 May and `StaffEditStudentPage.jsx` on 23 May, both under Rome Victor Sendin.
  - The owner has no commits after 19 May.
- **F1 (Rome, target 26 Sep):**
  - No commits touching curriculum files after 24 May.

**Local-only commits:** the clone used for this audit also contains an unpushed branch, `chore/cleanup-and-staff-uploads`, with five commits dated 26 Sep 00:09–00:15 UTC. An AI assistant made them during the audit session. They appear under the machine's configured git identity (Rome Victor Sendin) and carry the same `Co-Authored-By: Claude` trailer. They are not on GitHub and were excluded from this audit.

---

## 5. Unreported findings

### 5.1 Significant issues not mentioned in any report

1. **The UI and the tests exercise different enrollment and grade paths.**
   - On the Edit Student page, every manual handler is defined but never called: `handleAddEnrollment`, `handleUpdateEnrollment`, `handleDeleteEnrollment`, `handleAddGrade`, `handleUpdateGrade`, `handleDeleteGrade` and `fetchCurriculum` (`StaffEditStudentPage.jsx:217,398,433,462,521,579,614`).
   - The UI can only reach the guided next-term flow, bulk grade update and enrollment cancel (`AcademicProgressionStep4.jsx:43-72`).
   - Most of the 20 enrollment tests cover the manual paths A and D, which the UI no longer reaches. The guided path B, which the UI does use, has none.
2. **Admins could create, edit and delete student academic records until 26 Sep.**
   - From 22 Aug to 25 Sep, all student-record write routes sat in the `role:staff,admin` group (`66bd121:backend/routes/api.php:46-71`), and controller guards allowed both roles.
   - This contradicts the scope (§1.4): administrators "are not permitted to perform CRUD operations on student academic records".
   - It was fixed in `6abd131` on 26 Sep.
3. **The public registration endpoint accepts `role=admin`.**
   - `POST /api/auth/register` needs no login (`routes/api.php:19`). Its validation allows `role: staff|admin` (`RegisterRequest.php:29`), and `AuthService.php:24` assigns that role.
   - **Today** it fails with HTTP 500 because it never sets the NOT NULL `username` column. The probe confirmed this, and no account is created.
   - Anyone who "fixes" that 500 would let anonymous visitors create admin accounts. The frontend sign-up page was already removed (`4dec56c`, 28 Mar).
4. **The grade-correction audit trail records the wrong "old status".**
   - `StudentController.php:1297` reads `$grade->getOriginal('status')` after `$grade->update()`. By then Laravel has synced the original values, so `old_status` always equals `new_status`.
   - The probe confirmed an INC→Passed correction logged as `old_status=Passed`. The `old_value` JSON column is correct.
5. **The request log is written before validation.**
   - `RequestController::approve` logs "Request approved" (`:105`) before validating the appointment slot (`:110`).
   - `release` logs "Document released" (`:296`) before `doRelease` checks the status (`:554`).
   - Refused actions therefore still appear as successes in the system log.
6. **Student personal data goes to the application log on every record view.** `StudentController.php:230` calls `Log::info($student)`, which writes the whole record (name, birth date, address, guardian) to `storage/logs/laravel.log`.
7. **Single-grade edit cannot change the status.**
   - `PUT …/grades/{id}` validates only term, `grade_value` and `remarks` (`StudentController.php:863-868`).
   - The probe showed a Failed 5.00 corrected to 2.00 stays "Failed": GWA uses 2.00, but prerequisite checks still treat the subject as not passed.
   - No UI calls this endpoint today (item 1), but the API still exposes it.
8. **Transcript details:**
   - `RequestController.php:413-416` returns HTTP 500 if an XLSX template file is missing, even though that file is never used (the output is the Dompdf PDF).
   - The transcript shows neither a GWA nor the student number. Both are computed (`OfficialTranscriptExportService.php:68`, `:86`) but never placed in the template. *(Corrected 26 Sep: an earlier version of this report said the transcript printed the cached GPA; it does not.)*
   - `StudentDataSeeder.php:96` hard-codes `students.GPA` to 1.75 for seeded demo students, so the Student Records list can show a GWA that disagrees with the computed one until a grade is edited.
9. **Repository hygiene:**
   - 3,732 `node_modules` files are committed at the root.
   - `frontend/.env` is committed (it holds only the API URL).
   - The root `package.json` pins Tailwind v4 while the frontend builds with v3.
   - The frontend test scaffold can't run, and the build fails when `CI=true`.

### 5.2 Claims the code shows to be overstated

| Claim | What the code shows |
|---|---|
| **A2** "Role-based access: Done" (every report) | Admins could write student academic records, against the scope, until 26 Sep (§5.1 item 2). |
| **B1** Curriculum validation closed 21 Aug | On 21 Aug, new-student enrollment had no curriculum check and the manual path always errored. Enforcement on every path is dated 22 Aug. |
| **C1** UI verification & regression testing closed 29 Aug | No UI tests exist. The single frontend test file cannot run. |
| **C2** Admin UI for Subjects and Programs closed 29 Aug | No endpoints or screens exist. The data is seeded only. |
| **C3** "Report card & transcript PDF viewer" closed 29 Aug | PDF download exists. No in-app viewer and no report card. |
| **D1** "One agreed contract between modules" closed 12 Sep | The shared query exists (from 22 Aug), but four modules still keep their own copies, and no commits follow the 12 Sep date. |
| **D2 / E4** "Full path … with passing unit & integration coverage" | The tests cover enrollment only. On 19 Sep the suite was 22 tests: 20 enrollment and 2 scaffold. |
| **E3** Prerequisite info in the subject picker | Shown only for blocked subjects. The manual picker is dead code. |
| **General** | There are no commits between 22 Aug and 26 Sep, so items closed on 29 Aug, 12 Sep and 19 Sep aren't backed by repository changes in that window. |

---

## 6. Suggested corrections to the next weekly report (wording only)

- **A1–A6 (core platform):** "Done — implemented and working; automated tests cover role enforcement (added 26 Sep) and enrollment rules. Login, honors, records requests and transcript export are not yet covered by automated tests."
- **A2:** "Role separation tightened 26 Sep so admins can no longer edit student academic records, as required by §1.4."
- **B1:** "Curriculum validation — enforced on the guided flow since May; extended to all enrollment paths 22 Aug."
- **B2:** "Prerequisite check — done; INC and Failed prerequisites block enrollment by design. Tests for INC/Failed cases and the guided next-term flow still to be added."
- **C1:** "UI regression testing — not started in code; backend enrollment tests (20) exist. Frontend test setup needs repair."
- **C2:** "Subject/Program admin UI — not started; subjects and programs are currently seeded."
- **C3:** "Transcript PDF generation done (download). In-app PDF viewer and report card — not started."
- **D1:** "Shared academic-record query introduced 22 Aug and used by enrollment validation; retake, residency, load and progression services still to be migrated onto it."
- **D2 / E4:** "Enrollment rules have 20 passing integration tests (66 tests total as of 26 Sep). Grade correction, GWA/honors and transcript steps are verified manually; automated tests to follow."
- **D3:** "Integration checkpoint agreed as a team practice; not yet enforced by a PR template or CI."
- **E1:** "Grade correction recomputes GWA, honors and eligibility — verified manually; automated test pending. Known issue: the audit log's old-status field is recorded incorrectly."
- **E2:** "Transcripts are generated on demand, so corrections appear immediately."
- **E3:** "Subject picker shows the prerequisite reason for blocked subjects; showing prerequisites for all subjects is pending."
- **F1:** "Curriculum Builder — schema and seeded curricula exist; builder API and screens in progress. Supporting multiple curriculum versions per program needs a schema change."
