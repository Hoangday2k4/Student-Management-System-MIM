# API Test Matrix - Semester Upgrade

## 1) Semester APIs

### 1.1 GET /api/semesters
- Case: list without filters
  - Expected: `200`, non-error payload, pagination metadata present.
- Case: filter `namHoc=2024-2025&ky=1`
  - Expected: `200`, all rows satisfy filter (expect semester `maHocKy=241`).
- Case: invalid `ky=9`
  - Expected: `400` with `SEMESTER_QUERY_INVALID`.

### 1.2 GET /api/semesters/{maHocKy}
- Case: existing code
  - Expected: `200`, correct object.
- Case: non-existing code
  - Expected: `404` with `SEMESTER_NOT_FOUND`.

### 1.3 POST /api/semesters
- Case: valid payload
  - Payload example: `{"maHocKy":"241","tenHocKy":"HK1 2024-2025","namHoc":"2024-2025","ky":1}`
  - Expected: `201`, object created.
- Case: duplicate `maHocKy`
  - Expected: `409` with `SEMESTER_CODE_DUPLICATED`.
- Case: invalid `namHoc` format
  - Expected: `422` with validation details.
- Case: teacher role calls API
  - Expected: `403`.

### 1.4 PUT /api/semesters/{maHocKy}
- Case: update title/status
  - Expected: `200`.
- Case: not found
  - Expected: `404`.
- Case: invalid transition
  - Expected: `409`.

### 1.5 DELETE /api/semesters/{maHocKy} (soft delete)
- Case: existing active semester
  - Expected: `200`, `TrangThai=ARCHIVED`.
- Case: already archived
  - Expected: `409`.

### 1.6 POST /api/semesters/{maHocKy}/restore
- Case: archived semester
  - Expected: `200`, restored status.
- Case: active semester
  - Expected: `409`.

## 2) Course-section APIs with MaHocKy

### 2.1 GET /api/course-sections
- Case: filter by `maHocKy`
  - Expected: `200`, every row has requested semester.
- Case: filter by invalid semester code
  - Expected: `404` `SEMESTER_NOT_FOUND`.

### 2.2 GET /api/course-sections/{maLHP}
- Case: existing section
  - Expected: `200`, includes `hocKy` object + timetable.
- Case: non-existing section
  - Expected: `404`.

### 2.3 POST /api/course-sections
- Case: valid payload with `maHocKy`
  - Expected: `201`.
- Case: missing `maHocKy`
  - Expected: `422`.
- Case: invalid teacher/course FK
  - Expected: `404`.
- Case: same-semester room conflict
  - Expected: `409` `COURSE_SECTION_ROOM_CONFLICT`.

### 2.4 PUT /api/course-sections/{maLHP}
- Case: update capacity/status
  - Expected: `200`.
- Case: change semester when enrollments exist (forbidden)
  - Expected: `409` `COURSE_SECTION_SEMESTER_CHANGE_FORBIDDEN`.

### 2.5 POST /api/course-sections/{maLHP}/enrollment-status
- Case: DRAFT -> OPEN
  - Expected: `200`.
- Case: LOCKED -> OPEN (invalid)
  - Expected: `409`.
- Case: non-admin role
  - Expected: `403`.

### 2.6 GET /api/course-sections/{maLHP}/enrollments
- Case: section has data
  - Expected: `200`, list + pagination metadata.
- Case: unknown section
  - Expected: `404`.

### 2.7 GET /api/course-sections/conflicts/check
- Case: valid timetable in same semester
  - Expected: `200`, `hasConflict=true/false` with details.
- Case: malformed timetable payload
  - Expected: `400`.

### 2.8 GET /api/course-sections/reports/fail-rate?maHocKy=...
- Case: valid semester
  - Expected: `200`, summary + items.
- Case: unknown semester
  - Expected: `404`.

## 3) Regression matrix (must pass)
- Existing student profile APIs remain functional.
- Existing teacher profile APIs remain functional.
- Existing login/logout/session behavior unchanged.
- Existing course list endpoint still responds for old UI (compatibility phase).

## 4) Data integrity verification after migration
- No `LopHocPhan` row with null/blank `MaHocKy`.
- No orphan FK `LopHocPhan.MaHocKy`.
- No duplicate key in `DangKyHoc (MaSV, MaLHP)`.
- No duplicate key in `KetQuaHocTap (MaSV, MaLHP, LanHoc)`.

## 5) Suggested execution order in CI/staging
1. Run schema migration SQL.
2. Run smoke tests for semester APIs.
3. Run integration tests for course-section write flows.
4. Run regression tests for auth/student/teacher flows.
5. Run report API tests.
