import { createRouter, createWebHistory } from 'vue-router'
import PortalLayout from '../layouts/PortalLayout.vue'
import Home from '../pages/Home.vue'
import Login from '../pages/Login.vue'
import Register from '../pages/Register.vue'
import RequestReset from '../pages/RequestReset.vue'
import ResetPassword from '../pages/ResetPassword.vue'
import StudentCreate from '../pages/StudentCreate.vue'
import StudentSearch from '../pages/StudentSearch.vue'
import ChangePassword from '../pages/ChangePassword.vue'
import StudentProfile from '../pages/StudentProfile.vue'
import StudentProfileUpdate from '../pages/StudentProfileUpdate.vue'
import TeacherCreate from '../pages/TeacherCreate.vue'
import TeacherSearch from '../pages/TeacherSearch.vue'
import TeacherProfile from '../pages/TeacherProfile.vue'
import TeacherProfileUpdate from '../pages/TeacherProfileUpdate.vue'
import CourseCreate from '../pages/CourseCreate.vue'
import CourseManage from '../pages/CourseManage.vue'
import CourseDetail from '../pages/CourseDetail.vue'
import CourseUpdate from '../pages/CourseUpdate.vue'
import CourseMyList from '../pages/CourseMyList.vue'
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
      { path: 'reset-password', name: 'reset-password', component: ResetPassword },
      { path: 'students/create', name: 'student-create', component: StudentCreate, meta: { menuKey: 'student-create' } },
      { path: 'students/search', name: 'student-search', component: StudentSearch, meta: { menuKey: 'student-search' } },
      { path: 'change-password', name: 'change-password', component: ChangePassword, meta: { menuKey: 'change-password' } },
      { path: 'students/profile', name: 'student-profile', component: StudentProfile, meta: { menuKey: 'student-profile' } },
      { path: 'students/profile/update', name: 'student-profile-update', component: StudentProfileUpdate, meta: { menuKey: 'student-profile-update' } },
      { path: 'teachers/create', name: 'teacher-create', component: TeacherCreate, meta: { menuKey: 'teacher-create' } },
      { path: 'teachers/search', name: 'teacher-search', component: TeacherSearch, meta: { menuKey: 'teacher-search' } },
      { path: 'teachers/profile', name: 'teacher-profile', component: TeacherProfile, meta: { menuKey: 'teacher-profile' } },
      { path: 'teachers/profile/update', name: 'teacher-profile-update', component: TeacherProfileUpdate, meta: { menuKey: 'teacher-profile-update' } },
      { path: 'courses/create', name: 'course-create', component: CourseCreate, meta: { menuKey: 'course-create' } },
      { path: 'courses/manage', name: 'course-manage', component: CourseManage, meta: { menuKey: 'course-manage' } },
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
  history: createWebHistory(),
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
    const res = await fetch('/api/home')
    if (res.ok) {
      const data = await parseJsonSafe(res)
      if (data.login_id) {
        next()
        return
      }
    }
  } catch (e) {
    // ignore
  }

  next({ name: 'login' })
})

export default router
