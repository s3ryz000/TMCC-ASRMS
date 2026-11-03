# ASRMS Phase 2: TMCC's 27 Oct 2026 recommendations

Source: letter from Denise Marie G. Castillo, Ph.D., Registrar, Trece Martires City College, dated 27 October 2026 ("preliminary institutional recommendations", subject to institutional validation before implementation).

Work happens on the branch **`phase-2`**. `main` stays what runs on the live site (asrms-tmcc.duckdns.org) until Phase 2 is tested and TMCC validates it.

---

## 1. Database plan: how it fits the current schema

### What exists today (keep, extend)
| Table | Today | Phase 2 change |
|---|---|---|
| `users` + spatie `roles` (`admin`, `staff`, `student`), guard `api` | 3 roles | Add roles `faculty`, `coordinator`, `dean`, `registrar` (head). `staff` stays as registrar staff. |
| `programs` (code, name, description, archived_at) | 3 programs | Add `school_id` (FK). |
| `subjects`, `curriculum`, `curriculum_prerequisites` | Curriculum Builder | No change. Data entry for all 9 programs. |
| `students` (student_number YY+4, program_id, …) | | Add `section_id` (current block section, nullable). |
| `enrollments` (student, subject, AY, semester, status) | | Add `class_offering_id` (nullable FK), so an enrollment knows its class and teacher. |
| `grades` | Only approved values (#107) | No change. Still written only on final posting. |
| `grade_submissions` (+ `grade_submission_items`) | One step: `registrar` | Already has a **`step`** column. Phase 2 makes it a chain: `faculty` → `coordinator` → `dean` → `registrar_staff` → `registrar` → posted. Add `class_offering_id` (a submission per class, not per student), `deadline_at`. |
| `enrollment_audit_logs`, `system_logs` | Audit trail | Log every step: endorse, approve, return. |
| `record_requests` (record_type …) | TOR, COG, Copy of Grades, Latin honor | New document types, fees, processing days, signatories (config tables below). |
| `pending_student_updates` | Profile corrections | No change. |
| `system_settings` | AY / semester | Add grading rules and the submission deadline. |

### New tables
| Table | Columns (main) | Why (letter item) |
|---|---|---|
| `schools` | id, code, name, dean_user_id (FK users, nullable), archived_at | 1A: programs grouped under schools, each with a dean |
| `program_coordinators` | program_id, user_id, from/to dates | 1B: coordinator per program ("if applicable") |
| `faculty_profiles` | user_id, employee_no, school_id, status | 1C: faculty accounts |
| `sections` | id, program_id, year_level, name (e.g. "BSHM 2A"), academic_year, semester, adviser_user_id | 4A: block sections |
| `class_offerings` | id, subject_id, section_id (nullable for mixed classes), academic_year, semester, faculty_user_id, schedule_text, capacity, status | 4A: who teaches what; the unit a faculty member grades |
| `grade_approval_steps` | grade_submission_id, step, acted_by, action (submitted/endorsed/approved/returned), remarks, acted_at | 2A, 2D: full step history (audit trail) |
| `grade_change_requests` | grade_id, requested_by (faculty), old/new value and status, reason, form_reference (uploaded Change of Grade form), step, status | 3C: Faculty → Dean → Registrar |
| `completion_requirements` | grade_id, type (INC / CONDITIONAL), due_at, resolved_at, flagged_at | 3A: monitor completion and flag overdue |
| `grading_rules` | code (e.g. "4.0", "INC"), label, counts_as, resolves_to (JSON), completion_days, enabled | 3B: registrar-configurable rules |
| `admission_requirements` + `student_requirement_checks` | requirement name, required?, per student: submitted?, verified_by, file | 5A: configurable admission checklist |
| `document_types` | code, name, fee, processing_days, requires_clearance, signatory_title, enabled | 6A–6C: TOR, COG, Certificate of Enrollment, Graduation, Good Moral, others; fees; signatories |
| `graduation_evaluations` | student_id, AY, status, endorsed_by_dean, validated_by_registrar, latin_honor (proposed), remarks | 7A: candidates and Latin honors, endorsed by dean, validated by registrar |

### How a grade moves in Phase 2
```
class_offering (faculty X teaches SUBJ in BSHM 2A, AY 2026-2027 sem 1)
  └─ faculty encodes the Report of Grades for the whole class
       → grade_submission (step=faculty → submitted)
       → coordinator endorses (if the program has one)
       → dean approves
       → registrar staff verifies
       → college registrar approves → grades rows written (official posting)
  any step can return with remarks → back to faculty; every action in grade_approval_steps
```
- **The registrar-only flow keeps working.** For subjects with no class offering or faculty yet (and old records), registrar staff still encode and the registrar approves, exactly like today. This lets TMCC adopt faculty encoding gradually.
- **Students still see only posted grades.** Nothing changes for readers (GWA, prerequisites, report card, transcript).

### Migration safety
- Every new column is nullable, so existing data stays valid with no backfill on day one.
- Migrations must run on SQLite (local) and MySQL 8 (server), reversibly. Row counts are checked before and after.
- Seeders only create reference data (roles, grading rules for 1.0–5.0 / 4.0 / INC, the document types the letter lists). They never create real people.

---

## 2. Information and decisions we need from TMCC

Send as one request to Dr. Castillo (Registrar) and Mr. Pimentel (MIS), copied to the College Administration, since the letter says they "shall provide" several of these. **Use blank forms, or remove personal data.**

### A. Organization (needed first: everything hangs on it)
1. The validated list of the **9 programs** (code and full name) and which of the **4 schools** each belongs to.
2. The **dean** of each school, and the **program coordinator** of each program (or "none").
3. The **faculty list**: name, employee/ID number format, school, and full-time/part-time if it matters.
4. Who are the **Registrar Staff** vs the **College Registrar** in the approval chain? Can one person hold two steps (e.g. a dean who also teaches)?
5. Who in **MIS** administers the system (accounts, settings)?

### B. Curriculum and subjects
6. The official **prospectus of each of the 9 programs**: subject codes, titles, units (lec/lab), year and semester, prerequisites, and co-requisites.
7. TMCC's **own subject code format**, and how general-education subjects shared by all programs are coded.
8. Are there **curriculum versions** (e.g. by entry year, CHED memorandum)? Which batch follows which?
9. The **regular unit load** per program, year and semester, and the maximum allowed load for irregular students.

### C. Students and IDs
10. The official **student number format** (is "YY + 4 digits", e.g. 260001, correct?), and how numbers are assigned: sequence per year, per program?
11. **Employee/faculty ID format** (for faculty accounts).
12. Which **student details** are required at admission, and which are optional?
13. The **admission requirements checklist** (e.g. Form 138, PSA birth certificate, good moral), and which are required for new students vs transferees.
14. Should students sign in with their **TMCC Google Workspace account** later, or keep student number + password?

### D. Sections and enrollment
15. How **block sections** are named (e.g. "BSHM 2A") and who creates them each term.
16. How **faculty are assigned** to subjects and sections, by whom, and when.
17. How **irregular students** are placed: own schedule, joined to another section?
18. Who approves **special enrollment arrangements** (overloads, petitioned subjects, cross-enrollment)?
19. The **academic calendar**: semester dates, enrollment period, summer/midyear term?

### E. Grades
20. A copy of the official **Report of Grades** form (blank) that faculty submit.
21. The **grading scale** in full: 1.0–5.0 steps, passing grade, 4.0 Conditional, INC, Dropped/Withdrawn/FDA codes, and how each is written on the form.
22. The **completion period** for INC and 4.0, and what happens when it lapses (becomes 5.0?).
23. The **grade submission deadline** per term, and who sets it.
24. A copy of the **Change of Grade Form** and the **Completion Form**.
25. Can the dean or registrar **edit** a grade, or only return it to faculty?

### F. Documents and fees
26. The official list of **issuable documents** (TOR, COG, Certificate of Enrollment, Graduation, Good Moral, others).
27. The **schedule of fees**, processing days and clearance requirements for each.
28. The **signatories** for each document (name and title), and whether the system should print their names.
29. Blank samples of each document (layout for printing).

### G. Honors and graduation
30. The **Latin honors** criteria (GWA ranges, no grade below X, residency rules), and who validates them.
31. The steps for **graduation evaluation** (department → dean → registrar?), and the forms used.
32. Confirm that the **Dean's List / President's List** stay out until the College publishes criteria (letter 7B).

### H. IT, hosting and policy
33. **Google Workspace**: domain, whether faculty and students have accounts, and whether MIS allows sign-in with Google.
34. **City government system**: contact person, what it covers, and what data ASRMS should exchange with it (8A).
35. **Hosting and domain** after the pilot: TMCC server, city data center, or keep DigitalOcean? Will `tmcc.edu.ph` be available (e.g. `asrms.tmcc.edu.ph`)?
36. **Records retention and data protection policy**, and the Data Protection Officer's name (for DPA compliance).
37. Who signs the **final institutional validation and approval** before Phase 2 goes live?

---

## 3. Branch and release plan

- **Branch:** `phase-2`, created from `main` after #107/#108. All Phase 2 work is committed there.
- **`main`** stays the live system. Urgent fixes go to `main` first, then are merged into `phase-2` (`git merge main` on the branch) so both stay in step.
- **GitHub Actions** already run the tests on every push to `main`. Add `phase-2` to the workflow's branches so the branch is tested too.
- **Separate test database and server:** the branch never touches the live database.
  - Locally: its own SQLite file, `database.phase2.sqlite`.
  - For TMCC's validation: a second, cheaper Droplet ("staging", about USD 6–12 a month) or a separate site on the same server with its own database (`asrms_staging`) at a different address.
- **Release:** only after the tests pass, TMCC validates on staging, and the adviser agrees. Then `phase-2` is merged into `main`, and the live site is updated with `sudo asrms-update` (backup first).

### Build order (each one a ticket and a VS Code prompt)
| # | Module | Needs from TMCC (section 2) |
|---|---|---|
| P2-1 | Schools, deans, coordinators, faculty accounts and roles | A |
| P2-2 | All 9 programs' curricula (data entry in the Curriculum Builder) | B |
| P2-3 | Sections and class offerings (faculty assignment) | D |
| P2-4 | Faculty Report of Grades and the full approval chain (extends #107) | E20, E21, E23 |
| P2-5 | Change of Grade (Faculty → Dean → Registrar) with the form | E24, E25 |
| P2-6 | Configurable grading rules; INC / 4.0 completion monitoring and overdue flags | E21, E22 |
| P2-7 | Document types, fees, processing days, signatories | F |
| P2-8 | Admission requirements checklist | C13 |
| P2-9 | Graduation evaluation and Latin honors endorsement | G |
| P2-10 | Deadlines and notifications (grade submission, completion) | E23 |
| P2-11 | Google Workspace sign-in (optional) | H33 |
| P2-12 | Integration points for the city system (API/export design only) | H34 |

---

## 4. Testing plan

1. **Automated tests on every change** (backend PHPUnit, frontend Jest), on `phase-2` in GitHub Actions:
   - each step of the grade chain, plus return and resubmit;
   - nobody can approve their own submission, and nobody can skip a step;
   - a faculty member sees only their own classes, and a dean only their school's;
   - students see only posted grades (extend the access matrix tests to the new roles);
   - grade changes keep the old and new value;
   - completion deadlines flag overdue grades;
   - migrations work up and down on SQLite and MySQL.
2. **Regression:** all existing tests stay green, so the registrar-only flow and student portal behave exactly as today.
3. **Dummy data only** (letter item 10): a seeder that creates fictional schools, faculty, sections and students for staging, never real records.
4. **Role-by-role UAT on staging** with TMCC. A faculty member, a coordinator, a dean, registrar staff and the registrar each run a checklist. Results are recorded and signed.
5. **Security checks:** OWASP ZAP scan of staging, an access-control test for each new role, and a load test with all roles at once.
6. **Acceptance and validation:** TMCC signs off (letter: "final institutional validation and approval") before the merge to `main` and the live update.
