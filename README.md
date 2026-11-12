# Livro de Ocorrências Digital

Sistema web do TCC "Transformação digital em condomínios: a digitalização do livro de
ocorrências" (Sistemas de Informação, UDF). Moradores e portaria registram ocorrências
pelo celular; o síndico acompanha, responde e encerra; o histórico não pode ser apagado;
cada perfil vê só o que lhe cabe; e um painel de indicadores apoia as decisões do síndico.

PHP 8.5 sem framework, MariaDB e HTML/CSS com design system próprio. Sem dependências
externas para executar.

As telas do sistema, organizadas por requisito funcional, estão em [docs/README.md](docs/README.md).

## Rodar no RStack

O site já responde em <http://tcc.test> (pasta `D:\RStack\www\TCC`, banco `tcc`).

### Demonstração (dados fictícios)

```
D:\RStack\bin\php\php-8.5.11-nts-Win32-vs17-x64\php.exe banco\demo.php
```

Cria 120 registros de um livro físico fictício e 21 ocorrências no sistema. Contas, todas
com a senha `Demo@2026`:

| Perfil   | E-mail                                           |
|----------|--------------------------------------------------|
| Síndico  | `sindico@demo.test`                              |
| Portaria | `portaria@demo.test`                             |
| Morador  | `morador1@demo.test` até `morador8@demo.test`    |

Os dados de demonstração servem para apresentar e testar o sistema. **Não são dados do
condomínio estudado e não podem ser usados como resultado do TCC.** O `demo.php` se
recusa a apagar um banco que tenha contas reais (use `--forcar` só se quiser mesmo apagar).

### Uso real (condomínio)

Em um banco vazio:

```
php banco/instalar.php "Nome do síndico" email@exemplo.com "senha provisória"
```

Cria as tabelas, as categorias e os locais iniciais e o primeiro síndico, que troca a
senha no primeiro acesso. Os demais usuários são criados pelo síndico na tela Usuários.
Para outro servidor, crie `app/config.local.php` devolvendo só o que muda, por exemplo:

```php
<?php
return [
    'banco' => ['dsn' => 'mysql:host=...;dbname=...;charset=utf8mb4', 'usuario' => '...', 'senha' => '...'],
    'nome_condominio' => 'Nome do condomínio',
    'forcar_https' => true,
    'retencao_meses' => 24, // prazo de guarda acordado com o síndico
];
```

Com `retencao_meses` definido, agende `php banco/retencao.php` para rodar uma vez por dia.

## Testes

```
php testes/unidade.php       # 44 verificações das regras sem banco (indicadores, status, CSV)
node testes/funcional.mjs    # 33 casos de teste em navegador real, por caso de uso
node testes/capturas.mjs     # capturas de tela usadas no TCC
node testes/usabilidade.mjs  # sessão do teste de usabilidade com uma pessoa (tarefas, tempo e SUS)
```

Os três últimos usam o Playwright do RStack (`D:\RStack\bin\playwright`) e **recriam o
banco de demonstração** antes de começar. O resultado dos testes funcionais fica em
`testes/resultado.md`. As sessões de usabilidade ficam em `testes/usabilidade_resultados.csv`,
que não vai para o repositório; `node testes/usabilidade.mjs --resumo` soma o que já foi coletado.

## Organização

| Caminho               | Conteúdo |
|-----------------------|----------|
| `index.php`           | Controlador frontal: rotas, sessão, proteção dos formulários |
| `app/config.php`      | Configuração (banco, sessão, limites, alertas do painel, retenção) |
| `app/nucleo.php`      | Banco, sessão, autenticação, registro de acessos, avisos |
| `app/ocorrencias.php` | Regras: quem vê cada ocorrência, ciclo de status, histórico, fotos, CSV |
| `app/indicadores.php` | Indicadores e pontos de atenção do painel (funções sem banco) |
| `app/paginas/`        | Uma página por tela |
| `assets/`             | `ds.css` (design system), `app.css` (ajustes para web e celular), `app.js`, fontes |
| `banco/`              | `esquema.sql` (tabelas e gatilhos), instalação, demonstração, retenção |
| `storage/fotos/`      | Fotos das ocorrências (sem acesso direto pela web) |
| `testes/`             | Testes de unidade, funcionais e capturas |

## Regras de negócio do TCC e onde estão no código

O TCC numera as regras de negócio de RG1 a RG5. Os testes citam esses números; no código,
cada regra está descrita por extenso.

| Regra | O que diz | Onde está |
|-------|-----------|-----------|
| RG1 | Ciclo de status: aberta, em análise e, por fim, resolvida ou arquivada, sempre com comentário do síndico | `mover_status()` em `app/ocorrencias.php` |
| RG2 | Histórico imutável: nada é editado nem apagado; correção entra como complemento | gatilhos em `banco/esquema.sql` e `complementar()` |
| RG3 | Visibilidade: o morador vê o que registrou; a portaria, o que registrou e as do dia, sem fotos; o síndico, tudo | `pode_ver()` e `pode_ver_fotos()` |
| RG4 | Registro pela portaria em nome de um morador, com a unidade envolvida | `criar_ocorrencia()` |
| RG5 | Retenção: ao fim do prazo de guarda, a ocorrência é anonimizada | `banco/retencao.php` |

## Segurança, em resumo

- Perfil conferido no servidor a cada requisição; ocorrência de outra pessoa responde como inexistente.
- Senhas só como hash; bloqueio após 5 erros; sessão expira em 15 minutos; senha pedida de novo para exportar e para gerenciar usuários.
- Consultas sempre preparadas; saída sempre escapada; código de proteção (CSRF) em todos os formulários.
- Fotos regravadas sem metadados, com nome aleatório, entregues só ao autor e ao síndico.
- Gatilhos no banco impedem alterar ou apagar o histórico e o registro de acessos.
- `.htaccess` manda tudo para o `index.php`: `app/`, `banco/`, `storage/` e `testes/` não abrem pelo navegador.

O que não foi feito: testes de carga, auditoria de segurança por terceiros e tela para o
síndico cadastrar categorias e locais (novos valores entram pela importação do livro ou
direto no banco).
