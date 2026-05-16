describe('Course Detail (Section Detail) E2E', () => {
  beforeEach(() => {
    cy.fixture('admin/reset-list.json').then((body) => {
      cy.intercept('GET', '**/api/reset_list.php', body).as('resetList')
    })
  })

  // ── Admin views course detail ─────────────────────────────────────────────

  it('admin views course detail with student list', () => {
    cy.mockHomeAsAdmin()
    cy.fixture('admin/course-detail.json').then((detail) => {
      cy.intercept('GET', '**/api/courses/detail*', (req) => {
        if (!req.query.action) {
          req.reply({ statusCode: 200, body: detail })
        }
      }).as('courseDetail')

      cy.visit('/sections/detail?id=201')

      cy.wait('@courseDetail')
      cy.contains('INT201').should('be.visible')
      cy.contains('Cau truc du lieu').should('be.visible')
      cy.contains('S001').should('be.visible')
      cy.contains('S002').should('be.visible')
    })
  })

  // ── Teacher views own course detail ───────────────────────────────────────

  it('teacher views assigned course detail', () => {
    cy.fixture('role/teacher-home.json').then((home) => {
      cy.intercept('GET', '**/api/home', home).as('homeTeacher')
      cy.fixture('admin/course-detail.json').then((detail) => {
        cy.intercept('GET', '**/api/courses/detail*', (req) => {
          if (!req.query.action) {
            req.reply({ statusCode: 200, body: detail })
          }
        }).as('courseDetail')

        cy.visit('/sections/detail?id=201')

        cy.wait('@courseDetail')
        cy.contains('INT201').should('be.visible')
        cy.contains('Test Student 1').should('be.visible')
      })
    })
  })

  // ── Attendance modal interaction ──────────────────────────────────────────

  it('admin can open and submit attendance for a lesson', () => {
    cy.mockHomeAsAdmin()
    cy.fixture('admin/course-detail.json').then((detail) => {
      cy.fixture('admin/attendance-week.json').then((att) => {
        cy.intercept('GET', '**/api/courses/detail*', (req) => {
          if (!req.query.action) {
            req.reply({ statusCode: 200, body: detail })
            return
          }
          if (req.query.action === 'attendance') {
            req.reply({ statusCode: 200, body: att })
          }
        }).as('courseDetailOrAtt')

      cy.intercept('POST', '**/api/courses/detail*', (req) => {
        req.reply({ statusCode: 200, body: { status: 'success' } })
      }).as('coursePost')

      cy.visit('/sections/detail?id=201')

      cy.wait('@courseDetailOrAtt')

      // Click on a lesson button to open attendance modal
      cy.get('button').contains(/buổi|lesson|week|1/i).first().click()

      // Submit attendance
      cy.contains('button', /lưu điểm danh|submit|xác nhận/i).click()

      cy.wait('@coursePost')
      cy.contains(/thành công|success/i).should('be.visible')
      })
    })
  })

  // ── Score entry for individual student ────────────────────────────────────

  it('admin can submit score for a student', () => {
    cy.mockHomeAsAdmin()
    cy.fixture('admin/course-detail.json').then((detail) => {
      cy.intercept('GET', '**/api/courses/detail*', (req) => {
        if (!req.query.action) {
          req.reply({ statusCode: 200, body: detail })
        }
      }).as('courseDetail')

      cy.intercept('POST', '**/api/courses/detail*', (req) => {
        req.reply({ statusCode: 200, body: { status: 'success' } })
      }).as('submitScore')

      cy.visit('/sections/detail?id=201')

      cy.wait('@courseDetail')

      // Open score modal for S002 (no scores yet)
      cy.get('button[title*="điểm"]').first().click()

      cy.get('input[name="cc"], input[placeholder*="CC"]').first().clear().type('8')
      cy.get('input[name="gk"], input[placeholder*="GK"]').first().clear().type('7')
      cy.get('input[name="ck"], input[placeholder*="CK"]').first().clear().type('9')

      cy.contains('button', /lưu|save|xác nhận/i).click()

      cy.wait('@submitScore')
      cy.contains(/thành công|success/i).should('be.visible')
    })
  })

  // ── Course not found ──────────────────────────────────────────────────────

  it('shows error when course detail API returns 404', () => {
    cy.mockHomeAsAdmin()
    cy.intercept('GET', '**/api/courses/detail*', {
      statusCode: 404,
      body: { status: 'error', message: 'Không tìm thấy học phần.' },
    }).as('courseNotFound')

    cy.visit('/sections/detail?id=9999')

    cy.wait('@courseNotFound')
    cy.contains(/không tìm thấy|not found/i).should('be.visible')
  })
})
