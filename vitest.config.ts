import { defineConfig } from 'vitest/config';

export default defineConfig({
    define: {
        __YOPASS_BUILD_TIME__: JSON.stringify('2026-04-14T12:42:34.906Z'),
    },
    test: {
    environment: 'node',
    globals: true,
    include: ['src/Resources/assets/**/*.test.ts'],
    coverage: {
      provider: 'v8',
      reporter: ['text', 'text-summary', 'html'],
      reportsDirectory: './coverage-ts',
      include: ['src/Resources/assets/src/yopass-crypto.ts'],
      exclude: ['**/*.test.ts', '**/node_modules/**'],
    },
  },
});
