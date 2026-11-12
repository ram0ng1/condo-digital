-- Livro de Ocorrências Digital: esquema do banco de dados (MariaDB / MySQL).
-- Aplicado por banco/instalar.php. Datas e horas são gravadas pela aplicação (RNF06).

CREATE TABLE unidades (
    id      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    bloco   VARCHAR(10)  NOT NULL,
    numero  VARCHAR(10)  NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_unidade (bloco, numero)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE usuarios (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nome              VARCHAR(100) NOT NULL,
    email             VARCHAR(150) NOT NULL,
    senha_hash        VARCHAR(255) NOT NULL,
    perfil            ENUM('morador','portaria','sindico') NOT NULL,
    unidade_id        INT UNSIGNED NULL,
    ativo             TINYINT(1)   NOT NULL DEFAULT 1,
    trocar_senha      TINYINT(1)   NOT NULL DEFAULT 0,
    tentativas_falhas TINYINT UNSIGNED NOT NULL DEFAULT 0,
    bloqueado_ate     DATETIME     NULL,
    criado_em         DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_usuario_email (email),
    CONSTRAINT fk_usuario_unidade FOREIGN KEY (unidade_id) REFERENCES unidades (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE categorias (
    id    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nome  VARCHAR(60)  NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_categoria_nome (nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE locais (
    id    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nome  VARCHAR(60)  NOT NULL,
    tipo  ENUM('comum','garagem','unidade') NOT NULL DEFAULT 'comum',
    PRIMARY KEY (id),
    UNIQUE KEY uq_local_nome (nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ocorrencias (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    origem          ENUM('sistema','livro_fisico') NOT NULL DEFAULT 'sistema',
    numero_livro    INT UNSIGNED NULL,
    autor_id        INT UNSIGNED NULL,
    registrante     ENUM('morador','portaria','zeladoria','sindico','nao_identificado') NOT NULL,
    unidade_id      INT UNSIGNED NULL,
    categoria_id    INT UNSIGNED NOT NULL,
    local_id        INT UNSIGNED NOT NULL,
    referencia_local VARCHAR(60) NULL,
    descricao       TEXT         NOT NULL,
    urgencia        ENUM('baixa','media','alta') NOT NULL DEFAULT 'media',
    status          ENUM('aberta','em_analise','resolvida','arquivada') NOT NULL DEFAULT 'aberta',
    registrada_em   DATETIME     NULL,
    hora_informada  TINYINT(1)   NOT NULL DEFAULT 1,
    houve_resposta  TINYINT(1)   NOT NULL DEFAULT 0,
    respondida_em   DATETIME     NULL,
    completo        TINYINT(1)   NOT NULL DEFAULT 1,
    anonimizada_em  DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ocorrencia_livro (numero_livro),
    KEY ix_ocorrencia_data (registrada_em),
    KEY ix_ocorrencia_status (status),
    KEY ix_ocorrencia_autor (autor_id),
    CONSTRAINT fk_ocorrencia_autor     FOREIGN KEY (autor_id)     REFERENCES usuarios (id),
    CONSTRAINT fk_ocorrencia_unidade   FOREIGN KEY (unidade_id)   REFERENCES unidades (id),
    CONSTRAINT fk_ocorrencia_categoria FOREIGN KEY (categoria_id) REFERENCES categorias (id),
    CONSTRAINT fk_ocorrencia_local     FOREIGN KEY (local_id)     REFERENCES locais (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE movimentacoes (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    ocorrencia_id   INT UNSIGNED NOT NULL,
    usuario_id      INT UNSIGNED NULL,
    tipo            ENUM('registro','status','complemento') NOT NULL,
    status_anterior ENUM('aberta','em_analise','resolvida','arquivada') NULL,
    status_novo     ENUM('aberta','em_analise','resolvida','arquivada') NOT NULL,
    comentario      TEXT         NOT NULL,
    registrada_em   DATETIME     NOT NULL,
    PRIMARY KEY (id),
    KEY ix_movimentacao_ocorrencia (ocorrencia_id),
    CONSTRAINT fk_movimentacao_ocorrencia FOREIGN KEY (ocorrencia_id) REFERENCES ocorrencias (id),
    CONSTRAINT fk_movimentacao_usuario    FOREIGN KEY (usuario_id)    REFERENCES usuarios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE anexos (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    ocorrencia_id   INT UNSIGNED NOT NULL,
    caminho_arquivo VARCHAR(100) NOT NULL,
    enviado_em      DATETIME     NOT NULL,
    PRIMARY KEY (id),
    KEY ix_anexo_ocorrencia (ocorrencia_id),
    CONSTRAINT fk_anexo_ocorrencia FOREIGN KEY (ocorrencia_id) REFERENCES ocorrencias (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE registros_acesso (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    usuario_id    INT UNSIGNED NULL,
    ocorrencia_id INT UNSIGNED NULL,
    acao          ENUM('entrar','entrar_falha','sair','ler','criar','alterar_status',
                       'complementar','exportar','importar','gerenciar_usuario','trocar_senha','anonimizar') NOT NULL,
    detalhe       VARCHAR(255) NULL,
    ocorrido_em   DATETIME     NOT NULL,
    PRIMARY KEY (id),
    KEY ix_acesso_ocorrencia (ocorrencia_id),
    KEY ix_acesso_usuario (usuario_id),
    KEY ix_acesso_data (ocorrido_em),
    CONSTRAINT fk_acesso_usuario    FOREIGN KEY (usuario_id)    REFERENCES usuarios (id),
    CONSTRAINT fk_acesso_ocorrencia FOREIGN KEY (ocorrencia_id) REFERENCES ocorrencias (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE avisos (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    usuario_id    INT UNSIGNED NOT NULL,
    ocorrencia_id INT UNSIGNED NULL,
    mensagem      VARCHAR(255) NOT NULL,
    criado_em     DATETIME     NOT NULL,
    lido_em       DATETIME     NULL,
    PRIMARY KEY (id),
    KEY ix_aviso_usuario (usuario_id, lido_em),
    CONSTRAINT fk_aviso_usuario    FOREIGN KEY (usuario_id)    REFERENCES usuarios (id),
    CONSTRAINT fk_aviso_ocorrencia FOREIGN KEY (ocorrencia_id) REFERENCES ocorrencias (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- RG2 (imutabilidade): o histórico não pode ser alterado nem apagado, nem por quem tem
-- acesso direto ao banco. A única exceção é a rotina de retenção (RG5), que liga @retencao.
CREATE TRIGGER movimentacoes_sem_alteracao BEFORE UPDATE ON movimentacoes FOR EACH ROW
    BEGIN IF @retencao IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'RG2: movimentacao nao pode ser alterada'; END IF; END;

CREATE TRIGGER movimentacoes_sem_exclusao BEFORE DELETE ON movimentacoes FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'RG2: movimentacao nao pode ser excluida';

CREATE TRIGGER ocorrencias_sem_exclusao BEFORE DELETE ON ocorrencias FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'RG2: ocorrencia nao pode ser excluida';

CREATE TRIGGER ocorrencias_sem_reescrita BEFORE UPDATE ON ocorrencias FOR EACH ROW
    BEGIN IF @retencao IS NULL AND (NEW.descricao <> OLD.descricao OR NOT (NEW.registrada_em <=> OLD.registrada_em)
            OR NEW.categoria_id <> OLD.categoria_id OR NEW.local_id <> OLD.local_id OR NOT (NEW.autor_id <=> OLD.autor_id))
        THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'RG2: o registro da ocorrencia nao pode ser reescrito'; END IF; END;

CREATE TRIGGER acessos_sem_alteracao BEFORE UPDATE ON registros_acesso FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'RNF02: registro de acesso nao pode ser alterado';

CREATE TRIGGER acessos_sem_exclusao BEFORE DELETE ON registros_acesso FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'RNF02: registro de acesso nao pode ser excluido';
