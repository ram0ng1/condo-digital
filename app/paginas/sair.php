<?php
if (usuario()) {
    auditar('sair');
}
session_unset();
session_destroy();
redirecionar('entrar');
