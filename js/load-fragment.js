async function loadFragment(elementId, filepath) {
  const container = document.getElementById(elementId);
  if (!container) return;
  try {
    const res = await fetch(filepath);
    if (res.ok) {
      container.innerHTML = await res.text();
    }
  } catch (err) {
    console.warn(`Failed to load fragment ${filepath}:`, err);
  }
}