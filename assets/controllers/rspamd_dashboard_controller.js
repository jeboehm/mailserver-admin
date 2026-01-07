import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static values = {
        throughputUrl: String,
        actionsPieUrl: String,
        thresholdsUrl: String,
        countersUrl: String,
        historyUrl: String,
        defaultType: { type: String, default: 'day' }
    };

    static targets = [
        'throughputContainer',
        'actionsPieContainer',
        'thresholdsContainer',
        'countersContainer',
        'historyContainer',
        'typeButton'
    ];

    connect() {
        this.currentType = this.defaultTypeValue;
        this.loadAllFragments();
    }

    async selectType(event) {
        const type = event.currentTarget.dataset.type;

        if (type === this.currentType) {
            return;
        }

        // Update active button state
        this.typeButtonTargets.forEach(button => {
            button.classList.toggle('active', button.dataset.type === type);
        });

        this.currentType = type;
        await this.loadThroughput();
    }

    async loadAllFragments() {
        await Promise.all([
            this.loadThroughput(),
            this.loadActionsPie(),
            this.loadThresholds(),
            this.loadCounters(),
            this.loadHistory()
        ]);
    }

    async loadThroughput() {
        if (!this.hasThroughputContainerTarget) {
            return;
        }

        const url = this.throughputUrlValue.replace('{type}', this.currentType);
        await this.loadFragment(this.throughputContainerTarget, url);
    }

    async loadActionsPie() {
        if (!this.hasActionsPieContainerTarget) {
            return;
        }

        await this.loadFragment(this.actionsPieContainerTarget, this.actionsPieUrlValue);
    }

    async loadThresholds() {
        if (!this.hasThresholdsContainerTarget) {
            return;
        }

        await this.loadFragment(this.thresholdsContainerTarget, this.thresholdsUrlValue);
    }

    async loadCounters() {
        if (!this.hasCountersContainerTarget) {
            return;
        }

        await this.loadFragment(this.countersContainerTarget, this.countersUrlValue);
    }

    async loadHistory() {
        if (!this.hasHistoryContainerTarget) {
            return;
        }

        await this.loadFragment(this.historyContainerTarget, this.historyUrlValue);
    }

    async loadFragment(container, url) {
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
        container.innerHTML = `
            <div class="d-flex align-items-center justify-content-center" style="min-height: 200px;">
                <div class="text-center text-muted">
                    <div class="spinner-border spinner-border-sm mb-2" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mb-0 small">Loading...</p>
                </div>
            </div>
        `;
    }

    showError(container) {
        container.innerHTML = `
            <div class="d-flex align-items-center justify-content-center" style="min-height: 200px;">
                <div class="text-center text-danger">
                    <i class="fa fa-exclamation-triangle fa-2x mb-2"></i>
                    <p class="mb-0 small">Failed to load data. Please try refreshing the page.</p>
                </div>
            </div>
        `;
    }

    initializeCharts(container) {
        // Chart.js charts are automatically initialized by Symfony UX Chart.js
        // This method can be extended if additional initialization is needed
        const chartElements = container.querySelectorAll('[data-controller*="chart"]');

        // Trigger any custom chart initialization if needed
        if (chartElements.length > 0) {
            // Charts should be auto-initialized by Symfony UX Chart.js bundle
            // If manual initialization is needed, it can be added here
        }
    }
}
