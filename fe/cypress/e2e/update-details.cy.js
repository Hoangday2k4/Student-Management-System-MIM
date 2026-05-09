function fieldByLabel(labelText) {
  return cy.contains('label', labelText).next()
}

describe('Update detail screens E2E', () => {
  beforeEach(() => {
    cy.fixture('admin/reset-list.json').then((body) => {
      cy.intercept('GET', '**/api/reset_list.php', body).as('resetList')
    })
  })

  it('updates student profile and returns to profile screen', () => {
    cy.fixture('role/student-home.json').then((home) => {
      cy.intercept('GET', '**/api/home', home).as('homeStudent')
      cy.fixture('role/student-profile.json').then((profile) => {
        cy.intercept('GET', '**/api/students/me', profile).as('studentProfile')
        cy.fixture('role/update-success.json').then((successBody) => {
          cy.intercept('POST', '**/api/students/me', successBody).as('saveStudentProfile')

          cy.visit('/students/profile/update')

          cy.contains('h1', 'Cập nhật hồ sơ sinh viên').should('be.visible')
          fieldByLabel('Họ tên *').find('input').clear().type('Updated Student')
          fieldByLabel('Lớp *').find('input').clear().type('CTK43')
          fieldByLabel('Email').find('input').clear().type('updated.student@example.test')
          cy.contains('label', 'Số điện thoại').next('input').clear().type('0900000009')
          cy.contains('button', 'Xác nhận').click()

          cy.contains('h2', 'Xác nhận thông tin cập nhật').should('be.visible')
          cy.contains('button', 'Lưu thông tin').click()

          cy.wait('@saveStudentProfile')
          cy.url().should('include', '/students/profile')
          cy.contains('h1', 'Hồ sơ sinh viên').should('be.visible')
        })
      })
    })
  })

  it('updates teacher profile and returns to profile screen', () => {
    cy.fixture('role/teacher-home.json').then((home) => {
      cy.intercept('GET', '**/api/home', home).as('homeTeacher')
      cy.fixture('role/teacher-profile.json').then((profile) => {
        cy.intercept('GET', '**/api/teachers/me', profile).as('teacherProfile')
        cy.fixture('role/update-success.json').then((successBody) => {
          cy.intercept('POST', '**/api/teachers/me', successBody).as('saveTeacherProfile')

          cy.visit('/teachers/profile/update')

          cy.contains('h1', 'Cập nhật hồ sơ giáo viên').should('be.visible')
          fieldByLabel('Họ tên *').find('input').clear().type('Updated Teacher')
          fieldByLabel('Khoa *').find('select').select('Toán - Cơ - Tin')
          fieldByLabel('Email').find('input').clear().type('updated.teacher@example.test')
          cy.contains('label', 'Lớp phụ trách').next('input').clear().type('CTK43')
          cy.contains('button', 'Xác nhận').click()

          cy.contains('h2', 'Xác nhận thông tin cập nhật').should('be.visible')
          cy.contains('button', 'Lưu thông tin').click()

          cy.wait('@saveTeacherProfile')
          cy.url().should('include', '/teachers/profile')
          cy.contains('h1', 'Hồ sơ giáo viên').should('be.visible')
        })
      })
    })
  })

  it('saves teacher grades for a course', () => {
    cy.fixture('role/teacher-home.json').then((home) => {
      cy.intercept('GET', '**/api/home', home).as('homeTeacher')
      cy.fixture('role/teacher-grade.json').then((grade) => {
        cy.intercept('GET', '**/api/courses/grade?id=201', grade).as('loadGrades')
        cy.fixture('role/grade-save-success.json').then((successBody) => {
          cy.intercept('POST', '**/api/courses/grade', successBody).as('saveGrades')

          cy.visit('/courses/grade?id=201')

          cy.contains('h1', 'Nhập điểm thi').should('be.visible')
          cy.contains('Mã môn học').should('be.visible')
          cy.get('.weights input').eq(0).clear().type('30')
          cy.get('.weights input').eq(1).clear().type('30')
          cy.get('.weights input').eq(2).clear().type('40')
          cy.get('input.score-input').eq(0).clear().type('9')
          cy.get('input.score-input').eq(1).clear().type('8')
          cy.get('input.score-input').eq(2).clear().type('9.5')
          cy.get('input.score-input').eq(3).clear().type('7')
          cy.get('input.score-input').eq(4).clear().type('7.5')
          cy.get('input.score-input').eq(5).clear().type('8')

          cy.contains('button', 'Lưu điểm').click()
          cy.wait('@saveGrades')
          cy.contains('Lưu điểm thành công.').should('be.visible')
        })
      })
    })
  })
})
