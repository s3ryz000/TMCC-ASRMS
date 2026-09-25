# Table 4: Data Dictionary (regenerated from the migrations)

Generated September 26, 2026 from the 38 migration files. Column types are the MySQL types declared in the
migrations (§3.11.1.4); keys, nullability and defaults are introspected from the
migrated schema.

**19 application tables** (205 columns), plus 6 framework-managed tables listed at the end.

## Tables absent from the manuscript's current Table 4

The existing dictionary documents 13 modules. These 6 are implemented but undocumented:

- `curriculum_prerequisites` — 5 columns
- `enrollment_audit_logs` — 18 columns
- `pending_student_updates` — 16 columns
- `program_change_logs` — 10 columns
- `record_requests` — 17 columns
- `record_transactions` — 8 columns


## archive_records

| Field Name | Data Type | Nullable | Default | Constraints / Keys | Relationships |
|---|---|---|---|---|---|
| archive_id | BIGINT UNSIGNED | No | — | PK, NOT NULL, AUTO_INCREMENT | — |
| student_id | BIGINT UNSIGNED | No | — | FK, NOT NULL | students(student_id) |
| record_type | VARCHAR(255) | No | — | NOT NULL | — |
| cabinet_no | VARCHAR(255) | No | — | NOT NULL | — |
| shelf_no | VARCHAR(255) | No | — | NOT NULL | — |
| folder_code | VARCHAR(255) | No | — | NOT NULL | — |
| document_status | VARCHAR(255) | No | — | NOT NULL | — |
| created_at | TIMESTAMP | Yes | — | NULL | — |
| updated_at | TIMESTAMP | Yes | — | NULL | — |

## curriculum

| Field Name | Data Type | Nullable | Default | Constraints / Keys | Relationships |
|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | — | PK, NOT NULL, AUTO_INCREMENT | — |
| program_id | BIGINT UNSIGNED | No | — | FK, UNIQUE, NOT NULL | programs(id) |
| subject_id | BIGINT UNSIGNED | No | — | FK, UNIQUE, NOT NULL | subjects(id) |
| year_level | TINYINT UNSIGNED | No | — | UNIQUE, NOT NULL | — |
| semester | VARCHAR(20) | No | — | UNIQUE, NOT NULL | — |
| created_at | TIMESTAMP | Yes | — | NULL | — |
| updated_at | TIMESTAMP | Yes | — | NULL | — |
| prerequisite | BIGINT UNSIGNED | Yes | — | FK, NULL | subjects(id) |
| unresolved_prerequisites | JSON | Yes | — | NULL | — |
| prerequisite_logic | VARCHAR(10) | No | AND | NOT NULL | — |

## curriculum_prerequisites

| Field Name | Data Type | Nullable | Default | Constraints / Keys | Relationships |
|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | — | PK, NOT NULL, AUTO_INCREMENT | — |
| curriculum_id | BIGINT UNSIGNED | No | — | FK, UNIQUE, NOT NULL | curriculum(id) |
| prerequisite_subject_id | BIGINT UNSIGNED | No | — | FK, UNIQUE, NOT NULL | subjects(id) |
| created_at | TIMESTAMP | Yes | — | NULL | — |
| updated_at | TIMESTAMP | Yes | — | NULL | — |

## enrollment_audit_logs

| Field Name | Data Type | Nullable | Default | Constraints / Keys | Relationships |
|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | — | PK, NOT NULL, AUTO_INCREMENT | — |
| student_id | BIGINT UNSIGNED | No | — | FK, NOT NULL | students(student_id) |
| enrollment_id | BIGINT UNSIGNED | No | — | NOT NULL | — |
| subject_id | BIGINT UNSIGNED | No | — | FK, NOT NULL | subjects(id) |
| academic_year | VARCHAR(20) | No | — | NOT NULL | — |
| semester | VARCHAR(20) | No | — | NOT NULL | — |
| old_status | VARCHAR(30) | Yes | — | NULL | — |
| new_status | VARCHAR(30) | Yes | — | NULL | — |
| changed_by | BIGINT UNSIGNED | Yes | — | NULL | — |
| action | VARCHAR(50) | No | — | NOT NULL | — |
| reason | VARCHAR(255) | Yes | — | NULL | — |
| had_grade | TINYINT(1) | No | 0 | NOT NULL | — |
| created_at | TIMESTAMP | Yes | — | NULL | — |
| updated_at | TIMESTAMP | Yes | — | NULL | — |
| old_value | TEXT | Yes | — | NULL | — |
| new_value | TEXT | Yes | — | NULL | — |
| supporting_document_reference | VARCHAR(255) | Yes | — | NULL | — |
| user_role | VARCHAR(30) | Yes | — | NULL | — |

## enrollments

| Field Name | Data Type | Nullable | Default | Constraints / Keys | Relationships |
|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | — | PK, NOT NULL, AUTO_INCREMENT | — |
| student_id | BIGINT UNSIGNED | No | — | FK, NOT NULL | students(student_id) |
| subject_id | BIGINT UNSIGNED | No | — | FK, NOT NULL | subjects(id) |
| academic_year | VARCHAR(20) | No | — | NOT NULL | — |
| semester | VARCHAR(20) | No | — | NOT NULL | — |
| status | VARCHAR(20) | No | enrolled | NOT NULL | — |
| created_at | TIMESTAMP | Yes | — | NULL | — |
| updated_at | TIMESTAMP | Yes | — | NULL | — |
| deleted_at | TIMESTAMP | Yes | — | NULL | — |
| deleted_by | BIGINT UNSIGNED | Yes | — | NULL | — |
| delete_reason | VARCHAR(255) | Yes | — | NULL | — |
| year_level | TINYINT UNSIGNED | Yes | — | NULL | — |
| is_retake | TINYINT(1) | No | 0 | NOT NULL | — |

## grades

| Field Name | Data Type | Nullable | Default | Constraints / Keys | Relationships |
|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | — | PK, NOT NULL, AUTO_INCREMENT | — |
| student_id | BIGINT UNSIGNED | No | — | FK, UNIQUE, NOT NULL | students(student_id) |
| subject_id | BIGINT UNSIGNED | No | — | FK, UNIQUE, NOT NULL | subjects(id) |
| academic_year | VARCHAR(20) | No | — | UNIQUE, NOT NULL | — |
| semester | VARCHAR(20) | No | — | UNIQUE, NOT NULL | — |
| grade_value | DECIMAL(4,2) | Yes | — | NULL | — |
| remarks | VARCHAR(50) | Yes | — | NULL | — |
| created_at | TIMESTAMP | Yes | — | NULL | — |
| updated_at | TIMESTAMP | Yes | — | NULL | — |
| status | VARCHAR(30) | Yes | — | NULL | — |
| enrollment_id | BIGINT UNSIGNED | Yes | — | NULL | — |
| supporting_document_reference | VARCHAR(255) | Yes | — | NULL | — |
| converted_from_status | VARCHAR(30) | Yes | — | NULL | — |
| converted_at | TIMESTAMP | Yes | — | NULL | — |

## pending_student_updates

| Field Name | Data Type | Nullable | Default | Constraints / Keys | Relationships |
|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | — | PK, NOT NULL, AUTO_INCREMENT | — |
| student_id | BIGINT UNSIGNED | No | — | FK, NOT NULL | students(student_id) |
| submitted_by | BIGINT UNSIGNED | Yes | — | FK, NULL | users(id) |
| reviewed_by | BIGINT UNSIGNED | Yes | — | FK, NULL | users(id) |
| status | ENUM | No | pending | NOT NULL | — |
| old_values | JSON | No | — | NOT NULL | — |
| new_values | JSON | No | — | NOT NULL | — |
| changed_fields | JSON | Yes | — | NULL | — |
| rejection_reason | TEXT | Yes | — | NULL | — |
| reviewed_at | TIMESTAMP | Yes | — | NULL | — |
| created_at | TIMESTAMP | Yes | — | NULL | — |
| updated_at | TIMESTAMP | Yes | — | NULL | — |
| supporting_document_path | VARCHAR(255) | Yes | — | NULL | — |
| supporting_document_original_name | VARCHAR(255) | Yes | — | NULL | — |
| supporting_document_mime | VARCHAR(255) | Yes | — | NULL | — |
| supporting_document_size | BIGINT UNSIGNED | Yes | — | NULL | — |

## program_change_logs

| Field Name | Data Type | Nullable | Default | Constraints / Keys | Relationships |
|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | — | PK, NOT NULL, AUTO_INCREMENT | — |
| student_id | BIGINT UNSIGNED | No | — | FK, NOT NULL | students(student_id) |
| old_program_id | BIGINT UNSIGNED | Yes | — | FK, NULL | programs(id) |
| new_program_id | BIGINT UNSIGNED | No | — | FK, NOT NULL | programs(id) |
| reason | VARCHAR(100) | No | — | NOT NULL | — |
| remarks | TEXT | Yes | — | NULL | — |
| changed_by | BIGINT UNSIGNED | No | — | FK, NOT NULL | users(id) |
| affected_enrollments_archived | INT | No | 0 | NOT NULL | — |
| created_at | TIMESTAMP | Yes | — | NULL | — |
| updated_at | TIMESTAMP | Yes | — | NULL | — |

## program_mappings

| Field Name | Data Type | Nullable | Default | Constraints / Keys | Relationships |
|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | — | PK, NOT NULL, AUTO_INCREMENT | — |
| program_id | BIGINT UNSIGNED | No | — | FK, NOT NULL | programs(id) |
| student_id | BIGINT UNSIGNED | No | — | FK, NOT NULL | students(student_id) |
| academic_year | VARCHAR(255) | No | — | NOT NULL | — |
| semester | VARCHAR(255) | No | — | NOT NULL | — |
| status | VARCHAR(255) | No | enrolled | NOT NULL | — |
| year_level | INT | Yes | — | NULL | — |
| created_at | TIMESTAMP | Yes | — | NULL | — |
| updated_at | TIMESTAMP | Yes | — | NULL | — |

## programs

| Field Name | Data Type | Nullable | Default | Constraints / Keys | Relationships |
|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | — | PK, NOT NULL, AUTO_INCREMENT | — |
| code | VARCHAR(20) | No | — | UNIQUE, NOT NULL | — |
| name | VARCHAR(150) | No | — | NOT NULL | — |
| description | VARCHAR(255) | Yes | — | NULL | — |
| created_at | TIMESTAMP | Yes | — | NULL | — |
| updated_at | TIMESTAMP | Yes | — | NULL | — |

## record_requests

| Field Name | Data Type | Nullable | Default | Constraints / Keys | Relationships |
|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | — | PK, NOT NULL, AUTO_INCREMENT | — |
| student_id | BIGINT UNSIGNED | No | — | FK, NOT NULL | students(student_id) |
| record_type | VARCHAR(50) | No | — | NOT NULL | — |
| purpose | VARCHAR(255) | Yes | — | NULL | — |
| copies | TINYINT UNSIGNED | No | 1 | NOT NULL | — |
| status | VARCHAR(20) | No | pending | NOT NULL | — |
| requested_at | TIMESTAMP | No | — | NOT NULL | — |
| processed_by | BIGINT UNSIGNED | Yes | — | FK, NULL | staff(staff_id) |
| processed_at | TIMESTAMP | Yes | — | NULL | — |
| released_at | TIMESTAMP | Yes | — | NULL | — |
| rejection_reason | TEXT | Yes | — | NULL | — |
| created_at | TIMESTAMP | Yes | — | NULL | — |
| updated_at | TIMESTAMP | Yes | — | NULL | — |
| appointment_at | TIMESTAMP | Yes | — | NULL | — |
| academic_year | VARCHAR(20) | Yes | — | NULL | — |
| semester | VARCHAR(20) | Yes | — | NULL | — |
| award_name | VARCHAR(100) | Yes | — | NULL | — |

## record_transactions

| Field Name | Data Type | Nullable | Default | Constraints / Keys | Relationships |
|---|---|---|---|---|---|
| transaction_id | BIGINT UNSIGNED | No | — | PK, NOT NULL, AUTO_INCREMENT | — |
| student_id | BIGINT UNSIGNED | No | — | FK, NOT NULL | students(student_id) |
| staff_id | BIGINT UNSIGNED | No | — | FK, NOT NULL | staff(staff_id) |
| transaction_type | VARCHAR(50) | No | — | NOT NULL | — |
| transaction_date | DATETIME | No | — | NOT NULL | — |
| status | VARCHAR(20) | No | — | NOT NULL | — |
| created_at | TIMESTAMP | Yes | — | NULL | — |
| updated_at | TIMESTAMP | Yes | — | NULL | — |

## reports

| Field Name | Data Type | Nullable | Default | Constraints / Keys | Relationships |
|---|---|---|---|---|---|
| report_id | BIGINT UNSIGNED | No | — | PK, NOT NULL, AUTO_INCREMENT | — |
| report_type | VARCHAR(50) | No | — | NOT NULL | — |
| generated_date | DATETIME | No | — | NOT NULL | — |
| content | TEXT | No | — | NOT NULL | — |
| created_at | TIMESTAMP | Yes | — | NULL | — |
| updated_at | TIMESTAMP | Yes | — | NULL | — |

## staff

| Field Name | Data Type | Nullable | Default | Constraints / Keys | Relationships |
|---|---|---|---|---|---|
| staff_id | BIGINT UNSIGNED | No | — | PK, NOT NULL, AUTO_INCREMENT | — |
| first_name | VARCHAR(50) | No | — | NOT NULL | — |
| last_name | VARCHAR(50) | No | — | NOT NULL | — |
| role | VARCHAR(50) | No | — | NOT NULL | — |
| email | VARCHAR(100) | No | — | UNIQUE, NOT NULL | — |
| created_at | TIMESTAMP | Yes | — | NULL | — |
| updated_at | TIMESTAMP | Yes | — | NULL | — |
| user_id | BIGINT UNSIGNED | Yes | — | FK, NULL | users(id) |

## students

| Field Name | Data Type | Nullable | Default | Constraints / Keys | Relationships |
|---|---|---|---|---|---|
| student_id | BIGINT UNSIGNED | No | — | PK, NOT NULL, AUTO_INCREMENT | — |
| student_number | VARCHAR(20) | No | — | UNIQUE, NOT NULL | — |
| first_name | VARCHAR(50) | No | — | NOT NULL | — |
| last_name | VARCHAR(50) | No | — | NOT NULL | — |
| date_of_birth | DATE | No | — | NOT NULL | — |
| email | VARCHAR(100) | No | — | UNIQUE, NOT NULL | — |
| contact_number | VARCHAR(15) | Yes | — | NULL | — |
| address | VARCHAR(150) | Yes | — | NULL | — |
| enrollment_date | DATE | No | — | NOT NULL | — |
| graduation_date | DATE | Yes | — | NULL | — |
| GPA | DECIMAL(3,2) | Yes | — | NULL | — |
| created_at | TIMESTAMP | Yes | — | NULL | — |
| updated_at | TIMESTAMP | Yes | — | NULL | — |
| user_id | BIGINT UNSIGNED | Yes | — | FK, NULL | users(id) |
| program_id | BIGINT UNSIGNED | Yes | — | FK, NULL | programs(id) |
| middle_name | VARCHAR(50) | Yes | — | NULL | — |
| place_of_birth | VARCHAR(120) | Yes | — | NULL | — |
| sex | VARCHAR(10) | Yes | — | NULL | — |
| guardian_name | VARCHAR(120) | Yes | — | NULL | — |
| citizenship | VARCHAR(60) | Yes | — | NULL | — |
| elementary_school | VARCHAR(150) | Yes | — | NULL | — |
| elementary_year | SMALLINT UNSIGNED | Yes | — | NULL | — |
| high_school | VARCHAR(150) | Yes | — | NULL | — |
| high_school_year | SMALLINT UNSIGNED | Yes | — | NULL | — |
| previous_school | VARCHAR(150) | Yes | — | NULL | — |
| previous_course | VARCHAR(150) | Yes | — | NULL | — |

## subjects

| Field Name | Data Type | Nullable | Default | Constraints / Keys | Relationships |
|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | — | PK, NOT NULL, AUTO_INCREMENT | — |
| code | VARCHAR(20) | No | — | UNIQUE, NOT NULL | — |
| title | VARCHAR(150) | No | — | UNIQUE, NOT NULL | — |
| units | TINYINT UNSIGNED | No | 3 | NOT NULL | — |
| description | VARCHAR(255) | Yes | — | NULL | — |
| created_at | TIMESTAMP | Yes | — | NULL | — |
| updated_at | TIMESTAMP | Yes | — | NULL | — |

## system_logs

| Field Name | Data Type | Nullable | Default | Constraints / Keys | Relationships |
|---|---|---|---|---|---|
| log_id | BIGINT UNSIGNED | No | — | PK, NOT NULL, AUTO_INCREMENT | — |
| action | VARCHAR(255) | No | — | NOT NULL | — |
| user_id | BIGINT UNSIGNED | No | — | FK, NOT NULL | users(id) |
| role | VARCHAR(255) | No | — | NOT NULL | — |
| created_at | TIMESTAMP | Yes | — | NULL | — |
| updated_at | TIMESTAMP | Yes | — | NULL | — |

## system_settings

| Field Name | Data Type | Nullable | Default | Constraints / Keys | Relationships |
|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | — | PK, NOT NULL, AUTO_INCREMENT | — |
| key | VARCHAR(100) | No | — | UNIQUE, NOT NULL | — |
| value | TEXT | Yes | — | NULL | — |
| created_at | TIMESTAMP | Yes | — | NULL | — |
| updated_at | TIMESTAMP | Yes | — | NULL | — |

## users

| Field Name | Data Type | Nullable | Default | Constraints / Keys | Relationships |
|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | — | PK, NOT NULL, AUTO_INCREMENT | — |
| name | VARCHAR(255) | No | — | NOT NULL | — |
| email | VARCHAR(255) | No | — | UNIQUE, NOT NULL | — |
| username | VARCHAR(255) | No | — | UNIQUE, NOT NULL | — |
| email_verified_at | TIMESTAMP | Yes | — | NULL | — |
| role | VARCHAR(255) | No | — | NOT NULL | — |
| password | VARCHAR(255) | No | — | NOT NULL | — |
| remember_token | VARCHAR(100) | Yes | — | NULL | — |
| created_at | TIMESTAMP | Yes | — | NULL | — |
| updated_at | TIMESTAMP | Yes | — | NULL | — |
| department | VARCHAR(100) | Yes | — | NULL | — |
| status | VARCHAR(20) | No | active | NOT NULL | — |


## Summary

| Table | Columns | In manuscript Table 4? |
|---|---|---|
| archive_records | 9 | Yes |
| curriculum | 10 | Yes |
| curriculum_prerequisites | 5 | **No — add** |
| enrollment_audit_logs | 18 | **No — add** |
| enrollments | 13 | Yes |
| grades | 14 | Yes |
| pending_student_updates | 16 | **No — add** |
| program_change_logs | 10 | **No — add** |
| program_mappings | 9 | Yes |
| programs | 6 | Yes |
| record_requests | 17 | **No — add** |
| record_transactions | 8 | **No — add** |
| reports | 6 | Yes |
| staff | 8 | Yes |
| students | 26 | Yes |
| subjects | 7 | Yes |
| system_logs | 6 | Yes |
| system_settings | 5 | Yes |
| users | 12 | Yes |
| **Total** | **205** | **6 to add** |


## Framework-managed tables (not part of the records model)

Created by Laravel Sanctum and the Spatie permission package. Worth a one-line
footnote in the manuscript rather than full column documentation:

- `permissions`
- `roles`
- `model_has_permissions`
- `model_has_roles`
- `role_has_permissions`
- `personal_access_tokens`
