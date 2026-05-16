describe('Import Flows E2E (Student & Teacher CSV)', () => {
  beforeEach(() => {
    cy.mockHomeAsAdmin()
    cy.fixture('admin/reset-list.json').then((body) => {
      cy.intercept('GET', '**/api/reset_list.php', body).as('resetList')
    })
  })

  // ── Student import: preview → save ────────────────────────────────────────

  it('admin can preview and save student CSV import', () => {
    cy.fixture('admin/import-preview-students.json').then((preview) => {
      cy.fixture('admin/import-save-success.json').then((saved) => {
        cy.intercept('POST', '**/api/student_import*', (req) => {
          const url = new URL(req.url)
          if (url.searchParams.get('action') === 'preview') {
            req.reply({ statusCode: 200, body: preview })
          } else {
            req.reply({ statusCode: 200, body: saved })
          }
        }).as('studentImport')

      cy.visit('/students/import')

      cy.contains('h1', /import|nhập|sinh viên/i).should('be.visible')

      // Upload CSV file via file input
      cy.get('input[type="file"]').selectFile(
        {
          contents: Cypress.Buffer.from(
            'MSSV,Ho ten,Lop\nIMP001,Import Student One,CTK42\nIMP002,Import Student Two,CTK43'
          ),
          fileName: 'students.csv',
          mimeType: 'text/csv',
        },
        { force: true }
      )

      cy.contains('button', /preview|xem trước|kiểm tra/i).click()

      cy.wait('@studentImport')

      // Preview table shows 2 valid rows
      cy.contains('IMP001').should('be.visible')
      cy.contains('IMP002').should('be.visible')

      // Confirm save
      cy.contains('button', /lưu|import|nhập|xác nhận/i).click()

      cy.wait('@studentImport')
      cy.contains(/thành công|success|đã nhập/i).should('be.visible')
      cy.contains('2').should('be.visible')
      })
    })
  })

  // ── Student import: preview shows validation errors ───────────────────────

  it('admin sees validation errors when CSV has bad rows', () => {
    cy.fixture('errors/import-preview-with-errors.json').then((errorPreview) => {
      cy.intercept('POST', '**/api/student_import*', (req) => {
        const url = new URL(req.url)
        if (url.searchParams.get('action') === 'preview') {
          req.reply({ statusCode: 200, body: errorPreview })
        }
      }).as('studentImportError')

      cy.visit('/students/import')

      cy.get('input[type="file"]').selectFile(
        {
          contents: Cypress.Buffer.from('MSSV,Ho ten,Lop\n,Missing Code,CTK42\nBAD001,Bad Email,CTK43'),
          fileName: 'bad-students.csv',
          mimeType: 'text/csv',
        },
        { force: true }
      )

      cy.contains('button', /preview|xem trước|kiểm tra/i).click()

      cy.wait('@studentImportError')

      cy.contains(/lỗi|skipped|thiếu mã|không hợp lệ/i).should('be.visible')
    })
  })

  // ── Teacher import: preview → save ───────────────────────────────────────

  it('admin can preview and save teacher CSV import', () => {
    cy.fixture('admin/import-preview-teachers.json').then((preview) => {
      cy.intercept('POST', '**/api/teacher_import*', (req) => {
        const url = new URL(req.url)
        if (url.searchParams.get('action') === 'preview') {
          req.reply({ statusCode: 200, body: preview })
        } else {
          req.reply({
            statusCode: 200,
            body: { status: 'success', inserted_count: 1, skipped_count: 0, skipped: [], default_password: '123456' },
          })
        }
      }).as('teacherImport')

      cy.visit('/teachers/import')

      cy.contains('h1', /import|nhập|giáo viên/i).should('be.visible')

      cy.get('input[type="file"]').selectFile(
        {
          contents: Cypress.Buffer.from('Ma GV,Ho ten,Khoa\nTIMP001,Import Teacher One,CNTT'),
          fileName: 'teachers.csv',
          mimeType: 'text/csv',
        },
        { force: true }
      )

      cy.contains('button', /preview|xem trước|kiểm tra/i).click()

      cy.wait('@teacherImport')

      cy.contains('TIMP001').should('be.visible')

      cy.contains('button', /lưu|import|nhập|xác nhận/i).click()

      cy.wait('@teacherImport')
      cy.contains(/thành công|success/i).should('be.visible')
    })
  })

  // ── Non-admin cannot access import pages ─────────────────────────────────

  it('student is redirected away from student import page', () => {
    cy.fixture('role/student-home.json').then((home) => {
      cy.intercept('GET', '**/api/home', home).as('homeStudent')

      cy.visit('/students/import')

      cy.url().should('eq', `${Cypress.config('baseUrl')}/`)
    })
  })

  it('teacher is redirected away from teacher import page', () => {
    cy.fixture('role/teacher-home.json').then((home) => {
      cy.intercept('GET', '**/api/home', home).as('homeTeacher')
      cy.fixture('admin/reset-list.json').then((resetList) => {
        cy.intercept('GET', '**/api/reset_list.php', resetList).as('resetList')

        cy.visit('/teachers/import')

        cy.url().should('eq', `${Cypress.config('baseUrl')}/`)
      })
    })
  })
})
