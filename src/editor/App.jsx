import React, { useCallback, useEffect, useState } from 'react';
import { Background, Controls, MarkerType, MiniMap, Panel, ReactFlow, ReactFlowProvider, useEdgesState, useNodesState, useReactFlow } from '@xyflow/react';
import '@xyflow/react/dist/style.css';
import { request } from './api';
import { blockCatalog, defaultAction, defaultApproval } from './catalog';
import { nodeTypes } from './nodes';
import PropertiesPanel from './PropertiesPanel';

const outcomeLabels = { approved: 'Aprovado', rejected: 'Recusado', solved: 'Solucionar', expired: 'Prazo encerrado', approver_missing: 'Sem aprovador', success: 'Concluída', failure: 'Falhou' };

function positionFor(index) { return { x: 410, y: 180 + index * 170 }; }
function nextKey(prefix) { return `${prefix}-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`; }

export function graphFromDefinition(definition, errors) {
  const errorKeys = new Set(errors.map((error) => error.nodeKey));
  const anchors = definition.anchors || {};
  const finishFallback = { x: 410, y: 180 + definition.steps.length * 170 };
  const nodes = [{ id: 'start', type: 'start', position: anchors.start || { x: 410, y: 20 }, data: { error: errorKeys.has('start') } }];
  definition.conditions.forEach((group, index) => {
    const entryStep = definition.steps.find((step) => step.key === group.entry);
    nodes.push({ id: group.key, type: 'condition', position: group.position || { x: 40, y: 70 + index * 150 }, data: { ...group, order: index + 1, entryName: entryStep?.name || '', error: errorKeys.has(group.key) } });
  });
  definition.steps.forEach((step, index) => nodes.push({ id: step.key, type: step.kind, position: step.position || positionFor(index), data: { ...step, stepOrder: index + 1, error: errorKeys.has(step.key) } }));
  nodes.push({ id: 'finish', type: 'finish', position: anchors.finish || finishFallback, data: {} });
  const stepKeys = new Set(definition.steps.map((step) => step.key));
  const edges = definition.conditions.map((group) => {
    const target = group.entry && stepKeys.has(group.entry) ? group.entry : 'start';
    return {
      id: `${group.key}-${target}`, source: group.key, target, animated: true, className: 'ev-edge--condition',
      label: target === 'start' ? 'Gatilho' : 'Começa aqui', markerEnd: { type: MarkerType.ArrowClosed },
    };
  });
  const usesDefaultStart = definition.conditions.length === 0 || definition.conditions.some((group) => !group.entry || !stepKeys.has(group.entry));
  if (definition.steps.length && usesDefaultStart) edges.push({ id: 'start-first', source: 'start', target: definition.steps[0].key, markerEnd: { type: MarkerType.ArrowClosed } });
  definition.steps.forEach((step, index) => {
    const transitions = Object.entries(step.transitions || {});
    if (!transitions.length) {
      const target = definition.steps[index + 1]?.key || 'finish';
      edges.push({ id: `${step.key}-default`, source: step.key, sourceHandle: step.kind === 'approval' ? 'approved' : 'success', target, label: step.kind === 'approval' ? 'Aprovado' : 'Concluída', markerEnd: { type: MarkerType.ArrowClosed } });
      if (step.kind === 'approval') {
        edges.push({ id: `${step.key}-rejected-default`, source: step.key, sourceHandle: 'rejected', target: 'finish', label: 'Recusado', className: 'ev-edge--rejected', markerEnd: { type: MarkerType.ArrowClosed } });
        if (step.approval?.allowSolve) edges.push({ id: `${step.key}-solved-default`, source: step.key, sourceHandle: 'solved', target: 'finish', label: 'Solucionar', className: 'ev-edge--solved', markerEnd: { type: MarkerType.ArrowClosed } });
      }
    } else transitions.forEach(([outcome, target]) => edges.push({
      id: `${step.key}-${outcome}`, source: step.key, sourceHandle: outcome, target: String(target).startsWith('terminal:') ? 'finish' : target,
      label: outcomeLabels[outcome] || outcome,
      className: outcome === 'rejected' || outcome === 'failure'
        ? 'ev-edge--rejected'
        : outcome === 'approver_missing'
          ? 'ev-edge--missing'
          : outcome === 'solved'
            ? 'ev-edge--solved'
            : '',
      markerEnd: { type: MarkerType.ArrowClosed },
    }));
  });
  return { nodes, edges };
}

export function moveStep(steps, index, delta) {
  const target = index + delta;
  if (index < 0 || target < 0 || target >= steps.length) return steps;
  const next = [...steps];
  const [item] = next.splice(index, 1);
  next.splice(target, 0, item);
  return next;
}

function Editor({ config }) {
  const [definition, setDefinition] = useState(null);
  const [nodes, setNodes, onNodesChange] = useNodesState([]);
  const [edges, setEdges, onEdgesChange] = useEdgesState([]);
  const [selectedId, setSelectedId] = useState(null);
  const [errors, setErrors] = useState([]);
  const [notice, setNotice] = useState(null);
  const [busy, setBusy] = useState(true);
  const [dirty, setDirty] = useState(false);
  const [paletteOpen, setPaletteOpen] = useState(false);
  const [screensOpen, setScreensOpen] = useState(false);
  const [propsOpen, setPropsOpen] = useState(false);
  const flow = useReactFlow();

  const load = useCallback(async () => {
    setBusy(true); setNotice(null);
    try { const data = await request(config, 'load', {}, 'GET'); setDefinition(data); setDirty(false); }
    catch (error) { setNotice({ tone: 'danger', text: error.message }); }
    finally { setBusy(false); }
  }, [config]);
  useEffect(() => { load(); }, [load]);
  useEffect(() => {
    const root = document.getElementById('escalation-visual-editor');
    const place = () => {
      const column = document.querySelector('.page-wrapper') || root?.closest('.page-body');
      if (!root || !column) return;
      const rect = column.getBoundingClientRect();
      root.style.top = `${Math.max(0, rect.top)}px`;
      root.style.left = `${Math.max(0, rect.left)}px`;
      root.style.width = `${Math.max(320, rect.width)}px`;
      root.style.height = `${Math.max(320, window.innerHeight - Math.max(0, rect.top))}px`;
    };
    document.body.classList.add('escalation-editor-fullscreen');
    place();
    const column = document.querySelector('.page-wrapper');
    const observer = typeof ResizeObserver !== 'undefined' && column ? new ResizeObserver(place) : null;
    observer?.observe(column);
    window.addEventListener('resize', place);
    return () => {
      document.body.classList.remove('escalation-editor-fullscreen');
      observer?.disconnect();
      window.removeEventListener('resize', place);
    };
  }, []);
  useEffect(() => {
    const warn = (event) => { if (dirty) { event.preventDefault(); event.returnValue = ''; } };
    window.addEventListener('beforeunload', warn);
    return () => window.removeEventListener('beforeunload', warn);
  }, [dirty]);
  useEffect(() => {
    if (!definition) return;
    const graph = graphFromDefinition(definition, errors);
    setNodes(graph.nodes); setEdges(graph.edges);
  }, [definition, errors, setNodes, setEdges]);

  const selectedNode = nodes.find((node) => node.id === selectedId) || null;
  const updateDefinition = (next) => { setDefinition(next); setDirty(true); setErrors([]); setNotice(null); };

  const addBlock = useCallback((kind, position) => {
    if (!definition || definition.readOnly) return;
    if (kind === 'condition') {
      const key = nextKey('condition');
      updateDefinition({ ...definition, conditions: [...definition.conditions, { key, all: [{ field: 'category', operator: 'equals', value: '' }], position: position || { x: 70, y: 100 + definition.conditions.length * 150 } }] });
      setSelectedId(key); setPropsOpen(true); setPaletteOpen(false); return;
    }
    const key = nextKey('step'); const type = kind === 'approval' ? 'approval' : 'action';
    const manual = kind === 'manual_action';
    const step = { key, kind: type, name: kind === 'approval' ? 'Nova aprovação' : blockCatalog.find((block) => block.kind === kind)?.title || 'Nova ação', position: position || positionFor(definition.steps.length), transitions: {} };
    if (type === 'approval') step.approval = defaultApproval(); else step.action = defaultAction(manual ? 'assign_user' : kind, manual);
    updateDefinition({ ...definition, steps: [...definition.steps, step] }); setSelectedId(key); setPropsOpen(true);
  }, [definition]);

  const applyTemplate = (template) => {
    const approval = (name, index) => ({ key: nextKey('step'), kind: 'approval', name, position: positionFor(index), transitions: {}, approval: defaultApproval() });
    let steps = [];
    if (template !== 'empty') steps.push(approval('Aprovação', 0));
    if (template === 'double' || template === 'assignment') steps.push(approval('Segunda aprovação', 1));
    if (template === 'assignment') steps.push({ key: nextKey('step'), kind: 'action', name: 'Encaminhar para execução', position: positionFor(2), transitions: {}, action: defaultAction('assign_user') });
    updateDefinition({ ...definition, steps });
  };

  const onDrop = (event) => { event.preventDefault(); const kind = event.dataTransfer.getData('application/escalation-block'); if (kind) addBlock(kind, flow.screenToFlowPosition({ x: event.clientX, y: event.clientY })); };
  const onNodeDragStop = (_, node) => {
    if (node.id === 'start' || node.id === 'finish') {
      updateDefinition({ ...definition, anchors: { ...(definition.anchors || {}), [node.id]: node.position } });
      return;
    }
    if (node.type === 'condition') updateDefinition({ ...definition, conditions: definition.conditions.map((item) => item.key === node.id ? { ...item, position: node.position } : item) });
    else updateDefinition({ ...definition, steps: definition.steps.map((item) => item.key === node.id ? { ...item, position: node.position } : item) });
  };
  const onConnect = (connection) => {
    const conditionIndex = definition.conditions.findIndex((group) => group.key === connection.source);
    if (conditionIndex >= 0) {
      const targetExists = definition.steps.some((step) => step.key === connection.target);
      if (!targetExists) { setNotice({ tone: 'warning', text: 'Ligue este começo em uma etapa do fluxo.' }); return; }
      updateDefinition({ ...definition, conditions: definition.conditions.map((group, index) => index === conditionIndex ? { ...group, entry: connection.target } : group) });
      return;
    }
    const sourceIndex = definition.steps.findIndex((step) => step.key === connection.source);
    const targetIndex = definition.steps.findIndex((step) => step.key === connection.target);
    if (sourceIndex < 0 || (connection.target !== 'finish' && targetIndex <= sourceIndex)) { setNotice({ tone: 'warning', text: 'Escolha um bloco posterior. O motor não permite retornos ou ciclos.' }); return; }
    const outcome = connection.sourceHandle || (definition.steps[sourceIndex].kind === 'approval' ? 'approved' : 'success');
    let terminal = 'approved';
    if (outcome === 'rejected') terminal = 'rejected';
    else if (outcome === 'failure') terminal = 'failed';
    else if (outcome === 'approver_missing' || outcome === 'solved') terminal = 'approved';
    const target = connection.target === 'finish' ? `terminal:${terminal}` : connection.target;
    updateDefinition({ ...definition, steps: definition.steps.map((step, index) => index === sourceIndex ? { ...step, transitions: { ...step.transitions, [outcome]: target } } : step) });
  };

  const removeSelected = () => {
    if (!selectedId || ['start','finish'].includes(selectedId) || definition.readOnly || !window.confirm('Remover este bloco do fluxo?')) return;
    const conditions = definition.conditions.filter((item) => item.key !== selectedId).map((item) => item.entry === selectedId ? { ...item, entry: '' } : item);
    const steps = definition.steps.filter((item) => item.key !== selectedId).map((item) => ({ ...item, transitions: Object.fromEntries(Object.entries(item.transitions || {}).filter(([, target]) => target !== selectedId)) }));
    updateDefinition({ ...definition, conditions, steps }); setSelectedId(null);
  };
  const duplicateSelected = () => {
    const index = definition.steps.findIndex((item) => item.key === selectedId); if (index < 0 || definition.readOnly) return;
    const source = definition.steps[index]; const key = nextKey('step');
    const copy = JSON.parse(JSON.stringify(source)); copy.key = key; copy.name = `${copy.name} — cópia`; copy.transitions = {}; copy.position = { x: source.position.x + 36, y: source.position.y + 120 };
    const steps = [...definition.steps]; steps.splice(index + 1, 0, copy); updateDefinition({ ...definition, steps }); setSelectedId(key);
  };
  const autoLayout = () => updateDefinition({
    ...definition,
    anchors: { start: { x: 410, y: 20 }, finish: { x: 410, y: 180 + definition.steps.length * 170 } },
    conditions: definition.conditions.map((group, index) => ({ ...group, position: { x: 40, y: 80 + index * 150 } })),
    steps: definition.steps.map((step, index) => ({ ...step, position: positionFor(index) })),
  });
  const onMoveStep = (delta) => {
    const index = definition.steps.findIndex((step) => step.key === selectedId);
    if (index < 0 || definition.readOnly) return;
    updateDefinition({ ...definition, steps: moveStep(definition.steps, index, delta) });
  };

  const runAction = async (action) => {
    setBusy(true); setNotice(null);
    try {
      const result = await request(config, action, { definition: { ...definition, unsupported: definition.unsupported, lifecycleActions: definition.lifecycleActions || [] }, revision: definition.revision });
      if (result.conflict) { setNotice({ tone: 'warning', text: result.message }); return; }
      setErrors(result.errors || []);
      if (result.ok) {
        if (action === 'save' || action === 'publish') {
          const reloaded = await request(config, 'load', {}, 'GET');
          setDefinition(reloaded);
          setDirty(false);
          if (selectedId) {
            const stillExists = [...(reloaded.conditions || []), ...(reloaded.steps || [])].some((item) => item.key === selectedId) || ['start', 'finish'].includes(selectedId);
            if (!stillExists) setSelectedId(null);
          }
        } else if (result.revision !== undefined) {
          setDefinition((current) => ({ ...current, revision: result.revision }));
        }
        setNotice({ tone: 'success', text: result.published ? 'Fluxo publicado. As novas execuções já usarão esta versão.' : action === 'validate' ? 'Tudo certo. O fluxo está pronto para ser publicado.' : 'Rascunho salvo. Os nomes escolhidos foram recarregados.' });
      } else if (result.errors?.length) {
        setNotice({ tone: 'danger', text: `Encontramos ${result.errors.length} ${result.errors.length === 1 ? 'ajuste necessário' : 'ajustes necessários'}. Selecione um aviso para corrigir.` });
        setSelectedId(result.errors[0].nodeKey);
      }
    } catch (error) { setNotice({ tone: 'danger', text: error.message }); }
    finally { setBusy(false); }
  };

  if (busy && !definition) return <div className="ev-loading"><span className="spinner-border" /><strong>Preparando o editor visual…</strong><span>Carregando o rascunho e os cadastros do GLPI.</span></div>;
  if (!definition) return <div className="alert alert-danger">{notice?.text || 'Não foi possível abrir o editor.'}<button className="btn btn-outline-danger ms-3" onClick={load}>Tentar novamente</button></div>;

  const screens = config.screens || [];
  const selectNode = (id) => { setSelectedId(id); if (id) setPropsOpen(true); };

  return <div className="ev-shell">
    <div className="ev-float ev-float--screens">
      <button type="button" className={`ev-dock${screensOpen ? ' is-active' : ''}`} aria-expanded={screensOpen} onClick={() => setScreensOpen((open) => !open)}><i className="ti ti-layout-grid" /> Telas</button>
      {screensOpen && <nav className="ev-popover" aria-label="Trocar de tela">
        {screens.map((screen) => screen.current
          ? <span key={screen.key} className="is-current">{screen.label}</span>
          : <a key={screen.key} href={screen.href}>{screen.label}</a>)}
      </nav>}
    </div>
    <div className="ev-float ev-float--actions">
      <span className="ev-status">Rascunho v{definition.version}{dirty && <strong className="ev-unsaved"><i className="ti ti-point-filled" /> não salvo</strong>}</span>
      <button type="button" className="btn btn-ghost-secondary" onClick={autoLayout}><i className="ti ti-wand" /> Organizar</button>
      <button type="button" className="btn btn-outline-primary" disabled={busy || definition.readOnly} onClick={() => runAction('validate')}><i className="ti ti-shield-check" /> Validar</button>
      <button type="button" className="btn btn-primary" disabled={busy || definition.readOnly} onClick={() => runAction('save')}><i className="ti ti-device-floppy" /> Salvar</button>
      {config.canPublish && <button type="button" className="btn btn-success" disabled={busy || definition.readOnly} onClick={() => runAction('publish')}><i className="ti ti-send" /> Publicar</button>}
    </div>
    <button type="button" className={`ev-dock ev-dock--blocks${paletteOpen ? ' is-active' : ''}`} aria-expanded={paletteOpen} onClick={() => setPaletteOpen((open) => !open)}><i className="ti ti-layout-sidebar-left-expand" /> Blocos</button>
    <aside className={`ev-palette${paletteOpen ? ' is-open' : ''}`} aria-label="Blocos disponíveis" hidden={!paletteOpen}>
      <div className="ev-palette__header"><div><h3>Blocos</h3><p>Arraste ou clique para adicionar.</p></div><button type="button" aria-label="Fechar blocos" onClick={() => setPaletteOpen(false)}><i className="ti ti-x" /></button></div>
      <div className="ev-palette__items">{blockCatalog.map((block) => <button type="button" draggable={!definition.readOnly} key={block.kind} onDragStart={(event) => event.dataTransfer.setData('application/escalation-block', block.kind)} onClick={() => addBlock(block.kind)} disabled={definition.readOnly}><i className={`ti ${block.icon}`} /><span><strong>{block.title}</strong><small>{block.description}</small></span><i className="ti ti-plus" /></button>)}</div>
    </aside>
    <button type="button" className={`ev-dock ev-dock--props${propsOpen ? ' is-active' : ''}`} aria-expanded={propsOpen} disabled={!selectedNode} onClick={() => selectedNode && setPropsOpen((open) => !open)}><i className="ti ti-adjustments" /> Configurar</button>
    {notice && <div className={`alert alert-${notice.tone} ev-notice ev-notice--float`} role="status"><i className={`ti ${notice.tone === 'success' ? 'ti-circle-check' : 'ti-alert-triangle'}`} />{notice.text}</div>}
    {!!definition.unsupported?.length && <div className="alert alert-warning ev-notice ev-notice--float ev-notice--lower"><i className="ti ti-lock" /><div><strong>Este fluxo tem configurações avançadas protegidas.</strong><p>Você pode consultar e mover os blocos, mas a edição visual ficará bloqueada para não apagar informações.</p><ul>{definition.unsupported.map((message) => <li key={message}>{message}</li>)}</ul></div></div>}
    {!!errors.length && <div className="ev-error-strip" aria-label="Ajustes necessários">{errors.map((error, index) => <button type="button" key={`${error.nodeKey}-${index}`} onClick={() => selectNode(error.nodeKey)}><i className="ti ti-alert-circle" />{error.message}</button>)}</div>}
    <main className="ev-canvas" onDrop={onDrop} onDragOver={(event) => event.preventDefault()}>
      {!definition.steps.length && <div className="ev-starter"><h3>Como você quer começar?</h3><p>Escolha uma estrutura inicial. Você poderá alterar tudo antes de publicar.</p><div><button onClick={() => applyTemplate('empty')}>Fluxo vazio</button><button onClick={() => applyTemplate('simple')}>Aprovação simples</button><button onClick={() => applyTemplate('double')}>Duas aprovações</button><button onClick={() => applyTemplate('assignment')}>Aprovação e atribuição</button></div></div>}
      <ReactFlow nodes={nodes} edges={edges} nodeTypes={nodeTypes} onNodesChange={onNodesChange} onEdgesChange={onEdgesChange} onNodeDragStop={onNodeDragStop} onConnect={onConnect} onNodeClick={(_, node) => selectNode(node.id)} onPaneClick={() => { setSelectedId(null); setPropsOpen(false); }} fitView fitViewOptions={{ padding: 0.25 }} deleteKeyCode={null} nodesConnectable={!definition.readOnly} nodesDraggable={!definition.readOnly} minZoom={0.35} maxZoom={1.7}>
        <Background gap={24} size={1} /><Controls showInteractive={false} /><MiniMap pannable zoomable nodeStrokeWidth={3} />
        {selectedNode && !['start','finish'].includes(selectedNode.id) && <Panel position="bottom-left" className="ev-selection-actions"><button type="button" className="btn btn-sm btn-light" onClick={duplicateSelected} disabled={definition.readOnly}><i className="ti ti-copy" /> Duplicar</button><button type="button" className="btn btn-sm btn-light text-danger" onClick={removeSelected} disabled={definition.readOnly}><i className="ti ti-trash" /> Excluir</button></Panel>}
      </ReactFlow>
    </main>
    <PropertiesPanel open={propsOpen && !!selectedNode} selectedNode={selectedNode} definition={definition} updateDefinition={updateDefinition} config={config} onClose={() => setPropsOpen(false)} onMoveStep={onMoveStep} />
  </div>;
}

export default function App({ config }) { return <ReactFlowProvider><Editor config={config} /></ReactFlowProvider>; }
