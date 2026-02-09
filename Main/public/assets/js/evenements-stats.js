fetch('/admin/evenements/stats/data')
  .then(res => res.json())
  .then(data => {

    // ===== CHART TYPE =====
    new Chart(document.getElementById('chartType'), {
      type: 'doughnut',
      data: {
        labels: Object.keys(data.byType),
        datasets: [{
          data: Object.values(data.byType),
          backgroundColor: ['#4e73df', '#1cc88a', '#f6c23e', '#e74a3b']
        }]
      },
      options: {
        plugins: {
          tooltip: {
            callbacks: {
              label: function(ctx) {
                const arr = ctx.dataset.data;
                const total = arr.reduce((a, b) => a + b, 0);
                const value = ctx.raw;
                const percent = total ? ((value / total) * 100).toFixed(1) : 0;
                return `${ctx.label}: ${value} (${percent}%)`;
              }
            }
          }
        }
      }
    });

    // ===== CHART VILLE =====
    new Chart(document.getElementById('chartVille'), {
      type: 'doughnut',
      data: {
        labels: Object.keys(data.byVille),
        datasets: [{
          data: Object.values(data.byVille),
          backgroundColor: ['#36b9cc', '#858796', '#fd7e14']
        }]
      },
      options: {
        plugins: {
          tooltip: {
            callbacks: {
              label: function(ctx) {
                const arr = ctx.dataset.data;
                const total = arr.reduce((a, b) => a + b, 0);
                const value = ctx.raw;
                const percent = total ? ((value / total) * 100).toFixed(1) : 0;
                return` ${ctx.label}: ${value} (${percent}%)`;
              }
            }
          }
        }
      }
    });

  });