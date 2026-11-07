(() => {
  const params = new URLSearchParams(window.location.search);
  const ticketId = params.get('id');
  if (!ticketId) return;

  const root = (window.CFG_GLPI && window.CFG_GLPI.root_doc) ? window.CFG_GLPI.root_doc : '';
  const checkUrl = `${root}/plugins/escalation/front/solve.check.php?tickets_id=${encodeURIComponent(ticketId)}`;

  const inject = (data) => {
    const footers = document.querySelectorAll('.validation-footer');
    if (!footers.length) return;

    footers.forEach((footer) => {
      if (footer.querySelector('[data-escalation-solve="1"]')) return;
      const form = footer.closest('form');
      if (!form) return;

      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'btn btn-success me-2';
      btn.dataset.escalationSolve = '1';
      btn.innerHTML = `<i class="ti ti-circle-check"></i> <span>${data.label || 'Solucionar'}</span>`;
      btn.addEventListener('click', () => {
        const commentField = form.querySelector('[name="comment_validation"]');
        let comment = '';
        if (commentField) {
          if (window.tinymce && tinymce.get(commentField.id)) {
            comment = tinymce.get(commentField.id).getContent({ format: 'text' }) || '';
          } else {
            comment = commentField.value || '';
          }
        }
        comment = String(comment).replace(/<[^>]*>/g, ' ').trim();
        if (!comment) {
          alert('Descreva a solução no campo de comentários antes de solucionar.');
          commentField?.focus();
          return;
        }
        const post = document.createElement('form');
        post.method = 'post';
        post.action = data.solve_url;
        post.style.display = 'none';
        const fields = {
          _glpi_csrf_token: data.csrf,
          ticketstep_id: data.ticketstep_id,
          tickets_id: data.tickets_id,
          comment,
          solve: '1',
        };
        Object.entries(fields).forEach(([name, value]) => {
          const input = document.createElement('input');
          input.type = 'hidden';
          input.name = name;
          input.value = value;
          post.appendChild(input);
        });
        document.body.appendChild(post);
        post.submit();
      });

      footer.insertBefore(btn, footer.firstChild);
    });
  };

  const run = () => {
    fetch(checkUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then((response) => (response.ok ? response.json() : null))
      .then((data) => {
        if (!data || !data.ok) return;
        inject(data);
      })
      .catch(() => {});
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', run);
  } else {
    run();
  }

  // O pedido de aprovação pode ser re-renderizado via AJAX na timeline.
  const observer = new MutationObserver(() => {
    if (document.querySelector('.validation-footer') && !document.querySelector('[data-escalation-solve="1"]')) {
      run();
    }
  });
  observer.observe(document.body, { childList: true, subtree: true });
})();
