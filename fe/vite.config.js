import { fileURLToPath, URL } from 'node:url'

import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import vueDevTools from 'vite-plugin-vue-devtools'

// https://vite.dev/config/
export default defineConfig({
  plugins: [
    vue(),
    vueDevTools(),
  ],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url))
    },
  },
  server: {
    proxy: {
      '/api': {
        target: 'http://localhost:8000',
        changeOrigin: true,
        secure: false,
        rewrite: (path) => {
          const raw = path.replace(/^\/api/, '')
          const routeMap = {
            '/home': '/home.php',
            '/get_config': '/get_config.php',
            '/login': '/login.php',
            '/logout': '/logout.php',
            '/register': '/register.php',
            '/change-password': '/change_password.php',

            '/students': '/student.php',
            '/students/import': '/student_import.php',
            '/students/detail': '/student_detail.php',
            '/students/me': '/student_me.php',

            '/teachers': '/teacher.php',
            '/teachers/import': '/teacher_import.php',
            '/teachers/detail': '/teacher_detail.php',
            '/teachers/me': '/teacher_me.php',

            '/courses': '/course.php',
            '/courses/import': '/course_import.php',
            '/courses/detail': '/course_detail.php',
            '/courses/grade': '/course_grade.php',
            '/courses/enrollment-status': '/course_enrollment_status.php',
            '/courses/reports/fail-rate': '/course_fail_report.php',

            '/scores': '/score.php',
            '/scores/detail': '/score_detail.php',

            '/semesters': '/semester.php',
            '/semesters/detail': '/semester_detail.php',
            '/semesters/restore': '/semester_restore.php',

            '/homerooms': '/homeroom.php',
            '/homerooms/import': '/homeroom_import.php',
            '/homerooms/detail': '/homeroom_detail.php',
            '/homerooms/options': '/homeroom_options.php',

            '/reset_list': '/reset_list.php',
            '/reset_password': '/reset_password.php',
            '/request_reset': '/request_reset.php',
          }

          return routeMap[raw] || raw
        }
      }
    }
  }
})
