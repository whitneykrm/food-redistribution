async function loadFragment(elementId, filepath) {
  const container = document.getElementById(elementId);
  if (!container) return;
  try {
    const res = await fetch(filepath);
    if (res.ok) {
      container.innerHTML = await res.text();

      // innerHTML never executes <script> tags, so the navbar/footer's own
      // dynamic logic (session-check, logout wiring, etc.) would silently
      // never run without this. Re-create each script tag so the browser
      // actually executes it.
      container.querySelectorAll('script').forEach((oldScript) => {
        const newScript = document.createElement('script');
        if (oldScript.src) {
          newScript.src = oldScript.src;
        } else {
          newScript.textContent = oldScript.textContent;
        }
        oldScript.replaceWith(newScript);
      });
    }
  } catch (err) {
    console.warn(`Failed to load fragment ${filepath}:`, err);
  }
}