const themeToggle = document.getElementById('themeToggle');
const storedTheme = localStorage.getItem('theme');
const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

function updateToggleLabel(theme) {
    if (!themeToggle) return;
    themeToggle.textContent = theme === 'dark' ? 'Modo claro' : 'Modo oscuro';
}

function applyTheme(theme) {
    document.querySelector('.app')?.setAttribute('data-theme', theme);
    localStorage.setItem('theme', theme);
    updateToggleLabel(theme);
}

if (storedTheme) {
    applyTheme(storedTheme);
} else {
    applyTheme(prefersDark ? 'dark' : 'light');
}

themeToggle?.addEventListener('click', () => {
    const current = document.querySelector('.app')?.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
    applyTheme(current === 'dark' ? 'light' : 'dark');
});

const reviewButton = document.getElementById('reviewWeek');
const reviewHint = document.getElementById('reviewHint');
const movementRows = document.querySelectorAll('[data-movement]');
let reviewActive = false;

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
const pills = document.querySelectorAll('.pill');

pills.forEach((pill) => {
    pill.addEventListener('click', () => {
        pills.forEach((p) => p.classList.remove('active'));
        pill.classList.add('active');
        const value = pill.getAttribute('data-category');
        if (categorySelect && value) {
            categorySelect.value = value;
        }
    });
});

const dialog = document.getElementById('categoryDialog');
const closeButton = document.getElementById('closeCategory');
const accountDialog = document.getElementById('accountDialog');
const closeAccount = document.getElementById('closeAccount');
const copyBudgets = document.getElementById('copyBudgets');

document.querySelectorAll('[data-edit]').forEach((button) => {
    button.addEventListener('click', () => {
        if (!dialog) return;
        const data = JSON.parse(button.getAttribute('data-edit'));
        document.getElementById('catId').value = data.id;
        document.getElementById('catNombre').value = data.nombre;
        document.getElementById('catTipo').value = data.tipo;
        document.getElementById('catOrden').value = data.orden;
        document.getElementById('catActiva').checked = data.activa === 1;
        document.getElementById('catFavorite').checked = data.is_favorite === 1;
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
