import { describe, expect, it } from 'vitest';
import { graphFromDefinition, moveStep } from './App';
import { defaultAction, defaultApproval } from './catalog';

const definition = {
  settings: { name: 'Pagamentos' },
  conditions: [{ key: 'condition-1', all: [{ field: 'event', operator: 'equals', value: 'created' }] }],
  steps: [
    { key: 'step-1', kind: 'approval', name: 'Aprovação do RH', transitions: {}, approval: defaultApproval() },
    { key: 'step-2', kind: 'approval', name: 'Aprovação Financeira', transitions: {}, approval: defaultApproval() },
    { key: 'step-3', kind: 'action', name: 'Encaminhar para execução', transitions: {}, action: defaultAction('assign_user') },
  ],
};

describe('diagrama do editor visual', () => {
  it('preserva o modo manual de uma atividade opcional', () => {
    const action = defaultAction('assign_user', true);
    expect(action.manual).toBe(true);
    expect(action.type).toBe('assign_user');
    expect(action.parameters.replace_assignees).toBe(1);
  });

  it('representa o cenário sequencial de pagamentos', () => {
    const graph = graphFromDefinition(definition, []);
    expect(graph.nodes.map((node) => node.id)).toEqual(['start', 'condition-1', 'step-1', 'step-2', 'step-3', 'finish']);
    expect(graph.nodes.find((node) => node.id === 'step-2').data.stepOrder).toBe(2);
    expect(graph.edges.some((edge) => edge.source === 'step-2' && edge.target === 'step-3')).toBe(true);
    expect(graph.edges.some((edge) => edge.source === 'step-1' && edge.sourceHandle === 'rejected' && edge.target === 'finish')).toBe(true);
  });

  it('marca o bloco que possui erro contextual', () => {
    const graph = graphFromDefinition(definition, [{ nodeKey: 'step-2', message: 'Escolha o aprovador.' }]);
    expect(graph.nodes.find((node) => node.id === 'step-2').data.error).toBe(true);
  });

  it('mantém condições fora da sequência executável', () => {
    const graph = graphFromDefinition(definition, []);
    const edge = graph.edges.find((item) => item.source === 'condition-1');
    expect(edge.target).toBe('start');
    expect(edge.className).toBe('ev-edge--condition');
    expect(edge.label).toBe('Gatilho');
  });

  it('reordena etapas mantendo a ordem de execução', () => {
    const steps = moveStep(definition.steps, 2, -1);
    expect(steps.map((step) => step.key)).toEqual(['step-1', 'step-3', 'step-2']);
  });

  it('reordena sem tirar os cards do lugar', () => {
    const placed = definition.steps.map((step, index) => ({ ...step, position: { x: 40 + index * 80, y: 120 + index * 30 } }));
    const steps = moveStep(placed, 2, -1);
    expect(steps.find((step) => step.key === 'step-3').position).toEqual({ x: 200, y: 180 });
    expect(steps.find((step) => step.key === 'step-2').position).toEqual({ x: 120, y: 150 });
  });

  it('permite reposicionar início e fim no canvas', () => {
    const withAnchors = {
      ...definition,
      anchors: { start: { x: 120, y: 40 }, finish: { x: 500, y: 900 } },
    };
    const graph = graphFromDefinition(withAnchors, []);
    expect(graph.nodes.find((node) => node.id === 'start').position).toEqual({ x: 120, y: 40 });
    expect(graph.nodes.find((node) => node.id === 'finish').position).toEqual({ x: 500, y: 900 });
    expect(graph.nodes.find((node) => node.id === 'start').draggable).not.toBe(false);
    expect(graph.nodes.find((node) => node.id === 'finish').draggable).not.toBe(false);
  });

  it('liga um começo direto na etapa escolhida e mantém o início padrão dos demais', () => {
    const branched = {
      ...definition,
      conditions: [
        { key: 'condition-1', all: [{ field: 'requester', operator: 'equals', value: 7 }], entry: 'step-2' },
        { key: 'condition-2', all: [{ field: 'requester_group', operator: 'member_of', value: 7 }] },
      ],
    };
    const graph = graphFromDefinition(branched, []);
    expect(graph.edges.find((edge) => edge.source === 'condition-1').target).toBe('step-2');
    expect(graph.edges.find((edge) => edge.source === 'condition-1').label).toBe('Começa aqui');
    expect(graph.edges.find((edge) => edge.source === 'condition-2').target).toBe('start');
    expect(graph.edges.some((edge) => edge.id === 'start-first')).toBe(true);
    expect(graph.nodes.find((node) => node.id === 'condition-1').data.entryName).toBe('Aprovação Financeira');
  });

  it('esconde a saída padrão do início quando todos os começos escolhem uma etapa', () => {
    const branched = { ...definition, conditions: [{ key: 'condition-1', all: [], entry: 'step-3' }] };
    const graph = graphFromDefinition(branched, []);
    expect(graph.edges.find((edge) => edge.source === 'condition-1').target).toBe('step-3');
    expect(graph.edges.some((edge) => edge.source === 'start')).toBe(false);
  });

  it('desenha a saída Sem aprovador quando configurada', () => {
    const withSkip = {
      ...definition,
      steps: definition.steps.map((step) => step.key === 'step-1'
        ? { ...step, transitions: { approved: 'step-2', rejected: 'terminal:rejected', approver_missing: 'step-2' } }
        : step),
    };
    const graph = graphFromDefinition(withSkip, []);
    const edge = graph.edges.find((item) => item.source === 'step-1' && item.sourceHandle === 'approver_missing');
    expect(edge).toBeTruthy();
    expect(edge.target).toBe('step-2');
    expect(edge.label).toBe('Sem aprovador');
    expect(edge.className).toBe('ev-edge--missing');
  });

  it('desenha a saída Solucionar quando a etapa permite', () => {
    const withSolve = {
      ...definition,
      steps: definition.steps.map((step) => step.key === 'step-2'
        ? { ...step, approval: { ...defaultApproval(), allowSolve: true }, transitions: { approved: 'step-3', rejected: 'terminal:rejected', solved: 'terminal:approved' } }
        : step),
    };
    const graph = graphFromDefinition(withSolve, []);
    const edge = graph.edges.find((item) => item.source === 'step-2' && item.sourceHandle === 'solved');
    expect(edge).toBeTruthy();
    expect(edge.target).toBe('finish');
    expect(edge.label).toBe('Solucionar');
    expect(edge.className).toBe('ev-edge--solved');
  });
});
