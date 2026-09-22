/**
 * KETAN M/A B COMPLEX - Marks & Assessment Spreadsheets Engine
 */

class MarksSheetEngine {
    constructor(tableId, options = {}) {
        this.table = document.getElementById(tableId);
        if (!this.table) return;

        this.maxSba = options.maxSba || 50;
        this.maxExam = options.maxExam || 50;
        this.gradingScale = options.gradingScale || [
            { min: 80, grade: 'A', remark: 'Excellent' },
            { min: 70, grade: 'B', remark: 'Very Good' },
            { min: 60, grade: 'C', remark: 'Good' },
            { min: 50, grade: 'D', remark: 'Credit' },
            { min: 40, grade: 'E', remark: 'Pass' },
            { min: 0,  grade: 'F', remark: 'Fail' }
        ];

        this.init();
    }

    init() {
        const rows = this.table.querySelectorAll('tbody tr[data-student-id]');
        rows.forEach(row => {
            const sbaInput = row.querySelector('.sba-input');
            const examInput = row.querySelector('.exam-input');

            if (sbaInput) {
                sbaInput.addEventListener('input', () => this.calculateRow(row));
                this.setupKeyboardNavigation(sbaInput, 'sba');
            }
            if (examInput) {
                examInput.addEventListener('input', () => this.calculateRow(row));
                this.setupKeyboardNavigation(examInput, 'exam');
            }

            // Initial calculation
            this.calculateRow(row);
        });

        // Form submission validation
        const form = this.table.closest('form');
        if (form) {
            form.addEventListener('submit', (e) => {
                let hasErrors = false;
                const invalidInputs = this.table.querySelectorAll('.is-invalid');
                if (invalidInputs.length > 0) {
                    alert('Please correct invalid marks before submitting. (SBA max: ' + this.maxSba + ', Exam max: ' + this.maxExam + ')');
                    e.preventDefault();
                    invalidInputs[0].focus();
                    return;
                }
            });
        }
    }

    calculateRow(row) {
        const sbaInput = row.querySelector('.sba-input');
        const examInput = row.querySelector('.exam-input');
        const totalDisplay = row.querySelector('.total-display');
        const totalHidden = row.querySelector('.total-hidden');
        const gradeDisplay = row.querySelector('.grade-display');
        const gradeHidden = row.querySelector('.grade-hidden');
        const remarkDisplay = row.querySelector('.remark-display');
        const remarkHidden = row.querySelector('.remark-hidden');

        if (!sbaInput || !examInput) return;

        const sbaRaw = sbaInput.value.trim();
        const examRaw = examInput.value.trim();

        if (sbaRaw === '' && examRaw === '') {
            sbaInput.classList.remove('is-invalid');
            examInput.classList.remove('is-invalid');
            if (totalDisplay) totalDisplay.textContent = '—';
            if (totalHidden) totalHidden.value = '';
            if (gradeDisplay) gradeDisplay.textContent = '—';
            if (gradeHidden) gradeHidden.value = '';
            if (remarkDisplay) remarkDisplay.textContent = '—';
            if (remarkHidden) remarkHidden.value = '';
            return;
        }

        let sba = sbaRaw !== '' ? parseFloat(sbaRaw) : 0;
        let exam = examRaw !== '' ? parseFloat(examRaw) : 0;

        if (isNaN(sba)) sba = 0;
        if (isNaN(exam)) exam = 0;

        // Validation
        let sbaValid = (sba >= 0 && sba <= this.maxSba);
        let examValid = (exam >= 0 && exam <= this.maxExam);

        sbaInput.classList.toggle('is-invalid', !sbaValid && sbaRaw !== '');
        examInput.classList.toggle('is-invalid', !examValid && examRaw !== '');

        if (!sbaValid || !examValid) {
            if (totalDisplay) totalDisplay.textContent = 'Err';
            if (gradeDisplay) gradeDisplay.innerHTML = '<span class="badge bg-danger">!</span>';
            return;
        }

        const total = Math.round((sba + exam) * 100) / 100;
        const result = this.getGradeAndRemark(total);

        if (totalDisplay) totalDisplay.textContent = total.toFixed(1);
        if (totalHidden) totalHidden.value = total.toFixed(2);

        if (gradeDisplay) {
            gradeDisplay.innerHTML = `<span class="grade-badge grade-${result.grade}">${result.grade}</span>`;
        }
        if (gradeHidden) gradeHidden.value = result.grade;

        if (remarkDisplay) remarkDisplay.textContent = result.remark;
        if (remarkHidden) remarkHidden.value = result.remark;
    }

    getGradeAndRemark(score) {
        for (const tier of this.gradingScale) {
            if (score >= tier.min) {
                return { grade: tier.grade, remark: tier.remark };
            }
        }
        return { grade: 'F', remark: 'Fail' };
    }

    setupKeyboardNavigation(input, type) {
        input.addEventListener('keydown', (e) => {
            const currentRow = input.closest('tr');
            if (e.key === 'ArrowDown' || e.key === 'Enter') {
                e.preventDefault();
                const nextRow = currentRow.nextElementSibling;
                if (nextRow) {
                    const target = nextRow.querySelector(`.${type}-input`);
                    if (target) target.focus();
                }
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                const prevRow = currentRow.previousElementSibling;
                if (prevRow) {
                    const target = prevRow.querySelector(`.${type}-input`);
                    if (target) target.focus();
                }
            } else if (e.key === 'ArrowRight' && type === 'sba') {
                const exam = currentRow.querySelector('.exam-input');
                if (exam && input.selectionEnd === input.value.length) {
                    exam.focus();
                }
            } else if (e.key === 'ArrowLeft' && type === 'exam') {
                const sba = currentRow.querySelector('.sba-input');
                if (sba && input.selectionStart === 0) {
                    sba.focus();
                }
            }
        });
    }
}

window.MarksSheetEngine = MarksSheetEngine;
