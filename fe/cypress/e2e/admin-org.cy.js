describe('Admin Org Management E2E (Faculty / Major / Class)', () => {
  beforeEach(() => {
    cy.mockHomeAsAdmin()
    cy.fixture('admin/reset-list.json').then((body) => {
      cy.intercept('GET', '**/api/reset_list.php', body).as('resetList')
    })
  })

  // ── Faculty management ────────────────────────────────────────────────────

  it('faculty management supports create/read/update/delete actions', () => {
    cy.fixture('admin/faculties.json').then((facultiesFixture) => {
      let faculties = Cypress._.cloneDeep(facultiesFixture)

      cy.intercept('GET', '**/api/faculties*', (req) => {
        if (req.query.code) {
          const found = faculties.find((f) => f.code === req.query.code) || null
          req.reply({ statusCode: 200, body: { status: 'success', data: found } })
          return
        }
        req.reply({ statusCode: 200, body: faculties })
      }).as('facultyList')

      cy.intercept('POST', '**/api/faculties*', (req) => {
        const body = req.body
        if (body.action === 'create') {
          const newFaculty = { code: body.code, name: body.name, major_count: 0, teacher_count: 0 }
          faculties.push(newFaculty)
          req.reply({ statusCode: 200, body: { status: 'success', data: newFaculty } })
        } else if (body.action === 'update') {
          const idx = faculties.findIndex((f) => f.code === body.old_code)
          if (idx !== -1) {
            faculties[idx] = { ...faculties[idx], name: body.name }
          }
          req.reply({ statusCode: 200, body: { status: 'success', data: faculties[idx] } })
        } else {
          req.reply({ statusCode: 200, body: { status: 'success' } })
        }
      }).as('facultyMutate')

      cy.intercept('DELETE', '**/api/faculties*', (req) => {
        const url = new URL(req.url)
        const code = url.searchParams.get('code') || ''
        faculties = faculties.filter((f) => f.code !== code)
        req.reply({ statusCode: 200, body: { status: 'success' } })
      }).as('deleteFaculty')

      cy.visit('/faculties/manage')

      cy.contains('h1', 'Quản lý khoa').should('be.visible')

      // Navigate to create form
      cy.contains('button', '+ Thêm khoa').click()
      cy.url().should('include', '/faculties/form')

      cy.go('back')

      // View detail
      cy.get('button[title="Xem"]').first().click()
      cy.contains('button', 'Đóng').click()

      // Edit
      cy.get('button[title="Cập nhật"]').first().click()
      cy.url().should('include', '/faculties/form')

      cy.go('back')

      // Delete
      cy.window().then((win) => {
        cy.stub(win, 'confirm').returns(true)
      })
      cy.get('button[title="Xóa"]').first().click()
      cy.wait('@deleteFaculty')
      cy.contains('Không có khoa phù hợp.').should('be.visible')
    })
  })

  // ── Major management ──────────────────────────────────────────────────────

  it('major management supports create/read/update/delete actions', () => {
    cy.fixture('admin/majors.json').then((majorsFixture) => {
      let majors = Cypress._.cloneDeep(majorsFixture)

      cy.intercept('GET', '**/api/majors*', (req) => {
        if (req.query.code) {
          const found = majors.find((m) => m.code === req.query.code) || null
          req.reply({ statusCode: 200, body: { status: 'success', data: found } })
          return
        }
        req.reply({ statusCode: 200, body: majors })
      }).as('majorList')

      cy.intercept('POST', '**/api/majors*', (req) => {
        req.reply({ statusCode: 200, body: { status: 'success' } })
      }).as('majorMutate')

      cy.intercept('DELETE', '**/api/majors*', (req) => {
        const url = new URL(req.url)
        const code = url.searchParams.get('code') || ''
        majors = majors.filter((m) => m.code !== code)
        req.reply({ statusCode: 200, body: { status: 'success' } })
      }).as('deleteMajor')

      cy.visit('/majors/manage')

      cy.contains('h1', 'Quản lý ngành').should('be.visible')

      // Navigate to create form
      cy.contains('button', '+ Thêm ngành').click()
      cy.url().should('include', '/majors/form')

      cy.go('back')

      // View detail
      cy.get('button[title="Xem"]').first().click()
      cy.contains('button', 'Đóng').click()

      // Edit
      cy.get('button[title="Cập nhật"]').first().click()
      cy.url().should('include', '/majors/form')

      cy.go('back')

      // Delete
      cy.window().then((win) => {
        cy.stub(win, 'confirm').returns(true)
      })
      cy.get('button[title="Xóa"]').first().click()
      cy.wait('@deleteMajor')
      cy.contains('Không có ngành phù hợp.').should('be.visible')
    })
  })

  // ── Homeroom class management ──────────────────────────────────────────────

  it('homeroom class management supports create/read/update/delete actions', () => {
    cy.fixture('admin/classes.json').then((classesFixture) => {
      let classes = Cypress._.cloneDeep(classesFixture)

      cy.intercept('GET', '**/api/classes*', (req) => {
        if (req.query.code) {
          const found = classes.find((c) => c.code === req.query.code) || null
          req.reply({ statusCode: 200, body: { status: 'success', data: found } })
          return
        }
        req.reply({ statusCode: 200, body: classes })
      }).as('classList')

      cy.intercept('POST', '**/api/classes*', (req) => {
        req.reply({ statusCode: 200, body: { status: 'success' } })
      }).as('classMutate')

      cy.intercept('DELETE', '**/api/classes*', (req) => {
        const url = new URL(req.url)
        const code = url.searchParams.get('code') || ''
        classes = classes.filter((c) => c.code !== code)
        req.reply({ statusCode: 200, body: { status: 'success' } })
      }).as('deleteClass')

      cy.visit('/classes/manage')

      cy.contains('h1', 'Quản lý lớp sinh hoạt').should('be.visible')

      // Navigate to create form
      cy.contains('button', '+ Thêm lớp').click()
      cy.url().should('include', '/classes/form')

      cy.go('back')

      // View detail
      cy.get('button[title="Xem"]').first().click()
      cy.url().should('include', '/classes/detail')

      cy.go('back')

      // Edit
      cy.get('button[title="Cập nhật"]').first().click()
      cy.url().should('include', '/classes/form')

      cy.go('back')

      // Delete
      cy.window().then((win) => {
        cy.stub(win, 'confirm').returns(true)
      })
      cy.get('button[title="Xóa"]').first().click()
      cy.wait('@deleteClass')
      cy.contains('Không có lớp phù hợp.').should('be.visible')
    })
  })

  // ── Non-admin cannot access faculty form ──────────────────────────────────

  it('non-admin (teacher) is redirected away from faculty management', () => {
    cy.fixture('role/teacher-home.json').then((home) => {
      cy.intercept('GET', '**/api/home', home).as('homeTeacher')
      cy.fixture('admin/reset-list.json').then((resetList) => {
        cy.intercept('GET', '**/api/reset_list.php', resetList).as('resetList')

        cy.visit('/faculties/manage')

        cy.url().should('eq', `${Cypress.config('baseUrl')}/`)
      })
    })
  })
})
