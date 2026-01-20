const themeToggle = document.getElementById('themeToggle');
const storedTheme = localStorage.getItem('theme');
const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

function applyTheme(theme) {
    document.querySelector('.app')?.setAttribute('data-theme', theme);
    localStorage.setItem('theme', theme);
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
