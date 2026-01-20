const currencyFormatter = new Intl.NumberFormat("es-ES", {
  style: "currency",
  currency: "EUR",
});

const incomeInputs = Array.from(
  document.querySelectorAll('[data-group="income"]')
);
const expenseInputs = Array.from(
  document.querySelectorAll('[data-group="expenses"]')
);
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

const updateBadges = (totalExpenses) => {
  document.querySelectorAll("[data-badge]").forEach((badge) => {
    const input = badge.closest(".expense-card")?.querySelector("input");
    const value = input ? parseValue(input) : 0;
    const percent = totalExpenses > 0 ? Math.round((value / totalExpenses) * 100) : 0;
    badge.textContent = `${percent}%`;
  });
};

const updateProgress = (totalIncome, totalExpenses) => {
  const percent = totalIncome > 0 ? Math.min((totalExpenses / totalIncome) * 100, 100) : 0;
  progressFill.style.width = `${percent.toFixed(0)}%`;
  progressPercent.textContent = `${percent.toFixed(0)}%`;
};

const updateTotals = () => {
  const totalIncome = sumInputs(incomeInputs.filter((input) => input.dataset.field === "budget"));
  const totalExpenses = sumInputs(expenseInputs);
  const remaining = totalIncome - totalExpenses;

  incomeTotal.textContent = formatCurrency(totalIncome);
  expenseTotal.textContent = formatCurrency(totalExpenses);
  remainingBudget.textContent = formatCurrency(remaining);
  incomeFooter.textContent = formatCurrency(totalIncome);
  goalRemaining.textContent = formatCurrency(remaining);

  updateBadges(totalExpenses);
  updateProgress(totalIncome, totalExpenses);
};

[...incomeInputs, ...expenseInputs].forEach((input) => {
  input.addEventListener("input", updateTotals);
});

updateTotals();
