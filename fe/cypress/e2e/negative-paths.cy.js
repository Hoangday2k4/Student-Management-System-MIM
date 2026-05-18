describe('Negative and authorization paths E2E', () => {
  beforeEach(() => {
    cy.mockPublicConfig()
  })

  it('shows server field error on request reset failure', () => {
    cy.fixture('errors/request-reset-fail.json').then((body) => {
      cy.intercept('POST', '**/api/request_reset', {
        statusCode: 400,
        body,
      }).as('requestResetFail')

      cy.visit('/reset-password-request')

      cy.get('#loginId').type('X999')
      cy.contains('button', 'Gửi yêu cầu').click()

      cy.wait('@requestResetFail')
      cy.contains('Login ID không tồn tại.').should('be.visible')
      cy.contains('Gửi yêu cầu thất bại.').should('be.visible')
    })
  })

  it('redirects to login when scores API returns 401', () => {
    cy.fixture('role/student-home.json').then((home) => {
      cy.intercept('GET', '**/api/home', home).as('homeStudent')
    })

    cy.intercept('GET', '**/api/scores', {
      statusCode: 401,
      body: { message: 'Unauthorized' }
    }).as('scores401')

    cy.visit('/students/scores')

    cy.wait('@scores401')
    cy.url().should('include', '/login')
  })

  it('redirects non-student user away from student schedule', () => {
    cy.fixture('role/teacher-home.json').then((home) => {
      cy.intercept('GET', '**/api/home', home).as('homeTeacher')
      cy.fixture('admin/reset-list.json').then((resetList) => {
        cy.intercept('GET', '**/api/reset_list.php', resetList).as('resetList')
        cy.intercept('GET', '**/api/courses', { statusCode: 200, body: [] }).as('courses')

        cy.visit('/students/schedule')

        cy.url().should('eq', `${Cypress.config('baseUrl')}/`)
        cy.contains('THÔNG BÁO').should('be.visible')
      })
    })
  })

  it('shows API error and hides admin actions for non-admin in student management', () => {
    cy.fixture('role/teacher-home.json').then((home) => {
      cy.intercept('GET', '**/api/home', home).as('homeTeacher')
      cy.fixture('admin/reset-list.json').then((resetList) => {
        cy.intercept('GET', '**/api/reset_list.php', resetList).as('resetList')
        cy.fixture('errors/student-list-500.json').then((body) => {
          cy.intercept('GET', '**/api/students*', {
            statusCode: 500,
            body,
          }).as('studentError')

          cy.visit('/students/search')

          cy.wait('@studentError')
          cy.contains('Lỗi tải dữ liệu sinh viên.').should('be.visible')
          cy.contains('button', '+ Thêm sinh viên').should('not.exist')
          cy.get('body').should('not.contain', 'Action')
        })
      })
    })
  })

  it('shows empty state for student course list', () => {
    cy.fixture('role/student-home.json').then((home) => {
      cy.intercept('GET', '**/api/home', home).as('homeStudent')
      cy.fixture('admin/reset-list.json').then((resetList) => {
        cy.intercept('GET', '**/api/reset_list.php', resetList).as('resetList')
        cy.intercept('GET', '**/api/courses', { statusCode: 200, body: [] }).as('emptyCourses')

        cy.visit('/students/courses')

        cy.wait('@emptyCourses')
        cy.contains('Chưa có môn học nào.').should('be.visible')
      })
    })
  })
})
