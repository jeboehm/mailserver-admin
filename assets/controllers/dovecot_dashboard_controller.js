import {Controller} from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static values = {
        summaryUrl: String, chartsUrl: String, rawUrl: String, refreshUrl: String, refreshInterval: {type: Number, default: 30000}
    };

    static targets = ['summaryContainer', 'chartsContainer', 'rawContainer'];

    connect() {
        this.loadAllFragments();
        this.startAutoRefresh();
    }

    disconnect() {
        this.stopAutoRefresh();
    }

    startAutoRefresh() {
        this.stopAutoRefresh();
        this.refreshIntervalId = setInterval(() => {
            this.loadSummary();
        }, this.refreshIntervalValue);
    }

    stopAutoRefresh() {
        if (this.refreshIntervalId) {
            clearInterval(this.refreshIntervalId);
            this.refreshIntervalId = null;
        }
    }

    async loadAllFragments() {
        await Promise.all([this.loadSummary(), this.loadCharts(), this.loadRaw()]);
    }

    async loadSummary() {
        if (!this.hasSummaryContainerTarget) {
            return;
        }

        await this.loadFragment(this.summaryContainerTarget, this.summaryUrlValue);
    }

    async loadCharts() {
        if (!this.hasChartsContainerTarget) {
            return;
        }

        await this.loadFragment(this.chartsContainerTarget, this.chartsUrlValue);
    }

    async loadRaw() {
        if (!this.hasRawContainerTarget) {
            return;
        }

        await this.loadFragment(this.rawContainerTarget, this.rawUrlValue);
    }

    async refresh() {
        try {
            // Show loading state on all available containers
            if (this.hasSummaryContainerTarget) {
                this.showLoading(this.summaryContainerTarget);
            }
            if (this.hasChartsContainerTarget) {
                this.showLoading(this.chartsContainerTarget);
            }
            if (this.hasRawContainerTarget) {
                this.showLoading(this.rawContainerTarget);
            }

            const response = await fetch(this.refreshUrlValue, {
                method: 'POST', headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const data = await response.json();

            if (!data.success) {
                throw new Error(data.error || 'Refresh failed');
            }

            // Reload all fragments after successful refresh
            await this.loadAllFragments();
        } catch (error) {
            console.error('Error refreshing stats:', error);
            // Show error on all containers that were showing loading state
            if (this.hasSummaryContainerTarget) {
                this.showError(this.summaryContainerTarget, error.message);
            }
            if (this.hasChartsContainerTarget) {
                this.showError(this.chartsContainerTarget, error.message);
            }
            if (this.hasRawContainerTarget) {
                this.showError(this.rawContainerTarget, error.message);
            }
        }
    }

    async loadFragment(container, url) {
        if (!container) {
            return;
        }

        try {
            this.showLoading(container);

            const response = await fetch(url, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const html = await response.text();
            container.innerHTML = html;

            // Reinitialize any charts that might be in the fragment
            this.initializeCharts(container);
        } catch (error) {
            console.error('Error loading fragment:', error);
            this.showError(container);
        }
    }

    showLoading(container) {
        if (!container) {
            return;
        }

        container.innerHTML = `
            <div class="d-flex justify-content-center py-5">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
            </div>
        `;
    }

    showError(container, message = null) {
        if (!container) {
            return;
        }

        container.innerHTML = `
            <div class="alert alert-danger">
                <i class="fa fa-exclamation-triangle me-2"></i>
                Failed to load: ${message || 'Please try refreshing the page.'}
            </div>
        `;
    }

    initializeCharts(container) {
        // Chart.js charts are automatically initialized by Symfony UX Chart.js
        // This method can be extended if additional initialization is needed
        // Symfony UX Chart.js uses data-controller="symfony--ux-chartjs--chart" attribute
        const chartElements = container.querySelectorAll('[data-controller*="chart"]');

        // Charts are auto-initialized by Symfony UX Chart.js bundle when the fragment is loaded
        // No additional initialization needed unless custom behavior is required
        if (chartElements.length > 0) {
            // Charts should be auto-initialized by Symfony UX Chart.js bundle
            // If manual initialization is needed, it can be added here
        }
    }
}

