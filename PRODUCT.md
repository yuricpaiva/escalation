# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

- Administradores do GLPI e gestores de entidade criam, validam e publicam workflows.
- Aprovadores respondem solicitações usando as validações nativas do GLPI.
- Auditores e operadores acompanham decisões, prazos, automações e falhas nos chamados.

## Product Purpose

O Escalation transforma regras administrativas em workflows configuráveis de aprovação para chamados. Sucesso significa publicar um fluxo sem código, executá-lo uma única vez de forma previsível e manter prova auditável de cada decisão e efeito automático.

## Positioning

O motor combina condições, aprovadores dinâmicos, quórum, decisões e ações sem abandonar os objetos, permissões, entidades e notificações nativas do GLPI 11.

## Operating Context

O produto opera dentro do GLPI 11 em processos financeiros e administrativos. Definições são editadas como rascunho, validadas, publicadas como versões imutáveis e monitoradas na aba do chamado.

## Capabilities and Constraints

- Escopo inicial: `Ticket`; GLPI 11 e PHP 8.2 ou superior.
- Fluxos respeitam entidades, prioridade e execução única por chamado.
- Aprovadores são congelados quando a etapa inicia.
- Não há scripts personalizados, webhooks, grafos arbitrários, paralelismo ou expressões arbitrariamente aninhadas.
- O editor visual representa somente sequências, condições de início e saídas que o motor consegue executar.
- Dados e históricos da versão 1.2.0 devem ser preservados por migrações aditivas.

## Brand Commitments

Nome Escalation, licença GPL v2+ e linguagem visual nativa do GLPI 11.

## Evidence on Hand

O repositório 1.2.0 contém fluxos lineares funcionais, etapas, quórum, hierarquia, templates, timeouts e integração com `TicketValidation`.

## Product Principles

- Nunca aprovar silenciosamente quando faltar configuração.
- Tornar toda transição e ação idempotente e auditável.
- Preservar a decisão de negócio separada do resultado da automação.
- Preferir APIs e permissões nativas do GLPI.
- Mostrar nomes do GLPI e linguagem de negócio; nunca exigir IDs, JSON ou identificadores internos.
- Fazer erros recuperáveis sem esconder seu impacto.

## Accessibility & Inclusion

A interface deve manter semântica, foco, contraste, teclado, responsividade e textos traduzíveis compatíveis com o GLPI 11.
