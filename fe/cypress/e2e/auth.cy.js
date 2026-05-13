describe('Authentication E2E', () => {
  beforeEach(() => {
    cy.mockPublicConfig()
  })

  it('redirects guests to login when visiting home', () => {
    cy.mockHomeAsGuest()

    cy.visit('/')

    cy.url().should('include', '/login')
    cy.get('h2').should('contain.text', 'Đăng nhập hệ thống')
  })

  it('shows validation errors on empty login submit', () => {
    cy.visit('/login')

    cy.get('button[type="submit"]').click()

    cy.contains('Hãy nhập login id').should('be.visible')
  })

  it('logs in as admin and logs out successfully', () => {
    cy.mockLoginSuccess()
    cy.mockHomeAsAdmin()
    cy.mockAdminDashboardData()
    cy.mockLogout()

    cy.visit('/login')

    cy.get('#login_id').type('admin')
    cy.get('#password').type('123456')
    cy.get('button[type="submit"]').click()

    cy.wait('@login')
    cy.url().should('eq', `${Cypress.config('baseUrl')}/`)
    cy.get('.portal-page').should('be.visible')
    cy.get('.menu-item').should('have.length.greaterThan', 3)

    cy.contains('button', 'Thoát').click()
    cy.wait('@logout')
    cy.url().should('include', '/login')
  })
})
