/** Admin UI build. Run `npm run build:css` after changing classes in admin templates or admin.js. */
module.exports = {
  content: [
    './admin/templates/**/*.twig',
    './public/assets/js/admin.js',
    './public/assets/js/admin-blocks.js',
    './public/assets/js/admin-media-picker.js',
    './public/assets/js/admin-menus.js',
    './public/assets/js/admin-form-builder.js',
    './public/assets/js/admin-taxonomies.js',
    './public/assets/js/admin-ai-picture.js',
    './public/assets/js/admin-shop-import.js',
    './src/**/*.php',
  ],
  theme: {
    extend: {
      // Muted text must reach 4.5:1 on white and on the page background (#f1f5f9). The stock slate-400/500 and the
      // 600 shades of emerald and orange fall short (2.6, 4.3, 3.8, 3.6), so the scale is nudged: the class names, and
      // so the markup, stay the same. Checked with axe-core over the admin screens (docs/admin-accessibility.md).
      colors: {
        slate: { 400: '#5f6f85', 500: '#4d5d73' },
        emerald: { 600: '#047857' },
        orange: { 600: '#c2410c' },
      },
      fontFamily: {
        sans: ['"Inter Tight"', 'ui-sans-serif', 'system-ui', 'sans-serif'],
      },
    },
  },
  // Toggled from inline scripts in base.twig (tab state), so keep them even if unused in markup.
  safelist: [
    'hidden',
    'block',
    'bg-slate-200',
    'bg-white',
    'border-slate-400',
    'border-slate-300',
    'text-slate-900',
    'text-slate-600',
  ],
};
