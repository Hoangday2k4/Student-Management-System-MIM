# Semester Upgrade Implementation Plan (3NF)

## 1) Scope and rollout strategy
- Rollout mode: `compatibility` in 1-2 sprints.
- Keep existing APIs stable while introducing new semester-aware APIs.
- Cut final traffic to semester-aware APIs only after data reconciliation + UAT pass.

## 2) File-level implementation mapping

### 2.1 Backend migration and schema
- `api/storage/migrate_semester_3nf.sql`
  - Add/normalize `HocKy`.
  - Rebuild `LopHocPhan` with `MaHocKy` FK and `TrangThaiDangKy`.
  - Add/normalize `DangKyHoc`.
  - Add `LanHoc` and unique triple index for `KetQuaHocTap`.

- `api/storage/migrate_studentmanagement_design.php`
  - Keep as baseline full-design migration.
  - Add note to chain-run `migrate_semester_3nf.sql` after this script in compatibility phase.

### 2.2 Backend models
- `api/app/models/Course.php`
  - Add `ma_hoc_ky` handling in create/update/search/detail paths.
  - Add read model for semester object in all course responses.
  - Add enrollment-status setter (`OPEN`, `CLOSED`, `LOCKED`).
  - Conflict check default scope: same semester.

- `api/app/models/Semester.php` (new)
  - CRUD for `HocKy`.
  - Soft-delete (`TrangThai = ARCHIVED`) and restore.
  - Utility: resolve current semester.

- `api/app/models/Enrollment.php` (new or folded into `Course.php`)
  - Query and manage `DangKyHoc` list/status for a section.

### 2.3 Backend controllers and wrappers
- `api/app/controllers/CourseController.php`
  - `index`: add filter by `ma_hoc_ky`.
  - `create/update`: validate `ma_hoc_ky` exists.
  - `detail`: return semester object.
  - `importBulk`: map optional semester column; fallback current semester.
  - Add action for enrollment status toggle.

- `api/app/controllers/SemesterController.php` (new)
  - Endpoints: list/detail/create/update/delete-soft/restore.

- Wrapper files (new)
  - `api/semester.php`
  - `api/semesters/detail.php`

- Existing wrappers to patch
  - `api/course.php`
  - `api/course_detail.php`
  - `api/course_import.php`
  - `api/score.php`

- Route map update
  - `api/routes/api.php`
  - Add semester and semester-scoped course-section routes.

### 2.4 Frontend routing/menu/pages
- `fe/src/router/index.js`
  - Add routes:
    - `/semesters/manage`
    - `/semesters/create`
    - `/semesters/update`

- `fe/src/layouts/PortalLayout.vue`
  - Add admin menu entries for semester management and reports.

- Existing pages to semester-enable
  - `fe/src/pages/CourseManage.vue`
  - `fe/src/pages/CourseCreate.vue`
  - `fe/src/pages/CourseUpdate.vue`
  - `fe/src/pages/CourseDetail.vue`
  - `fe/src/pages/CourseMyList.vue`
  - `fe/src/pages/StudentSchedule.vue`
  - `fe/src/pages/StudentScoreList.vue`
  - `fe/src/pages/CourseGrade.vue`

- New pages
  - `fe/src/pages/SemesterManage.vue`
  - `fe/src/pages/SemesterCreate.vue`
  - `fe/src/pages/SemesterUpdate.vue`

- Service layer
  - `fe/src/services/semesterService.js` (new)
  - `fe/src/services/courseService.js` (extend with `maHocKy` support)

## 3) Compatibility-phase delivery slices

### Sprint A (schema + read compatibility)
- Deploy schema delta SQL.
- Add semester read APIs and include semester data in course responses.
- Frontend reads semester-aware responses but keeps old screens working.

### Sprint B (write compatibility + admin controls)
- Enable semester CRUD.
- Force `ma_hoc_ky` on course-section create/update.
- Enable open/close registration at section level.

### Sprint C (final cutover)
- Remove old semester fields usage (`HocKy`, `NamHoc`) from write logic.
- Keep migration-safe read fallback for one release.
- Freeze and remove fallback code after audit sign-off.

## 4) Non-functional controls
- Add migration checkpoint logs (row counts, orphan checks, duplicate checks).
- Enforce authorization: write = staff/admin only.
- Add request validation consistently for all new endpoints.

## 5) Done criteria
- All create/update section flows require `MaHocKy`.
- Admin can CRUD semester with soft-delete/restore.
- Student/teacher listing, schedule, score are filterable by semester.
- Regression suite passes for legacy student/teacher/account flows.
