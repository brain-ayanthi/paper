import { posPost } from '../lib/http.js';
import { navigateInstaller } from './navigation.js';

export function installerForm({ genericError }, { post = posPost, navigate = navigateInstaller } = {}) {
    return {
        submitting: false,
        errors: [],
        mode: 'none',
        async submit(event) {
            if (this.submitting) return;
            this.submitting = true;
            this.errors = [];
            const form = event.target;
            form.querySelectorAll('[aria-invalid]').forEach(el => el.removeAttribute('aria-invalid'));
            try {
                // Admin creation/demo seeding may outlast the admin client's 30s
                // default. Do not time out and encourage duplicate submissions.
                const { data } = await post(form.action, new FormData(form), { timeout: 0 });
                if (!data?.redirect || data.ok === false) throw { message: data?.error || genericError };
                await navigate(data.redirect);
            } catch (error) {
                const fields = error?.errors || {};
                this.errors = Object.values(fields).flat().filter(message => typeof message === 'string');
                if (!this.errors.length) this.errors = [error?.raw?.response?.data?.error
                    || (error?.status === 422 ? error?.raw?.response?.data?.message : null) || genericError];
                let first;
                for (const name of Object.keys(fields)) {
                    const input = form.elements.namedItem(name);
                    if (input?.setAttribute) {
                        input.setAttribute('aria-invalid', 'true');
                        first ||= input;
                    }
                }
                first?.focus();
            } finally {
                this.submitting = false;
            }
        },
    };
}
