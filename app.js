const currencyFormatter = new Intl.NumberFormat("es-ES", {
  style: "currency",
  currency: "EUR",
});

const incomeTable = document.querySelector(".table");
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
  incomeTable.appendChild(newRow);
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

monthSelect.addEventListener("change", () => {
  document.querySelector(".month-selector").dataset.month = monthSelect.value;
});

updateTotals();
incomeForm.hidden = true;
expenseForm.hidden = true;
