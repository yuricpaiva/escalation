import React, { useEffect, useId, useState } from 'react';
import { labelFromMap, searchOptions } from './api';
import { actionLabels, conditionFields, operatorLabels, sourceLabels } from './catalog';

function Field({ label, help, children }) {
  return <label className="ev-field"><span className="ev-field__label">{label}</span>{children}{help && <small>{help}</small>}</label>;
}

function AsyncSelect({ config, kind, value, onChange, placeholder = 'Pesquisar…', allowEmpty = true, labels, multiple = false }) {
  const id = useId();
  const [query, setQuery] = useState('');
  const [items, setItems] = useState([]);
  const [open, setOpen] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [selectedText, setSelectedText] = useState('');
  const values = multiple ? (Array.isArray(value) ? value.map(Number).filter((item) => item > 0) : []) : [];
  const scalar = multiple ? 0 : Number(value || 0);

  useEffect(() => {
    if (!open) return;
    const timer = setTimeout(async () => {
      setBusy(true);
      setError('');
      try { setItems(await searchOptions(config, kind, query)); }
      catch { setItems([]); setError('Não foi possível consultar o GLPI. Tente novamente.'); }
      finally { setBusy(false); }
    }, 220);
    return () => clearTimeout(timer);
  }, [config, kind, open, query]);

  useEffect(() => {
    if (multiple) return;
    const cached = labelFromMap(labels, kind, scalar);
    if (cached) { setSelectedText(cached); return; }
    if (!scalar) { setSelectedText(''); return; }
    let cancelled = false;
    (async () => {
      try {
        const resolved = await searchOptions(config, kind, '', [scalar]);
        const found = resolved.find((item) => Number(item.id) === scalar);
        if (!cancelled) setSelectedText(found?.text || '');
      } catch {
        if (!cancelled) setSelectedText('');
      }
    })();
    return () => { cancelled = true; };
  }, [config, kind, labels, multiple, scalar]);

  useEffect(() => {
    const found = items.find((item) => Number(item.id) === scalar);
    if (found) setSelectedText(found.text);
  }, [items, scalar]);

  const chipLabel = (itemId) => labelFromMap(labels, kind, itemId)
    || items.find((item) => Number(item.id) === Number(itemId))?.text
    || `Item #${itemId}`;

  if (multiple) {
    return <div className="ev-combobox">
      <div className="ev-chip-list ev-chip-list--values">
        {values.map((itemId) => <span className="ev-chip" key={itemId}>{chipLabel(itemId)}<button type="button" aria-label="Remover item" onClick={() => onChange(values.filter((entry) => entry !== itemId))}><i className="ti ti-x" /></button></span>)}
      </div>
      <input id={id} className="form-control" role="combobox" aria-expanded={open} aria-controls={`${id}-list`} value={open ? query : ''} placeholder={placeholder}
        onFocus={() => { setOpen(true); setQuery(''); }} onChange={(event) => { setQuery(event.target.value); setOpen(true); }} />
      {open && <div id={`${id}-list`} role="listbox" className="ev-combobox__menu">
        {busy && <span className="ev-combobox__status">Buscando no GLPI…</span>}
        {!busy && error && <span className="ev-combobox__status text-danger">{error}</span>}
        {!busy && items.filter((item) => !values.includes(Number(item.id))).map((item) => <button type="button" role="option" key={item.id} onMouseDown={(event) => event.preventDefault()} onClick={() => { onChange([...values, Number(item.id)]); setQuery(''); }}>{item.text}</button>)}
        {!busy && !error && !items.length && <span className="ev-combobox__status">Nenhum resultado encontrado.</span>}
      </div>}
    </div>;
  }

  return <div className="ev-combobox">
    <input id={id} className="form-control" role="combobox" aria-expanded={open} aria-controls={`${id}-list`} value={open ? query : selectedText} placeholder={scalar && !selectedText ? 'Carregando nome…' : placeholder}
      onFocus={() => { setOpen(true); setQuery(''); }} onChange={(event) => { setQuery(event.target.value); setOpen(true); }} />
    {open && <div id={`${id}-list`} role="listbox" className="ev-combobox__menu">
      {allowEmpty && <button type="button" role="option" onMouseDown={(event) => event.preventDefault()} onClick={() => { onChange(0); setSelectedText(''); setOpen(false); }}>Nenhum</button>}
      {busy && <span className="ev-combobox__status">Buscando no GLPI…</span>}
      {!busy && error && <span className="ev-combobox__status text-danger">{error}</span>}
      {!busy && items.map((item) => <button type="button" role="option" key={item.id} onMouseDown={(event) => event.preventDefault()} onClick={() => { onChange(Number(item.id)); setSelectedText(item.text); setOpen(false); }}>{item.text}</button>)}
      {!busy && !error && !items.length && <span className="ev-combobox__status">Nenhum resultado encontrado.</span>}
    </div>}
  </div>;
}

function StepOrderControls({ index, total, onMove, readOnly }) {
  if (total < 2) return null;
  return <div>
    <div className="ev-order">
    <span>Ordem interna {index + 1} de {total}</span>
    <div>
      <button type="button" className="btn btn-sm btn-outline-secondary" disabled={readOnly || index === 0} onClick={() => onMove(-1)} aria-label="Subir etapa"><i className="ti ti-arrow-up" /> Subir</button>
      <button type="button" className="btn btn-sm btn-outline-secondary" disabled={readOnly || index >= total - 1} onClick={() => onMove(1)} aria-label="Descer etapa"><i className="ti ti-arrow-down" /> Descer</button>
    </div>
    </div>
    <p className="ev-help">Subir ou descer não mexe no desenho. A seta é o que define o caminho.</p>
  </div>;
}

function StartProperties({ definition, updateDefinition, config }) {
  const settings = definition.settings;
  const update = (patch) => updateDefinition({ ...definition, settings: { ...settings, ...patch } });
  return <>
    <header className="ev-properties__header"><span className="ev-properties__icon"><i className="ti ti-player-play-filled" /></span><div><h3>Início do fluxo</h3><p>Defina onde este fluxo vale e qual tem prioridade.</p></div></header>
    <Field label="Nome do fluxo"><input className="form-control" value={settings.name || ''} onChange={(e) => update({ name: e.target.value })} /></Field>
    <Field label="Descrição"><textarea className="form-control" rows="3" value={settings.description || ''} onChange={(e) => update({ description: e.target.value })} /></Field>
    <Field label="Entidade"><AsyncSelect config={config} kind="entities" value={settings.entityId} onChange={(entityId) => update({ entityId })} labels={definition.labels} /></Field>
    <label className="form-check"><input className="form-check-input" type="checkbox" checked={!!settings.recursive} onChange={(e) => update({ recursive: e.target.checked })} /><span className="form-check-label">Aplicar também às entidades abaixo desta</span></label>
    <Field label="Prioridade do fluxo" help="Se mais de um fluxo combinar com o chamado, o GLPI usa o de maior prioridade."><input className="form-control" type="number" value={settings.priority || 0} onChange={(e) => update({ priority: Number(e.target.value) })} /></Field>
  </>;
}

function ConditionValue({ condition, update, config, labels }) {
  const fieldKinds = { category: 'categories', creator: 'users', requester: 'users', creator_group: 'groups', requester_group: 'groups', type: 'types', status: 'statuses' };
  const multi = ['in', 'not_in'].includes(condition.operator);
  if (condition.field === 'event') return <select className="form-select" value={condition.value || ''} onChange={(e) => update({ value: e.target.value })}><option value="">Escolha…</option><option value="created">Quando o chamado for aberto</option><option value="updated">Quando o chamado for atualizado</option></select>;
  if (fieldKinds[condition.field]) {
    if (multi) {
      const values = Array.isArray(condition.value) ? condition.value : (condition.value ? [condition.value] : []);
      return <AsyncSelect config={config} kind={fieldKinds[condition.field]} value={values} onChange={(value) => update({ value })} allowEmpty={false} labels={labels} multiple />;
    }
    return <AsyncSelect config={config} kind={fieldKinds[condition.field]} value={condition.value} onChange={(value) => update({ value })} allowEmpty={false} labels={labels} />;
  }
  return <input className="form-control" value={condition.value || ''} onChange={(e) => update({ value: e.target.value })} />;
}

function ConditionProperties({ node, definition, updateDefinition, config }) {
  const groupIndex = definition.conditions.findIndex((group) => group.key === node.id);
  const group = definition.conditions[groupIndex];
  const replace = (next) => updateDefinition({ ...definition, conditions: definition.conditions.map((item, index) => index === groupIndex ? next : item) });
  const add = () => replace({ ...group, all: [...group.all, { field: 'category', operator: 'equals', value: '' }] });
  return <>
    <header className="ev-properties__header"><span className="ev-properties__icon"><i className="ti ti-filter" /></span><div><h3>Quando iniciar</h3><p>Decide quem entra e em qual etapa esse caso começa.</p></div></header>
    <div className="ev-rule-list">
      {group.all.map((condition, index) => {
        const update = (patch) => {
          const next = { ...condition, ...patch };
          if (patch.operator && ['in', 'not_in'].includes(patch.operator) && !Array.isArray(next.value)) {
            next.value = next.value ? [next.value] : [];
          }
          if (patch.operator && !['in', 'not_in'].includes(patch.operator) && Array.isArray(next.value)) {
            next.value = next.value[0] ?? '';
          }
          replace({ ...group, all: group.all.map((item, itemIndex) => itemIndex === index ? next : item) });
        };
        return <div className="ev-rule" key={index}>
          <div className="ev-rule__heading"><strong>{index === 0 ? 'Quando' : 'E também'}</strong><button type="button" className="btn btn-sm btn-ghost-danger" aria-label="Remover condição" onClick={() => replace({ ...group, all: group.all.filter((_, itemIndex) => itemIndex !== index) })}><i className="ti ti-trash" /></button></div>
          <select className="form-select" value={condition.field} onChange={(e) => update({ field: e.target.value, operator: e.target.value.includes('group') ? 'member_of' : 'equals', value: '' })}>{Object.entries(conditionFields).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select>
          <select className="form-select" value={condition.operator} onChange={(e) => update({ operator: e.target.value })}>{Object.entries(operatorLabels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select>
          <ConditionValue condition={condition} update={update} config={config} labels={definition.labels} />
        </div>;
      })}
    </div>
    <button type="button" className="btn btn-outline-primary w-100" onClick={add}><i className="ti ti-plus me-1" />Adicionar condição</button>
    <label className="form-label mt-3">Onde este caso começa</label>
    <select className="form-select" value={group.entry || ''} onChange={(event) => replace({ ...group, entry: event.target.value })}>
      <option value="">Primeira etapa</option>
      {definition.steps.map((step) => <option key={step.key} value={step.key}>{step.name || 'Etapa sem nome'}</option>)}
    </select>
    <p className="ev-help"><i className="ti ti-info-circle" /> Todas as regras deste bloco precisam ser verdadeiras. Ligue a saída na etapa em que este caso começa. Se dois blocos servirem ao mesmo chamado, o que cita uma pessoa específica vale no lugar do que cita um grupo.</p>
  </>;
}

function sourceChipLabel(source, labels) {
  if (source.source === 'user') return labelFromMap(labels, 'users', source.value) || sourceLabels.user;
  if (source.source === 'group') return labelFromMap(labels, 'groups', source.value) || sourceLabels.group;
  return sourceLabels[source.source] || source.source;
}

function ApprovalProperties({ node, definition, updateDefinition, config, onMoveStep }) {
  const index = definition.steps.findIndex((step) => step.key === node.id); const step = definition.steps[index]; const approval = step.approval;
  const replaceStep = (patch) => updateDefinition({ ...definition, steps: definition.steps.map((item, itemIndex) => itemIndex === index ? { ...item, ...patch } : item) });
  const replaceApproval = (patch) => replaceStep({ approval: { ...approval, ...patch } });
  const [sourceType, setSourceType] = useState('user'); const [sourceValue, setSourceValue] = useState(0);
  const addSource = () => { if ((sourceType === 'user' || sourceType === 'group') && !sourceValue) return; replaceApproval({ sources: [...approval.sources, { source: sourceType, value: sourceValue }] }); setSourceValue(0); };
  return <>
    <header className="ev-properties__header"><span className="ev-properties__icon"><i className="ti ti-user-check" /></span><div><h3>Solicitar aprovação</h3><p>Escolha quem responde e quantas aprovações são necessárias.</p></div></header>
    <StepOrderControls index={index} total={definition.steps.length} onMove={onMoveStep} readOnly={definition.readOnly} />
    <Field label="Nome desta aprovação"><input className="form-control" value={step.name} onChange={(e) => replaceStep({ name: e.target.value })} /></Field>
    <Field label="Quem precisa aprovar?"><select className="form-select" value={sourceType} onChange={(e) => { setSourceType(e.target.value); setSourceValue(0); }}>{Object.entries(sourceLabels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></Field>
    {sourceType === 'user' && <AsyncSelect config={config} kind="users" value={sourceValue} onChange={setSourceValue} allowEmpty={false} labels={definition.labels} />}
    {sourceType === 'group' && <AsyncSelect config={config} kind="groups" value={sourceValue} onChange={setSourceValue} allowEmpty={false} labels={definition.labels} />}
    <button type="button" className="btn btn-outline-primary w-100" onClick={addSource}><i className="ti ti-user-plus me-1" />Adicionar aprovador</button>
    <div className="ev-chip-list">{approval.sources.map((source, sourceIndex) => <span className="ev-chip" key={`${source.source}-${sourceIndex}`}>{sourceChipLabel(source, definition.labels)}<button type="button" aria-label="Remover aprovador" onClick={() => replaceApproval({ sources: approval.sources.filter((_, idx) => idx !== sourceIndex) })}><i className="ti ti-x" /></button></span>)}</div>
    <Field label="Quantas pessoas precisam aprovar?"><select className="form-select" value={approval.quorum} onChange={(e) => replaceApproval({ quorum: e.target.value })}><option value="min1">Apenas uma aprovação</option><option value="majority">Maioria dos aprovadores</option><option value="all">Todos os aprovadores</option></select></Field>
    <Field label="Quando solicitar?"><select className="form-select" value={approval.immediate ? 'now' : 'solved'} onChange={(e) => replaceApproval({ immediate: e.target.value === 'now' })}><option value="now">Imediatamente</option><option value="solved">Quando o chamado for solucionado</option></select></Field>
    <Field label="Prazo para responder (dias)" help="Use zero para não estabelecer prazo."><input className="form-control" type="number" min="0" value={approval.timeoutDays} onChange={(e) => replaceApproval({ timeoutDays: Math.max(0, Number(e.target.value)) })} /></Field>
    <Field label="Modelo da mensagem"><AsyncSelect config={config} kind="templates" value={approval.templateId} onChange={(templateId) => replaceApproval({ templateId })} labels={definition.labels} /></Field>
    <Field label="Mensagem complementar"><textarea className="form-control" rows="3" value={approval.message} onChange={(e) => replaceApproval({ message: e.target.value })} /></Field>
    <label className="form-check"><input className="form-check-input" type="checkbox" checked={approval.preventSelfApproval} onChange={(e) => replaceApproval({ preventSelfApproval: e.target.checked })} /><span className="form-check-label">Impedir que o solicitante aprove o próprio chamado</span></label>
    <label className="form-check"><input className="form-check-input" type="checkbox" checked={!!approval.allowSolve} onChange={(e) => {
      const allowSolve = e.target.checked;
      const transitions = { ...(step.transitions || {}) };
      if (allowSolve) transitions.solved = transitions.solved || 'terminal:approved';
      else delete transitions.solved;
      replaceStep({ approval: { ...approval, allowSolve }, transitions });
    }} /><span className="form-check-label">Permitir solucionar o chamado nesta etapa</span></label>
    {!!approval.allowSolve && <p className="ev-help"><i className="ti ti-info-circle" /> O aprovador ganha a opção <strong>Solucionar</strong>: o chamado é atribuído a ele, solucionado e o fluxo termina sem as etapas seguintes.</p>}
    <Field label="Aprovador substituto" help="Usado quando nenhum aprovador restante for encontrado. Alternativa: no canvas, ligue a saída “Sem aprovador” à próxima etapa."><AsyncSelect config={config} kind="users" value={approval.fallbackUserId} onChange={(fallbackUserId) => replaceApproval({ fallbackUserId })} labels={definition.labels} /></Field>
    <p className="ev-help"><i className="ti ti-git-branch" /> Saídas do bloco: <strong>Aprovado</strong>, <strong>Recusado</strong>{approval.allowSolve ? <>, <strong>Solucionar</strong></> : null} e <strong>Sem aprovador</strong>.</p>
  </>;
}

function ActionFields({ action, update, config, labels }) {
  const p = action.parameters || {}; const patch = (values) => update({ parameters: { ...p, ...values } });
  if (action.type === 'assign_user') return <><Field label="Técnico que receberá o chamado"><AsyncSelect config={config} kind="users" value={p.users_id} onChange={(users_id) => patch({ users_id })} allowEmpty={false} labels={labels} /></Field><label className="form-check"><input className="form-check-input" type="checkbox" checked={!!p.replace_assignees} onChange={(e) => patch({ replace_assignees: e.target.checked ? 1 : 0 })} /><span className="form-check-label">Substituir os responsáveis atuais</span></label></>;
  if (action.type === 'assign_group') return <><Field label="Grupo que receberá o chamado"><AsyncSelect config={config} kind="groups" value={p.groups_id} onChange={(groups_id) => patch({ groups_id })} allowEmpty={false} labels={labels} /></Field><label className="form-check"><input className="form-check-input" type="checkbox" checked={!!p.replace_assignees} onChange={(e) => patch({ replace_assignees: e.target.checked ? 1 : 0 })} /><span className="form-check-label">Substituir os responsáveis atuais</span></label></>;
  if (action.type === 'change_status') return <Field label="Nova situação"><AsyncSelect config={config} kind="statuses" value={p.status} onChange={(status) => patch({ status })} allowEmpty={false} labels={labels} /></Field>;
  if (action.type === 'change_category') return <Field label="Nova categoria"><AsyncSelect config={config} kind="categories" value={p.itilcategories_id} onChange={(itilcategories_id) => patch({ itilcategories_id })} allowEmpty={false} labels={labels} /></Field>;
  if (action.type === 'add_followup') return <><Field label="Conteúdo do acompanhamento"><textarea className="form-control" rows="5" value={p.content || ''} onChange={(e) => patch({ content: e.target.value })} /></Field><label className="form-check"><input className="form-check-input" type="checkbox" checked={!!p.is_private} onChange={(e) => patch({ is_private: e.target.checked ? 1 : 0 })} /><span className="form-check-label">Acompanhamento privado</span></label></>;
  if (action.type === 'create_task') return <><Field label="Descrição da tarefa"><textarea className="form-control" rows="5" value={p.content || ''} onChange={(e) => patch({ content: e.target.value })} /></Field><Field label="Responsável pela tarefa"><AsyncSelect config={config} kind="users" value={p.users_id} onChange={(users_id) => patch({ users_id })} labels={labels} /></Field></>;
  if (action.type === 'notify') return <Field label="Aviso do GLPI"><select className="form-select" value={p.event || 'escalation_workflow'} onChange={(e) => patch({ event: e.target.value })}><option value="escalation_workflow">Atualização do fluxo</option><option value="escalation_approved">Fluxo aprovado</option><option value="escalation_rejected">Fluxo recusado</option><option value="escalation_failed">Falha na automação</option></select></Field>;
  if (action.type === 'create_related_ticket') return <><Field label="Título do novo chamado"><input className="form-control" value={p.name || ''} onChange={(e) => patch({ name: e.target.value })} /></Field><Field label="Descrição"><textarea className="form-control" rows="4" value={p.content || ''} onChange={(e) => patch({ content: e.target.value })} /></Field><Field label="Categoria"><AsyncSelect config={config} kind="categories" value={p.itilcategories_id} onChange={(itilcategories_id) => patch({ itilcategories_id })} labels={labels} /></Field></>;
  return null;
}

function ActionProperties({ node, definition, updateDefinition, config, onMoveStep }) {
  const index = definition.steps.findIndex((step) => step.key === node.id); const step = definition.steps[index]; const action = step.action;
  const replace = (patch) => updateDefinition({ ...definition, steps: definition.steps.map((item, itemIndex) => itemIndex === index ? { ...item, ...patch } : item) });
  const updateAction = (patch) => replace({ action: { ...action, ...patch } });
  return <>
    <header className="ev-properties__header"><span className="ev-properties__icon"><i className="ti ti-bolt" /></span><div><h3>{actionLabels[action.type] || 'Ação automática'}</h3><p>Esta ação só será executada quando o fluxo chegar a este bloco.</p></div></header>
    <StepOrderControls index={index} total={definition.steps.length} onMove={onMoveStep} readOnly={definition.readOnly} />
    <Field label="Nome do bloco"><input className="form-control" value={step.name} onChange={(e) => replace({ name: e.target.value })} /></Field>
    <label className="form-check mb-3"><input className="form-check-input" type="checkbox" checked={!!action.manual} onChange={(e) => updateAction({ manual: e.target.checked })} /><span className="form-check-label">Disponibilizar como atividade opcional para o técnico</span></label>
    {!!action.manual && <p className="ev-help"><i className="ti ti-info-circle" /> O técnico verá somente o nome desta atividade em <strong>Adicionar atividade</strong>. O responsável e as demais regras abaixo ficam ocultos para ele.</p>}
    <Field label="O que o GLPI deve fazer?"><select className="form-select" value={action.type} onChange={(e) => updateAction({ type: e.target.value, parameters: {} })}>{Object.entries(actionLabels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></Field>
    <ActionFields action={action} update={updateAction} config={config} labels={definition.labels} />
    <Field label="Se acontecer um erro"><select className="form-select" value={action.errorPolicy} onChange={(e) => updateAction({ errorPolicy: e.target.value })}><option value="retry">Tentar novamente</option><option value="stop">Parar as próximas ações</option><option value="continue">Continuar mesmo assim</option></select></Field>
    {action.errorPolicy === 'retry' && <Field label="Número de tentativas"><input className="form-control" type="number" min="1" max="10" value={action.maxAttempts} onChange={(e) => updateAction({ maxAttempts: Number(e.target.value) })} /></Field>}
  </>;
}

function FinishProperties({ definition, updateDefinition }) {
  const lifecycle = definition.lifecycleActions || [];
  const closeOnReject = lifecycle.some((action) => action.event === 'flow_rejected' && action.type === 'change_status' && Number(action.parameters?.status) === 6);
  const setCloseOnReject = (checked) => {
    let lifecycleActions = lifecycle.filter((action) => !(action.event === 'flow_rejected' && action.type === 'change_status'));
    if (checked) {
      lifecycleActions = [...lifecycleActions, {
        key: 'lifecycle-flow_rejected-close',
        name: 'Encerrar chamado na reprovação',
        event: 'flow_rejected',
        type: 'change_status',
        parameters: { status: 6 },
        errorPolicy: 'retry',
        maxAttempts: 3,
      }];
    }
    const steps = definition.steps.map((step) => {
      if (step.kind !== 'approval') return step;
      return { ...step, transitions: { ...(step.transitions || {}), rejected: 'terminal:rejected' } };
    });
    updateDefinition({ ...definition, lifecycleActions, steps });
  };
  return <>
    <header className="ev-properties__header"><span className="ev-properties__icon"><i className="ti ti-flag-check" /></span><div><h3>Fim do fluxo</h3><p>Define o que acontece quando o fluxo termina.</p></div></header>
    <label className="form-check">
      <input className="form-check-input" type="checkbox" checked={closeOnReject} onChange={(e) => setCloseOnReject(e.target.checked)} />
      <span className="form-check-label">Se o fluxo for reprovado, encerrar o chamado (Fechado)</span>
    </label>
    <p className="ev-help"><i className="ti ti-info-circle" /> Quando alguém recusar uma aprovação, o fluxo encerra e o chamado passa para <strong>Fechado</strong> automaticamente.</p>
  </>;
}

export default function PropertiesPanel({ open, selectedNode, definition, updateDefinition, config, onClose, onMoveStep }) {
  return <aside className={`ev-properties${open ? ' is-open' : ''}`} aria-label="Configuração do bloco" hidden={!open}>
    <button type="button" className="ev-properties__close" aria-label="Fechar configurações" onClick={onClose}><i className="ti ti-x" /></button>
    {!selectedNode && <div className="ev-properties__empty"><i className="ti ti-cursor-text" /><h3>Selecione um bloco</h3><p>As opções do bloco escolhido aparecerão aqui. Use “Salvar rascunho” para gravar.</p></div>}
    {selectedNode?.type === 'start' && <StartProperties definition={definition} updateDefinition={updateDefinition} config={config} />}
    {selectedNode?.type === 'condition' && <ConditionProperties node={selectedNode} definition={definition} updateDefinition={updateDefinition} config={config} />}
    {selectedNode?.type === 'approval' && <ApprovalProperties node={selectedNode} definition={definition} updateDefinition={updateDefinition} config={config} onMoveStep={onMoveStep} />}
    {selectedNode?.type === 'action' && <ActionProperties node={selectedNode} definition={definition} updateDefinition={updateDefinition} config={config} onMoveStep={onMoveStep} />}
    {selectedNode?.type === 'finish' && <FinishProperties definition={definition} updateDefinition={updateDefinition} />}
    {selectedNode && !['start', 'finish'].includes(selectedNode.type) && <p className="ev-help ev-help--footer"><i className="ti ti-device-floppy" /> As alterações deste painel entram no rascunho ao clicar em <strong>Salvar rascunho</strong>.</p>}
  </aside>;
}
