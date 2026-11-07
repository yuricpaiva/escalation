import { afterEach, describe, expect, it, vi } from 'vitest';
import { request, searchOptions } from './api';

const config = { endpoint: '/plugins/escalation/ajax/visualeditor.php', flowId: 3, csrfToken: 'stale' };
const jsonResponse = (data, ok = true) => ({ ok, json: async () => data });

afterEach(() => vi.unstubAllGlobals());

describe('comunicação com o GLPI', () => {
  it('consulta listas por GET sem exigir token CSRF', async () => {
    const fetch = vi.fn().mockResolvedValue(jsonResponse({ items: [{ id: 25, text: 'Pagamentos' }] }));
    vi.stubGlobal('fetch', fetch);
    await expect(searchOptions(config, 'categories', 'Paga')).resolves.toEqual([{ id: 25, text: 'Pagamentos' }]);
    expect(fetch).toHaveBeenCalledTimes(1);
    expect(fetch.mock.calls[0][0]).toContain('action=options');
    expect(fetch.mock.calls[0][1].credentials).toBe('same-origin');
  });

  it('obtém um token novo antes de salvar', async () => {
    const fetch = vi.fn()
      .mockResolvedValueOnce(jsonResponse({ csrfToken: 'fresh-token' }))
      .mockResolvedValueOnce(jsonResponse({ ok: true, revision: 2 }));
    vi.stubGlobal('fetch', fetch);
    await request(config, 'save', { definition: {}, revision: 1 });
    const posted = new URLSearchParams(fetch.mock.calls[1][1].body);
    expect(posted.get('_glpi_csrf_token')).toBe('fresh-token');
    expect(JSON.parse(posted.get('payload'))).toEqual({ definition: {}, revision: 1 });
    expect(fetch.mock.calls[1][1].headers['X-Glpi-Csrf-Token']).toBe('fresh-token');
    expect(fetch).toHaveBeenCalledTimes(2);
  });
});
