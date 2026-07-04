import { defineConfig } from 'vite';

export default defineConfig({
    define: {
        __YOPASS_BUILD_TIME__: JSON.stringify(new Date().toISOString()),
    },
    build: {
        outDir: 'src/Resources/public',
        emptyOutDir: false,
        rollupOptions: {
            input: 'src/Resources/assets/src/yopass.ts',
            output: {
                format: 'es',
                entryFileNames: 'js/yopass.js',
                assetFileNames: 'js/yopass.[ext]',
            },
        },
        minify: true,
        sourcemap: false,
    },
});
