import React from 'react';
import { Handle, Position } from '@xyflow/react';
import { actionLabels, conditionFields } from './catalog';

function Shell({ icon, tone, title, subtitle, selected, error, children, badge }) {
  return <div className={`ev-node ev-node--${tone}${selected ? ' is-selected' : ''}${error ? ' has-error' : ''}`}>
    {badge && <span className="ev-node__badge">{badge}</span>}
    <div className="ev-node__icon" aria-hidden="true"><i className={`ti ${icon}`} /></div>
    <div className="ev-node__body"><strong>{title}</strong>{subtitle && <span>{subtitle}</span>}{children}</div>
    {error && <i className="ti ti-alert-circle ev-node__error" title="Configuração incompleta" />}
  </div>;
}

export function StartNode({ selected, data }) {
  return <><Shell icon="ti-player-play-filled" tone="start" title="Início do fluxo" subtitle="Quando o chamado atender às regras" selected={selected} error={data.error} /><Handle type="source" position={Position.Bottom} /></>;
}
export function ConditionNode({ selected, data }) {
  const count = data.all?.length || 0;
  const title = data.order > 1 ? `Quando iniciar · alt. ${data.order}` : 'Quando iniciar';
  return <><Handle type="source" position={Position.Right} title="Ligue na etapa em que este caso começa" /><Shell icon="ti-filter" tone="condition" title={title} subtitle={count ? `${count} ${count === 1 ? 'regra de início' : 'regras de início'}` : 'Nenhuma regra'} selected={selected} error={data.error}>
    {data.all?.slice(0, 2).map((condition, index) => <small key={index}>{conditionFields[condition.field] || condition.field}</small>)}
    {data.entryName && <small>Começa em {data.entryName}</small>}
  </Shell></>;
}
export function ApprovalNode({ selected, data }) {
  const allowSolve = !!data.approval?.allowSolve;
  return <>
    <Handle type="target" position={Position.Top} />
    <Shell icon="ti-user-check" tone="approval" title={data.name || 'Solicitar aprovação'} subtitle={data.approval?.sources?.length ? `${data.approval.sources.length} forma(s) de encontrar aprovadores` : 'Escolha os aprovadores'} selected={selected} error={data.error} />
    <Handle type="source" id="approved" position={Position.Bottom} style={{ left: allowSolve ? '22%' : '32%' }} title="Aprovado" />
    <Handle type="source" id="rejected" position={Position.Bottom} style={{ left: allowSolve ? '40%' : '50%' }} title="Recusado" />
    {allowSolve && <Handle type="source" id="solved" position={Position.Bottom} style={{ left: '58%' }} className="ev-handle--solved" title="Solucionar o chamado nesta etapa" />}
    <Handle type="source" id="approver_missing" position={Position.Bottom} style={{ left: allowSolve ? '78%' : '68%' }} className="ev-handle--missing" title="Sem aprovador — pular para a próxima etapa" />
  </>;
}
export function ActionNode({ selected, data }) {
  return <><Handle type="target" position={Position.Top} /><Shell icon="ti-bolt" tone="action" title={data.name || actionLabels[data.action?.type] || 'Ação automática'} subtitle="Executada ao chegar nesta etapa" selected={selected} error={data.error} /><Handle type="source" id="success" position={Position.Bottom} style={{ left: '38%' }} /><Handle type="source" id="failure" position={Position.Bottom} style={{ left: '62%' }} /></>;
}
export function FinishNode({ selected }) {
  return <><Handle type="target" position={Position.Top} /><Shell icon="ti-flag-check" tone="finish" title="Fluxo concluído" subtitle="Todas as etapas foram executadas" selected={selected} /></>;
}

export const nodeTypes = { start: StartNode, condition: ConditionNode, approval: ApprovalNode, action: ActionNode, finish: FinishNode };
