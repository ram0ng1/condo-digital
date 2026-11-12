// Testes funcionais de ponta a ponta, um ou mais por caso de uso do TCC (UC01 a UC13).
// Cada caso de teste (CT) dirige um navegador de verdade contra o sistema em execução.
// Nas descrições, RG1 a RG5 são as regras de negócio do TCC, listadas no README.
//
//   node testes/funcional.mjs
//
// Atenção: recria o banco de demonstração antes de começar (banco/demo.php se recusa a
// apagar um banco que tenha contas reais). Grava o resultado em testes/resultado.md.
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const RSTACK = process.env.RSTACK || 'D:/RStack';
const BASE = process.env.BASE || 'http://tcc.test';
const PHP = process.env.PHP || `${RSTACK}/bin/php/php-8.5.11-nts-Win32-vs17-x64/php.exe`;
const MARIADB = process.env.MARIADB || `${RSTACK}/bin/mysql/mariadb-13.0.2-winx64/bin/mariadb.exe`;
process.env.PLAYWRIGHT_BROWSERS_PATH ||= `${RSTACK}/bin/playwright/browsers`;
const requer = createRequire(`${RSTACK}/bin/playwright/`);
const { chromium } = requer('playwright');
const AXE = requer.resolve('axe-core/axe.min.js');

const RAIZ = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const SENHA = 'Demo@2026';
const sql = (comando) => execFileSync(MARIADB, ['-uroot', 'tcc', '-N', '-B', '-e', comando], { encoding: 'utf8' }).trim();

console.log(execFileSync(PHP, ['banco/demo.php'], { cwd: RAIZ, encoding: 'utf8' }).trim().split('\n')[0]);

const navegador = await chromium.launch();
const resultados = [];
/** Executa um caso de teste; qualquer exceção ou verificação falsa reprova o caso. */
async function ct(id, uc, cenario, fn) {
  let ok = true;
  let detalhe = '';
  try {
    await fn((condicao, mensagem) => {
      if (!condicao) throw new Error(mensagem);
    });
  } catch (e) {
    ok = false;
    detalhe = e.message.split('\n')[0];
  }
  resultados.push({ id, uc, cenario, ok, detalhe });
  console.log(`${ok ? 'ok     ' : 'FALHOU '} ${id} [${uc}] ${cenario}${detalhe ? `\n         -> ${detalhe}` : ''}`);
}

async function sessao(email, senha = SENHA, opcoes = {}) {
  const contexto = await navegador.newContext({ viewport: { width: 1366, height: 860 }, ...opcoes });
  const pagina = await contexto.newPage();
  await pagina.goto(`${BASE}/entrar`);
  await pagina.fill('#email', email);
  await pagina.fill('#senha', senha);
  await pagina.click('button[type=submit]');
  await pagina.waitForLoadState('networkidle');
  return pagina;
}
const csrf = (pagina) => pagina.locator('input[name=csrf]').first().inputValue();
const texto = async (pagina) => (await pagina.locator('body').innerText()).replace(/\s+/g, ' ');

// ----- UC01 Autenticar-se -----
await ct('CT01', 'UC01', 'Morador entra e cai em "Minhas ocorrências"; síndico cai no painel', async (v) => {
  const m = await sessao('morador1@demo.test');
  v(m.url().endsWith('/ocorrencias') && (await texto(m)).includes('Minhas ocorrências'), `morador foi para ${m.url()}`);
  const s = await sessao('sindico@demo.test');
  v(s.url().endsWith('/painel'), `síndico foi para ${s.url()}`);
  await m.context().close();
  await s.context().close();
});

await ct('CT02', 'UC01', 'Senha incorreta: mensagem igual à de e-mail inexistente, sem entrar', async (v) => {
  const a = await sessao('morador2@demo.test', 'senha-errada');
  const b = await sessao('ninguem@demo.test', 'senha-errada');
  const msg = 'E-mail ou senha incorretos.';
  v((await texto(a)).includes(msg) && (await texto(b)).includes(msg), 'mensagens diferentes para conta existente e inexistente');
  v(a.url().endsWith('/entrar'), 'entrou com senha errada');
  await a.context().close();
  await b.context().close();
});

await ct('CT03', 'UC01', 'Cinco senhas erradas seguidas bloqueiam a conta, mesmo para a senha correta', async (v) => {
  let p;
  for (let i = 0; i < 5; i++) {
    p = await sessao('morador8@demo.test', `errada${i}`);
    if (i < 4) await p.context().close();
  }
  v((await texto(p)).includes('Conta bloqueada'), 'a quinta tentativa não avisou do bloqueio');
  await p.context().close();
  p = await sessao('morador8@demo.test');
  v(p.url().endsWith('/entrar') && (await texto(p)).includes('Conta bloqueada'), 'a senha correta entrou em conta bloqueada');
  await p.context().close();
});

await ct('CT04', 'UC01', 'Página interna sem login redireciona para a tela de entrada', async (v) => {
  const c = await navegador.newContext();
  const p = await c.newPage();
  for (const rota of ['painel', 'ocorrencias', 'ocorrencias/1', 'usuarios', 'acessos', 'fotos/1']) {
    await p.goto(`${BASE}/${rota}`);
    v(p.url().endsWith('/entrar'), `/${rota} abriu sem login`);
  }
  await c.close();
});

// ----- UC02 Registrar ocorrência -----
let nova; // número da ocorrência criada no CT05, usada pelos casos seguintes
const morador = await sessao('morador2@demo.test');
await ct('CT05', 'UC02', 'Morador registra ocorrência com foto; ela nasce "Aberta" com data e hora do servidor', async (v) => {
  await morador.goto(`${BASE}/ocorrencias/nova`);
  await morador.selectOption('#categoria_id', { label: 'Vazamento ou infiltração' });
  await morador.selectOption('#local_id', { label: 'Garagem' });
  await morador.selectOption('#urgencia', 'alta');
  await morador.fill('#descricao', 'Vazamento no teto da garagem, perto da vaga de visitantes (teste automatizado).');
  await morador.setInputFiles('#fotos', path.join(RAIZ, 'testes/foto_teste.jpg'));
  const antes = Date.now();
  await morador.click('form.Card button[type=submit]');
  await morador.waitForURL(/ocorrencias\/\d+$/);
  nova = Number(morador.url().match(/(\d+)$/)[1]);
  const t = await texto(morador);
  v(t.includes(`Ocorrência nº ${nova}`) && t.includes('Aberta'), 'detalhe sem número ou sem status Aberta');
  v((await morador.locator('.Fotos img').count()) === 1, 'a foto não apareceu para o autor');
  const registrada = new Date(sql(`SELECT registrada_em FROM ocorrencias WHERE id = ${nova}`).replace(' ', 'T')).getTime();
  v(Math.abs(registrada - antes) < 120000, 'data e hora gravadas diferem do relógio do servidor');
  const foto = sql(`SELECT caminho_arquivo FROM anexos WHERE ocorrencia_id = ${nova}`);
  const bytes = fs.readFileSync(path.join(RAIZ, 'storage/fotos', foto));
  v(!bytes.includes(Buffer.from('Exif')), 'a foto salva ainda tem metadados EXIF');
});

await ct('CT06', 'UC02', 'Descrição curta demais é recusada pelo servidor, com a mensagem junto ao campo', async (v) => {
  await morador.goto(`${BASE}/ocorrencias/nova`);
  await morador.selectOption('#categoria_id', { label: 'Barulho' });
  await morador.selectOption('#local_id', { label: 'Unidade' });
  await morador.fill('#descricao', 'curta');
  await morador.evaluate(() => document.querySelector('form.Card').setAttribute('novalidate', '')); // pula a validação do navegador
  const total = sql('SELECT COUNT(*) FROM ocorrencias');
  await morador.click('form.Card button[type=submit]');
  await morador.waitForLoadState('networkidle');
  v((await morador.locator('#erro-descricao').innerText()).includes('pelo menos 10 caracteres'), 'sem mensagem junto ao campo');
  v((await morador.inputValue('#descricao')) === 'curta', 'o formulário perdeu o que o usuário digitou');
  v(sql('SELECT COUNT(*) FROM ocorrencias') === total, 'gravou ocorrência inválida');
});

await ct('CT07', 'UC02', 'Arquivo que não é imagem é recusado como foto', async (v) => {
  await morador.goto(`${BASE}/ocorrencias/nova`);
  await morador.selectOption('#categoria_id', { label: 'Barulho' });
  await morador.selectOption('#local_id', { label: 'Unidade' });
  await morador.fill('#descricao', 'Teste de envio de arquivo que não é uma imagem.');
  await morador.setInputFiles('#fotos', { name: 'falsa.jpg', mimeType: 'image/jpeg', buffer: Buffer.from('<?php echo 1; ?>') });
  await morador.click('form.Card button[type=submit]');
  await morador.waitForLoadState('networkidle');
  v((await morador.locator('#erro-fotos').innerText()).includes('JPG, PNG ou WebP'), 'aceitou arquivo que não é imagem');
});

// ----- UC03 Acompanhar (cada perfil vê só o que pode) -----
await ct('CT08', 'UC03', 'Morador vê a própria ocorrência na lista e não abre a de outro morador', async (v) => {
  await morador.goto(`${BASE}/ocorrencias`);
  v((await texto(morador)).includes(`nº ${nova}`), 'a ocorrência nova não está na lista do autor');
  const alheia = sql(`SELECT id FROM ocorrencias WHERE origem = 'sistema' AND autor_id <> (SELECT id FROM usuarios WHERE email = 'morador2@demo.test') LIMIT 1`);
  const r = await morador.goto(`${BASE}/ocorrencias/${alheia}`);
  v(r.status() === 404, `ocorrência de outro morador respondeu ${r.status()}`);
  const livro = sql("SELECT id FROM ocorrencias WHERE origem = 'livro_fisico' LIMIT 1");
  v((await morador.goto(`${BASE}/ocorrencias/${livro}`)).status() === 404, 'morador abriu registro do livro físico');
});

// ----- UC05 Ocorrências do dia (portaria) -----
const portaria = await sessao('portaria@demo.test');
await ct('CT09', 'UC05', 'Portaria vê a ocorrência do dia, mas sem a foto, nem pelo endereço direto', async (v) => {
  v((await texto(portaria)).includes(`nº ${nova}`), 'a ocorrência de hoje não aparece para a portaria');
  await portaria.goto(`${BASE}/ocorrencias/${nova}`);
  v((await portaria.locator('.Fotos img').count()) === 0, 'a portaria viu a foto');
  v((await texto(portaria)).includes('apenas para o autor e o síndico'), 'sem aviso de que há foto restrita');
  const anexo = sql(`SELECT id FROM anexos WHERE ocorrencia_id = ${nova}`);
  v((await portaria.goto(`${BASE}/fotos/${anexo}`)).status() === 404, 'a portaria baixou a foto pelo endereço direto');
  const antiga = sql("SELECT id FROM ocorrencias WHERE origem = 'sistema' AND registrada_em < CURDATE() AND autor_id <> (SELECT id FROM usuarios WHERE email = 'portaria@demo.test') LIMIT 1");
  v((await portaria.goto(`${BASE}/ocorrencias/${antiga}`)).status() === 404, 'a portaria abriu ocorrência de outro dia');
});

await ct('CT10', 'UC02', 'Portaria registra em nome de um morador, indicando a unidade envolvida (RG4)', async (v) => {
  await portaria.goto(`${BASE}/ocorrencias/nova`);
  await portaria.selectOption('#categoria_id', { label: 'Encomendas' });
  await portaria.selectOption('#local_id', { label: 'Portaria' });
  await portaria.selectOption('#unidade_id', { label: 'Bloco B, unidade 201' });
  await portaria.fill('#descricao', 'Morador relatou na portaria que a encomenda não foi localizada.');
  await portaria.click('form.Card button[type=submit]');
  await portaria.waitForURL(/ocorrencias\/\d+$/);
  const t = await texto(portaria);
  v(t.includes('Portaria Demonstração (Portaria)') && t.includes('Bloco B, unidade 201'), 'autor ou unidade envolvida não aparecem');
});

// ----- UC06 Alterar status e responder -----
const sindico = await sessao('sindico@demo.test');
await ct('CT11', 'UC12', 'Síndico é avisado da nova ocorrência', async (v) => {
  await sindico.goto(`${BASE}/avisos`);
  v((await texto(sindico)).includes(`Nova ocorrência nº ${nova} registrada.`), 'sem aviso da nova ocorrência');
});

await ct('CT12', 'UC06', 'Pular etapa (aberta direto para resolvida) é recusado pelo servidor (RG1)', async (v) => {
  await sindico.goto(`${BASE}/ocorrencias/${nova}`);
  v(!(await sindico.locator('#status option').allInnerTexts()).includes('Resolvida'), 'a tela oferece "Resolvida" para ocorrência aberta');
  const r = await sindico.request.post(`${BASE}/ocorrencias/${nova}`, {
    form: { csrf: await csrf(sindico), acao: 'status', status: 'resolvida', comentario: 'tentando pular etapa' },
  });
  v((await r.text()).includes('Mudança de status não permitida'), 'o servidor não recusou a transição');
  v(sql(`SELECT status FROM ocorrencias WHERE id = ${nova}`) === 'aberta', 'o status mudou');
});

await ct('CT13', 'UC06', 'Resposta sem comentário é recusada', async (v) => {
  const r = await sindico.request.post(`${BASE}/ocorrencias/${nova}`, {
    form: { csrf: await csrf(sindico), acao: 'status', status: 'em_analise', comentario: '  ' },
  });
  v((await r.text()).includes('Escreva um comentário'), 'aceitou resposta sem comentário');
  v(sql(`SELECT status FROM ocorrencias WHERE id = ${nova}`) === 'aberta', 'o status mudou sem comentário');
});

await ct('CT14', 'UC06', 'Síndico responde: aberta, em análise, resolvida, com comentário em cada passo', async (v) => {
  for (const [valor, comentario] of [['em_analise', 'Chamei a manutenção para avaliar.'], ['resolvida', 'Tubulação reparada.']]) {
    await sindico.goto(`${BASE}/ocorrencias/${nova}`);
    await sindico.selectOption('#status', valor);
    await sindico.fill('#comentario', comentario);
    await sindico.click('form[aria-labelledby=t-responder] button[type=submit]');
    await sindico.waitForLoadState('networkidle');
    v((await texto(sindico)).includes(comentario), `o comentário "${comentario}" não entrou no histórico`);
  }
  v(sql(`SELECT status FROM ocorrencias WHERE id = ${nova}`) === 'resolvida', 'status final diferente de resolvida');
  v(sql(`SELECT COUNT(*) FROM movimentacoes WHERE ocorrencia_id = ${nova}`) === '3', 'histórico sem as 3 movimentações');
  v((await sindico.locator('#status').count()) === 0, 'a tela ainda oferece mudança de status após o encerramento');
});

await ct('CT15', 'UC03', 'Morador vê o novo status, lê o comentário do síndico e recebe o aviso (RF06)', async (v) => {
  await morador.goto(`${BASE}/avisos`);
  v((await texto(morador)).includes(`Ocorrência nº ${nova}: status alterado para "Resolvida".`), 'sem aviso de mudança de status');
  await morador.goto(`${BASE}/ocorrencias/${nova}`);
  const t = await texto(morador);
  v(t.includes('Resolvida') && t.includes('Tubulação reparada.'), 'status ou comentário não aparecem para o autor');
});

// ----- UC04 Complementar (o histórico não se altera) -----
await ct('CT16', 'UC04', 'Autor acrescenta complemento; o registro original continua igual (RG2)', async (v) => {
  const original = sql(`SELECT descricao FROM ocorrencias WHERE id = ${nova}`);
  await morador.fill('#texto', 'A goteira parou depois do reparo. Obrigado.');
  await morador.click('form[aria-labelledby=t-complemento] button[type=submit]');
  await morador.waitForLoadState('networkidle');
  v((await texto(morador)).includes('A goteira parou depois do reparo.'), 'o complemento não entrou no histórico');
  v(sql(`SELECT descricao FROM ocorrencias WHERE id = ${nova}`) === original, 'a descrição original foi alterada');
});

await ct('CT17', 'UC04', 'O banco recusa alterar ou apagar o histórico, mesmo com acesso direto (RG2)', async (v) => {
  for (const comando of [
    `UPDATE movimentacoes SET comentario = 'adulterado' WHERE ocorrencia_id = ${nova}`,
    `DELETE FROM movimentacoes WHERE ocorrencia_id = ${nova}`,
    `DELETE FROM ocorrencias WHERE id = ${nova}`,
    `UPDATE ocorrencias SET descricao = 'adulterada' WHERE id = ${nova}`,
    'DELETE FROM registros_acesso LIMIT 1',
  ]) {
    let recusou = false;
    try {
      execFileSync(MARIADB, ['-uroot', 'tcc', '-e', comando], { stdio: 'pipe' });
    } catch (e) {
      recusou = /nao pode ser/.test(String(e.stderr));
    }
    v(recusou, `o banco aceitou: ${comando}`);
  }
});

// ----- UC07 Pesquisar e exportar -----
await ct('CT18', 'UC07', 'Filtro por categoria e status devolve só o que corresponde', async (v) => {
  const cat = sql("SELECT id FROM categorias WHERE nome = 'Barulho'");
  await sindico.goto(`${BASE}/ocorrencias?categoria=${cat}&status=resolvida&origem=sistema`);
  const esperado = sql(`SELECT COUNT(*) FROM ocorrencias WHERE categoria_id = ${cat} AND status = 'resolvida' AND origem = 'sistema'`);
  const linhas = await sindico.locator('table.is-lista tbody tr').allInnerTexts();
  v(String(linhas.length) === esperado, `lista com ${linhas.length} linhas, banco com ${esperado}`);
  v(linhas.every((l) => l.includes('Barulho') && l.includes('Resolvida')), 'há linha fora do filtro');
});

await ct('CT19', 'UC07', 'Exportar pede a senha de novo: errada recusa, correta baixa o CSV filtrado', async (v) => {
  await sindico.goto(`${BASE}/ocorrencias?origem=sistema`);
  await sindico.click('[data-abrir=exportar]');
  await sindico.fill('#exp-senha', 'senha-errada');
  await sindico.click('#exportar button[type=submit]');
  await sindico.waitForLoadState('networkidle');
  v((await texto(sindico)).includes('A exportação não foi feita'), 'exportou com a senha errada');
  await sindico.click('[data-abrir=exportar]');
  await sindico.fill('#exp-senha', SENHA);
  const [arquivo] = await Promise.all([sindico.waitForEvent('download'), sindico.click('#exportar button[type=submit]')]);
  const linhas = fs.readFileSync(await arquivo.path(), 'utf8').replace(/^\uFEFF/, '').trim().split('\n');
  v(linhas[0].startsWith('numero;origem;registrada_em'), 'cabeçalho do CSV inesperado');
  v(String(linhas.length - 1) === sql("SELECT COUNT(*) FROM ocorrencias WHERE origem = 'sistema'"), 'número de linhas diferente do filtro');
  v(sql("SELECT COUNT(*) FROM registros_acesso WHERE acao = 'exportar'") === '1', 'a exportação não ficou no registro de acessos');
});

// ----- UC08 Painel -----
await ct('CT20', 'UC08', 'Painel mostra os mesmos números que o banco (total, taxa de resposta, incompletos)', async (v) => {
  await sindico.goto(`${BASE}/painel?origem=livro_fisico`);
  const t = await texto(sindico);
  const [total, resp, incompletos] = sql("SELECT COUNT(*), ROUND(SUM(houve_resposta) * 100 / COUNT(*), 1), ROUND(SUM(completo = 0) * 100 / COUNT(*), 1) FROM ocorrencias WHERE origem = 'livro_fisico'").split('\t');
  const br = (n) => String(Number(n)).replace('.', ',');
  v((await sindico.locator('.Kpi.is-hero .Kpi-value').innerText()) === total, `total do painel diferente de ${total}`);
  v(t.includes(`${br(resp)} %`), `taxa de resposta ${br(resp)} % não aparece`);
  await sindico.goto(`${BASE}/painel`);
  v((await texto(sindico)).includes(`Registros incompletos ${br(incompletos)} % 0 %`), 'quadro antes e depois sem a linha de incompletos');
  v((await sindico.locator('.Mapa tbody td').count()) === 28, 'mapa de dia e turno sem as 28 células');
});

await ct('CT21', 'UC08', 'Morador e portaria não acessam painel, usuários, acessos nem importação', async (v) => {
  for (const [quem, p] of [['morador', morador], ['portaria', portaria]]) {
    for (const rota of ['painel', 'usuarios', 'acessos', 'importar']) {
      v((await p.goto(`${BASE}/${rota}`)).status() === 403, `${quem} acessou /${rota}`);
    }
  }
});

// ----- UC09 Importar livro físico -----
const cabecalho = 'id;data;hora;dia_semana;turno;registrante;local;bloco_andar;categoria;descricao_resumo;urgencia_percebida;houve_resposta;data_resposta;desfecho;recorrencia;completo';
const enviarCsv = async (linhas) => {
  await sindico.goto(`${BASE}/importar`);
  await sindico.setInputFiles('#arquivo', { name: 'livro.csv', mimeType: 'text/csv', buffer: Buffer.from([cabecalho, ...linhas].join('\r\n'), 'utf8') });
  await sindico.click('form.Card button[type=submit]');
  await sindico.waitForLoadState('networkidle');
};
await ct('CT22', 'UC09', 'Importa a transcrição do livro; reenviar o mesmo arquivo não duplica', async (v) => {
  const antes = Number(sql("SELECT COUNT(*) FROM ocorrencias WHERE origem = 'livro_fisico'"));
  const linhas = [
    '9001;05/03/2025;21:30;;;morador;Salão de festas;;Barulho;Som alto após as 22h;média;sim;08/03/2025;resolvida;;sim',
    '9002;;;;;não identificado;Garagem;;Garagem e veículos;Registro com data ilegível;baixa;não;;sem registro;;não',
    '9003;10/03/2025;;;;portaria;Portaria;Bloco A;Categoria nova do livro;Categoria que ainda não existia;alta;não;;pendente;;sim',
  ];
  await enviarCsv(linhas);
  v((await texto(sindico)).includes('3 registros importados'), 'sem confirmação da importação');
  v(Number(sql("SELECT COUNT(*) FROM ocorrencias WHERE origem = 'livro_fisico'")) === antes + 3, 'os 3 registros não foram gravados');
  v(sql("SELECT COUNT(*) FROM categorias WHERE nome = 'Categoria nova do livro'") === '1', 'categoria nova não foi criada');
  v(sql('SELECT registrada_em IS NULL FROM ocorrencias WHERE numero_livro = 9002') === '1', 'data ilegível não ficou vazia');
  await enviarCsv(linhas);
  v((await texto(sindico)).includes('0 registros importados') && Number(sql("SELECT COUNT(*) FROM ocorrencias WHERE origem = 'livro_fisico'")) === antes + 3, 'reenviar duplicou registros');
});

await ct('CT23', 'UC09', 'Arquivo com linha inválida: nada é importado e o erro indica a linha', async (v) => {
  const antes = sql('SELECT COUNT(*) FROM ocorrencias');
  await enviarCsv([
    '9101;05/04/2025;21:30;;;morador;Garagem;;Barulho;Linha correta;média;não;;sem registro;;sim',
    '9102;31/02/2025;25:99;;;morador;Garagem;;Barulho;Data e hora impossíveis;urgente;talvez;;;;sim',
  ]);
  const t = await texto(sindico);
  v(t.includes('Nada foi importado') && t.includes('Linha 3'), 'sem a indicação da linha com erro');
  v(t.includes('data inválida') && t.includes('hora inválida'), 'a mensagem não diz o que corrigir');
  v(sql('SELECT COUNT(*) FROM ocorrencias') === antes, 'importou parte do arquivo com erro');
});

// ----- UC10 Gerenciar usuários / UC13 trocar senha -----
await ct('CT24', 'UC10', 'Criar usuário exige a senha do síndico; com senha errada nada é criado', async (v) => {
  await sindico.goto(`${BASE}/usuarios/novo`);
  await sindico.fill('#nome', 'Moradora Teste');
  await sindico.fill('#email', 'teste@demo.test');
  await sindico.fill('#bloco', 'C');
  await sindico.fill('#numero', '404');
  await sindico.fill('#senha_provisoria', 'Provisoria1');
  await sindico.fill('#senha', 'senha-errada');
  await sindico.click('form.Card button[type=submit]');
  await sindico.waitForLoadState('networkidle');
  v((await sindico.locator('#erro-senha').innerText()).includes('Senha incorreta'), 'sem erro junto ao campo da senha');
  v(sql("SELECT COUNT(*) FROM usuarios WHERE email = 'teste@demo.test'") === '0', 'criou usuário sem confirmar a senha');
  await sindico.fill('#senha_provisoria', 'Provisoria1');
  await sindico.fill('#senha', SENHA);
  await sindico.click('form.Card button[type=submit]');
  await sindico.waitForURL(/usuarios$/);
  v((await texto(sindico)).includes('Moradora Teste'), 'o usuário novo não aparece na lista');
});

await ct('CT25', 'UC13', 'Usuário novo é obrigado a trocar a senha provisória antes de usar o sistema', async (v) => {
  const p = await sessao('teste@demo.test', 'Provisoria1');
  v(p.url().endsWith('/conta'), `primeiro acesso foi para ${p.url()}`);
  await p.goto(`${BASE}/ocorrencias`);
  v(p.url().endsWith('/conta'), 'navegou sem trocar a senha provisória');
  await p.fill('#atual', 'Provisoria1');
  await p.fill('#nova', 'MinhaSenha2026');
  await p.fill('#confirmar', 'MinhaSenha2026');
  await p.click('form.Card button[type=submit]');
  await p.waitForURL(/ocorrencias$/);
  await p.context().close();
  const q = await sessao('teste@demo.test', 'MinhaSenha2026');
  v(q.url().endsWith('/ocorrencias'), 'a senha nova não entrou');
  await q.context().close();
});

await ct('CT26', 'UC10', 'Usuário desativado não entra; síndico não consegue desativar a própria conta', async (v) => {
  const idTeste = sql("SELECT id FROM usuarios WHERE email = 'teste@demo.test'");
  await sindico.goto(`${BASE}/usuarios/${idTeste}`);
  await sindico.uncheck('input[name=ativo]');
  await sindico.fill('#senha', SENHA);
  await sindico.click('form.Card button[type=submit]');
  await sindico.waitForURL(/usuarios$/);
  const p = await sessao('teste@demo.test', 'MinhaSenha2026');
  v(p.url().endsWith('/entrar'), 'conta desativada entrou');
  await p.context().close();
  await sindico.goto(`${BASE}/usuarios/${sql("SELECT id FROM usuarios WHERE email = 'sindico@demo.test'")}`);
  await sindico.uncheck('input[name=ativo]');
  await sindico.fill('#senha', SENHA);
  await sindico.click('form.Card button[type=submit]');
  await sindico.waitForLoadState('networkidle');
  v((await texto(sindico)).includes('não pode desativar a própria conta'), 'o síndico desativou a própria conta');
});

// ----- UC11 Registro de acessos -----
await ct('CT27', 'UC11', 'Registro de acessos mostra quem leu e quem alterou a ocorrência (RNF02)', async (v) => {
  await sindico.goto(`${BASE}/acessos?ocorrencia=${nova}`);
  const t = await texto(sindico);
  for (const trecho of ['Registrou ocorrência', 'Leu a ocorrência', 'Alterou o status', 'Complementou', 'Portaria Demonstração', 'Morador Demo 2']) {
    v(t.includes(trecho), `"${trecho}" não aparece no registro de acessos`);
  }
});

// ----- Requisitos não funcionais -----
await ct('CT28', 'RNF03', 'Formulário sem o código de proteção (CSRF) é recusado', async (v) => {
  const r = await sindico.request.post(`${BASE}/ocorrencias/${nova}`, { form: { acao: 'complemento', texto: 'pedido forjado por outro site' } });
  v(r.status() === 403, `respondeu ${r.status()}`);
});

await ct('CT29', 'RNF01', 'Arquivos internos (configuração, banco, fotos) não são servidos pelo navegador', async (v) => {
  const foto = sql('SELECT caminho_arquivo FROM anexos LIMIT 1');
  for (const rota of ['app/config.php', 'app/nucleo.php', 'banco/esquema.sql', 'banco/exemplo_livro_fisico.csv', `storage/fotos/${foto}`, 'testes/resultado.md']) {
    const r = await sindico.request.get(`${BASE}/${rota}`);
    const corpo = await r.text();
    v(r.status() === 404 && !corpo.includes('CREATE TABLE') && !corpo.includes("'banco'"), `/${rota} respondeu ${r.status()}`);
  }
});

await ct('CT30', 'RNF03', 'Sessão expira após o tempo de inatividade e pede nova entrada', async (v) => {
  const local = path.join(RAIZ, 'app/config.local.php');
  v(!fs.existsSync(local), 'app/config.local.php já existe; teste pulado para não sobrescrever');
  fs.writeFileSync(local, "<?php return ['sessao_minutos' => 0.03]; // criado pelo teste CT30\n");
  try {
    const p = await sessao('morador3@demo.test');
    v(p.url().endsWith('/ocorrencias'), 'não entrou');
    await p.waitForTimeout(3000);
    await p.goto(`${BASE}/ocorrencias`);
    v(p.url().endsWith('/entrar') && (await texto(p)).includes('sessão expirou'), 'a sessão continuou ativa após a inatividade');
    await p.context().close();
  } finally {
    fs.unlinkSync(local);
  }
});

await ct('CT31', 'RNF04', 'No celular (390 px) as telas não têm rolagem horizontal e o menu abre', async (v) => {
  const celular = { viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true };
  const s = await sessao('sindico@demo.test', SENHA, celular);
  for (const rota of ['painel', 'ocorrencias', `ocorrencias/${nova}`, 'ocorrencias/nova', 'importar', 'usuarios', 'usuarios/novo', 'acessos', 'avisos', 'conta']) {
    await s.goto(`${BASE}/${rota}`);
    const sobra = await s.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    v(sobra <= 0, `/${rota} tem ${sobra}px de rolagem horizontal`);
  }
  await s.click('[data-menu]');
  v(await s.locator('.Sidebar a', { hasText: 'Registrar ocorrência' }).isVisible(), 'o menu não abriu no celular');
  await s.context().close();
});

await ct('CT32', 'RNF05', 'Acessibilidade: axe-core sem violações sérias ou críticas (WCAG 2.1 A e AA)', async (v) => {
  const s = await sessao('sindico@demo.test', SENHA, { bypassCSP: true });
  const problemas = [];
  for (const rota of ['painel', 'ocorrencias', `ocorrencias/${nova}`, 'ocorrencias/nova', 'importar', 'usuarios', 'usuarios/novo', 'acessos', 'avisos', 'conta']) {
    await s.goto(`${BASE}/${rota}`);
    await s.addScriptTag({ path: AXE });
    const r = await s.evaluate(() => axe.run(document, { runOnly: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'] }));
    for (const violacao of r.violations.filter((x) => ['serious', 'critical'].includes(x.impact))) {
      problemas.push(`/${rota}: ${violacao.id} (${violacao.nodes.length})`);
    }
  }
  await s.context().close();
  const c = await navegador.newContext({ bypassCSP: true });
  const p = await c.newPage();
  await p.goto(`${BASE}/entrar`);
  await p.addScriptTag({ path: AXE });
  const r = await p.evaluate(() => axe.run(document, { runOnly: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'] }));
  problemas.push(...r.violations.filter((x) => ['serious', 'critical'].includes(x.impact)).map((x) => `/entrar: ${x.id} (${x.nodes.length})`));
  await c.close();
  v(problemas.length === 0, problemas.join('; '));
});

await ct('CT33', 'RG5', 'Retenção: ocorrência encerrada além do prazo é anonimizada e continua nos indicadores', async (v) => {
  const local = path.join(RAIZ, 'app/config.local.php');
  v(!fs.existsSync(local), 'app/config.local.php já existe; teste pulado para não sobrescrever');
  const alvo = sql("SELECT id FROM ocorrencias WHERE origem = 'sistema' AND status = 'resolvida' AND registrada_em < NOW() - INTERVAL 1 MONTH ORDER BY id LIMIT 1");
  const recente = sql("SELECT id FROM ocorrencias WHERE origem = 'sistema' AND status = 'resolvida' AND registrada_em > NOW() - INTERVAL 20 DAY ORDER BY id LIMIT 1");
  const total = sql('SELECT COUNT(*) FROM ocorrencias');
  fs.writeFileSync(local, "<?php return ['retencao_meses' => 1]; // criado pelo teste CT33\n");
  try {
    execFileSync(PHP, ['banco/retencao.php'], { cwd: RAIZ, encoding: 'utf8' });
  } finally {
    fs.unlinkSync(local);
  }
  const [descricao, autor, quando] = sql(`SELECT descricao, autor_id IS NULL, anonimizada_em IS NOT NULL FROM ocorrencias WHERE id = ${alvo}`).split('\t');
  v(descricao.includes('anonimizada') && autor === '1' && quando === '1', 'a ocorrência antiga não foi anonimizada');
  v(sql(`SELECT COUNT(*) FROM movimentacoes WHERE ocorrencia_id = ${alvo} AND comentario <> '[anonimizado]'`) === '0', 'sobraram comentários');
  v(sql(`SELECT anonimizada_em IS NULL FROM ocorrencias WHERE id = ${recente}`) === '1', 'anonimizou ocorrência dentro do prazo');
  v(sql('SELECT COUNT(*) FROM ocorrencias') === total, 'a anonimização apagou ocorrências');
  await sindico.goto(`${BASE}/ocorrencias/${alvo}`);
  v((await texto(sindico)).includes('Ocorrência anonimizada em'), 'a tela não informa a anonimização');
});

await navegador.close();

// ----- Resultado -----
const aprovados = resultados.filter((r) => r.ok).length;
const md = [
  `# Resultado dos testes funcionais`,
  ``,
  `Executado em ${new Date().toLocaleString('pt-BR')} contra ${BASE}, com o banco de demonstração (dados fictícios).`,
  ``,
  `**${aprovados} de ${resultados.length} casos de teste aprovados.**`,
  ``,
  `| ID | Caso de uso ou requisito | Cenário | Resultado |`,
  `|---|---|---|---|`,
  ...resultados.map((r) => `| ${r.id} | ${r.uc} | ${r.cenario} | ${r.ok ? 'Aprovado' : `Reprovado: ${r.detalhe}`} |`),
  ``,
].join('\n');
fs.writeFileSync(path.join(RAIZ, 'testes/resultado.md'), md);
console.log(`\n${aprovados} de ${resultados.length} casos de teste aprovados. Resultado em testes/resultado.md`);
process.exit(aprovados === resultados.length ? 0 : 1);
