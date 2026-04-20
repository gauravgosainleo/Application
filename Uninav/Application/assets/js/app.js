// Close any open profile dropdown when clicking outside
document.addEventListener('click', (e) => {
  document.querySelectorAll('.profile.open').forEach(el => {
    if (!el.contains(e.target)) el.classList.remove('open');
  });
});

// Close modals on backdrop click
document.addEventListener('click', (e) => {
  if (e.target.classList && e.target.classList.contains('modal')) {
    e.target.classList.remove('open');
  }
});
