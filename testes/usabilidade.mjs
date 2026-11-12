// Sessão do teste de usabilidade do TCC: mostra as tarefas T1 a T5 a quem está testando,
// mede sozinha se cada uma deu certo e quanto tempo levou, aplica o questionário SUS e
// guarda tudo em testes/usabilidade_resultados.csv.
//
//   node testes/usabilidade.mjs            abre o navegador para uma pessoa fazer o teste
//   node testes/usabilidade.mjs --resumo   soma as sessões já feitas (sucesso, tempo e SUS)
//   node testes/usabilidade.mjs --robo     o próprio roteiro faz as tarefas, sem ninguém
//
// O modo --robo não é teste de usabilidade. Ele só confere se o roteiro funciona, e o
// tempo que mede é o do computador, não o de uma pessoa. Por isso fica fora do resumo.
// Atenção: cada sessão recria o banco de demonstração (não apaga banco com contas reais).
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
const { chromium } = createRequire(`${RSTACK}/bin/playwright/`)('playwright');

const RAIZ = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const ROBO = process.argv.includes('--robo');
const LIMITE_MS = 5 * 60 * 1000; // tarefa não concluída em 5 minutos conta como insucesso
const sql = (c) => execFileSync(MARIADB, ['-uroot', 'tcc', '-N', '-B', '-e', c], { encoding: 'utf8' }).trim();
const ARQUIVO = path.join(RAIZ, 'testes/usabilidade_resultados.csv');
const IDS = ['T1', 'T2', 'T3', 'T4', 'T5'];

if (process.argv.includes('--resumo')) {
  resumo();
  process.exit(0);
}

/** Soma as sessões feitas por pessoas e imprime os números que o TCC pede. */
function resumo() {
  const sessoes = fs.existsSync(ARQUIVO)
    ? fs.readFileSync(ARQUIVO, 'utf8').trim().split(/\r?\n/).slice(1).map((l) => l.split(';')).filter((l) => l[4] === 'pessoa')
    : [];
  const num = (s) => Number(s.replace(',', '.'));
  const media = (v) => v.reduce((a, b) => a + b, 0) / v.length;
  const br = (n, casas = 1) => n.toFixed(casas).replace('.', ',');
  const contar = (linhas, coluna) => Object.entries(linhas.reduce((m, l) => ({ ...m, [l[coluna]]: (m[l[coluna]] || 0) + 1 }), {}))
    .map(([nome, n]) => `${n} ${nome}`).join(', ');

  const bloco = (titulo, linhas) => {
    console.log(`*${titulo}*`);
    if (!linhas.length) {
      console.log('- Nenhuma sessão ainda.\n');
      return;
    }
    console.log(`- Sessões: ${linhas.length} (${contar(linhas, 2)}); faixas de idade: ${contar(linhas, 3)}`);
    IDS.forEach((id, i) => {
      const feitas = linhas.filter((l) => l[5 + 2 * i] !== '');
      const certas = feitas.filter((l) => l[5 + 2 * i] === 'sim');
      if (feitas.length) {
        console.log(`- ${id}: ${br(certas.length * 100 / feitas.length, 0)} % de sucesso (${certas.length} de ${feitas.length})`
          + (certas.length ? `, tempo médio de quem concluiu: ${br(media(certas.map((l) => num(l[6 + 2 * i]))))} s` : ''));
      }
    });
    const sus = linhas.filter((l) => l[15] !== '').map((l) => num(l[15]));
    if (sus.length) {
      const m = media(sus);
      const desvio = sus.length > 1 ? Math.sqrt(sus.reduce((a, x) => a + (x - m) ** 2, 0) / (sus.length - 1)) : null;
      console.log(`- SUS: média ${br(m)}` + (desvio === null ? ' (uma resposta só, sem desvio-padrão)' : `, desvio-padrão ${br(desvio)}`));
    }
    console.log('');
  };

  // Quem escolhe "pré-teste" faz as cinco tarefas para ensaiar a sessão. Não é usuário final
  // do condomínio e, por isso, não entra na conta dos participantes.
  bloco('Teste de usabilidade: participantes', sessoes.filter((l) => l[2] !== 'todos'));
  bloco('Pré-teste (fora da amostra)', sessoes.filter((l) => l[2] === 'todos'));
}

execFileSync(PHP, ['banco/demo.php'], { cwd: RAIZ });
const parquinho = sql("SELECT id FROM ocorrencias WHERE descricao LIKE 'Brinquedo do parquinho%'");
const obra = sql("SELECT id FROM ocorrencias WHERE descricao LIKE 'Obra com furadeira%'");
const maisFrequente = sql('SELECT c.nome FROM ocorrencias o JOIN categorias c ON c.id = o.categoria_id GROUP BY c.id ORDER BY COUNT(*) DESC LIMIT 1');
const ultima = () => Number(sql('SELECT MAX(id) FROM ocorrencias'));

// Cada tarefa diz quem faz, o que é pedido, em que tela começa, como o roteiro percebe que
// ela terminou (e se deu certo) e o que o modo --robo faz no lugar da pessoa.
const TAREFAS = [
  { id: 'T1', papel: 'morador', conta: 'morador1@demo.test', inicio: 'ocorrencias',
    pedido: 'Registre uma ocorrência com foto: há uma goteira no teto da garagem. Use qualquer foto do aparelho.',
    fim: async (p, antes) => /\/ocorrencias\/\d+$/.test(p.url()) && Number(p.url().match(/(\d+)$/)[1]) > antes
      ? { sucesso: (await p.locator('.Fotos img').count()) > 0 } : null,
    robo: async (p) => {
      await p.goto(`${BASE}/ocorrencias/nova`);
      await p.selectOption('#categoria_id', { label: 'Vazamento ou infiltração' });
      await p.selectOption('#local_id', { label: 'Garagem' });
      await p.fill('#descricao', 'Goteira no teto da garagem, perto das vagas de visitantes.');
      await p.setInputFiles('#fotos', path.join(RAIZ, 'testes/foto_teste.jpg'));
      await p.click('form.Card button[type=submit]');
    } },
  { id: 'T2', papel: 'morador', conta: 'morador1@demo.test', inicio: 'ocorrencias',
    pedido: 'Você avisou sobre o brinquedo do parquinho com parafuso solto. Descubra o que o síndico respondeu.',
    fim: async (p) => (p.url().endsWith(`/ocorrencias/${parquinho}`) && (await p.locator('.PageHead').count()) ? { sucesso: true } : null),
    robo: async (p) => p.goto(`${BASE}/ocorrencias/${parquinho}`) },
  { id: 'T3', papel: 'portaria', conta: 'portaria@demo.test', inicio: 'ocorrencias',
    pedido: 'O morador do bloco B, unidade 201, veio à portaria dizer que há lixo no corredor. Registre em nome dele.',
    fim: async (p, antes) => /\/ocorrencias\/\d+$/.test(p.url()) && Number(p.url().match(/(\d+)$/)[1]) > antes
      ? { sucesso: (await p.locator('body').innerText()).includes('Bloco B, unidade 201') } : null,
    robo: async (p) => {
      await p.goto(`${BASE}/ocorrencias/nova`);
      await p.selectOption('#categoria_id', { label: 'Limpeza' });
      await p.selectOption('#local_id', { label: 'Hall e corredores' });
      await p.selectOption('#unidade_id', { label: 'Bloco B, unidade 201' });
      await p.fill('#descricao', 'Morador relatou sacos de lixo deixados no corredor.');
      await p.click('form.Card button[type=submit]');
    } },
  { id: 'T4', papel: 'sindico', conta: 'sindico@demo.test', inicio: 'ocorrencias',
    pedido: `Responda à ocorrência nº ${obra} (obra com furadeira depois das 18h) e leve-a até "Resolvida".`,
    fim: async (p) => (p.url().endsWith(`/ocorrencias/${obra}`)
      && (await p.locator('.PageHead .Pill').innerText().catch(() => '')) === 'Resolvida' ? { sucesso: true } : null),
    robo: async (p) => {
      for (const [status, texto] of [['em_analise', 'Vou falar com o morador da unidade.'], ['resolvida', 'Morador orientado sobre o horário de obras.']]) {
        await p.goto(`${BASE}/ocorrencias/${obra}`);
        await p.selectOption('#status', status);
        await p.fill('#comentario', texto);
        await p.click('form[aria-labelledby=t-responder] button[type=submit]');
        await p.waitForLoadState('networkidle');
      }
    } },
  { id: 'T5', papel: 'sindico', conta: 'sindico@demo.test', inicio: 'ocorrencias', pergunta: true,
    pedido: 'Descubra no sistema qual é a categoria de ocorrência mais frequente e escreva o nome dela abaixo.',
    fim: async (p, antes, resposta) => (resposta === null ? null
      : { sucesso: resposta.trim().toLowerCase() === maisFrequente.toLowerCase() }),
    robo: async (p, responder) => {
      await p.goto(`${BASE}/painel`);
      responder(await p.locator('.HBar-row span').first().innerText());
    } },
];

const navegador = await chromium.launch({ headless: ROBO });

/** Faixa fixa no rodapé da página com o pedido da tarefa; volta a aparecer a cada página aberta. */
async function faixa(pagina, html) {
  await pagina.evaluate((conteudo) => {
    document.getElementById('faixa-teste')?.remove();
    const f = document.createElement('div');
    f.id = 'faixa-teste';
    f.style.cssText = 'position:fixed;left:0;right:0;bottom:0;z-index:99999;padding:14px 18px;background:#12251b;color:#fff;font:600 15px/1.4 system-ui;display:flex;gap:12px;align-items:center;flex-wrap:wrap;box-shadow:0 -6px 24px rgba(0,0,0,.3)';
    f.innerHTML = conteudo; // texto fixo do próprio roteiro, não vem de usuário
    f.querySelectorAll('button').forEach((b) => {
      b.style.cssText = 'font:700 14px system-ui;padding:9px 16px;border:0;border-radius:999px;cursor:pointer;background:#fff;color:#12251b';
      b.addEventListener('click', () => window.sinalTeste(b.dataset.sinal === 'resposta' ? `resposta:${f.querySelector('input').value}` : b.dataset.sinal));
    });
    document.body.appendChild(f);
  }, html).catch(() => {});
}

async function executar(tarefa) {
  const contexto = await navegador.newContext({ viewport: tarefa.papel === 'morador' ? { width: 400, height: 820 } : { width: 1280, height: 820 } });
  let sinal = null;
  await contexto.exposeBinding('sinalTeste', (_, valor) => { sinal = valor; });
  const p = await contexto.newPage();
  await p.goto(`${BASE}/entrar`);
  await p.fill('#email', tarefa.conta);
  await p.fill('#senha', 'Demo@2026');
  await p.click('button[type=submit]');
  await p.goto(`${BASE}/${tarefa.inicio}`);

  await faixa(p, `<span style="flex:1;min-width:240px"><b>${tarefa.id}.</b> ${tarefa.pedido}</span><button data-sinal="comecar">Começar</button>`);
  if (ROBO) sinal = 'comecar';
  while (sinal !== 'comecar') await p.waitForTimeout(150);
  sinal = null;

  const durante = `<span style="flex:1;min-width:240px"><b>${tarefa.id}.</b> ${tarefa.pedido}</span>`
    + (tarefa.pergunta ? '<input aria-label="Sua resposta" style="font:15px system-ui;padding:8px 10px;border-radius:8px;border:0;min-width:180px"><button data-sinal="resposta">Responder</button>' : '')
    + '<button data-sinal="desisti">Não consegui</button>';
  p.on('load', () => faixa(p, durante));
  await faixa(p, durante);

  const antes = ultima();
  const t0 = Date.now();
  let resposta = null;
  if (ROBO) await tarefa.robo(p, (r) => { resposta = r; });
  let resultado = null;
  while (!resultado && Date.now() - t0 < LIMITE_MS) {
    if (sinal === 'desisti') break;
    if (sinal?.startsWith('resposta:')) resposta = sinal.slice(9);
    resultado = await tarefa.fim(p, antes, resposta).catch(() => null);
    if (!resultado) await p.waitForTimeout(200);
  }
  const segundos = (Date.now() - t0) / 1000;
  await contexto.close();
  return { id: tarefa.id, sucesso: Boolean(resultado?.sucesso), segundos: Number(segundos.toFixed(1)) };
}

// ----- Participante e questionário -----
// As dez afirmações do SUS em português. Antes de aplicar, conferir a redação com a versão
// validada que o TCC cita (Lourenço; Carmona; Lopes, 2022) e ajustar aqui se houver diferença.
const SUS = [
  'Eu acho que gostaria de usar este sistema com frequência.',
  'Eu achei o sistema desnecessariamente complexo.',
  'Eu achei o sistema fácil de usar.',
  'Eu acho que precisaria da ajuda de uma pessoa com conhecimento técnico para usar o sistema.',
  'Eu achei que as várias funções do sistema estão bem integradas.',
  'Eu achei que o sistema tem muita inconsistência.',
  'Eu imagino que a maioria das pessoas aprenderia a usar este sistema rapidamente.',
  'Eu achei o sistema atrapalhado de usar.',
  'Eu me senti confiante ao usar o sistema.',
  'Eu precisei aprender várias coisas novas antes de conseguir usar o sistema.',
];

/** Abre um formulário local e devolve o que a pessoa respondeu. */
async function formulario(titulo, campos) {
  const contexto = await navegador.newContext({ viewport: { width: 760, height: 860 } });
  let enviado = null;
  await contexto.exposeBinding('sinalTeste', (_, valor) => { enviado = valor; });
  const p = await contexto.newPage();
  await p.setContent(`<html lang="pt-BR"><body style="font:16px/1.5 system-ui;max-width:640px;margin:32px auto;padding:0 20px;color:#12251b">
    <h1 style="font-size:24px">${titulo}</h1><form id="f">${campos}
    <p><button style="font:700 16px system-ui;padding:12px 24px;border:0;border-radius:999px;background:#17603b;color:#fff;cursor:pointer">Continuar</button></p></form></body></html>`);
  await p.evaluate(() => document.getElementById('f').addEventListener('submit', (e) => {
    e.preventDefault();
    window.sinalTeste(JSON.stringify(Object.fromEntries(new FormData(e.target))));
  }));
  while (!enviado) await p.waitForTimeout(200);
  await contexto.close();
  return JSON.parse(enviado);
}

let pessoa = { nome: 'robô (execução automática)', papel: 'todos', idade: '' };
if (!ROBO) {
  pessoa = await formulario('Teste de usabilidade do livro de ocorrências', `
    <p>Obrigado por participar. Você fará algumas tarefas no sistema; o tempo é medido automaticamente. Não há resposta certa ou errada: o que está sendo avaliado é o sistema, não você.</p>
    <p><label>Como podemos chamar você (apelido ou código)?<br><input name="nome" required style="font:16px system-ui;padding:8px;width:100%"></label></p>
    <p><label>Seu papel no condomínio<br><select name="papel" style="font:16px system-ui;padding:8px;width:100%">
      <option value="morador">Morador</option><option value="portaria">Portaria</option><option value="sindico">Síndico</option>
      <option value="todos">Pré-teste: fazer todas as tarefas</option></select></label></p>
    <p><label>Faixa de idade<br><select name="idade" style="font:16px system-ui;padding:8px;width:100%">
      <option>18–29</option><option>30–44</option><option>45–59</option><option>60 ou mais</option><option>Prefiro não informar</option></select></label></p>`);
}

const resultados = [];
for (const tarefa of TAREFAS.filter((t) => pessoa.papel === 'todos' || t.papel === pessoa.papel)) {
  const r = await executar(tarefa);
  resultados.push(r);
  console.log(`${r.id}: ${r.sucesso ? 'concluída' : 'não concluída'} em ${r.segundos} s`);
}

let sus = '';
if (!ROBO) {
  const respostas = await formulario('Sua opinião sobre o sistema', `
    <p>Marque de 1 (discordo totalmente) a 5 (concordo totalmente).</p>${SUS.map((item, i) => `
    <fieldset style="border:0;border-top:1px solid #dde4e0;padding:12px 0;margin:0"><legend style="font-weight:600">${i + 1}. ${item}</legend>
      ${[1, 2, 3, 4, 5].map((n) => `<label style="margin-right:18px"><input type="radio" name="q${i + 1}" value="${n}" required> ${n}</label>`).join('')}</fieldset>`).join('')}`);
  // Pontuação do SUS: itens ímpares valem (resposta − 1); pares, (5 − resposta); a soma é multiplicada por 2,5.
  sus = SUS.reduce((soma, _, i) => soma + (i % 2 === 0 ? respostas[`q${i + 1}`] - 1 : 5 - respostas[`q${i + 1}`]), 0) * 2.5;
  console.log(`SUS: ${sus}`);
}
await navegador.close();

if (!fs.existsSync(ARQUIVO)) {
  fs.writeFileSync(ARQUIVO,'\uFEFFdata;participante;papel;faixa_idade;modo;T1_sucesso;T1_s;T2_sucesso;T2_s;T3_sucesso;T3_s;T4_sucesso;T4_s;T5_sucesso;T5_s;SUS\n');
}
const celula = (id, campo) => {
  const r = resultados.find((x) => x.id === id);
  return r ? (campo === 'sucesso' ? (r.sucesso ? 'sim' : 'não') : String(r.segundos).replace('.', ',')) : '';
};
fs.appendFileSync(ARQUIVO, [new Date().toLocaleString('pt-BR'), pessoa.nome.replace(/;/g, ','), pessoa.papel, pessoa.idade, ROBO ? 'automático' : 'pessoa',
  ...IDS.flatMap((id) => [celula(id, 'sucesso'), celula(id, 's')]), String(sus).replace('.', ',')].join(';') + '\n');
console.log(`Resultado acrescentado a ${ARQUIVO}\n`);
if (!ROBO) resumo();
