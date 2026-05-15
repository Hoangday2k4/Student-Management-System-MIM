describe('Change Password E2E', () => {
  beforeEach(() => {
    cy.fixture('admin/reset-list.json').then((body) => {
      cy.intercept('GET', '**/api/reset_list.php', body).as('resetList')
    })
  })

  // ── Admin can change password successfully ────────────────────────────────

  it('admin changes password successfully', () => {
    cy.mockHomeAsAdmin()
    cy.fixture('auth/change-password-success.json').then((body) => {
      cy.intercept('POST', '**/api/change-password', { statusCode: 200, body }).as('changePassword')

      cy.visit('/change-password')

      cy.contains('h1', 'Đổi mật khẩu').should('be.visible')
      cy.get('#old_password').type('123456')
      cy.get('#new_password').type('newpass123')
      cy.get('#confirm_password').type('newpass123')
      cy.contains('button', 'Đổi mật khẩu').click()

      cy.wait('@changePassword')
      cy.contains('Đổi mật khẩu thành công').should('be.visible')
    })
  })

  // ── Student can change password ───────────────────────────────────────────

  it('student changes password successfully', () => {
    cy.fixture('role/student-home.json').then((home) => {
      cy.intercept('GET', '**/api/home', home).as('homeStudent')
      cy.fixture('auth/change-password-success.json').then((body) => {
        cy.intercept('POST', '**/api/change-password', { statusCode: 200, body }).as('changePassword')

        cy.visit('/change-password')

        cy.contains('h1', 'Đổi mật khẩu').should('be.visible')
        cy.get('#old_password').type('123456')
        cy.get('#new_password').type('newpass456')
        cy.get('#confirm_password').type('newpass456')
        cy.contains('button', 'Đổi mật khẩu').click()

        cy.wait('@changePassword')
        cy.contains('Đổi mật khẩu thành công').should('be.visible')
      })
    })
  })

  // ── Validation: empty fields ───────────────────────────────────────────────

  it('shows validation errors when fields are empty', () => {
    cy.mockHomeAsAdmin()

    cy.visit('/change-password')

    cy.contains('button', 'Đổi mật khẩu').click()

    cy.contains('Hãy nhập mật khẩu hiện tại').should('be.visible')
  })

  // ── Validation: passwords do not match ────────────────────────────────────

  it('shows error when new passwords do not match', () => {
    cy.mockHomeAsAdmin()

    cy.visit('/change-password')

    cy.get('#old_password').type('123456')
    cy.get('#new_password').type('newpass123')
    cy.get('#confirm_password').type('different789')
    cy.contains('button', 'Đổi mật khẩu').click()

    cy.contains('Mật khẩu xác nhận không khớp').should('be.visible')
  })

  // ── Server error: wrong old password ─────────────────────────────────────

  it('shows server error when old password is wrong', () => {
    cy.mockHomeAsAdmin()
    cy.fixture('errors/change-password-fail.json').then((body) => {
      cy.intercept('POST', '**/api/change-password', { statusCode: 400, body }).as('changeFail')

      cy.visit('/change-password')

      cy.get('#old_password').type('wrong-password')
      cy.get('#new_password').type('newpass123')
      cy.get('#confirm_password').type('newpass123')
      cy.contains('button', 'Đổi mật khẩu').click()

      cy.wait('@changeFail')
      cy.contains('Mật khẩu hiện tại không đúng').should('be.visible')
    })
  })

  // ── Guest is redirected to login ──────────────────────────────────────────

  it('redirects unauthenticated user to login', () => {
    cy.mockHomeAsGuest()

    cy.visit('/change-password')

    cy.url().should('include', '/login')
  })
})
