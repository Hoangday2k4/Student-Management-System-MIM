describe('Role flows E2E', () => {
  beforeEach(() => {
    cy.fixture('admin/reset-list.json').then((body) => {
      cy.intercept('GET', '**/api/reset_list.php', body).as('resetList')
    })
  })

  it('teacher can view teacher routes and assigned courses', () => {
    cy.fixture('role/teacher-home.json').then((home) => {
      cy.intercept('GET', '**/api/home', home).as('homeTeacher')
      cy.fixture('role/teacher-profile.json').then((profile) => {
        cy.intercept('GET', '**/api/teachers/me', profile).as('teacherProfile')
        cy.fixture('role/teacher-courses.json').then((courses) => {
          cy.intercept('GET', '**/api/courses', courses).as('teacherCourses')

          cy.visit('/teachers/profile')
          cy.contains('h1', 'Hồ sơ giáo viên').should('be.visible')
          cy.contains('Mã GV:').should('be.visible')

          cy.visit('/teachers/courses')
          cy.contains('h1', 'Môn học được phân công').should('be.visible')
          cy.contains('INT201').should('be.visible')
          cy.get('a[title="Nhập điểm thi"]').should('have.length.at.least', 1)
        })
      })
    })
  })

  it('student can view student profile, courses, scores and schedule', () => {
    cy.fixture('role/student-home.json').then((home) => {
      cy.intercept('GET', '**/api/home', home).as('homeStudent')
      cy.fixture('role/student-profile.json').then((profile) => {
        cy.intercept('GET', '**/api/students/me', profile).as('studentProfile')
        cy.fixture('role/student-courses.json').then((courses) => {
          cy.intercept('GET', '**/api/courses', courses).as('studentCourses')
          cy.fixture('role/student-scores.json').then((scores) => {
            cy.intercept('GET', '**/api/scores', scores).as('studentScores')

            cy.visit('/students/profile')
            cy.contains('h1', 'Hồ sơ sinh viên').should('be.visible')
            cy.contains('MSSV:').should('be.visible')

            cy.visit('/students/courses')
            cy.contains('h1', 'Môn học đang tham gia').should('be.visible')
            cy.contains('INT301').should('be.visible')

            cy.visit('/students/scores')
            cy.contains('h1', 'Điểm thi').should('be.visible')
            cy.contains('GPA:').should('be.visible')

            cy.visit('/students/schedule')
            cy.contains('h1', 'Thời khóa biểu').should('be.visible')
            cy.contains('Thứ 2').should('be.visible')
          })
        })
      })
    })
  })
})
