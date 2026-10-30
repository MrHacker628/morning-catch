/* =====================================================================
   MORNING CATCH — shared Tailwind theme

   Loaded right after the Tailwind CDN script by index.html, /admin and
   the legal pages, so every page uses one palette. Change a colour here
   and the whole site follows.

   Palette: "harbour at dawn" — deep sea ink, teal water, sunrise coral,
   saffron sun, warm sand.
   ===================================================================== */

tailwind.config = {
  theme: {
    extend: {
      colors: {
        ink:   { DEFAULT: '#0B2733', soft: '#33505C' },
        sea:   { DEFAULT: '#0E6B74', light: '#DDF0EE' },
        coral: { DEFAULT: '#F2643D', dark: '#D24E2A', light: '#FDE7DF' },
        sun:   { DEFAULT: '#F5B53F', light: '#FDF1D6' },
        sand:  { DEFAULT: '#FBF6EE', dark: '#F1E8DA' },
      },
      fontFamily: {
        display: ['Fraunces', 'Georgia', 'serif'],
        sans: ['"Plus Jakarta Sans"', 'system-ui', 'sans-serif'],
      },
      boxShadow: {
        card: '0 1px 2px rgba(11,39,51,.06), 0 8px 24px -12px rgba(11,39,51,.18)',
        lift: '0 20px 40px -16px rgba(11,39,51,.35)',
      },
    },
  },
};
