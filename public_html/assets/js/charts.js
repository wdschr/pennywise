(function () {
  var CATEGORY_PALETTE = [
    '#4f8ef7', '#9b6bf2', '#28a99e', '#e0a72e', '#e0632e',
    '#d94f8c', '#2ecc71', '#5b6cff', '#f2a65a', '#6bc5f2',
  ];

  function readData(elId) {
    var el = document.getElementById(elId);
    if (!el) return null;
    try {
      return JSON.parse(el.textContent);
    } catch (e) {
      return null;
    }
  }

  function themeColors() {
    var style = getComputedStyle(document.documentElement);
    return {
      text: style.getPropertyValue('--text-muted').trim() || '#9aa1b4',
      grid: style.getPropertyValue('--border').trim() || '#2c3140',
    };
  }

  var redrawFns = [];

  function buildPie(canvas, data) {
    var chart = null;
    function draw() {
      if (chart) chart.destroy();
      var colors = themeColors();
      chart = new Chart(canvas, {
        type: 'doughnut',
        data: {
          labels: data.labels,
          datasets: [{
            data: data.values,
            backgroundColor: data.colors && data.colors.length === data.labels.length
              ? data.colors
              : data.labels.map(function (_, i) { return CATEGORY_PALETTE[i % CATEGORY_PALETTE.length]; }),
            borderWidth: 0,
          }],
        },
        options: { plugins: { legend: { position: 'right', labels: { color: colors.text } } } },
      });
    }
    draw();
    redrawFns.push(draw);
  }

  function buildTrend(canvas, data) {
    var chart = null;
    function draw() {
      if (chart) chart.destroy();
      var colors = themeColors();
      chart = new Chart(canvas, {
        type: 'bar',
        data: {
          labels: data.labels,
          datasets: [
            { label: 'Income', data: data.income, backgroundColor: '#2ecc71' },
            { label: 'Expenses', data: data.expense, backgroundColor: '#e0632e' },
          ],
        },
        options: {
          scales: {
            x: { ticks: { color: colors.text }, grid: { color: colors.grid } },
            y: { ticks: { color: colors.text }, grid: { color: colors.grid } },
          },
          plugins: { legend: { labels: { color: colors.text } } },
        },
      });
    }
    draw();
    redrawFns.push(draw);
  }

  function buildLine(canvas, data, label, color) {
    var chart = null;
    function draw() {
      if (chart) chart.destroy();
      var colors = themeColors();
      chart = new Chart(canvas, {
        type: 'line',
        data: {
          labels: data.labels,
          datasets: [{
            label: label,
            data: data.values,
            borderColor: color,
            backgroundColor: color + '33',
            fill: true,
            tension: 0.25,
          }],
        },
        options: {
          scales: {
            x: { ticks: { color: colors.text }, grid: { color: colors.grid } },
            y: { ticks: { color: colors.text }, grid: { color: colors.grid } },
          },
          plugins: { legend: { labels: { color: colors.text } } },
        },
      });
    }
    draw();
    redrawFns.push(draw);
  }

  function init() {
    document.querySelectorAll('[data-chart]').forEach(function (canvas) {
      var type = canvas.getAttribute('data-chart');
      var data = readData(canvas.getAttribute('data-source'));
      if (!data || !data.labels || data.labels.length === 0) return;

      if (type === 'pie') {
        buildPie(canvas, data);
      } else if (type === 'trend') {
        buildTrend(canvas, data);
      } else if (type === 'line') {
        buildLine(canvas, data, canvas.getAttribute('data-label') || 'Value', canvas.getAttribute('data-color') || '#4f8ef7');
      }
    });

    var toggle = document.getElementById('theme-toggle');
    if (toggle) {
      toggle.addEventListener('click', function () {
        setTimeout(function () { redrawFns.forEach(function (fn) { fn(); }); }, 30);
      });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
