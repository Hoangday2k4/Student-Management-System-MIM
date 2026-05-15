describe('Student Score Detail E2E', () => {
  beforeEach(() => {
    cy.fixture('admin/reset-list.json').then((body) => {
      cy.intercept('GET', '**/api/reset_list.php', body).as('resetList')
    })
  })

  // ── Student views own score detail ────────────────────────────────────────

  it('student views score detail with all fields', () => {
    cy.fixture('role/student-home.json').then((home) => {
      cy.intercept('GET', '**/api/home', home).as('homeStudent')
      cy.fixture('role/score-detail.json').then((detail) => {
        cy.intercept('GET', '**/api/scores/detail*', detail).as('scoreDetail')

        cy.visit('/students/scores/detail?id=401')

        cy.wait('@scoreDetail')
        cy.contains('h1', 'Chi tiết điểm thi').should('be.visible')
        cy.contains('S001').should('be.visible')
        cy.contains('INT301').should('be.visible')
        cy.contains('Mang may tinh').should('be.visible')
        cy.contains('A').should('be.visible')
      })
    })
  })

  // ── Score detail shows weight breakdown ───────────────────────────────────

  it('score detail shows CC / GK / CK weights and scores', () => {
    cy.fixture('role/student-home.json').then((home) => {
      cy.intercept('GET', '**/api/home', home).as('homeStudent')
      cy.fixture('role/score-detail.json').then((detail) => {
        cy.intercept('GET', '**/api/scores/detail*', detail).as('scoreDetail')

        cy.visit('/students/scores/detail?id=401')

        cy.wait('@scoreDetail')
        // Weight labels
        cy.contains('CC').should('be.visible')
        cy.contains('GK').should('be.visible')
        cy.contains('CK').should('be.visible')
      })
    })
  })

  // ── Navigation: back to score list ────────────────────────────────────────

  it('navigates from score list to score detail and back', () => {
    cy.fixture('role/student-home.json').then((home) => {
      cy.intercept('GET', '**/api/home', home).as('homeStudent')
      cy.fixture('role/student-scores.json').then((scores) => {
        cy.intercept('GET', '**/api/scores', scores).as('scoreList')
        cy.fixture('role/score-detail.json').then((detail) => {
          cy.intercept('GET', '**/api/scores/detail*', detail).as('scoreDetail')

          cy.visit('/students/scores')

          cy.wait('@scoreList')
          cy.contains('h1', 'Điểm thi').should('be.visible')

          // Click into detail (if score list has a detail link)
          cy.get('a[href*="/students/scores/detail"]').first().click()

          cy.wait('@scoreDetail')
          cy.contains('h1', 'Chi tiết điểm thi').should('be.visible')

          cy.go('back')
          cy.contains('h1', 'Điểm thi').should('be.visible')
        })
      })
    })
  })

  // ── Teacher cannot access student score detail ────────────────────────────

  it('teacher visiting student score detail is redirected to home', () => {
    cy.fixture('role/teacher-home.json').then((home) => {
      cy.intercept('GET', '**/api/home', home).as('homeTeacher')
      cy.fixture('admin/reset-list.json').then((resetList) => {
        cy.intercept('GET', '**/api/reset_list.php', resetList).as('resetList')

        cy.visit('/students/scores/detail?id=401')

        cy.url().should('eq', `${Cypress.config('baseUrl')}/`)
      })
    })
  })

  // ── Score detail API 401 → redirect to login ─────────────────────────────

  it('redirects to login when score detail API returns 401', () => {
    cy.fixture('role/student-home.json').then((home) => {
      cy.intercept('GET', '**/api/home', home).as('homeStudent')
      cy.intercept('GET', '**/api/scores/detail*', {
        statusCode: 401,
        body: { message: 'Unauthorized' },
      }).as('scoreDetailUnauth')

      cy.visit('/students/scores/detail?id=401')

      cy.wait('@scoreDetailUnauth')
      cy.url().should('include', '/login')
    })
  })
})
