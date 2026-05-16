describe('Reset Password (Admin Panel) E2E', () => {
  beforeEach(() => {
    cy.mockPublicConfig()
  })

  // ── Admin sees pending reset requests ─────────────────────────────────────

  it('admin sees pending reset requests and can reset a user password', () => {
    cy.mockHomeAsAdmin()
    cy.fixture('auth/reset-list-pending.json').then((pendingList) => {
      let items = Cypress._.cloneDeep(pendingList.items)

      cy.intercept('GET', '**/api/reset_list.php', (req) => { req.reply({ statusCode: 200, body: { items } }) }).as('resetList')
      cy.intercept('POST', '**/api/reset_password.php', (req) => {
        const targetId = req.body?.admin_id
        items = items.filter((item) => item.id !== targetId)
        req.reply({ statusCode: 200, body: { success: true, default_password: '123456' } })
      }).as('doReset')

      cy.visit('/reset-password')

      cy.contains('S001').should('be.visible')
      cy.contains('T001').should('be.visible')

      cy.get('.primary').first().click()

      cy.wait('@doReset')

      // After reset, item is removed from the list
      cy.contains('S001').should('not.exist')
    })
  })

  // ── Empty state ───────────────────────────────────────────────────────────

  it('admin sees empty state when no reset requests exist', () => {
    cy.mockHomeAsAdmin()
    cy.intercept('GET', '**/api/reset_list.php', { items: [] }).as('resetListEmpty')

    cy.visit('/reset-password')

    cy.wait('@resetListEmpty')
    cy.contains('Không có yêu cầu reset').should('be.visible')
  })

  // ── Load error ────────────────────────────────────────────────────────────

  it('admin sees error message when reset list fails to load', () => {
    cy.mockHomeAsAdmin()
    cy.intercept('GET', '**/api/reset_list.php', { statusCode: 500, body: {} }).as('resetListError')

    cy.visit('/reset-password')

    cy.wait('@resetListError')
    cy.contains('Không tải được danh sách').should('be.visible')
  })

  // ── Reset failure (server error) ──────────────────────────────────────────

  it('admin sees per-row error when reset call fails', () => {
    cy.mockHomeAsAdmin()
    cy.fixture('auth/reset-list-pending.json').then((pendingList) => {
      cy.intercept('GET', '**/api/reset_list.php', pendingList).as('resetList')
      cy.intercept('POST', '**/api/reset_password.php', {
        statusCode: 400,
        body: { success: false, error: 'Reset thất bại.' },
      }).as('doResetFail')

      cy.visit('/reset-password')

      cy.get('.primary').first().click()

      cy.wait('@doResetFail')
      cy.contains('Reset thất bại').should('be.visible')
    })
  })

  // ── Non-admin cannot access this page ────────────────────────────────────

  it('teacher is redirected away from reset-password page', () => {
    cy.fixture('role/teacher-home.json').then((home) => {
      cy.intercept('GET', '**/api/home', home).as('homeTeacher')
      cy.fixture('admin/reset-list.json').then((resetList) => {
        cy.intercept('GET', '**/api/reset_list.php', resetList).as('resetList')

        cy.visit('/reset-password')

        cy.url().should('eq', `${Cypress.config('baseUrl')}/`)
      })
    })
  })

  it('guest is redirected to login', () => {
    cy.mockHomeAsGuest()

    cy.visit('/reset-password')

    cy.url().should('include', '/login')
  })
})
