import { createRouter, createWebHistory } from 'vue-router'
import { getAuth } from '../authStore.js'
import PortalLayout from '../layouts/PortalLayout.vue'
import Home from '../pages/Home.vue'
import Login from '../pages/Login.vue'
import Register from '../pages/Register.vue'
import RequestReset from '../pages/RequestReset.vue'
import ResetPassword from '../pages/ResetPassword.vue'
import StudentCreate from '../pages/StudentCreate.vue'
import StudentAdminEdit from '../pages/StudentAdminEdit.vue'
import StudentSearch from '../pages/StudentSearch.vue'
import ChangePassword from '../pages/ChangePassword.vue'
import StudentProfile from '../pages/StudentProfile.vue'
import StudentProfileUpdate from '../pages/StudentProfileUpdate.vue'
import TeacherCreate from '../pages/TeacherCreate.vue'
import TeacherSearch from '../pages/TeacherSearch.vue'
import TeacherAdminEdit from '../pages/TeacherAdminEdit.vue'
import TeacherProfile from '../pages/TeacherProfile.vue'
import TeacherProfileUpdate from '../pages/TeacherProfileUpdate.vue'
import CourseCreate from '../pages/CourseCreate.vue'
import CourseManage from '../pages/CourseManage.vue'
import SectionManage from '../pages/SectionManage.vue'
import SectionCreate from '../pages/SectionCreate.vue'
import FacultyManage from '../pages/FacultyManage.vue'
import MajorManage from '../pages/MajorManage.vue'
import ClassManage from '../pages/ClassManage.vue'
import ClassDetail from '../pages/ClassDetail.vue'
import FacultyForm from '../pages/FacultyForm.vue'
import MajorForm from '../pages/MajorForm.vue'
import ClassForm from '../pages/ClassForm.vue'
import CourseDetail from '../pages/CourseDetail.vue'
import CourseUpdate from '../pages/CourseUpdate.vue'
import SubjectDetail from '../pages/SubjectDetail.vue'
import SubjectForm from '../pages/SubjectForm.vue'
import CourseMyList from '../pages/CourseMyList.vue'
import StudentImport from '../pages/StudentImport.vue'
import TeacherImport from '../pages/TeacherImport.vue'
import CourseGrade from '../pages/CourseGrade.vue'
import StudentScoreList from '../pages/StudentScoreList.vue'
import StudentScoreDetail from '../pages/StudentScoreDetail.vue'
import StudentSchedule from '../pages/StudentSchedule.vue'

const routes = [
  { path: '/login', name: 'login', component: Login },
  { path: '/register', name: 'register', component: Register },
  { path: '/reset-password-request', name: 'reset-password-request', component: RequestReset },
  {
    path: '/',
    component: PortalLayout,
    children: [
      { path: '', name: 'home', component: Home },
      { path: 'faculties/manage', name: 'faculty-manage', component: FacultyManage, meta: { menuKey: 'faculty-manage' } },
      { path: 'faculties/form', name: 'faculty-form', component: FacultyForm, meta: { menuKey: 'faculty-manage' } },
      { path: 'majors/manage', name: 'major-manage', component: MajorManage, meta: { menuKey: 'major-manage' } },
      { path: 'majors/form', name: 'major-form', component: MajorForm, meta: { menuKey: 'major-manage' } },
      { path: 'classes/manage', name: 'class-manage', component: ClassManage, meta: { menuKey: 'class-manage' } },
      { path: 'classes/detail', name: 'class-detail', component: ClassDetail, meta: { menuKey: 'class-manage' } },
      { path: 'classes/form', name: 'class-form', component: ClassForm, meta: { menuKey: 'class-manage' } },
      { path: 'reset-password', name: 'reset-password', component: ResetPassword },
      { path: 'students/import', name: 'student-import', component: StudentImport, meta: { menuKey: 'student-search' } },
      { path: 'teachers/import', name: 'teacher-import', component: TeacherImport, meta: { menuKey: 'teacher-search' } },
      { path: 'students/create', name: 'student-create', component: StudentCreate, meta: { menuKey: 'student-search' } },
      { path: 'students/edit', name: 'student-admin-edit', component: StudentAdminEdit, meta: { menuKey: 'student-search' } },
      { path: 'students/search', name: 'student-search', component: StudentSearch, meta: { menuKey: 'student-search' } },
      { path: 'change-password', name: 'change-password', component: ChangePassword, meta: { menuKey: 'change-password' } },
      { path: 'students/profile', name: 'student-profile', component: StudentProfile, meta: { menuKey: 'student-profile' } },
      { path: 'students/profile/update', name: 'student-profile-update', component: StudentProfileUpdate, meta: { menuKey: 'student-profile-update' } },
      { path: 'teachers/create', name: 'teacher-create', component: TeacherCreate, meta: { menuKey: 'teacher-search' } },
      { path: 'teachers/edit', name: 'teacher-admin-edit', component: TeacherAdminEdit, meta: { menuKey: 'teacher-search' } },
      { path: 'teachers/search', name: 'teacher-search', component: TeacherSearch, meta: { menuKey: 'teacher-search' } },
      { path: 'teachers/profile', name: 'teacher-profile', component: TeacherProfile, meta: { menuKey: 'teacher-profile' } },
      { path: 'teachers/profile/update', name: 'teacher-profile-update', component: TeacherProfileUpdate, meta: { menuKey: 'teacher-profile-update' } },
      { path: 'courses/create', name: 'course-create', component: CourseCreate, meta: { menuKey: 'course-manage' } },
      { path: 'courses/manage', name: 'course-manage', component: CourseManage, meta: { menuKey: 'course-manage' } },
      { path: 'sections/manage', name: 'section-manage', component: SectionManage, meta: { menuKey: 'section-manage' } },
      { path: 'sections/create', name: 'section-create', component: SectionCreate, meta: { menuKey: 'section-manage' } },
      { path: 'sections/detail', name: 'section-detail', component: CourseDetail, meta: { menuKey: 'section-manage' } },
      { path: 'sections/update', name: 'section-update', component: CourseUpdate, meta: { menuKey: 'section-manage' } },
      { path: 'subjects/detail', name: 'subject-detail', component: SubjectDetail, meta: { menuKey: 'course-manage' } },
      { path: 'subjects/form', name: 'subject-form', component: SubjectForm, meta: { menuKey: 'course-manage' } },
      { path: 'courses/detail', name: 'course-detail', component: CourseDetail, meta: { menuKey: 'course-detail' } },
      { path: 'courses/update', name: 'course-update', component: CourseUpdate, meta: { menuKey: 'course-manage' } },
      { path: 'courses/grade', name: 'course-grade', component: CourseGrade, meta: { menuKey: 'teacher-course-my' } },
      { path: 'courses/my', name: 'course-my', component: CourseMyList, meta: { menuKey: 'course-my' } },
      { path: 'teachers/courses', name: 'teacher-course-my', component: CourseMyList, meta: { menuKey: 'teacher-course-my' } },
      { path: 'students/courses', name: 'student-course-my', component: CourseMyList, meta: { menuKey: 'student-course-my' } },
      { path: 'students/scores', name: 'student-score-list', component: StudentScoreList, meta: { menuKey: 'student-score-list' } },
      { path: 'students/scores/detail', name: 'student-score-detail', component: StudentScoreDetail, meta: { menuKey: 'student-score-list' } },
      { path: 'students/schedule', name: 'student-schedule', component: StudentSchedule, meta: { menuKey: 'student-schedule' } },
    ],
  },
]

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes,
})

async function parseJsonSafe(res) {
  const raw = await res.text()
  const text = raw.replace(/^\uFEFF/, '').trim()
  if (!text) return null
  try {
    return JSON.parse(text)
  } catch (e) {
    return null
  }
}

router.beforeEach(async (to, from, next) => {
  const publicPages = ['login', 'register', 'reset-password-request']
  if (publicPages.includes(to.name)) {
    next()
    return
  }

  try {
    const data = await getAuth()
    if (data?.login_id) {
      next()
      return
    }
  } catch (e) {
    // ignore
  }

  next({ name: 'login' })
})

export default router
