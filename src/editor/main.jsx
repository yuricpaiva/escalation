import React from 'react';
import { createRoot } from 'react-dom/client';
import App from './App';
import './editor.css';

function hideAllTab() {
  document.querySelectorAll('a[href*="forcetab=-1"], a[href*="forcetab=all"]').forEach((link) => {
    (link.closest('li') || link).remove();
  });
}

function mount() {
  const root = document.getElementById('escalation-visual-editor');
  const configNode = document.getElementById('escalation-visual-editor-config');
  if (!root || !configNode || root.dataset.mounted) return;
  root.dataset.mounted = 'true';
  createRoot(root).render(<App config={JSON.parse(configNode.textContent)} />);
}

if (/[?&]forcetab=(-1|all)(?:&|$)/.test(window.location.search)) {
  const url = new URL(window.location.href);
  url.searchParams.set('forcetab', 'PluginEscalationFlow$1');
  window.location.replace(url.toString());
}

function refresh() {
  hideAllTab();
  mount();
}

refresh();
new MutationObserver(refresh).observe(document.body, { childList: true, subtree: true });
