# Resultado dos testes funcionais

Executado em 12/11/2026, 11:39:41 contra http://tcc.test, com o banco de demonstração (dados fictícios).

**33 de 33 casos de teste aprovados.**

| ID | Caso de uso ou requisito | Cenário | Resultado |
|---|---|---|---|
| CT01 | UC01 | Morador entra e cai em "Minhas ocorrências"; síndico cai no painel | Aprovado |
| CT02 | UC01 | Senha incorreta: mensagem igual à de e-mail inexistente, sem entrar | Aprovado |
| CT03 | UC01 | Cinco senhas erradas seguidas bloqueiam a conta, mesmo para a senha correta | Aprovado |
| CT04 | UC01 | Página interna sem login redireciona para a tela de entrada | Aprovado |
| CT05 | UC02 | Morador registra ocorrência com foto; ela nasce "Aberta" com data e hora do servidor | Aprovado |
| CT06 | UC02 | Descrição curta demais é recusada pelo servidor, com a mensagem junto ao campo | Aprovado |
| CT07 | UC02 | Arquivo que não é imagem é recusado como foto | Aprovado |
| CT08 | UC03 | Morador vê a própria ocorrência na lista e não abre a de outro morador | Aprovado |
| CT09 | UC05 | Portaria vê a ocorrência do dia, mas sem a foto, nem pelo endereço direto | Aprovado |
| CT10 | UC02 | Portaria registra em nome de um morador, indicando a unidade envolvida (RG4) | Aprovado |
| CT11 | UC12 | Síndico é avisado da nova ocorrência | Aprovado |
| CT12 | UC06 | Pular etapa (aberta direto para resolvida) é recusado pelo servidor (RG1) | Aprovado |
| CT13 | UC06 | Resposta sem comentário é recusada | Aprovado |
| CT14 | UC06 | Síndico responde: aberta, em análise, resolvida, com comentário em cada passo | Aprovado |
| CT15 | UC03 | Morador vê o novo status, lê o comentário do síndico e recebe o aviso (RF06) | Aprovado |
| CT16 | UC04 | Autor acrescenta complemento; o registro original continua igual (RG2) | Aprovado |
| CT17 | UC04 | O banco recusa alterar ou apagar o histórico, mesmo com acesso direto (RG2) | Aprovado |
| CT18 | UC07 | Filtro por categoria e status devolve só o que corresponde | Aprovado |
| CT19 | UC07 | Exportar pede a senha de novo: errada recusa, correta baixa o CSV filtrado | Aprovado |
| CT20 | UC08 | Painel mostra os mesmos números que o banco (total, taxa de resposta, incompletos) | Aprovado |
| CT21 | UC08 | Morador e portaria não acessam painel, usuários, acessos nem importação | Aprovado |
| CT22 | UC09 | Importa a transcrição do livro; reenviar o mesmo arquivo não duplica | Aprovado |
| CT23 | UC09 | Arquivo com linha inválida: nada é importado e o erro indica a linha | Aprovado |
| CT24 | UC10 | Criar usuário exige a senha do síndico; com senha errada nada é criado | Aprovado |
| CT25 | UC13 | Usuário novo é obrigado a trocar a senha provisória antes de usar o sistema | Aprovado |
| CT26 | UC10 | Usuário desativado não entra; síndico não consegue desativar a própria conta | Aprovado |
| CT27 | UC11 | Registro de acessos mostra quem leu e quem alterou a ocorrência (RNF02) | Aprovado |
| CT28 | RNF03 | Formulário sem o código de proteção (CSRF) é recusado | Aprovado |
| CT29 | RNF01 | Arquivos internos (configuração, banco, fotos) não são servidos pelo navegador | Aprovado |
| CT30 | RNF03 | Sessão expira após o tempo de inatividade e pede nova entrada | Aprovado |
| CT31 | RNF04 | No celular (390 px) as telas não têm rolagem horizontal e o menu abre | Aprovado |
| CT32 | RNF05 | Acessibilidade: axe-core sem violações sérias ou críticas (WCAG 2.1 A e AA) | Aprovado |
| CT33 | RG5 | Retenção: ocorrência encerrada além do prazo é anonimizada e continua nos indicadores | Aprovado |
