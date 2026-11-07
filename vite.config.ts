import fs from 'node:fs';
import path from 'node:path';
import { defineConfig, lazyPlugins } from 'vite-plus';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

const certDir = path.resolve(__dirname, '.infrastructure/conf/traefik/dev/certificates');
const keyPath = path.join(certDir, 'local-dev-key.pem');
const certPath = path.join(certDir, 'local-dev.pem');

// Dev-server TLS certs are only needed when serving (HMR over HTTPS), and only
// when they actually exist. Skip during `vite build` and when certs are absent
// (e.g. CI), so dev still starts over HTTP instead of crashing.
const devHttps =
    fs.existsSync(keyPath) && fs.existsSync(certPath)
        ? {
              key: fs.readFileSync(keyPath),
              cert: fs.readFileSync(certPath),
          }
        : undefined;

export default defineConfig(({ command }) => ({
    // Pre-commit (.vite-hooks/pre-commit): format, lint and type-check staged files.
    staged: {
        '*.{ts,tsx}': 'vp check --fix',
    },
    fmt: {
        singleQuote: true,
        tabWidth: 4,
        printWidth: 140,
        // Only the frontend source is formatted. Everything else (test
        // fixtures, docs, compose files, composer.json) is left as is.
        ignorePatterns: [
            '.agents/**',
            '.claude/**',
            '.infrastructure/**',
            'docs/**',
            'resources/css/**',
            'tests/**',
            '**/*.json',
            '**/*.md',
            '**/*.yml',
            '**/*.yaml',
        ],
    },
    lint: {
        plugins: ['oxc', 'typescript', 'unicorn'],
        categories: {
            correctness: 'warn',
        },
        env: {
            builtin: true,
        },
        ignorePatterns: [
            'node_modules/**',
            'public/build/**',
            'public/hot/**',
            'vendor/**',
            '.agents/**',
            '.claude/**',
            'bootstrap/cache/**',
            'storage/**',
            '*.config.js',
            '*.config.mjs',
        ],
        overrides: [
            {
                files: ['resources/js/**/*.{ts,tsx}'],
                rules: {
                    'constructor-super': 'off',
                    'for-direction': 'error',
                    'getter-return': 'off',
                    'no-async-promise-executor': 'error',
                    'no-case-declarations': 'error',
                    'no-class-assign': 'off',
                    'no-compare-neg-zero': 'error',
                    'no-cond-assign': 'error',
                    'no-const-assign': 'off',
                    'no-constant-binary-expression': 'error',
                    'no-constant-condition': 'error',
                    'no-control-regex': 'error',
                    'no-debugger': 'error',
                    'no-delete-var': 'error',
                    'no-dupe-class-members': 'off',
                    'no-dupe-else-if': 'error',
                    'no-dupe-keys': 'off',
                    'no-duplicate-case': 'error',
                    'no-empty': 'error',
                    'no-empty-character-class': 'error',
                    'no-empty-pattern': 'error',
                    'no-empty-static-block': 'error',
                    'no-ex-assign': 'error',
                    'no-extra-boolean-cast': 'error',
                    'no-fallthrough': 'error',
                    'no-func-assign': 'off',
                    'no-global-assign': 'error',
                    'no-import-assign': 'off',
                    'no-invalid-regexp': 'error',
                    'no-irregular-whitespace': 'error',
                    'no-loss-of-precision': 'error',
                    'no-misleading-character-class': 'error',
                    'no-new-native-nonconstructor': 'off',
                    'no-nonoctal-decimal-escape': 'error',
                    'no-obj-calls': 'off',
                    'no-prototype-builtins': 'error',
                    'no-redeclare': 'off',
                    'no-regex-spaces': 'error',
                    'no-self-assign': 'error',
                    'no-setter-return': 'off',
                    'no-shadow-restricted-names': 'error',
                    'no-sparse-arrays': 'error',
                    'no-this-before-super': 'off',
                    'no-undef': 'off',
                    'no-unexpected-multiline': 'error',
                    'no-unreachable': 'off',
                    'no-unsafe-finally': 'error',
                    'no-unsafe-negation': 'off',
                    'no-unsafe-optional-chaining': 'error',
                    'no-unused-labels': 'error',
                    'no-unused-private-class-members': 'error',
                    'no-unused-vars': 'error',
                    'no-useless-backreference': 'error',
                    'no-useless-catch': 'error',
                    'no-useless-escape': 'error',
                    'no-with': 'off',
                    'require-yield': 'error',
                    'use-isnan': 'error',
                    'valid-typeof': 'error',
                    'no-var': 'error',
                    'prefer-const': 'error',
                    'prefer-rest-params': 'error',
                    'prefer-spread': 'error',
                    'no-array-constructor': 'error',
                    'no-unused-expressions': 'error',
                    'typescript/ban-ts-comment': 'error',
                    'typescript/no-duplicate-enum-values': 'error',
                    'typescript/no-empty-object-type': 'error',
                    'typescript/no-explicit-any': 'error',
                    'typescript/no-extra-non-null-assertion': 'error',
                    'typescript/no-misused-new': 'error',
                    'typescript/no-namespace': 'error',
                    'typescript/no-non-null-asserted-optional-chain': 'error',
                    'typescript/no-require-imports': 'error',
                    'typescript/no-this-alias': 'error',
                    'typescript/no-unnecessary-type-constraint': 'error',
                    'typescript/no-unsafe-declaration-merging': 'error',
                    'typescript/no-unsafe-function-type': 'error',
                    'typescript/no-wrapper-object-types': 'error',
                    'typescript/prefer-as-const': 'error',
                    'typescript/prefer-namespace-keyword': 'error',
                    'typescript/triple-slash-reference': 'error',
                    'react/display-name': 'error',
                    'react/jsx-key': 'error',
                    'react/jsx-no-comment-textnodes': 'error',
                    'react/jsx-no-duplicate-props': 'error',
                    'react/jsx-no-target-blank': 'error',
                    'react/jsx-no-undef': 'error',
                    'react/no-children-prop': 'error',
                    'react/no-danger-with-children': 'error',
                    'react/no-direct-mutation-state': 'error',
                    'react/no-find-dom-node': 'error',
                    'react/no-is-mounted': 'error',
                    'react/no-render-return-value': 'error',
                    'react/no-string-refs': 'error',
                    'react/no-unescaped-entities': 'error',
                    'react/no-unknown-property': 'error',
                    'react/no-unsafe': 'off',
                    'react/react-in-jsx-scope': 'off',
                    'react/require-render-return': 'error',
                    'react/rules-of-hooks': 'error',
                    'react/exhaustive-deps': 'warn',
                    'react/static-components': 'error',
                    'react/use-memo': 'error',
                    'react/preserve-manual-memoization': 'error',
                    'react/incompatible-library': 'warn',
                    'react/immutability': 'error',
                    'react/globals': 'error',
                    'react/refs': 'error',
                    'react/set-state-in-effect': 'warn',
                    'react/error-boundaries': 'error',
                    'react/purity': 'error',
                    'react/set-state-in-render': 'error',
                    'react/unsupported-syntax': 'warn',
                },
                plugins: ['react'],
                env: {
                    es2026: true,
                    browser: true,
                },
                globals: {
                    route: 'readonly',
                },
            },
        ],
        options: {
            typeAware: true,
            typeCheck: true,
        },
        jsPlugins: [
            {
                name: 'vite-plus',
                specifier: 'vite-plus/oxlint-plugin',
            },
        ],
        rules: {
            'vite-plus/prefer-vite-plus-imports': 'error',
        },
    },
    server: {
        host: '0.0.0.0',
        hmr: {
            host: 'vite.dev.test',
            clientPort: 443,
        },
        https: command === 'serve' ? devHttps : undefined,
        // The dev server runs in Docker on a macOS bind mount, where native
        // file events miss newly created folders (a new resources/js/Pages
        // directory after a branch switch shows "Inertia page not found"
        // until Vite restarts). Polling catches them. Heavy folders Vite
        // never serves are excluded to keep the polling cost down.
        watch: {
            usePolling: true,
            interval: 300,
            ignored: ['**/vendor/**', '**/storage/**', '**/.pnpm-store/**', '**/.playwright-mcp/**'],
        },
    },
    resolve: {
        alias: {
            '@': path.resolve(__dirname, 'resources/js'),
        },
    },
    plugins: lazyPlugins(() => [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            refresh: true,
        }),
        react(),
        tailwindcss(),
    ]),
}));
