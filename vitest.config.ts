import { fileURLToPath } from 'node:url'
import { defineConfig } from 'vitest/config'

// Standalone Vitest config kept separate from vite.config.ts so the test
// runner does not pull in the Laravel/Wayfinder/Tailwind build plugins.
export default defineConfig({
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./resources/js', import.meta.url))
    }
  },
  test: {
    environment: 'node',
    include: ['resources/js/**/*.{test,spec}.ts']
  }
})
