// Gera as capturas de tela usadas no TCC, a partir do banco de demonstração (dados fictícios).
//
//   node testes/capturas.mjs [pasta de saída]     (padrão: docs/telas)
//
// Recria a demonstração antes de começar (banco/demo.php não apaga banco com contas reais).
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const RSTACK = process.env.RSTACK || 'D:/RStack';
const BASE = process.env.BASE || 'http://tcc.test';
const PHP = process.env.PHP || `${RSTACK}/bin/php/php-8.5.11-nts-Win32-vs17-x64/php.exe`;
process.env.PLAYWRIGHT_BROWSERS_PATH ||= `${RSTACK}/bin/playwright/browsers`;
const { chromium } = createRequire(`${RSTACK}/bin/playwright/`)('playwright');

const RAIZ = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const SAIDA = path.resolve(process.argv[2] || path.join(RAIZ, 'docs/telas'));
fs.mkdirSync(SAIDA, { recursive: true });
execFileSync(PHP, ['banco/demo.php'], { cwd: RAIZ });

const navegador = await chromium.launch();
const DESKTOP = { viewport: { width: 1240, height: 800 }, deviceScaleFactor: 2 };
const CELULAR = { viewport: { width: 390, height: 780 }, deviceScaleFactor: 3, isMobile: true, hasTouch: true };

async function sessao(email, opcoes) {
  const pagina = await (await navegador.newContext(opcoes)).newPage();
  await pagina.goto(`${BASE}/entrar`);
  if (email) {
    await pagina.fill('#email', email);
    await pagina.fill('#senha', 'Demo@2026');
    await pagina.click('button[type=submit]');
    await pagina.waitForLoadState('networkidle');
  }
  return pagina;
}
const foto = async (pagina, nome, opcoes = { fullPage: true }) => {
  await pagina.evaluate(() => document.fonts.ready);
  await pagina.screenshot({ path: path.join(SAIDA, `${nome}.png`), ...opcoes });
  console.log('ok', nome);
};
/** Fotografa só a faixa vertical que vai do primeiro ao último seletor (para dividir páginas longas). */
const faixa = async (pagina, nome, seletores) => {
  // Coordenadas do documento (não da janela), porque a página pode estar rolada.
  const r = await pagina.evaluate((lista) => {
    const caixas = lista.map((s) => document.querySelector(s).getBoundingClientRect());
    const conteudo = document.querySelector('.Content').getBoundingClientRect();
    return {
      x: conteudo.left + window.scrollX,
      y: Math.min(...caixas.map((c) => c.top)) + window.scrollY - 12,
      width: conteudo.width,
      height: Math.max(...caixas.map((c) => c.bottom)) - Math.min(...caixas.map((c) => c.top)) + 24,
    };
  }, seletores);
  await foto(pagina, nome, { fullPage: true, clip: r });
};

// ----- Entrada -----
let p = await sessao(null, DESKTOP);
await foto(p, 'tela_entrar');
await p.fill('#email', 'morador1@demo.test');
await p.fill('#senha', 'senha-errada');
await p.click('button[type=submit]');
await p.waitForLoadState('networkidle');
await foto(p, 'tela_entrar_erro');
await p.context().close();

// ----- Morador, no celular -----
p = await sessao('morador1@demo.test', CELULAR);
await p.goto(`${BASE}/ocorrencias/nova`);
await p.selectOption('#categoria_id', { label: 'Vazamento ou infiltração' });
await p.selectOption('#local_id', { label: 'Garagem' });
await p.fill('#referencia_local', 'Perto das vagas de visitantes');
await p.selectOption('#urgencia', 'alta');
await p.fill('#descricao', 'Goteira no teto da garagem, molhando o piso perto das vagas de visitantes.');
await p.setInputFiles('#fotos', path.join(RAIZ, 'testes/foto_teste.jpg'));
await foto(p, 'cel_registrar');
await p.click('form.Card button[type=submit]');
await p.waitForURL(/ocorrencias\/\d+$/);
const nova = p.url().match(/(\d+)$/)[1];
await foto(p, 'cel_registrada');
await p.goto(`${BASE}/ocorrencias`);
await foto(p, 'cel_minhas');
await p.click('[data-menu]');
await p.waitForTimeout(400);
await foto(p, 'cel_menu', { fullPage: false });
await p.goto(`${BASE}/ocorrencias/nova`);
await p.evaluate(() => document.querySelector('form.Card').setAttribute('novalidate', ''));
await p.fill('#descricao', 'Barulho');
await p.click('form.Card button[type=submit]');
await p.waitForLoadState('networkidle');
await foto(p, 'cel_registrar_erro');
const antiga = (await p.goto(`${BASE}/ocorrencias`), await p.locator('table.is-lista tbody tr a').last().getAttribute('href'));
await p.goto(BASE + antiga);
await foto(p, 'cel_ocorrencia');
await p.goto(`${BASE}/avisos`);
await foto(p, 'cel_avisos');
await p.goto(`${BASE}/conta`);
await foto(p, 'cel_conta');
await p.context().close();

// ----- Portaria -----
p = await sessao('portaria@demo.test', DESKTOP);
await foto(p, 'portaria_dia');
await p.goto(`${BASE}/ocorrencias/${nova}`);
await foto(p, 'portaria_ocorrencia');
await p.goto(`${BASE}/ocorrencias/nova`);
await foto(p, 'portaria_registrar');
await p.context().close();

// ----- Síndico -----
p = await sessao('sindico@demo.test', DESKTOP);
await faixa(p, 'painel_atencao', ['.PageHead', 'section[aria-label="Indicadores principais"]']);
await faixa(p, 'painel_categorias', ['section[aria-labelledby=t-situacao]', 'section[aria-labelledby=t-pareto]']);
await p.locator('.Bars-col').nth(6).hover();
await faixa(p, 'painel_mes', ['section[aria-labelledby=t-mes]']);
await faixa(p, 'painel_mapa', ['section[aria-labelledby=t-mapa]']);
await p.goto(`${BASE}/ocorrencias?origem=sistema`);
await foto(p, 'sindico_ocorrencias');
await p.click('[data-abrir=exportar]');
await p.waitForTimeout(300);
await foto(p, 'sindico_exportar', { fullPage: false });
await p.goto(`${BASE}/ocorrencias/${nova}`);
await p.selectOption('#status', 'em_analise');
await p.fill('#comentario', 'Recebido. A empresa de manutenção vem avaliar amanhã cedo.');
await foto(p, 'sindico_responder');
await p.click('form[aria-labelledby=t-responder] button[type=submit]');
await p.waitForLoadState('networkidle');
await foto(p, 'sindico_respondida');
await p.goto(`${BASE}/importar`);
await foto(p, 'sindico_importar');
await p.setInputFiles('#arquivo', { name: 'livro.csv', mimeType: 'text/csv', buffer: Buffer.from(
  'id;data;hora;dia_semana;turno;registrante;local;bloco_andar;categoria;descricao_resumo;urgencia_percebida;houve_resposta;data_resposta;desfecho;recorrencia;completo\r\n'
  + '9101;05/04/2025;21:30;;;morador;Garagem;;Barulho;Linha correta;média;não;;sem registro;;sim\r\n'
  + '9102;31/02/2025;25:99;;;morador;Garagem;;Barulho;Data e hora impossíveis;urgente;talvez;;;;sim\r\n') });
await p.click('form.Card button[type=submit]');
await p.waitForLoadState('networkidle');
await foto(p, 'sindico_importar_erro');
await p.goto(`${BASE}/usuarios`);
await foto(p, 'sindico_usuarios');
await p.goto(`${BASE}/usuarios/novo`);
await foto(p, 'sindico_usuario_novo');
await p.goto(`${BASE}/acessos?ocorrencia=${nova}`);
await foto(p, 'sindico_acessos');
await p.goto(`${BASE}/avisos`);
await foto(p, 'sindico_avisos');
await p.context().close();

p = await sessao('sindico@demo.test', CELULAR);
await foto(p, 'cel_painel');
await p.context().close();

await navegador.close();
console.log(`Capturas em ${SAIDA}`);
