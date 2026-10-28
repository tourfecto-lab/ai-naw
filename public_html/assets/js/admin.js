/**
 * Tourfecto - Admin JavaScript
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

(function() {
    'use strict';

    // ============================================
    // 1. DOM Ready
    // ============================================
    document.addEventListener('DOMContentLoaded', function() {
        initAdmin();
    });

    // ============================================
    // 2. Initialize Admin
    // ============================================
    function initAdmin() {
        initSidebar();
        initDataTables();
        initCharts();
        initFileUpload();
        initBulkActions();
        initSearchFilter();
    }

    // ============================================
    // 3. Sidebar
    // ============================================
    function initSidebar() {
        const toggleBtn = document.querySelector('.toggle-sidebar');
        const sidebar = document.querySelector('.admin-sidebar');
        const overlay = document.querySelector('.admin-overlay');

        if (toggleBtn && sidebar) {
            toggleBtn.addEventListener('click', function() {
                sidebar.classList.toggle('open');
                if (overlay) overlay.classList.toggle('active');
            });
        }

        if (overlay) {
            overlay.addEventListener('click', function() {
                sidebar.classList.remove('open');
                this.classList.remove('active');
            });
        }

        // Submenu toggle
        document.querySelectorAll('.admin-sidebar-nav .has-submenu > .nav-link').forEach(link => {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                const parent = this.parentElement;
                const submenu = parent.querySelector('.submenu');
                if (submenu) {
                    submenu.classList.toggle('show');
                    parent.classList.toggle('open');
                }
            });
        });
    }

    // ============================================
    // 4. DataTables
    // ============================================
    function initDataTables() {
        // Simple table search
        document.querySelectorAll('.table-search').forEach(input => {
            input.addEventListener('keyup', function() {
                const search = this.value.toLowerCase();
                const table = this.closest('.admin-table');
                const rows = table.querySelectorAll('.table-row');

                rows.forEach(row => {
                    const text = row.textContent.toLowerCase();
                    row.style.display = text.includes(search) ? '' : 'none';
                });
            });
        });

        // Table sorting
        document.querySelectorAll('.table-sortable th').forEach(th => {
            th.style.cursor = 'pointer';
            th.addEventListener('click', function() {
                const table = this.closest('table');
                const index = Array.from(this.parentElement.children).indexOf(this);
                const rows = Array.from(table.querySelectorAll('tbody tr'));
                const isAscending = this.classList.contains('asc');

                rows.sort((a, b) => {
                    const aVal = a.children[index].textContent.trim();
                    const bVal = b.children[index].textContent.trim();
                    return isAscending ? aVal.localeCompare(bVal) : bVal.localeCompare(aVal);
                });

                rows.forEach(row => table.querySelector('tbody').appendChild(row));
                this.classList.toggle('asc');
                this.classList.toggle('desc');
            });
        });
    }

    // ============================================
    // 5. Charts
    // ============================================
    function initCharts() {
        // Check if Chart.js is available
        if (typeof Chart === 'undefined') {
            console.warn('Chart.js not loaded');
            return;
        }

        // Bar Chart
        const barCanvas = document.getElementById('barChart');
        if (barCanvas) {
            new Chart(barCanvas, {
                type: 'bar',
                data: {
                    labels: ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو'],
                    datasets: [{
                        label: 'المستخدمين',
                        data: [12, 19, 3, 5, 2, 3],
                        backgroundColor: 'rgba(0, 119, 190, 0.6)',
                        borderColor: 'rgba(0, 119, 190, 1)',
                        borderWidth: 2
                    }, {
                        label: 'الاشتراكات',
                        data: [5, 8, 10, 7, 12, 15],
                        backgroundColor: 'rgba(40, 167, 69, 0.6)',
                        borderColor: 'rgba(40, 167, 69, 1)',
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top',
                            labels: {
                                font: {
                                    family: 'Tajawal'
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true
                        }
                    }
                }
            });
        }

        // Line Chart
        const lineCanvas = document.getElementById('lineChart');
        if (lineCanvas) {
            new Chart(lineCanvas, {
                type: 'line',
                data: {
                    labels: ['أسبوع 1', 'أسبوع 2', 'أسبوع 3', 'أسبوع 4'],
                    datasets: [{
                        label: 'التقارير',
                        data: [5, 12, 8, 15],
                        borderColor: 'rgba(0, 119, 190, 1)',
                        backgroundColor: 'rgba(0, 119, 190, 0.1)',
                        fill: true,
                        tension: 0.4
                    }, {
                        label: 'المراجعات',
                        data: [3, 7, 11, 9],
                        borderColor: 'rgba(40, 167, 69, 1)',
                        backgroundColor: 'rgba(40, 167, 69, 0.1)',
                        fill: true,
                        tension: 0.4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top',
                            labels: {
                                font: {
                                    family: 'Tajawal'
                                }
                            }
                        }
                    }
                }
            });
        }

        // Pie Chart
        const pieCanvas = document.getElementById('pieChart');
        if (pieCanvas) {
            new Chart(pieCanvas, {
                type: 'doughnut',
                data: {
                    labels: ['إيجابي', 'محايد', 'سلبي'],
                    datasets: [{
                        data: [65, 25, 10],
                        backgroundColor: [
                            'rgba(40, 167, 69, 0.8)',
                            'rgba(255, 193, 7, 0.8)',
                            'rgba(220, 53, 69, 0.8)'
                        ],
                        borderColor: [
                            'rgba(40, 167, 69, 1)',
                            'rgba(255, 193, 7, 1)',
                            'rgba(220, 53, 69, 1)'
                        ],
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                font: {
                                    family: 'Tajawal'
                                }
                            }
                        }
                    }
                }
            });
        }
    }

    // ============================================
    // 6. File Upload
    // ============================================
    function initFileUpload() {
        document.querySelectorAll('.file-upload').forEach(upload => {
            const input = upload.querySelector('input[type="file"]');
            const preview = upload.querySelector('.file-preview');
            const filename = upload.querySelector('.file-name');

            if (input) {
                input.addEventListener('change', function() {
                    const file = this.files[0];
                    if (file) {
                        if (filename) {
                            filename.textContent = file.name;
                        }

                        // Image preview
                        if (preview && file.type.startsWith('image/')) {
                            const reader = new FileReader();
                            reader.onload = function(e) {
                                preview.innerHTML = `<img src="${e.target.result}" alt="Preview">`;
                            };
                            reader.readAsDataURL(file);
                        }
                    }
                });
            }
        });
    }

    // ============================================
    // 7. Bulk Actions
    // ============================================
    function initBulkActions() {
        const selectAll = document.querySelector('.select-all');
        const checkboxes = document.querySelectorAll('.select-item');

        if (selectAll) {
            selectAll.addEventListener('change', function() {
                checkboxes.forEach(cb => cb.checked = this.checked);
                updateBulkActions();
            });
        }

        checkboxes.forEach(cb => {
            cb.addEventListener('change', updateBulkActions);
        });

        function updateBulkActions() {
            const checked = document.querySelectorAll('.select-item:checked').length;
            const bulkActions = document.querySelector('.bulk-actions');
            if (bulkActions) {
                bulkActions.style.display = checked > 0 ? 'block' : 'none';
                const count = bulkActions.querySelector('.selected-count');
                if (count) {
                    count.textContent = checked;
                }
            }
        }
    }

    // ============================================
    // 8. Search & Filter
    // ============================================
    function initSearchFilter() {
        // Live search
        const searchInput = document.querySelector('.search-input');
        if (searchInput) {
            const debouncedSearch = Tourfecto.debounce(function() {
                const query = this.value;
                // Perform search
                window.location.href = window.location.pathname + '?search=' + encodeURIComponent(query);
            }, 500);

            searchInput.addEventListener('input', debouncedSearch);
        }

        // Filter dropdowns
        document.querySelectorAll('.filter-select').forEach(select => {
            select.addEventListener('change', function() {
                const params = new URLSearchParams(window.location.search);
                params.set(this.name, this.value);
                window.location.search = params.toString();
            });
        });
    }

    // ============================================
    // 9. Export Functions
    // ============================================
    window.Admin = {
        /**
         * Export table data to CSV
         * @param {string} tableId - Table ID
         * @param {string} filename - Export filename
         */
        exportCSV: function(tableId, filename = 'export.csv') {
            const table = document.getElementById(tableId);
            if (!table) return;

            let csv = [];
            const rows = table.querySelectorAll('tr');

            rows.forEach(row => {
                const cells = row.querySelectorAll('th, td');
                const rowData = Array.from(cells).map(cell => {
                    let text = cell.textContent.trim();
                    if (text.includes(',')) {
                        text = '"' + text + '"';
                    }
                    return text;
                });
                csv.push(rowData.join(','));
            });

            const blob = new Blob(['\uFEFF' + csv.join('\n')], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = filename;
            link.click();
        },

        /**
         * Print table
         * @param {string} tableId - Table ID
         */
        printTable: function(tableId) {
            const printContents = document.getElementById(tableId).outerHTML;
            const originalContents = document.body.innerHTML;
            document.body.innerHTML = printContents;
            window.print();
            document.body.innerHTML = originalContents;
        }
    };

})();