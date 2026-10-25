export function saasPlatformProgress(options) {
    return {
        label: options.checking,
        count: '0 / 6',
        percent: 0,
        message: '',
        errorCode: '',
        ready: false,
        needsAttention: false,
        timer: null,
        pending: false,
        disposed: false,

        init() { this.poll(); },
        destroy() {
            this.disposed = true;
            clearTimeout(this.timer);
        },
        retry() {
            if (this.pending || this.disposed) return;
            this.needsAttention = false;
            this.message = '';
            this.errorCode = '';
            this.poll();
        },
        async poll() {
            if (this.pending || this.disposed) return;
            this.pending = true;
            try {
                const response = await fetch(options.url, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': options.csrf,
                    },
                    credentials: 'same-origin',
                });
                if (!response.ok) throw new Error('status');
                const value = await response.json();
                if (this.disposed) return;
                const total = Number.isInteger(value.total_steps) && value.total_steps > 0 ? value.total_steps : 6;
                const completed = Number.isInteger(value.completed_steps)
                    ? Math.max(0, Math.min(value.completed_steps, total)) : 0;
                this.ready = value.ready === true && value.status === 'complete' && completed === total;
                this.needsAttention = value.status === 'needs_attention';
                this.count = `${completed} / ${total}`;
                this.percent = this.ready ? 100 : Math.min(99, Math.round((completed / total) * 100));
                this.errorCode = this.needsAttention && typeof value.error === 'string' ? value.error : '';
                this.label = this.ready ? options.complete
                    : (this.errorCode.startsWith('management_') ? options.steps.management
                        : (options.steps[value.current_step] || options.checking));
                this.message = this.needsAttention
                    ? (options.errors?.[this.errorCode]
                        || (value.current_step === 'health' ? options.healthHelp : options.contactHost))
                    : '';
            } catch (error) {
                if (!this.disposed) this.message = options.unavailable;
            } finally {
                this.pending = false;
                if (!this.disposed && !this.ready && !this.needsAttention) {
                    this.timer = setTimeout(() => this.poll(), 5000);
                }
            }
        },
    };
}
