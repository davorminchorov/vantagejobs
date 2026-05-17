import '../css/app.css';

import { createInertiaApp, type ResolvedComponent } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';

const appName = import.meta.env.VITE_APP_NAME ?? 'VantageJobs';

void createInertiaApp({
    title: (title) => (title ? `${title} · ${appName}` : appName),
    resolve: async (name) => {
        const pages = import.meta.glob<ResolvedComponent>('./Pages/**/*.tsx');
        const importer = pages[`./Pages/${name}.tsx`];
        if (!importer) {
            throw new Error(`Inertia page not found: ${name}`);
        }
        return await importer();
    },
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
    progress: {
        color: '#4f46e5',
    },
});
