const currencyFormatter = new Intl.NumberFormat("es-ES", {
  style: "currency",
  currency: "EUR",
});

const incomeTable = document.querySelector(".table");
const incomeRowsContainer = document.getElementById("income-rows");
const expenseContainer = document.querySelector(".expenses");
const incomeForm = document.getElementById("income-form");
const expenseForm = document.getElementById("expense-form");
const monthSelect = document.getElementById("month-select");
const limitInputs = Array.from(document.querySelectorAll("[data-limit]"));
const incomeTotal = document.getElementById("total-income");
const expenseTotal = document.getElementById("total-expenses");
const remainingBudget = document.getElementById("remaining-budget");
const incomeFooter = document.getElementById("income-total-footer");
const goalRemaining = document.getElementById("goal-remaining");
const progressFill = document.getElementById("progress-fill");
const progressPercent = document.getElementById("progress-percent");

const loginForm = document.getElementById("login-form");
const registerForm = document.getElementById("register-form");
const logoutButton = document.getElementById("logout-button");
const authStatus = document.getElementById("auth-status");
const saveButton = document.getElementById("save-button");
const loadButton = document.getElementById("load-button");
const syncStatus = document.getElementById("sync-status");

const API_BASE = "api";
let currentUser = null;
let defaultSnapshot = null;

const parseValue = (input) => Number.parseFloat(input.value || "0") || 0;

const sumInputs = (inputs) =>
  inputs.reduce((total, input) => total + parseValue(input), 0);

const formatCurrency = (value) => currencyFormatter.format(value);

const getLimitValues = () =>
  limitInputs.reduce((limits, input) => {
    const value = Number.parseFloat(input.value || "0") || 0;
    return { ...limits, [input.dataset.limit]: value };
  }, {});

const updateBadges = (totalIncome, totalExpenses, limits) => {
  document.querySelectorAll("[data-badge]").forEach((badge) => {
    const card = badge.closest(".expense-card");
    const input = card?.querySelector("input");
    const value = input ? parseValue(input) : 0;
    const category = card?.dataset.category;
    const percentOfExpenses =
      totalExpenses > 0 ? Math.round((value / totalExpenses) * 100) : 0;
    const percentOfIncome = totalIncome > 0 ? (value / totalIncome) * 100 : 0;
    const limit = category && limits[category] ? limits[category] : 0;
    badge.textContent = `${percentOfExpenses}%`;

    if (limit > 0) {
      badge.textContent = `${percentOfIncome.toFixed(0)}% / ${limit}%`;
      badge.classList.toggle("badge--over", percentOfIncome > limit);
    } else {
      badge.classList.remove("badge--over");
    }
  });
};

const updateProgress = (totalIncome, totalExpenses) => {
  const percent =
    totalIncome > 0 ? Math.min((totalExpenses / totalIncome) * 100, 100) : 0;
  progressFill.style.width = `${percent.toFixed(0)}%`;
  progressPercent.textContent = `${percent.toFixed(0)}%`;
};

const updateTotals = () => {
  const incomeInputs = Array.from(
    document.querySelectorAll('[data-group="income"]')
  );
  const expenseInputs = Array.from(
    document.querySelectorAll('[data-group="expenses"]')
  );
  const totalIncome = sumInputs(
    incomeInputs.filter((input) => input.dataset.field === "budget")
  );
  const totalExpenses = sumInputs(expenseInputs);
  const remaining = totalIncome - totalExpenses;

  incomeTotal.textContent = formatCurrency(totalIncome);
  expenseTotal.textContent = formatCurrency(totalExpenses);
  remainingBudget.textContent = formatCurrency(remaining);
  incomeFooter.textContent = formatCurrency(totalIncome);
  goalRemaining.textContent = formatCurrency(remaining);

  updateBadges(totalIncome, totalExpenses, getLimitValues());
  updateProgress(totalIncome, totalExpenses);
};

const toggleForm = (form) => {
  form.hidden = !form.hidden;
};

const createIncomeRow = ({ name, budget, actual }) => {
  const row = document.createElement("div");
  row.className = "table__row";
  row.dataset.incomeRow = "true";
  row.innerHTML = `
    <span>${name}</span>
    <span class="amount">
      <input class="amount-input" type="number" step="0.01" value="${budget}" data-group="income" data-field="budget" />
    </span>
    <span class="amount">
      <input class="amount-input positive" type="number" step="0.01" value="${actual}" data-group="income" data-field="actual" />
    </span>
    <span class="amount">
      <button type="button" class="icon-button" data-remove="income">✕</button>
    </span>
  `;
  return row;
};

const createExpenseCard = ({ name, desc, amount, category }) => {
  const card = document.createElement("div");
  card.className = "expense-card";
  card.dataset.category = category;
  card.innerHTML = `
    <h3>${name}</h3>
    <p>${desc || "Sin detalles adicionales."}</p>
    <div class="expense-card__footer">
      <input class="amount-input" type="number" step="0.01" value="${amount}" data-group="expenses" />
      <span class="badge" data-badge="${category}">0%</span>
    </div>
    <div class="expense-card__meta">
      <button type="button" class="icon-button" data-remove="expense">Eliminar</button>
    </div>
  `;
  return card;
};

const snapshotDefault = () => {
  if (!defaultSnapshot) {
    defaultSnapshot = collectBudgetData();
  }
};

const collectBudgetData = () => ({
  incomes: Array.from(
    incomeRowsContainer.querySelectorAll("[data-income-row]")
  ).map((row) => {
    const name = row.querySelector("span")?.textContent?.trim() || "";
    const budget = row.querySelector('[data-field="budget"]')?.value || "0";
    const actual = row.querySelector('[data-field="actual"]')?.value || "0";
    return { name, budget, actual };
  }),
  expenses: Array.from(expenseContainer.querySelectorAll(".expense-card")).map(
    (card) => ({
      name: card.querySelector("h3")?.textContent?.trim() || "",
      desc: card.querySelector("p")?.textContent?.trim() || "",
      amount: card.querySelector("input")?.value || "0",
      category: card.dataset.category || "libre",
    })
  ),
  limits: getLimitValues(),
});

const applyBudgetData = (data) => {
  if (!data) {
    return;
  }

  incomeRowsContainer.innerHTML = "";
  data.incomes?.forEach((income) => {
    incomeRowsContainer.appendChild(createIncomeRow(income));
  });

  expenseContainer.innerHTML = "";
  data.expenses?.forEach((expense) => {
    expenseContainer.appendChild(createExpenseCard(expense));
  });

  limitInputs.forEach((input) => {
    const nextValue = data.limits?.[input.dataset.limit];
    if (typeof nextValue === "number") {
      input.value = nextValue;
    }
  });

  updateTotals();
};

const setSyncStatus = (message, type = "") => {
  syncStatus.textContent = message;
  syncStatus.className = `sync-status ${type}`.trim();
};

const fetchJson = async (url, options = {}) => {
  const response = await fetch(url, {
    credentials: "same-origin",
    headers: {
      "Content-Type": "application/json",
      ...(options.headers || {}),
    },
    ...options,
  });

  const data = await response.json().catch(() => ({}));
  if (!response.ok || data.ok === false) {
    const errorMessage = data.error || "Error inesperado.";
    throw new Error(errorMessage);
  }

  return data;
};

const updateAuthUI = (authenticated, email = "") => {
  currentUser = authenticated ? { email } : null;
  authStatus.textContent = authenticated ? email : "No autenticado";
  loginForm.hidden = authenticated;
  registerForm.hidden = authenticated;
  logoutButton.hidden = !authenticated;
  saveButton.disabled = !authenticated;
  loadButton.disabled = !authenticated;
};

const checkSession = async () => {
  try {
    const data = await fetchJson(`${API_BASE}/session.php`);
    updateAuthUI(data.authenticated, data.email);
    if (data.authenticated) {
      await loadBudget();
    }
  } catch (error) {
    updateAuthUI(false);
    setSyncStatus("Servidor no disponible. Usa el modo local.", "sync-status--error");
  }
};

const login = async (email, password) => {
  const data = await fetchJson(`${API_BASE}/login.php`, {
    method: "POST",
    body: JSON.stringify({ email, password }),
  });
  updateAuthUI(true, data.email);
  setSyncStatus("Sesión iniciada.", "sync-status--success");
  await loadBudget();
};

const registerUser = async (email, password) => {
  const data = await fetchJson(`${API_BASE}/register.php`, {
    method: "POST",
    body: JSON.stringify({ email, password }),
  });
  updateAuthUI(true, data.email);
  setSyncStatus("Cuenta creada y sesión iniciada.", "sync-status--success");
  await saveBudget();
};

const logout = async () => {
  await fetchJson(`${API_BASE}/logout.php`, {
    method: "POST",
    body: JSON.stringify({}),
  });
  updateAuthUI(false);
  setSyncStatus("Sesión cerrada.", "sync-status--success");
  if (defaultSnapshot) {
    applyBudgetData(defaultSnapshot);
  }
};

const saveBudget = async () => {
  if (!currentUser) {
    setSyncStatus("Necesitas iniciar sesión para guardar.", "sync-status--error");
    return;
  }

  const payload = {
    month: monthSelect.value,
    data: collectBudgetData(),
  };

  const data = await fetchJson(`${API_BASE}/budget.php`, {
    method: "POST",
    body: JSON.stringify(payload),
  });

  setSyncStatus(`Guardado ${new Date(data.updated_at).toLocaleString("es-ES")}.`, "sync-status--success");
};

const loadBudget = async () => {
  if (!currentUser) {
    setSyncStatus("Inicia sesión para cargar datos.", "sync-status--error");
    return;
  }

  const data = await fetchJson(`${API_BASE}/budget.php?month=${monthSelect.value}`);
  if (!data.data) {
    setSyncStatus("No hay datos guardados para este mes.", "sync-status--error");
    if (defaultSnapshot) {
      applyBudgetData(defaultSnapshot);
    }
    return;
  }

  applyBudgetData(data.data);
  setSyncStatus("Datos cargados desde el servidor.", "sync-status--success");
};

document.querySelectorAll("#add-income-button, #add-expense-button").forEach((button) => {
  button.addEventListener("click", (event) => {
    const targetForm = event.currentTarget.id === "add-income-button" ? incomeForm : expenseForm;
    toggleForm(targetForm);
  });
});

incomeForm.addEventListener("submit", (event) => {
  event.preventDefault();
  const name = document.getElementById("income-name").value.trim();
  const budget = document.getElementById("income-budget").value;
  const actual = document.getElementById("income-actual").value;
  if (!name) return;
  const newRow = createIncomeRow({ name, budget, actual });
  incomeRowsContainer.appendChild(newRow);
  incomeForm.reset();
  incomeForm.hidden = true;
  updateTotals();
});

expenseForm.addEventListener("submit", (event) => {
  event.preventDefault();
  const name = document.getElementById("expense-name").value.trim();
  const desc = document.getElementById("expense-desc").value.trim();
  const category = document.getElementById("expense-category").value;
  const amount = document.getElementById("expense-amount").value;
  if (!name) return;
  const newCard = createExpenseCard({ name, desc, amount, category });
  expenseContainer.appendChild(newCard);
  expenseForm.reset();
  expenseForm.hidden = true;
  updateTotals();
});

loginForm.addEventListener("submit", async (event) => {
  event.preventDefault();
  const email = document.getElementById("login-email").value.trim();
  const password = document.getElementById("login-password").value;
  try {
    await login(email, password);
    loginForm.reset();
  } catch (error) {
    setSyncStatus(error.message, "sync-status--error");
  }
});

registerForm.addEventListener("submit", async (event) => {
  event.preventDefault();
  const email = document.getElementById("register-email").value.trim();
  const password = document.getElementById("register-password").value;
  try {
    await registerUser(email, password);
    registerForm.reset();
  } catch (error) {
    setSyncStatus(error.message, "sync-status--error");
  }
});

logoutButton.addEventListener("click", async () => {
  try {
    await logout();
  } catch (error) {
    setSyncStatus(error.message, "sync-status--error");
  }
});

saveButton.addEventListener("click", async () => {
  try {
    await saveBudget();
  } catch (error) {
    setSyncStatus(error.message, "sync-status--error");
  }
});

loadButton.addEventListener("click", async () => {
  try {
    await loadBudget();
  } catch (error) {
    setSyncStatus(error.message, "sync-status--error");
  }
});

document.addEventListener("input", (event) => {
  if (
    event.target.matches('[data-group="income"]') ||
    event.target.matches('[data-group="expenses"]') ||
    event.target.matches("[data-limit]")
  ) {
    updateTotals();
  }
});

document.addEventListener("click", (event) => {
  if (event.target.matches('[data-remove="income"]')) {
    event.target.closest("[data-income-row]")?.remove();
    updateTotals();
  }
  if (event.target.matches('[data-remove="expense"]')) {
    event.target.closest(".expense-card")?.remove();
    updateTotals();
  }
});

monthSelect.addEventListener("change", async () => {
  document.querySelector(".month-selector").dataset.month = monthSelect.value;
  if (currentUser) {
    try {
      await loadBudget();
    } catch (error) {
      setSyncStatus(error.message, "sync-status--error");
    }
  }
});

updateTotals();
incomeForm.hidden = true;
expenseForm.hidden = true;
logoutButton.hidden = true;
loginForm.hidden = false;
registerForm.hidden = false;
saveButton.disabled = true;
loadButton.disabled = true;

snapshotDefault();
checkSession();
