import js from '@eslint/js'
import globals from 'globals'

// Control panel scripts are plain browser scripts loaded by asset bundles,
// not modules, so they get ESLint's bug-catching rules and no style rules.
export default [
  {
    ignores: ['**/*.min.js', 'docs/**', 'node_modules/**', 'tests/**', 'vendor/**'],
  },
  {
    files: ['src/**/*.js'],
    ...js.configs.recommended,
    languageOptions: {
      ecmaVersion: 'latest',
      sourceType: 'script',
      globals: {
        ...globals.browser,
        // Provided by the control panel.
        $: 'readonly',
        Craft: 'readonly',
        Garnish: 'readonly',
        jQuery: 'readonly',
      },
    },
    rules: {
      ...js.configs.recommended.rules,
      // An empty catch marks a failure as harmless.
      'no-empty': ['error', { allowEmptyCatch: true }],
      'no-unused-vars': ['error', { caughtErrors: 'none' }],
    },
  },
]
