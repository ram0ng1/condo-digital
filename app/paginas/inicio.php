<?php
// Cada perfil cai na tela que mais usa: o síndico no painel, os demais nas ocorrências.
$u = exigir_login();
redirecionar($u['perfil'] === 'sindico' ? 'painel' : 'ocorrencias');
