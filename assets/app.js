// Comportamentos da interface. As páginas funcionam sem JavaScript, exceto o menu no celular
// e a janela de confirmação da exportação.

// Menu lateral no celular
const botaoMenu = document.querySelector('[data-menu]');
const fecharMenu = () => {
  document.body.classList.remove('menu-aberto');
  botaoMenu?.setAttribute('aria-expanded', 'false');
};
botaoMenu?.addEventListener('click', () => {
  const aberto = document.body.classList.toggle('menu-aberto');
  botaoMenu.setAttribute('aria-expanded', String(aberto));
  if (aberto) document.querySelector('.Sidebar a')?.focus();
});
document.addEventListener('click', (e) => {
  if (document.body.classList.contains('menu-aberto') && !e.target.closest('.Sidebar, [data-menu]')) fecharMenu();
});
document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') fecharMenu();
});

// Janelas de confirmação (<dialog>)
document.querySelectorAll('[data-abrir]').forEach((botao) => {
  botao.addEventListener('click', () => document.getElementById(botao.dataset.abrir).showModal());
});
document.querySelectorAll('[data-fechar]').forEach((botao) => {
  botao.addEventListener('click', () => botao.closest('dialog').close());
});

// Limite de fotos por ocorrência (o servidor confere de novo)
const fotos = document.querySelector('input[type="file"][data-max]');
fotos?.addEventListener('change', () => {
  const max = Number(fotos.dataset.max);
  fotos.setCustomValidity(fotos.files.length > max ? `Escolha no máximo ${max} fotos.` : '');
  fotos.reportValidity();
});

// Depois de um erro, leva o cursor direto ao primeiro campo que precisa de correção.
document.querySelector('[aria-invalid="true"]')?.focus();

// Evita o envio em dobro e mostra que o pedido está em andamento.
document.querySelectorAll('form[method="post"]').forEach((form) => {
  form.addEventListener('submit', () => {
    const janela = form.closest('dialog');
    if (janela) {
      // A exportação baixa um arquivo sem sair da página: basta fechar a janela.
      setTimeout(() => { janela.close(); form.reset(); }, 400);
      return;
    }
    form.querySelectorAll('button[type="submit"]').forEach((botao) => {
      botao.disabled = true;
      botao.classList.add('is-loading');
    });
  });
});
// Ao voltar pelo navegador, a página reaparece com os botões liberados.
window.addEventListener('pageshow', () => {
  document.querySelectorAll('button.is-loading').forEach((botao) => {
    botao.disabled = false;
    botao.classList.remove('is-loading');
  });
});

// Contador de caracteres da descrição
document.querySelectorAll('textarea[data-contador]').forEach((campo) => {
  const contador = document.createElement('span');
  contador.className = 'Field-help Contador';
  const atualizar = () => {
    const falta = campo.minLength - campo.value.trim().length;
    contador.textContent = falta > 0
      ? `Faltam ${falta} caracteres para o mínimo.`
      : `${campo.value.length} de ${campo.maxLength} caracteres.`;
  };
  campo.addEventListener('input', atualizar);
  campo.after(contador);
  atualizar();
});

// Mostrar a senha digitada (útil no celular)
document.querySelectorAll('[data-mostrar-senha]').forEach((caixa) => {
  caixa.addEventListener('change', () => {
    document.getElementById(caixa.dataset.mostrarSenha).type = caixa.checked ? 'text' : 'password';
  });
});
