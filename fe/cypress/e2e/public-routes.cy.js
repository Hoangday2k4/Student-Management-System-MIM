describe('Public routes E2E', () => {
  beforeEach(() => {
    cy.mockPublicConfig()
  })

  it('register flow redirects to login when successful', () => {
    cy.fixture('auth/register-success.json').then((body) => {
      cy.intercept('POST', '**/api/register', body).as('register')

      cy.visit('/register')

      cy.get('#login_id').type('new_user_01')
      cy.get('#password').type('123456')
      cy.get('#confirm_password').type('123456')
      cy.contains('button', 'Đăng ký').click()

      cy.wait('@register')
      cy.url().should('include', '/login')
      cy.contains('h2', 'Đăng nhập hệ thống').should('be.visible')
    })
  })

  it('request reset flow shows success and returns to login', () => {
    cy.fixture('auth/request-reset-success.json').then((body) => {
      cy.intercept('POST', '**/api/request_reset.php', body).as('requestReset')

      cy.visit('/reset-password-request')

      cy.get('#loginId').type('S001')
      cy.contains('button', 'Gửi yêu cầu').click()

      cy.wait('@requestReset')
      cy.contains('h2', 'Gửi yêu cầu thành công').should('be.visible')

      cy.contains('button', 'Quay về đăng nhập').click()
      cy.url().should('include', '/login')
    })
  })
})
