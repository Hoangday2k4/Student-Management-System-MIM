describe('Admin management E2E', () => {
  beforeEach(() => {
    cy.mockHomeAsAdmin()
    cy.fixture('admin/reset-list.json').then((body) => {
      cy.intercept('GET', '**/api/reset_list.php', body).as('resetList')
    })
  })

  it('student management supports create/read/update/delete actions', () => {
    cy.fixture('admin/students.json').then((studentsFixture) => {
      let students = Cypress._.cloneDeep(studentsFixture)

      cy.intercept('GET', '**/api/students*', (req) => {
        if (req.query.student_code) {
          req.reply({ statusCode: 200, body: { status: 'success', data: students[0] || null } })
          return
        }
        req.reply({ statusCode: 200, body: students })
      }).as('studentList')

      cy.intercept('DELETE', '**/api/students?*', (req) => {
        const url = new URL(req.url)
        const code = String(url.searchParams.get('student_code') || '')
        students = students.filter((item) => item.student_code !== code)
        req.reply({ statusCode: 200, body: { status: 'success' } })
      }).as('deleteStudent')

      cy.visit('/students/search')

      cy.contains('h1', 'Quản lý sinh viên').should('be.visible')
      cy.contains('button', '+ Thêm sinh viên').click()
      cy.url().should('include', '/students/create')

      cy.go('back')
      cy.contains('button', 'Tra cứu').click()

      cy.get('button[title="Xem"]').first().click()
      cy.contains('Chi tiết sinh viên').should('be.visible')
      cy.contains('button', 'Đóng').click()

      cy.get('button[title="Sửa"]').first().click()
      cy.url().should('include', '/students/edit')

      cy.go('back')
      cy.window().then((win) => {
        cy.stub(win, 'confirm').returns(true)
      })
      cy.get('button[title="Xóa"]').first().click()

      cy.wait('@deleteStudent')
      cy.contains('Không tìm thấy sinh viên phù hợp.').should('be.visible')
    })
  })

  it('teacher management supports create/read/update/delete actions', () => {
    cy.fixture('admin/teachers.json').then((teachersFixture) => {
      let teachers = Cypress._.cloneDeep(teachersFixture)

      cy.intercept('GET', '**/api/teachers*', (req) => {
        if (req.query.teacher_code) {
          req.reply({ statusCode: 200, body: { status: 'success', data: teachers[0] || null } })
          return
        }
        req.reply({ statusCode: 200, body: teachers })
      }).as('teacherList')

      cy.intercept('DELETE', '**/api/teachers?*', (req) => {
        const url = new URL(req.url)
        const code = String(url.searchParams.get('teacher_code') || '')
        teachers = teachers.filter((item) => item.teacher_code !== code)
        req.reply({ statusCode: 200, body: { status: 'success' } })
      }).as('deleteTeacher')

      cy.visit('/teachers/search')

      cy.contains('h1', 'Quản lý giảng viên').should('be.visible')
      cy.contains('button', '+ Thêm giảng viên').click()
      cy.url().should('include', '/teachers/create')

      cy.go('back')
      cy.contains('button', 'Tra cứu').click()

      cy.get('button[title="Xem"]').first().click()
      cy.contains('Chi tiết giảng viên').should('be.visible')
      cy.contains('button', 'Đóng').click()

      cy.get('button[title="Sửa"]').first().click()
      cy.url().should('include', '/teachers/edit')

      cy.go('back')
      cy.window().then((win) => {
        cy.stub(win, 'confirm').returns(true)
      })
      cy.get('button[title="Xóa"]').first().click()

      cy.wait('@deleteTeacher')
      cy.contains('Không tìm thấy giảng viên phù hợp.').should('be.visible')
    })
  })

  it('course and section management supports key CRUD actions', () => {
    cy.fixture('admin/subjects.json').then((subjectsFixture) => {
      cy.fixture('admin/sections.json').then((sectionsFixture) => {
        let subjects = Cypress._.cloneDeep(subjectsFixture)
        let sections = Cypress._.cloneDeep(sectionsFixture)

        cy.intercept('GET', '**/api/courses?*', (req) => {
          if (req.query.mode === 'subject') {
            if (req.query.code) {
              req.reply({ statusCode: 200, body: { status: 'success', data: subjects[0] || null } })
              return
            }
            req.reply({ statusCode: 200, body: subjects })
            return
          }
          req.reply({ statusCode: 200, body: sections })
        }).as('courseGeneric')

        cy.intercept('DELETE', '**/api/courses?mode=subject*', (req) => {
          const url = new URL(req.url)
          const code = String(url.searchParams.get('code') || '')
          subjects = subjects.filter((item) => item.course_code !== code)
          req.reply({ statusCode: 200, body: { status: 'success' } })
        }).as('deleteSubject')

        cy.intercept('DELETE', '**/api/courses?id=*', (req) => {
          const url = new URL(req.url)
          const id = Number(url.searchParams.get('id') || 0)
          sections = sections.filter((item) => item.id !== id)
          req.reply({ statusCode: 200, body: { status: 'success' } })
        }).as('deleteSection')

        cy.visit('/courses/manage')
        cy.contains('h1', 'Quản lý môn học').should('be.visible')

        cy.contains('button', '+ Thêm môn học').click()
        cy.url().should('include', '/courses/create')

        cy.go('back')
        cy.get('button[title="Xem"]').first().click()
        cy.contains('Chi tiết môn học').should('be.visible')
        cy.contains('button', 'Đóng').click()

        cy.get('button[title="Cập nhật"]').first().click()
        cy.url().should('include', '/subjects/form')

        cy.go('back')
        cy.window().then((win) => {
          cy.stub(win, 'confirm').returns(true)
        })
        cy.get('button[title="Xóa môn học"]').first().click()
        cy.wait('@deleteSubject')
        cy.contains('Không có môn học phù hợp.').should('be.visible')

        cy.visit('/sections/manage')
        cy.contains('h1', 'Quản lý học phần').should('be.visible')

        cy.contains('button', '+ Thêm học phần').click()
        cy.url().should('include', '/sections/create')

        cy.go('back')
        cy.get('button[title="Xem"]').first().click()
        cy.url().should('include', '/sections/detail')

        cy.go('back')
        cy.get('button[title="Cập nhật"]').first().click()
        cy.url().should('include', '/sections/update')

        cy.go('back')
        cy.window().then((win) => {
          cy.stub(win, 'confirm').returns(true)
        })
        cy.get('button[title="Xóa"]').first().click()
        cy.wait('@deleteSection')
        cy.contains('Không có lớp học phần phù hợp.').should('be.visible')
      })
    })
  })
})
