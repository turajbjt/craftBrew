/**
 * CraftBrew Interactive Frontend JS Helper
 */

document.addEventListener('DOMContentLoaded', () => {
    // Mobile navigation toggle
    const toggleBtn = document.querySelector('.mobile-toggle');
    const navMenu = document.querySelector('.navbar-nav');
    if (toggleBtn && navMenu) {
        toggleBtn.addEventListener('click', () => {
            navMenu.classList.toggle('show');
        });
    }

    // Auto-calculate ABV on gravity inputs change
    const ogInput = document.getElementById('calc_og');
    const fgInput = document.getElementById('calc_fg');
    const abvOutput = document.getElementById('calc_abv_result');

    function calculateABV() {
        if (!ogInput || !fgInput || !abvOutput) return;
        const og = parseFloat(ogInput.value);
        const fg = parseFloat(fgInput.value);
        if (!isNaN(og) && !isNaN(fg) && og > 1.0 && fg > 0 && og > fg) {
            const abv = ((og - fg) * 131.25).toFixed(2);
            abvOutput.textContent = abv + '%';
        } else {
            abvOutput.textContent = '--%';
        }
    }

    if (ogInput && fgInput) {
        ogInput.addEventListener('input', calculateABV);
        fgInput.addEventListener('input', calculateABV);
    }
});

/**
 * Initialize Fermentation Curve Chart using Chart.js
 */
function renderFermentationChart(canvasId, labels, gravityData, tempData) {
    const canvas = document.getElementById(canvasId);
    if (!canvas || typeof Chart === 'undefined') return;

    new Chart(canvas, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Specific Gravity (SG)',
                    data: gravityData,
                    borderColor: '#d97706',
                    backgroundColor: 'rgba(217, 119, 6, 0.1)',
                    yAxisID: 'yGravity',
                    fill: true,
                    tension: 0.2
                },
                {
                    label: 'Temperature (°F)',
                    data: tempData,
                    borderColor: '#ef4444',
                    borderDash: [5, 5],
                    yAxisID: 'yTemp',
                    fill: false
                }
            ]
        },
        options: {
            responsive: true,
            scales: {
                yGravity: {
                    type: 'linear',
                    display: true,
                    position: 'left',
                    title: { display: true, text: 'Specific Gravity' }
                },
                yTemp: {
                    type: 'linear',
                    display: true,
                    position: 'right',
                    grid: { drawOnChartArea: false },
                    title: { display: true, text: 'Temperature (°F)' }
                }
            }
        }
    });
}
