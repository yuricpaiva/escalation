export async function request(config, action, payload = {}, method = 'POST') {
  const body = { action, flowId: config.flowId, ...payload };
  let csrfToken = config.csrfToken;
  if (method === 'POST') {
    const tokenResponse = await fetch(`${config.endpoint}?${new URLSearchParams({ action: 'token', flowId: config.flowId })}`, { credentials: 'same-origin' });
    const tokenData = await tokenResponse.json().catch(() => ({}));
    if (!tokenResponse.ok || !tokenData.csrfToken) throw new Error('Não foi possível validar sua sessão. Atualize a página e tente novamente.');
    csrfToken = tokenData.csrfToken;
  }
  const response = method === 'GET'
    ? await fetch(`${config.endpoint}?${new URLSearchParams(body)}`, { credentials: 'same-origin' })
    : await fetch(config.endpoint, {
        method: 'POST', credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
          'X-Requested-With': 'XMLHttpRequest',
          'X-Glpi-Csrf-Token': csrfToken,
        },
        body: new URLSearchParams({
          action,
          flowId: String(config.flowId),
          _glpi_csrf_token: csrfToken,
          payload: JSON.stringify(payload),
        }).toString(),
      });
  const data = await response.json().catch(() => ({ message: 'O GLPI retornou uma resposta que não pôde ser lida.' }));
  if (!response.ok) throw new Error(data.message || 'Não foi possível concluir a operação.');
  return data;
}

export async function searchOptions(config, kind, search = '', ids = []) {
  const payload = { kind, search };
  if (ids?.length) payload.ids = ids.map(Number).filter((id) => id > 0).join(',');
  const data = await request(config, 'options', payload, 'GET');
  return data.items || [];
}

export function labelFromMap(labels, kind, id) {
  if (!id && id !== 0) return '';
  return labels?.[kind]?.[String(id)] || labels?.[kind]?.[Number(id)] || '';
}
