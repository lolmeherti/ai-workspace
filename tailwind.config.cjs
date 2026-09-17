module.exports = {
  content: ['./src/**/*.php', './src/js/**/*.js'],
  theme: {
    extend: {
      colors: { slate: { 750: '#2a3b55', 850: '#182236' } }
    }
  },
  // Streamed activity selects colour variants at runtime. Keep these in the
  // compiled stylesheet as well as the literal classes found in templates.
  safelist: ['slate', 'cyan', 'sky', 'blue', 'indigo', 'violet', 'emerald', 'green', 'amber', 'yellow', 'orange', 'red', 'rose'].flatMap(color => [`bg-${color}-500/5`, `border-${color}-500/20`, `text-${color}-400`, `text-${color}-500`])
};
