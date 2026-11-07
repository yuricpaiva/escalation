# Editor visual

## Acesso

1. Abra **Administração > Escalation**.
2. Crie ou abra um fluxo.
3. A aba **Editor visual** é a superfície principal (Modo avançado e Etapas legado ficam nas abas seguintes).

Ao criar um fluxo, escolha entre fluxo vazio, aprovação simples, duas aprovações ou duas aprovações com atribuição final. As pessoas, os grupos e as regras reais devem ser escolhidos antes da publicação.

As alterações do painel lateral só são gravadas ao clicar em **Salvar rascunho**. Depois do save, os nomes do GLPI (categoria, usuários, grupos) voltam a aparecer no campo — não apenas o ID.

## Fluxo de pagamento

Para montar o cenário RH → Financeiro → Executor:

1. Selecione **Início do fluxo** e informe entidade, prioridade e descrição.
2. Na condição de início, escolha “Quando o chamado for aberto”, a categoria Pagamentos e o solicitante desejado.
3. Configure a primeira aprovação com o responsável do RH.
4. Adicione a segunda aprovação e selecione o responsável Financeiro.
5. Adicione “Atribuir chamado” e escolha o técnico que executará a atividade.
6. Use **Validar fluxo**, corrija os blocos indicados, salve e publique.

A recusa em qualquer aprovação encerra o fluxo e impede a atribuição final. O chamado continua aberto depois da atribuição, salvo se outra ação alterar sua situação.

## Compilação

```bash
npm install
npm test
npm run build
```

Distribua `public/build/visual-editor.js` e `public/build/visual-editor.css` junto com o plugin. Node.js não é necessário no servidor GLPI.

## Compatibilidade e segurança

- O diagrama grava diretamente o rascunho versionado do motor existente.
- Somente posições e zoom são guardados como metadados visuais.
- Alterações concorrentes são detectadas por revisão e não são sobrescritas.
- Configurações desconhecidas são protegidas e devem ser ajustadas no Modo avançado.
- Condições filtram o início do fluxo; não criam decisões durante a execução.
- Não são permitidos ciclos, retornos, paralelismo ou scripts personalizados.

## Atualização e restauração

Antes de atualizar, faça backup do banco e da pasta do plugin. Instale os novos arquivos, execute a atualização do plugin pelo GLPI e limpe o cache. Para restaurar, recoloque os arquivos anteriores e restaure o backup do banco correspondente; não tente desfazer manualmente as migrações.
