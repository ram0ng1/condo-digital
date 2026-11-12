# Telas do sistema e requisitos funcionais

Capturas do Livro de Ocorrências Digital, geradas por `testes/capturas.mjs` no ambiente de
demonstração. **Todos os usuários e ocorrências que aparecem são fictícios.** As telas do
morador foram capturadas em tamanho de celular; as do síndico e da portaria, em tamanho de
computador. Todas se adaptam aos dois tamanhos.

## Requisitos funcionais

### RF01 — Autenticar o usuário e identificar o perfil

A mensagem de erro é a mesma para e-mail inexistente e senha incorreta.

| Entrar | Senha incorreta |
|---|---|
| ![Tela Entrar](telas/tela_entrar.png) | ![Tela Entrar com erro](telas/tela_entrar_erro.png) |

### RF02 — Registrar ocorrência (categoria, local, descrição, urgência; data e hora do servidor)

| Morador, no celular | Erro junto ao campo | Portaria, com a unidade envolvida |
|---|---|---|
| ![Registrar ocorrência](telas/cel_registrar.png) | ![Registrar com erro](telas/cel_registrar_erro.png) | ![Registrar pela portaria](telas/portaria_registrar.png) |

### RF03 — Anexar até três fotos

A foto é regravada sem os metadados do celular e fica visível só ao autor e ao síndico.

| Ocorrência registrada, com foto | A portaria vê a ocorrência do dia sem a foto |
|---|---|
| ![Ocorrência com foto](telas/cel_registrada.png) | ![Ocorrência vista pela portaria](telas/portaria_ocorrencia.png) |

### RF04 — Listar as ocorrências do próprio usuário com o status

| Minhas ocorrências | Menu no celular | Ocorrências do dia (portaria) |
|---|---|---|
| ![Minhas ocorrências](telas/cel_minhas.png) | ![Menu](telas/cel_menu.png) | ![Ocorrências do dia](telas/portaria_dia.png) |

### RF05 — Síndico altera o status com comentário obrigatório

Só são oferecidos os status permitidos a partir do atual: aberta, em análise, resolvida ou arquivada.

| Respondendo | Depois da resposta |
|---|---|
| ![Síndico responde](telas/sindico_responder.png) | ![Ocorrência respondida](telas/sindico_respondida.png) |

### RF06 — Avisar o síndico de novas ocorrências e o autor de cada mudança de status

| Avisos do morador | Avisos do síndico |
|---|---|
| ![Avisos do morador](telas/cel_avisos.png) | ![Avisos do síndico](telas/sindico_avisos.png) |

### RF07 — Histórico sem edição nem exclusão; correções entram como complemento

![Ocorrência com histórico e complemento](telas/cel_ocorrencia.png)

### RF08 — Pesquisar e filtrar ocorrências (síndico)

![Ocorrências do síndico com filtros](telas/sindico_ocorrencias.png)

### RF09 — Painel de indicadores (apoio à decisão do síndico)

| Pontos de atenção e indicadores | Situação, Pareto das categorias e locais |
|---|---|
| ![Pontos de atenção](telas/painel_atencao.png) | ![Categorias e locais](telas/painel_categorias.png) |

| Ocorrências por mês | Dia da semana e turno; antes e depois |
|---|---|
| ![Série mensal](telas/painel_mes.png) | ![Mapa e antes e depois](telas/painel_mapa.png) |

No celular:

![Painel no celular](telas/cel_painel.png)

### RF10 — Importar o histórico transcrito do livro físico (CSV)

| Importar | Arquivo com erro: nada é importado |
|---|---|
| ![Importar livro físico](telas/sindico_importar.png) | ![Importação com erro](telas/sindico_importar_erro.png) |

### RF11 — Exportar as ocorrências filtradas em CSV

A senha é pedida de novo, mesmo com a sessão ativa.

![Confirmação da exportação](telas/sindico_exportar.png)

### RF12 — Cadastrar, desativar e alterar o perfil de usuários

| Usuários | Novo usuário |
|---|---|
| ![Usuários](telas/sindico_usuarios.png) | ![Novo usuário](telas/sindico_usuario_novo.png) |

## Outras telas

| Registro de acessos (RNF02) | Minha conta |
|---|---|
| ![Registro de acessos](telas/sindico_acessos.png) | ![Minha conta](telas/cel_conta.png) |

## Como gerar de novo

```
node testes/capturas.mjs
```

Recria o banco de demonstração e grava as imagens em `docs/telas`.
