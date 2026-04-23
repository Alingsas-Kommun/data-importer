import { defineConfig } from 'vite';
import path from 'node:path';

export default defineConfig({
	build: {
		outDir: 'dist',
		emptyOutDir: true,
		cssCodeSplit: true,
		manifest: 'manifest.json',
		sourcemap: false,
		rollupOptions: {
			input: {
				admin: path.resolve(__dirname, 'src/js/admin.js'),
				frontend: path.resolve(__dirname, 'src/js/frontend.js')
			},
			output: {
				entryFileNames: 'js/[name].[hash].js',
				chunkFileNames: 'js/[name].[hash].js',
				assetFileNames: ({ names = [] }) => {
					const assetName = names[0] ?? '';

					if (assetName.endsWith('.css')) {
						return 'css/[name].[hash][extname]';
					}

					return 'assets/[name].[hash][extname]';
				}
			}
		}
	}
});
