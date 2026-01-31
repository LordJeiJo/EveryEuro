const reviewButton = document.getElementById('reviewWeek');
const reviewHint = document.getElementById('reviewHint');
const movementRows = document.querySelectorAll('[data-movement]');
const statusForms = document.querySelectorAll('[data-status-form]');
let reviewActive = false;

if (window.location.hash) {
    const target = document.querySelector(window.location.hash);
    if (target) {
        target.scrollIntoView({ block: 'center' });
    }
}

const savedScroll = window.sessionStorage.getItem('movement-scroll');
if (savedScroll !== null) {
    window.sessionStorage.removeItem('movement-scroll');
    requestAnimationFrame(() => {
        const scrollValue = Number.parseInt(savedScroll, 10);
        window.scrollTo({ top: Number.isNaN(scrollValue) ? 0 : scrollValue });
    });
}

statusForms.forEach((form) => {
    form.addEventListener('submit', () => {
        window.sessionStorage.setItem('movement-scroll', String(window.scrollY));
    });
});

function applyReviewFilter() {
    if (!movementRows.length) return;
    const cutoff = new Date();
    cutoff.setDate(cutoff.getDate() - 6);
    movementRows.forEach((row) => {
        const status = row.getAttribute('data-status');
        const dateValue = row.getAttribute('data-date');
        const date = dateValue ? new Date(`${dateValue}T00:00:00`) : null;
        const isRecent = date ? date >= cutoff : false;
        const shouldShow = !reviewActive || (status === 'pendiente' && isRecent);
        row.style.display = shouldShow ? '' : 'none';
    });
    if (reviewHint) {
        reviewHint.textContent = reviewActive
            ? 'Mostrando pendientes de los últimos 7 días.'
            : 'Pendientes destacados, revisados en gris.';
    }
    if (reviewButton) {
        reviewButton.classList.toggle('active', reviewActive);
        reviewButton.textContent = reviewActive ? 'Salir de revisión' : 'Revisar semana';
    }
}

reviewButton?.addEventListener('click', () => {
    reviewActive = !reviewActive;
    applyReviewFilter();
});

const categorySelect = document.getElementById('categorySelect');
const descriptionInput = document.getElementById('descriptionInput');

function normalizeText(value) {
    return value
        .toLowerCase()
        .normalize('NFD')
        .replace(/\p{Diacritic}/gu, '');
}

function buildKeywordMap() {
    if (!categorySelect) return [];
    return Array.from(categorySelect.options)
        .filter((option) => option.value)
        .map((option) => {
            const keywords = option.dataset.keywords || '';
            const list = keywords
                .split(',')
                .map((keyword) => normalizeText(keyword.trim()))
                .filter(Boolean)
                .sort((a, b) => b.length - a.length);
            return {
                value: option.value,
                keywords: list,
            };
        });
}

const keywordMap = buildKeywordMap();
let manualCategorySelection = false;

function suggestCategory() {
    if (!descriptionInput || !categorySelect || manualCategorySelection) return;
    const description = normalizeText(descriptionInput.value);
    if (!description) {
        categorySelect.value = '';
        return;
    }
    let suggested = '';
    for (const entry of keywordMap) {
        if (entry.keywords.some((keyword) => description.includes(keyword))) {
            suggested = entry.value;
            break;
        }
    }
    if (suggested) {
        categorySelect.value = suggested;
    }
}

descriptionInput?.addEventListener('input', () => {
    if (!descriptionInput.value.trim()) {
        manualCategorySelection = false;
    }
    suggestCategory();
});

categorySelect?.addEventListener('change', () => {
    manualCategorySelection = categorySelect.value !== '';
});

const dialog = document.getElementById('categoryDialog');
const closeButton = document.getElementById('closeCategory');
const accountDialog = document.getElementById('accountDialog');
const closeAccount = document.getElementById('closeAccount');
const copyBudgets = document.getElementById('copyBudgets');
const budgetColumnsSelect = document.getElementById('budgetColumns');
const budgetGrid = document.querySelector('.budget-grid');
const budgetColumnsKey = 'budget-columns';
const extraColumnsSelect = document.getElementById('extraColumns');
const extraGrid = document.querySelector('.extra-grid');
const extraColumnsKey = 'extra-columns';
const menuToggle = document.getElementById('menuToggle');
const appNav = document.getElementById('appNav');

document.querySelectorAll('[data-edit]').forEach((button) => {
    button.addEventListener('click', () => {
        if (!dialog) return;
        const data = JSON.parse(button.getAttribute('data-edit'));
        document.getElementById('catId').value = data.id;
        document.getElementById('catNombre').value = data.nombre;
        document.getElementById('catTipo').value = data.tipo;
        document.getElementById('catOrden').value = data.orden;
        document.getElementById('catActiva').checked = data.activa === 1;
        const keywordsInput = document.getElementById('catKeywords');
        if (keywordsInput) {
            keywordsInput.value = data.keywords || '';
        }
        dialog.showModal();
    });
});

closeButton?.addEventListener('click', () => dialog?.close());

document.querySelectorAll('[data-edit-account]').forEach((button) => {
    button.addEventListener('click', () => {
        if (!accountDialog) return;
        const data = JSON.parse(button.getAttribute('data-edit-account'));
        document.getElementById('accountId').value = data.id;
        document.getElementById('accountNombre').value = data.nombre;
        document.getElementById('accountOrden').value = data.orden;
        document.getElementById('accountActiva').checked = data.activa === 1;
        accountDialog.showModal();
    });
});

closeAccount?.addEventListener('click', () => accountDialog?.close());

copyBudgets?.addEventListener('click', (event) => {
    const form = event.currentTarget.closest('form');
    if (!form) return;
    const hasExisting = form.dataset.hasExisting === '1';
    if (hasExisting && !window.confirm('Ya existen presupuestos en este mes. ¿Sobrescribir?')) {
        event.preventDefault();
        return;
    }
    const confirmInput = form.querySelector('input[name="confirm_overwrite"]');
    if (confirmInput && hasExisting) {
        confirmInput.value = '1';
    }
});

function applyBudgetColumns(value) {
    if (!budgetGrid) return;
    budgetGrid.style.setProperty('--budget-columns', value);
}

if (budgetColumnsSelect && budgetGrid) {
    const savedColumns = window.localStorage.getItem(budgetColumnsKey);
    const initialColumns = savedColumns || budgetColumnsSelect.value;
    budgetColumnsSelect.value = initialColumns;
    applyBudgetColumns(initialColumns);
    budgetColumnsSelect.addEventListener('change', (event) => {
        const value = event.target.value;
        applyBudgetColumns(value);
        window.localStorage.setItem(budgetColumnsKey, value);
    });
}

function applyExtraColumns(value) {
    if (!extraGrid) return;
    extraGrid.style.setProperty('--extra-columns', value);
}

if (extraColumnsSelect && extraGrid) {
    const savedColumns = window.localStorage.getItem(extraColumnsKey);
    const initialColumns = savedColumns || extraColumnsSelect.value;
    extraColumnsSelect.value = initialColumns;
    applyExtraColumns(initialColumns);
    extraColumnsSelect.addEventListener('change', (event) => {
        const value = event.target.value;
        applyExtraColumns(value);
        window.localStorage.setItem(extraColumnsKey, value);
    });
}

function closeNav() {
    document.body.classList.remove('nav-open');
    if (menuToggle) {
        menuToggle.setAttribute('aria-expanded', 'false');
    }
}

menuToggle?.addEventListener('click', (event) => {
    event.stopPropagation();
    const isOpen = document.body.classList.toggle('nav-open');
    menuToggle.setAttribute('aria-expanded', String(isOpen));
});

document.addEventListener('click', (event) => {
    if (!document.body.classList.contains('nav-open')) return;
    const target = event.target;
    if (!(target instanceof Node)) return;
    if (appNav?.contains(target) || menuToggle?.contains(target)) return;
    closeNav();
});

appNav?.addEventListener('click', () => closeNav());
