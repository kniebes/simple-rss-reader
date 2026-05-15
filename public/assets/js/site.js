document.addEventListener('click', async (e) => {
    const btn = e.target.closest('.favorite-toggle');
    if (!btn) return;
    e.preventDefault();

    const id = btn.dataset.postId;
    const newState = btn.getAttribute('aria-pressed') !== 'true';

    const render = (state) => {
        btn.setAttribute('aria-pressed', state ? 'true' : 'false');
        btn.classList.toggle('is-favorite', state);
        btn.textContent = state ? '★' : '☆';
    };

    render(newState);

    try {
        const response = await fetch('/favorite.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `id=${encodeURIComponent(id)}&favorite=${newState ? '1' : '0'}`,
        });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
    } catch (err) {
        render(!newState);
        console.error('favorite toggle failed', err);
    }
});
