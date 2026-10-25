import Alpine from 'alpinejs';
import { enhancedSelect } from './admin/select.js';
import { migrateRunner } from './installer/migrate-runner.js';
import { installerForm } from './installer/form.js';
import { saasPackageForm } from './installer/saas-package-form.js';
import { saasPlatformProgress } from './installer/saas-progress.js';
import { registerInstallerNavigation } from './installer/navigation.js';

// Searchable <select> upgrade (TomSelect), shared with the admin so the
// installer's country / currency / timezone pickers look + behave identically
// to the rest of the system. Registered before Alpine.start().
Alpine.data('enhancedSelect', enhancedSelect);

// Chunked migrate + seed progress driver for the database step.
Alpine.data('migrateRunner', migrateRunner);

// Shared AJAX submission for every installer form.
Alpine.data('installerForm', installerForm);
Alpine.data('saasPackageForm', saasPackageForm);
Alpine.data('saasPlatformProgress', saasPlatformProgress);

window.Alpine = Alpine;
Alpine.start();
registerInstallerNavigation();
