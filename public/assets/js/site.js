document.addEventListener('htmx:afterSwap', () => {
    const activeFilter = new URLSearchParams(window.location.search).get('filter') ?? 'new';
    document.querySelectorAll('.filter-link').forEach((link) => {
        link.classList.toggle('active', link.dataset.filter === activeFilter);
    });
});
