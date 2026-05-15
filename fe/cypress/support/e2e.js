function interceptFixture(method, url, fixturePath, alias) {
  cy.fixture(fixturePath).then((body) => {
    cy.intercept(method, url, body).as(alias)
  })
}

Cypress.Commands.add('mockPublicConfig', () => {
  interceptFixture('GET', '**/api/get_config', 'public/config.json', 'getConfig')
})

Cypress.Commands.add('mockHomeAsGuest', () => {
  interceptFixture('GET', '**/api/home', 'auth/home-guest.json', 'homeGuest')
})

Cypress.Commands.add('mockHomeAsAdmin', () => {
  interceptFixture('GET', '**/api/home', 'auth/home-admin.json', 'homeAdmin')
})

Cypress.Commands.add('mockAdminDashboardData', () => {
  interceptFixture('GET', '**/api/reset_list.php', 'admin/reset-list.json', 'resetList')
  interceptFixture('GET', '**/api/students', 'admin/students.json', 'students')
  interceptFixture('GET', '**/api/teachers', 'admin/teachers.json', 'teachers')
  interceptFixture('GET', '**/api/courses?action=meta', 'admin/course-meta.json', 'courseMeta')
  interceptFixture('GET', '**/api/courses', 'admin/courses.json', 'courses')
})

Cypress.Commands.add('mockLoginSuccess', () => {
  interceptFixture('POST', '**/api/login', 'auth/login-success.json', 'login')
})

Cypress.Commands.add('mockLogout', () => {
  interceptFixture('POST', '**/api/logout', 'auth/logout.json', 'logout')
})

Cypress.Commands.add('mockChangePasswordSuccess', () => {
  interceptFixture('POST', '**/api/change-password', 'auth/change-password-success.json', 'changePassword')
})

Cypress.Commands.add('mockAdminOrgData', () => {
  interceptFixture('GET', '**/api/faculties*', 'admin/faculties.json', 'faculties')
  interceptFixture('GET', '**/api/majors*', 'admin/majors.json', 'majors')
  interceptFixture('GET', '**/api/classes*', 'admin/classes.json', 'classes')
})
