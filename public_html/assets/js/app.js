(function () {
  var LAST_DATE_KEY = 'bp_last_txn_date';

  function getLastDate() {
    try {
      return window.localStorage.getItem(LAST_DATE_KEY);
    } catch (e) {
      return null;
    }
  }

  function setLastDate(value) {
    try {
      if (value) window.localStorage.setItem(LAST_DATE_KEY, value);
    } catch (e) { /* private browsing / blocked storage: ignore */ }
  }

  function openModal(modal) {
    modal.classList.add('open');
  }
  function closeModal(modal) {
    modal.classList.remove('open');
  }

  function setKind(modal, kind) {
    var expenseSelect = modal.querySelector('[data-kind-group="expense"]');
    var incomeSelect = modal.querySelector('[data-kind-group="income"]');
    var kindInput = modal.querySelector('[name="kind"]');
    if (kindInput) kindInput.value = kind;
    if (expenseSelect) expenseSelect.style.display = kind === 'expense' ? '' : 'none';
    if (incomeSelect) incomeSelect.style.display = kind === 'income' ? '' : 'none';
    if (expenseSelect) expenseSelect.disabled = kind !== 'expense';
    if (incomeSelect) incomeSelect.disabled = kind !== 'income';
    modal.querySelectorAll('[data-kind-btn]').forEach(function (btn) {
      btn.classList.toggle('btn', btn.getAttribute('data-kind-btn') === kind);
      btn.classList.toggle('secondary', btn.getAttribute('data-kind-btn') !== kind);
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    var modal = document.getElementById('txn-modal');
    if (!modal) return;
    var form = modal.querySelector('form');
    var title = modal.querySelector('[data-modal-title]');
    var qtyField = document.getElementById('qty-field');
    var qtyInput = document.getElementById('qty-input');
    var amountInput = document.getElementById('amount-input');

    function resetForm() {
      form.reset();
      form.querySelector('[name="id"]').value = '';
      form.querySelector('[name="form_action"]').value = 'create';
      form.querySelector('[name="txn_date"]').value = getLastDate() || new Date().toISOString().slice(0, 10);
      form.querySelector('[name="preset_item_id"]').value = '';
      if (qtyField) qtyField.style.display = 'none';
      if (qtyInput) {
        qtyInput.value = 1;
        delete qtyInput.dataset.unitPrice;
      }
    }

    if (qtyInput && amountInput) {
      qtyInput.addEventListener('input', function () {
        var unitPrice = parseFloat(qtyInput.dataset.unitPrice || '');
        var qty = parseInt(qtyInput.value, 10) || 1;
        if (!isNaN(unitPrice)) {
          amountInput.value = (unitPrice * qty).toFixed(2);
        }
      });
    }

    document.body.addEventListener('click', function (e) {
      var addBtn = e.target.closest('[data-modal-open="add"]');
      if (addBtn) {
        resetForm();
        setKind(modal, addBtn.getAttribute('data-kind') || 'expense');
        title.textContent = 'Add transaction';
        openModal(modal);
        return;
      }

      var editBtn = e.target.closest('[data-modal-open="edit"]');
      if (editBtn) {
        resetForm();
        form.querySelector('[name="id"]').value = editBtn.dataset.id;
        form.querySelector('[name="form_action"]').value = 'update';
        form.querySelector('[name="description"]').value = editBtn.dataset.description || '';
        form.querySelector('[name="amount"]').value = editBtn.dataset.amount || '';
        form.querySelector('[name="txn_date"]').value = editBtn.dataset.date || '';
        form.querySelector('[name="notes"]').value = editBtn.dataset.notes || '';
        setKind(modal, editBtn.dataset.kind || 'expense');
        var select = modal.querySelector('[data-kind-group="' + (editBtn.dataset.kind || 'expense') + '"]');
        if (select) select.value = editBtn.dataset.categoryId || '';
        title.textContent = 'Edit transaction';
        openModal(modal);
        return;
      }

      var presetBtn = e.target.closest('[data-preset-chip]');
      if (presetBtn) {
        resetForm();
        var presetKind = presetBtn.dataset.kind || 'expense';
        setKind(modal, presetKind);
        form.querySelector('[name="preset_item_id"]').value = presetBtn.dataset.presetId || '';
        form.querySelector('[name="description"]').value = presetBtn.dataset.name || '';
        form.querySelector('[name="amount"]').value = presetBtn.dataset.amount || '';
        var kindSelect = modal.querySelector('[data-kind-group="' + presetKind + '"]');
        if (kindSelect) kindSelect.value = presetBtn.dataset.categoryId || '';
        if (qtyField && qtyInput && presetBtn.dataset.amount) {
          qtyField.style.display = '';
          qtyInput.value = 1;
          qtyInput.dataset.unitPrice = presetBtn.dataset.amount;
        }
        title.textContent = 'Quick add: ' + (presetBtn.dataset.name || '');
        openModal(modal);
        return;
      }

      if (e.target.closest('[data-modal-close]') || e.target === modal) {
        closeModal(modal);
      }
    });

    modal.querySelectorAll('[data-kind-btn]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        setKind(modal, btn.getAttribute('data-kind-btn'));
      });
    });
  });

  // Any field marked data-auto-submit submits its form as soon as it
  // changes (e.g. a month picker) - CSP blocks inline onchange="", so
  // this is delegated from here instead.
  document.addEventListener('change', function (e) {
    if (e.target.hasAttribute('data-auto-submit') && e.target.form) {
      e.target.form.submit();
    }
  });

  document.addEventListener('submit', function (e) {
    var submitter = e.submitter;
    if (submitter && submitter.hasAttribute('data-confirm')) {
      if (!window.confirm(submitter.getAttribute('data-confirm'))) {
        e.preventDefault();
        return;
      }
    }

    var dateField = e.target.querySelector('[name="txn_date"]');
    if (dateField && dateField.value) {
      setLastDate(dateField.value);
    }
  });

  // Categories page: show either the "day of month" or "anchor date"
  // field depending on how a carry-forward preset repeats.
  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-carry-interval]').forEach(function (select) {
      var container = select.closest('[data-carry-controls]');
      if (!container) return;

      function update() {
        var monthly = select.value !== 'every_4_weeks';
        container.querySelectorAll('[data-carry-monthly]').forEach(function (el) { el.style.display = monthly ? '' : 'none'; });
        container.querySelectorAll('[data-carry-4weekly]').forEach(function (el) { el.style.display = monthly ? 'none' : ''; });
      }

      select.addEventListener('change', update);
      update();
    });
  });
})();
