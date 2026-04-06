/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    "./**/*.html",
    "./**/*.js"
  ],
  theme: {
    extend: {
      colors: {
        brand: {
          dark:   '#0f172a',
          blue:   '#1e3a8a',
          red:    '#dc2626',
          green:  '#059669',
          yellow: '#d97706',
          purple: '#6d28d9',
          teal:   '#0f766e',
          indigo: '#6366f1',
        },
      },
      fontFamily: {
        sans: ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'],
      },
    },
  },
  plugins: [],
}
