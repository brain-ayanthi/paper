import { posPost } from '../lib/http.js';
import { navigateInstaller } from './navigation.js';

const chunkBytes = 512 * 1024;
const maxBytes = 64 * 1024 * 1024;

export function saasPackageForm({ genericError, invalidFile, tooLarge, rateLimited },
    { post = posPost, navigate = navigateInstaller } = {}) {
    return {
        submitting: false,
        dragging: false,
        fileName: '',
        progress: 0,
        errors: [],
        async submit(event) {
            if (this.submitting) return;
            const form = event.target;
            const file = this.$refs.delivery.files[0];
            this.errors = [];
            if (!file || !/\.zip$/i.test(file.name)) {
                this.errors = [invalidFile];
                this.$refs.delivery.focus();
                return;
            }
            if (!file.size || file.size > maxBytes) {
                this.errors = [tooLarge];
                this.$refs.delivery.focus();
                return;
            }
            this.submitting = true;
            this.progress = 0;
            const uploadId = [...crypto.getRandomValues(new Uint8Array(16))]
                .map(byte => byte.toString(16).padStart(2, '0')).join('');
            const total = Math.ceil(file.size / chunkBytes);
            try {
                for (let index = 0; index < total; index++) {
                    const part = new FormData();
                    part.append('upload_id', uploadId);
                    part.append('index', String(index));
                    part.append('total', String(total));
                    part.append('size', String(file.size));
                    part.append('chunk', file.slice(index * chunkBytes,
                        Math.min(file.size, (index + 1) * chunkBytes)), 'part.bin');
                    const { data } = await post(form.dataset.chunkUrl, part, { timeout: 0 });
                    if (data?.ok !== true || data.next_index !== index + 1) {
                        throw { message: genericError };
                    }
                    this.progress = Math.round(((index + 1) / total) * 100);
                }
                const finish = new FormData();
                finish.append('upload_id', uploadId);
                const { data } = await post(form.action, finish, { timeout: 0 });
                if (data?.ok !== true || !data.redirect) throw { message: genericError };
                await navigate(data.redirect);
            } catch (error) {
                const fields = error?.errors || {};
                this.errors = Object.values(fields).flat().filter(value => typeof value === 'string');
                if (error?.status === 429) this.errors = [rateLimited];
                else if (!this.errors.length) this.errors = [error?.message || genericError];
            } finally {
                this.submitting = false;
            }
        },
    };
}
