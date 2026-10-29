import { defineConfig } from 'vitest/config';

export default defineConfig({
    define: {
        __YOPASS_BUILD_TIME__: JSON.stringify('2026-04-14T12:42:34.906Z'),
    },
    test: {
        // Default env is node (crypto tests); DOM/controller tests opt in with
        // a per-file `// @vitest-environment happy-dom` docblock.
        environment: 'node',
        globals: true,
        include: ['src/Resources/assets/**/*.test.ts'],
        coverage: {
            provider: 'v8',
            // text + text-summary are parsed by .scripts/ts-coverage-percent.sh.
            reporter: ['text', 'text-summary', 'html'],
            reportsDirectory: './coverage-ts',
            // Every TypeScript source shipped in the bundle is measured (no per-file exclusions).
            include: ['src/Resources/assets/src/**/*.ts'],
            exclude: ['**/*.test.ts', '**/node_modules/**'],
            thresholds: {
                lines: 90,
                statements: 90,
                functions: 90,
                branches: 90,
            },
        },
    },
});
