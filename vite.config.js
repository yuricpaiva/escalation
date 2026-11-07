import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

export default defineConfig({
  plugins: [react()],
  publicDir: false,
  build: {
    outDir: 'public/build',
    emptyOutDir: true,
    cssCodeSplit: false,
    rollupOptions: {
      input: 'src/editor/main.jsx',
      output: {
        entryFileNames: 'visual-editor-2.1.11.js',
        assetFileNames: ({ names = [] }) => names.some((name) => name.endsWith('.css')) ? 'visual-editor-2.1.11.css' : '[name][extname]',
      },
    },
  },
});
