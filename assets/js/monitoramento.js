(function () {

    var AUTO_REFRESH_MS = 30 * 60 * 1000; // 30 minutos
    var lastData = null;

    // ── Abas Chamados abertos/Tarefas — painel único, só uma aba visível por vez ──
    // Padrão: Chamados abertos (o mais checado com mais frequência).
    var monAbaAtiva = 'cha';

    function monAtualizarAbas() {
        var tabCha  = document.getElementById('mon-tab-cha');
        var tabTsk  = document.getElementById('mon-tab-tsk');
        var contCha = document.getElementById('mon-tab-content-cha');
        var contTsk = document.getElementById('mon-tab-content-tsk');
        var filCha  = document.getElementById('cha-filtros');
        var filTsk  = document.getElementById('tsk-filter-row');
        if (!tabCha || !tabTsk || !contCha || !contTsk) return;

        tabCha.classList.toggle('active', monAbaAtiva === 'cha');
        tabTsk.classList.toggle('active', monAbaAtiva === 'tsk');
        contCha.style.display = monAbaAtiva === 'cha' ? 'flex' : 'none';
        contTsk.style.display = monAbaAtiva === 'tsk' ? 'flex' : 'none';
        if (filCha) filCha.style.display = monAbaAtiva === 'cha' ? 'flex' : 'none';
        if (filTsk) filTsk.style.display = monAbaAtiva === 'tsk' ? 'flex' : 'none';
    }

    window.monTrocarAba = function (aba) {
        if (monAbaAtiva === aba) return;
        monAbaAtiva = aba;
        monAtualizarAbas();
    };

    monAtualizarAbas();

    function escHtml(s) {
        return String(s)
            .replace(/&/g,'&amp;').replace(/</g,'&lt;')
            .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    // "1 chamado" / "2 chamados" — plural correto, mais legível que só o número cru.
    function fmtChamados(n) {
        return n + (n === 1 ? ' chamado' : ' chamados');
    }

    // Card por pessoa: nome no topo + 2 linhas empilhadas (Suporte acima, Desenvolvimento
    // abaixo) — sem repetir a palavra "Suporte"/"Desenvolvimento" (só a cor identifica, o
    // texto por extenso já está na legenda do topo, ver renderEquipeTotal()). "Em andamento"
    // foi removido daqui (só da UI — a query em MonitoramentoEquipeService.php continua
    // intacta, ver relatório).
    function membroCardHtml(m, idx) {
        var fin       = m.finalizado || {};
        var finSupMin = (fin.suporte && fin.suporte.minutos) || 0;
        var finDevMin = (fin.desenvolvimento && fin.desenvolvimento.minutos) || 0;
        var finSupCnt = (fin.suporte && fin.suporte.count) || 0;
        var finDevCnt = (fin.desenvolvimento && fin.desenvolvimento.count) || 0;

        return '<div class="mon-membro-card">'
            + '<div class="mon-membro-nome">' + escHtml(m.nome) + '</div>'
            + '<div class="mon-membro-valor suporte" onclick="monAbrirDrill(' + idx + ',\'finalizado\',\'suporte\')"><span class="n">' + fmtChamados(finSupCnt) + '</span> — ' + fmtMinutos(finSupMin) + '</div>'
            + '<div class="mon-membro-valor dev" onclick="monAbrirDrill(' + idx + ',\'finalizado\',\'desenvolvimento\')"><span class="n">' + fmtChamados(finDevCnt) + '</span> — ' + fmtMinutos(finDevMin) + '</div>'
            + '</div>';
    }

    // Legenda do topo (mesma linha do título "Equipe") — palavras por extenso, com um
    // separador visível entre os dois totais.
    function renderEquipeTotal(totalMinutos) {
        var el = document.getElementById('mon-equipe-total');
        if (!el) return;
        if (!totalMinutos) { el.innerHTML = ''; return; }

        el.innerHTML =
            '<span class="mon-equipe-total-item suporte">Suporte — ' + fmtMinutos(totalMinutos.suporte || 0) + '</span>'
            + '<span class="mon-equipe-total-sep">|</span>'
            + '<span class="mon-equipe-total-item dev">Desenvolvimento — ' + fmtMinutos(totalMinutos.desenvolvimento || 0) + '</span>';
    }

    function render(data) {
        var grid = document.getElementById('mon-equipe-grid');

        if (data.aviso) {
            grid.innerHTML = '<div class="mon-empty"><i class="fas fa-plug"></i><div>' + escHtml(data.aviso) + '</div></div>';
            renderEquipeTotal(null);
            return;
        }

        renderEquipeTotal(data.totalFinalizadoMinutos);

        var equipe = data.equipe || [];
        if (!equipe.length) {
            grid.innerHTML = '<div class="mon-empty"><i class="fas fa-inbox"></i><div>Nenhum dado disponível.</div></div>';
            return;
        }

        grid.innerHTML = equipe.map(membroCardHtml).join('');
    }

    // ── Drill-down: lista de chamados (com ID clicável para o Bitrix24) por trás de um segmento ──
    var ROW_LABELS    = { finalizado: 'Finalizado no ciclo' };
    var BUCKET_LABELS = { suporte: 'Suporte', desenvolvimento: 'Desenvolvimento' };

    function bitrixCardUrl(id) {
        var base = (lastData && lastData.bitrixBase) || '';
        return base ? (base + '/crm/type/1054/details/' + id + '/') : '';
    }

    function drillItemHtml(card, rowKey) {
        var url = bitrixCardUrl(card.id);
        var timeHtml = (rowKey === 'finalizado')
            ? '<span class="mon-drill-time">' + (card.minutos || 0) + ' min</span>'
            : '';
        var body = '<span class="mon-drill-item-main">'
            + '<span class="mon-drill-id">#' + card.id + '</span>'
            + '<span class="mon-drill-titletext">' + escHtml(card.title || '') + '</span>'
            + '</span>'
            + timeHtml
            + '<i class="fas fa-external-link-alt" style="flex-shrink:0"></i>';

        return url
            ? '<a class="mon-drill-item" href="' + escHtml(url) + '" target="_blank" rel="noopener">' + body + '</a>'
            : '<div class="mon-drill-item" style="cursor:default">' + body + '</div>';
    }

    window.monAbrirDrill = function (personIdx, rowKey, bucketKey) {
        if (!lastData || !lastData.equipe || !lastData.equipe[personIdx]) return;

        var membro = lastData.equipe[personIdx];
        var bucket = (membro[rowKey] || {})[bucketKey] || {};
        var cards  = bucket.cards || [];

        document.getElementById('mon-drill-title').textContent =
            membro.nome + ' — ' + ROW_LABELS[rowKey] + ' — ' + BUCKET_LABELS[bucketKey];
        document.getElementById('mon-drill-subtitle').textContent =
            cards.length + ' chamado' + (cards.length !== 1 ? 's' : '');

        var listEl = document.getElementById('mon-drill-list');
        listEl.innerHTML = cards.length
            ? cards.map(function (c) { return drillItemHtml(c, rowKey); }).join('')
            : '<div style="color:rgba(255,255,255,.35);font-size:.82rem;padding:.5rem 0">Nenhum chamado encontrado.</div>';

        document.getElementById('mon-drill-overlay').style.display = 'flex';
    };

    window.monFecharDrill = function () {
        document.getElementById('mon-drill-overlay').style.display = 'none';
    };

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') monFecharDrill();
    });

    // ── Painel Tarefas (Bitrix24 Tasks — fonte separada do SPA 1054) ──────────────
    var lastTarefas     = null;
    var tskSelectedUids = null; // Set — inicializado no primeiro carregamento (todos selecionados)

    function fmtDataHora(iso) {
        if (!iso) return '';
        var d = new Date(iso);
        if (isNaN(d.getTime())) return '';
        return d.toLocaleDateString('pt-BR') + ' ' + d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
    }

    function primeiroNome(nome) {
        return (nome || '').split(' ')[0];
    }

    // Papel não repetido no chip — a coluna (Participantes/Observadores, ver .tsk-thead) já
    // diz qual é. Uma pessoa com os dois papéis na mesma tarefa aparece nas duas colunas.
    function tskBadgeHtml(b) {
        return '<span class="tsk-badge ' + (b.intensidade || 'media') + '">'
            + escHtml(primeiroNome(b.nome)) + '</span>';
    }

    // Criador/Responsável aparecem sempre, cada um na sua própria coluna (ver .tsk-thead) —
    // mesmo quando não são um dos 4 da equipe. Só o destaque visual (forte = verde) muda
    // conforme pessoa.ehEquipe (ver MonitoramentoTarefasService).
    function tskPessoaChipHtml(pessoa) {
        if (!pessoa || !pessoa.bitrixUserId) {
            return '<span style="color:rgba(255,255,255,.3);font-size:.72rem">—</span>';
        }
        var classe = pessoa.ehEquipe ? 'forte' : 'externo';
        return '<span class="tsk-badge ' + classe + '" title="' + escHtml(pessoa.nome) + '">'
            + escHtml(primeiroNome(pessoa.nome)) + '</span>';
    }

    function tskChatMsgHtml(c) {
        return '<div class="tsk-chat-msg">'
            + '<div class="tsk-chat-msg-head">'
                + '<span class="tsk-chat-msg-autor">' + escHtml(c.autor) + '</span>'
                + '<span class="tsk-chat-msg-data">' + escHtml(fmtDataHora(c.data)) + '</span>'
            + '</div>'
            + '<div class="tsk-chat-msg-texto">' + escHtml(c.mensagem) + '</div>'
            + '</div>';
    }

    function tskRowHtml(t) {
        var url = (lastTarefas && lastTarefas.bitrixBase && t.responsibleId)
            ? lastTarefas.bitrixBase + '/company/personal/user/' + t.responsibleId + '/tasks/task/view/' + t.id + '/'
            : '';
        var idHtml = url
            ? '<a class="tsk-row-id" href="' + escHtml(url) + '" target="_blank" rel="noopener" onclick="event.stopPropagation()">#' + t.id + '</a>'
            : '<span class="tsk-row-id">#' + t.id + '</span>';

        // Cor de alerta já comunica atraso — sem rótulo de texto redundante.
        var deadlineHtml = t.deadline
            ? '<span class="tsk-deadline' + (t.atrasada ? ' atrasada' : '') + '">' + escHtml(fmtDataHora(t.deadline)) + '</span>'
            : '<span class="tsk-deadline">Sem prazo</span>';

        var chatIcon = t.temChat
            ? '<i class="fas fa-comment-dots tsk-chat-icon" title="Ver mensagens" onclick="event.stopPropagation();tskAbrirChat(' + t.id + ')"></i>'
            : '';
        var participantesHtml = (t.badges || [])
            .filter(function (b) { return (b.papeis || []).indexOf('Participante') !== -1; })
            .map(tskBadgeHtml).join('');
        var observadoresHtml = (t.badges || [])
            .filter(function (b) { return (b.papeis || []).indexOf('Observador') !== -1; })
            .map(tskBadgeHtml).join('');

        var descricaoHtml = t.descricao
            ? '<div class="tsk-detail-label">Descrição</div><div class="tsk-detail-text">' + escHtml(t.descricao) + '</div>'
            : '<div class="tsk-detail-text" style="color:rgba(255,255,255,.35)">Sem descrição.</div>';

        var prazoDetalheHtml = t.deadline ? escHtml(fmtDataHora(t.deadline)) : 'Sem prazo definido';

        return '<div class="tsk-row">'
            + '<div class="tsk-row-main" onclick="tskToggle(' + t.id + ')">'
                + '<button class="tsk-chevron-btn" id="tsk-btn-' + t.id + '"><i class="fas fa-chevron-right" style="font-size:.7rem"></i></button>'
                + idHtml
                + '<span class="tsk-row-title">' + escHtml(t.titulo) + '</span>'
                + '<span class="tsk-criado" title="' + escHtml(fmtDiaMes((t.criadoEm || '').slice(0, 10))) + '">' + escHtml(fmtDiaMes((t.criadoEm || '').slice(0, 10))) + '</span>'
                + '<span class="tsk-pessoa-cell">' + tskPessoaChipHtml(t.criador) + '</span>'
                + '<span class="tsk-pessoa-cell">' + tskPessoaChipHtml(t.responsavel) + '</span>'
                + '<span class="tsk-outros">' + participantesHtml + '</span>'
                + '<span class="tsk-outros">' + observadoresHtml + '</span>'
                + deadlineHtml
                + chatIcon
            + '</div>'
            + '<div class="tsk-row-detail" id="tsk-detail-' + t.id + '">'
                + '<div class="tsk-detail-inner">'
                    + descricaoHtml
                    + '<div class="tsk-detail-label">Prazo</div><div class="tsk-detail-text">' + prazoDetalheHtml + '</div>'
                + '</div>'
            + '</div>'
            + '</div>';
    }

    // ── Modal de chat — componente compartilhado entre Tarefas e Chamados abertos ────
    function abrirChatModal(titulo, comentarios, chatErro) {
        document.getElementById('tsk-chat-title').textContent = titulo;
        var listEl = document.getElementById('tsk-chat-list');

        if (chatErro) {
            listEl.innerHTML = '<div style="color:rgba(255,255,255,.35);font-size:.82rem;padding:.5rem 0">Sem permissão para acessar este chat.</div>';
        } else if (comentarios && comentarios.length) {
            listEl.innerHTML = comentarios.map(tskChatMsgHtml).join('');
        } else {
            listEl.innerHTML = '<div style="color:rgba(255,255,255,.35);font-size:.82rem;padding:.5rem 0">Nenhuma mensagem.</div>';
        }

        document.getElementById('tsk-chat-overlay').style.display = 'flex';
    }

    window.tskAbrirChat = function (id) {
        if (!lastTarefas || !lastTarefas.tarefas) return;
        var tarefa = lastTarefas.tarefas.filter(function (t) { return t.id === id; })[0];
        if (!tarefa) return;
        abrirChatModal(tarefa.titulo, tarefa.comentarios, null); // chat de tarefa não tem chatErro — sempre acessível
    };

    window.chaAbrirChat = function (id) {
        if (!lastChamados || !lastChamados.chamados) return;
        var chamado = lastChamados.chamados.filter(function (c) { return c.id === id; })[0];
        if (!chamado) return;
        abrirChatModal(chamado.titulo, chamado.comentarios, chamado.chatErro);
    };

    window.tskFecharChat = function () {
        document.getElementById('tsk-chat-overlay').style.display = 'none';
    };

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') tskFecharChat();
    });

    // ── Config dos webhooks pessoais (Atendimento) — CRUD via modal ───────────────
    var monWebhookEditandoId = null;

    function monWebhookFeedback(msg, classe) {
        var fb = document.getElementById('mon-webhooks-feedback');
        fb.textContent = msg;
        fb.className = classe;
        if (classe === 'ok') setTimeout(function () { fb.textContent = ''; }, 4000);
    }

    function monWebhookLimparForm() {
        monWebhookEditandoId = null;
        document.getElementById('mon-webhook-nome').value = '';
        document.getElementById('mon-webhook-url').value = '';
        document.getElementById('mon-webhook-url').placeholder = 'https://.../rest/.../TOKEN/';
        document.getElementById('mon-webhooks-submit-label').textContent = 'Adicionar';
    }

    function monWebhookRowHtml(p) {
        var nomeEscapado = escHtml(p.nome).replace(/'/g, "\\'");
        return '<div class="mon-webhook-row">'
            + '<span class="mon-webhook-nome" title="' + escHtml(p.nome) + '">' + escHtml(p.nome) + '</span>'
            + '<span class="mon-webhook-url" title="' + escHtml(p.webhookMascarado) + '">' + escHtml(p.webhookMascarado) + '</span>'
            + '<span class="mon-webhook-acoes">'
                + '<button onclick="monEditarWebhookPessoal(\'' + p.id + '\',\'' + nomeEscapado + '\')" title="Editar"><i class="fas fa-pen"></i></button>'
                + '<button onclick="monRemoverWebhookPessoal(\'' + p.id + '\')" title="Remover"><i class="fas fa-trash"></i></button>'
            + '</span>'
            + '</div>';
    }

    function monRenderWebhooksPessoais(pessoas) {
        var el = document.getElementById('mon-webhooks-list');
        el.innerHTML = (pessoas && pessoas.length)
            ? pessoas.map(monWebhookRowHtml).join('')
            : '<div class="mon-empty" style="padding:1.5rem 0"><div>Nenhum webhook pessoal cadastrado ainda — o Atendimento usa só o webhook de automação até você cadastrar o primeiro.</div></div>';
    }

    function monCarregarWebhooksPessoais() {
        return fetch('/api/monitoramento-webhooks-pessoais.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ acao: 'listar' })
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.erro) {
                    document.getElementById('mon-webhooks-list').innerHTML =
                        '<div class="mon-empty" style="color:#fc8181">' + escHtml(data.erro) + '</div>';
                    return;
                }
                monRenderWebhooksPessoais(data.pessoas || []);
            })
            .catch(function () {
                document.getElementById('mon-webhooks-list').innerHTML =
                    '<div class="mon-empty" style="color:#fc8181">Erro de comunicação.</div>';
            });
    }

    window.monAbrirConfigWebhooks = function () {
        monWebhookLimparForm();
        document.getElementById('mon-webhooks-feedback').textContent = '';
        document.getElementById('mon-webhooks-overlay').style.display = 'flex';
        monCarregarWebhooksPessoais();
    };

    window.monFecharConfigWebhooks = function () {
        document.getElementById('mon-webhooks-overlay').style.display = 'none';
    };

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') monFecharConfigWebhooks();
    });

    window.monEditarWebhookPessoal = function (id, nomeAtual) {
        monWebhookEditandoId = id;
        document.getElementById('mon-webhook-nome').value = nomeAtual;
        document.getElementById('mon-webhook-url').value = '';
        document.getElementById('mon-webhook-url').placeholder = 'Deixe em branco pra manter o webhook atual';
        document.getElementById('mon-webhooks-submit-label').textContent = 'Salvar edição';
        document.getElementById('mon-webhooks-feedback').textContent = '';
    };

    window.monRemoverWebhookPessoal = function (id) {
        fetch('/api/monitoramento-webhooks-pessoais.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ acao: 'remover', id: id })
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.erro) { monWebhookFeedback(data.erro, 'erro'); return; }
                monRenderWebhooksPessoais(data.pessoas || []);
                if (monWebhookEditandoId === id) monWebhookLimparForm();
            })
            .catch(function () { monWebhookFeedback('Erro de comunicação.', 'erro'); });
    };

    // Dispara ao sair do campo de URL (colar/tab) — valida o webhook de verdade via
    // user.current (ver WebhooksPessoaisAtendimento::buscarNomeConta()) e pré-preenche o nome
    // com a conta real dona do webhook, em vez de depender do que foi digitado a mão. O campo
    // continua editável depois — isso só sugere o valor correto. Em modo edição mantendo o
    // webhook atual (campo em branco), não há nada pra validar.
    window.monValidarWebhookPessoal = function () {
        var url = document.getElementById('mon-webhook-url').value.trim();
        if (!url) return;
        if (url.indexOf('https://') !== 0) {
            monWebhookFeedback('Webhook deve começar com https://', 'erro');
            return;
        }

        monWebhookFeedback('Verificando webhook…', '');
        fetch('/api/monitoramento-webhooks-pessoais.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ acao: 'validar', webhookUrl: url })
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.erro) { monWebhookFeedback(data.erro, 'erro'); return; }
                document.getElementById('mon-webhook-nome').value = data.nome;
                monWebhookFeedback('Nome preenchido automaticamente: ' + data.nome + ' (pode editar antes de salvar)', 'ok');
            })
            .catch(function () { monWebhookFeedback('Erro de comunicação ao validar o webhook.', 'erro'); });
    };

    window.monSalvarWebhookPessoal = function () {
        var nome = document.getElementById('mon-webhook-nome').value.trim();
        var url  = document.getElementById('mon-webhook-url').value.trim();

        if (!nome) { monWebhookFeedback('Nome é obrigatório.', 'erro'); return; }
        if (!monWebhookEditandoId && !url) { monWebhookFeedback('Webhook é obrigatório.', 'erro'); return; }

        var acao    = monWebhookEditandoId ? 'editar' : 'adicionar';
        var payload = { acao: acao, nome: nome, webhookUrl: url };
        if (monWebhookEditandoId) payload.id = monWebhookEditandoId;

        fetch('/api/monitoramento-webhooks-pessoais.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.erro) { monWebhookFeedback(data.erro, 'erro'); return; }
                monRenderWebhooksPessoais(data.pessoas || []);
                monWebhookLimparForm();
                monWebhookFeedback('Salvo com sucesso.', 'ok');
            })
            .catch(function () { monWebhookFeedback('Erro de comunicação.', 'erro'); });
    };

    // ── Filtro por pessoa (dropdown-checklist, mesmo padrão .cha-dropdown-* de Chamados
    // abertos/Atendimento — antes eram pills) — filtra por envolvimento como
    // Participante/Observador (ver tskEnvolveSelecionados() abaixo), não por
    // Responsável/Criador (esses são colunas sempre visíveis, não filtráveis, ver
    // tskPessoaChipHtml()). Roster fixo (os 4 da equipe monitorada), default todos
    // marcados — mesmo default de sempre, só a apresentação virou dropdown. ──────────
    window.tskToggleDropdown = function () {
        var el = document.getElementById('tsk-dropdown-pessoa');
        if (el) el.classList.toggle('open');
    };

    function renderFiltroPessoas(equipe) {
        var painel  = document.getElementById('tsk-dropdown-pessoa-panel');
        var badgeEl = document.getElementById('tsk-dropdown-pessoa-count');
        if (!painel) return;
        if (!equipe || !equipe.length) { painel.innerHTML = ''; if (badgeEl) badgeEl.textContent = '0'; return; }

        if (tskSelectedUids === null) {
            tskSelectedUids = new Set(equipe.map(function (p) { return p.bitrixUserId; }));
        }

        painel.innerHTML = equipe.map(function (p) {
            var ativo = tskSelectedUids.has(p.bitrixUserId);
            return '<label class="cha-dropdown-item">'
                + '<input type="checkbox"' + (ativo ? ' checked' : '') + ' onchange="tskToggleFiltro(' + p.bitrixUserId + ')">'
                + escHtml(p.nome)
                + '</label>';
        }).join('');

        if (badgeEl) badgeEl.textContent = tskSelectedUids.size;
    }

    window.tskToggleFiltro = function (uid) {
        if (!tskSelectedUids) return;
        if (tskSelectedUids.has(uid)) {
            if (tskSelectedUids.size <= 1) { renderFiltroPessoas(lastTarefas && lastTarefas.equipe); return; } // nunca deixa ficar com 0 selecionados
            tskSelectedUids.delete(uid);
        } else {
            tskSelectedUids.add(uid);
        }
        if (lastTarefas) {
            renderFiltroPessoas(lastTarefas.equipe);
            renderTarefas(lastTarefas);
        }
    };

    // Qualquer um dos 4 papéis conta (Responsável OU Participante OU Criador OU Observador) —
    // antes só casava Participante/Observador (via badges); Criador/Responsável ficavam de fora.
    function tskEnvolveSelecionados(t) {
        if (t.responsavel && tskSelectedUids.has(t.responsavel.bitrixUserId)) return true;
        if (t.criador && tskSelectedUids.has(t.criador.bitrixUserId)) return true;
        return (t.badges || []).some(function (b) { return tskSelectedUids.has(b.bitrixUserId); });
    }

    // ── Ordenação por coluna (Criador/Responsável/Participantes/Observadores/Prazo) ──
    var tskSortColuna = null; // 'criador' | 'responsavel' | 'participantes' | 'observadores' | 'prazo' | null
    var tskSortAsc    = true;

    function tskPrimeiroPorPapel(t, papel) {
        var lista = (t.badges || []).filter(function (b) { return (b.papeis || []).indexOf(papel) !== -1; });
        return lista.length ? lista[0].nome : '';
    }

    function tskValorOrdenacao(t, coluna) {
        if (coluna === 'criador')       return (t.criador && t.criador.nome) || '';
        if (coluna === 'responsavel')   return (t.responsavel && t.responsavel.nome) || '';
        if (coluna === 'participantes') return tskPrimeiroPorPapel(t, 'Participante');
        if (coluna === 'observadores')  return tskPrimeiroPorPapel(t, 'Observador');
        return '';
    }

    function tskAtualizarIconesOrdenacao() {
        document.querySelectorAll('#mon-tab-content-tsk .tsk-th-sort').forEach(function (el) {
            var icon = el.querySelector('.mon-sort-icon');
            if (!icon) return;
            if (tskSortColuna === el.getAttribute('data-col')) {
                icon.className = 'fas ' + (tskSortAsc ? 'fa-sort-up' : 'fa-sort-down') + ' mon-sort-icon active';
            } else {
                icon.className = 'fas fa-sort mon-sort-icon';
            }
        });
    }

    window.tskOrdenarPor = function (coluna) {
        if (tskSortColuna === coluna) {
            tskSortAsc = !tskSortAsc;
        } else {
            tskSortColuna = coluna;
            tskSortAsc = true;
        }
        tskAtualizarIconesOrdenacao();
        if (lastTarefas) renderTarefas(lastTarefas);
    };

    // Prazo ordena por timestamp (não string) — 1º clique = mais cedo/mais atrasado primeiro,
    // já que isso é o que mais importa pra decidir o que olhar primeiro.
    function tskTimestampPrazo(t) {
        if (!t.deadline) return null;
        var ts = Date.parse(t.deadline);
        return isNaN(ts) ? null : ts;
    }

    function tskAplicarOrdenacao(tarefas) {
        if (!tskSortColuna) return tarefas; // padrão: mantém a ordem original (atrasada/prazo)

        if (tskSortColuna === 'prazo') {
            return tarefas.slice().sort(function (a, b) {
                var va = tskTimestampPrazo(a);
                var vb = tskTimestampPrazo(b);
                if (va === null && vb === null) return 0;
                if (va === null) return 1;  // sem prazo sempre vai pro fim
                if (vb === null) return -1;
                var cmp = va - vb;
                return tskSortAsc ? cmp : -cmp;
            });
        }

        return tarefas.slice().sort(function (a, b) {
            var va = tskValorOrdenacao(a, tskSortColuna).toLowerCase();
            var vb = tskValorOrdenacao(b, tskSortColuna).toLowerCase();
            if (va === '' && vb === '') return 0;
            if (va === '') return 1;  // sem pessoa nessa coluna sempre vai pro fim
            if (vb === '') return -1;
            var cmp = va < vb ? -1 : (va > vb ? 1 : 0);
            return tskSortAsc ? cmp : -cmp;
        });
    }

    function renderTarefas(data) {
        var listEl  = document.getElementById('tsk-list');
        var countEl = document.getElementById('tsk-count');

        renderFiltroPessoas(data.equipe);

        if (data.aviso) {
            listEl.innerHTML = '<div class="mon-empty"><i class="fas fa-plug"></i><div>' + escHtml(data.aviso) + '</div></div>';
            countEl.textContent = '';
            return;
        }

        var todasTarefas = data.tarefas || [];
        var tarefas = (tskSelectedUids && tskSelectedUids.size)
            ? todasTarefas.filter(tskEnvolveSelecionados)
            : todasTarefas;
        tarefas = tskAplicarOrdenacao(tarefas);

        countEl.textContent = tarefas.length + ' em aberto';

        if (!tarefas.length) {
            listEl.innerHTML = '<div class="mon-empty"><i class="fas fa-check-circle"></i><div>Nenhuma tarefa em aberto.</div></div>';
            return;
        }

        listEl.innerHTML = tarefas.map(tskRowHtml).join('');
    }

    window.tskToggle = function (id) {
        var detail = document.getElementById('tsk-detail-' + id);
        var btn    = document.getElementById('tsk-btn-' + id);
        if (!detail) return;

        var isOpen = detail.classList.contains('open');
        detail.classList.toggle('open', !isOpen);
        if (btn) btn.classList.toggle('open', !isOpen);
    };

    function carregarTarefas() {
        return fetch('/api/monitoramento-tarefas-cards.php', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.erro) {
                    document.getElementById('tsk-list').innerHTML =
                        '<div class="mon-empty" style="color:#fc8181"><i class="fas fa-exclamation-circle"></i><div>'
                        + escHtml(data.erro) + '</div></div>';
                    return;
                }
                lastTarefas = data;
                renderTarefas(data);
            })
            .catch(function () {
                document.getElementById('tsk-list').innerHTML =
                    '<div class="mon-empty" style="color:#fc8181"><i class="fas fa-exclamation-circle"></i><div>Erro de comunicação.</div></div>';
            });
    }

    // ── Painel Chamados abertos (SPA 1054 / Funil 208 — fila inteira, sem escopo de equipe) ──
    var lastChamados    = null;
    var chaSelectedTipos = null; // Set — inicializado no primeiro carregamento (todos selecionados)

    function iniciais(nome) {
        var partes = (nome || '').trim().split(/\s+/).filter(Boolean);
        if (!partes.length) return '?';
        if (partes.length === 1) return partes[0].substring(0, 2).toUpperCase();
        return (partes[0][0] + partes[partes.length - 1][0]).toUpperCase();
    }

    function chaAvatarHtml(r) {
        return '<span class="cha-avatar" title="' + escHtml(r.nome) + '">' + escHtml(iniciais(r.nome)) + '</span>';
    }

    function chaRowHtml(c) {
        var url = (lastChamados && lastChamados.bitrixBase)
            ? lastChamados.bitrixBase + '/crm/type/1054/details/' + c.id + '/'
            : '';
        var idHtml = url
            ? '<a class="cha-row-id" href="' + escHtml(url) + '" target="_blank" rel="noopener" onclick="event.stopPropagation()">#' + c.id + '</a>'
            : '<span class="cha-row-id">#' + c.id + '</span>';
        var linkIcon = url
            ? '<a href="' + escHtml(url) + '" target="_blank" rel="noopener" class="cha-link-icon" onclick="event.stopPropagation()" title="Abrir no Bitrix24"><i class="fas fa-external-link-alt"></i></a>'
            : '';
        var chatIcon = c.temChat
            ? '<i class="fas fa-comment-dots cha-chat-icon" title="Ver mensagens" onclick="event.stopPropagation();chaAbrirChat(' + c.id + ')"></i>'
            : '';
        var avatares = (c.responsaveis && c.responsaveis.length)
            ? c.responsaveis.map(chaAvatarHtml).join('')
            : '<span class="cha-sem-resp">—</span>';

        var resumoHtml = c.resumo
            ? escHtml(c.resumo)
            : '<span style="color:rgba(255,255,255,.35)">Sem resumo</span>';

        // Highlight independente por data (mockup Option C): "criado" fica âmbar com mais de 30
        // dias, "previsão" fica coral se já passou — condições independentes, ver .cha-data-
        // criado.envelhecido/.cha-data-previsao.atrasada.
        var criadoIso        = (c.createdTime || '').slice(0, 10);
        var hojeIso           = chaHojeIso();
        var criadoEnvelhecido = criadoIso !== '' && criadoIso < chaSubtrairDias(hojeIso, 30);
        var criadoHtml = '<span class="cha-data-criado' + (criadoEnvelhecido ? ' envelhecido' : '') + '">'
            + escHtml(fmtDiaMes(criadoIso)) + '</span>';

        var previsaoHtml = '';
        if (c.previsao) {
            var previsaoAtrasada = c.previsao < hojeIso;
            previsaoHtml = ' / <span class="cha-data-previsao' + (previsaoAtrasada ? ' atrasada' : '') + '">'
                + escHtml(fmtDiaMes(c.previsao)) + '</span>';
        }

        var dataHtml   = criadoHtml + previsaoHtml;
        var dataTitulo = fmtDiaMes(criadoIso) + (c.previsao ? ' / ' + fmtDiaMes(c.previsao) : '');

        return '<div class="cha-row">'
            + '<div class="cha-row-main" onclick="chaToggle(' + c.id + ')">'
                + '<button class="cha-chevron-btn" id="cha-btn-' + c.id + '"><i class="fas fa-chevron-right" style="font-size:.7rem"></i></button>'
                + '<div class="cha-row-chamado">' + idHtml + '<span class="cha-row-title" title="' + escHtml(c.titulo) + '">' + escHtml(c.titulo) + '</span></div>'
                + '<span class="cha-data" title="' + escHtml(dataTitulo) + '">' + dataHtml + '</span>'
                + '<span class="cha-empresa" title="' + escHtml(c.empresaNome || '') + '">' + escHtml(c.empresaNome || '—') + '</span>'
                + '<span class="cha-badge" title="' + escHtml(c.tipoLabel) + '" style="background:' + c.tipoCor + '22;color:' + c.tipoCor + ';border:1px solid ' + c.tipoCor + '55">' + escHtml(c.tipoLabel) + '</span>'
                + (c.prioridadeLabel
                    ? '<span class="cha-badge" title="' + escHtml(c.prioridadeLabel) + '" style="background:' + c.prioridadeCor + '22;color:' + c.prioridadeCor + ';border:1px solid ' + c.prioridadeCor + '55">' + escHtml(c.prioridadeLabel) + '</span>'
                    : '<span class="cha-etapa">—</span>')
                + '<span class="cha-etapa" title="' + escHtml(c.etapaLabel) + '">' + escHtml(c.etapaLabel) + '</span>'
                + '<span class="cha-solicitante" title="' + escHtml(c.solicitante || '') + '">' + escHtml(c.solicitante || '—') + '</span>'
                + '<span class="cha-avatares">' + avatares + '</span>'
                + '<span class="cha-acoes">' + chatIcon + linkIcon + '</span>'
            + '</div>'
            + '<div class="cha-row-detail" id="cha-detail-' + c.id + '">'
                + '<div class="cha-detail-inner">'
                    + '<div class="cha-detail-label">Resumo</div><div class="cha-detail-text">' + resumoHtml + '</div>'
                + '</div>'
            + '</div>'
            + '</div>';
    }

    window.chaToggle = function (id) {
        var detail = document.getElementById('cha-detail-' + id);
        var btn    = document.getElementById('cha-btn-' + id);
        if (!detail) return;

        var isOpen = detail.classList.contains('open');
        detail.classList.toggle('open', !isOpen);
        if (btn) btn.classList.toggle('open', !isOpen);
    };

    // ── Dropdowns de filtro (Tipo / Responsável) — trigger "Rótulo · N" + painel com
    // checkboxes, mesmo Set multi-select de sempre por baixo (só a apresentação virou dropdown
    // em vez de pills, pra não ocupar a linha inteira de largura). Só um aberto por vez;
    // fecha ao clicar fora. ──────────────────────────────────────────────────────────
    window.chaToggleDropdown = function (nome) {
        ['tipo', 'pessoa'].forEach(function (n) {
            var el = document.getElementById('cha-dropdown-' + n);
            if (!el) return;
            el.classList.toggle('open', n === nome ? !el.classList.contains('open') : false);
        });
    };

    document.addEventListener('click', function (e) {
        if (e.target.closest('.cha-dropdown')) return;
        document.querySelectorAll('.cha-dropdown.open').forEach(function (el) { el.classList.remove('open'); });
    });

    // ── Filtro por Tipo — cada tipo nomeado vem do backend (TipoChamadoCatalogo::paraPills(),
    // fonte única de verdade compartilhada com a classificação Suporte/Desenvolvimento do
    // painel Equipe) — nada de mapa duplicado aqui. "Outros" é um bucket catch-all (chave
    // especial, não um tipo real) que cobre qualquer tipo fora da lista vinda do backend,
    // incluindo tipos novos que apareçam no futuro sem precisar mexer neste código.
    var chaCatalogoTipos = null; // [{tipo, label, ativoPadrao}] — vem de data.catalogoTipos

    function chaChaveTipo(c) {
        var key = String(c.tipo);
        var conhecido = (chaCatalogoTipos || []).some(function (p) { return String(p.tipo) === key; });
        return conhecido ? key : 'outros';
    }

    function chaRenderFiltroTipos(catalogo) {
        var painel  = document.getElementById('cha-dropdown-tipo-panel');
        var badgeEl = document.getElementById('cha-dropdown-tipo-count');
        if (!painel) return;

        chaCatalogoTipos = catalogo || [];

        // Default idêntico ao das pills de antes — mesmos tipos pré-selecionados, mesmos
        // excluídos por padrão. Só calculado uma vez (primeiro carregamento).
        if (chaSelectedTipos === null) {
            chaSelectedTipos = new Set(
                chaCatalogoTipos.filter(function (p) { return p.ativoPadrao; })
                                .map(function (p) { return String(p.tipo); })
            );
        }

        var itens = chaCatalogoTipos.map(function (p) { return { key: String(p.tipo), label: p.label, cor: p.cor }; });
        itens.push({ key: 'outros', label: 'Outros', cor: '#a0aec0' });

        painel.innerHTML = itens.map(function (p) {
            var ativo = chaSelectedTipos.has(p.key);
            return '<label class="cha-dropdown-item">'
                + '<input type="checkbox"' + (ativo ? ' checked' : '') + ' onchange="chaToggleTipoFiltro(\'' + p.key + '\')">'
                + '<span class="dot" style="background:' + (p.cor || '#a0aec0') + '"></span>'
                + escHtml(p.label)
                + '</label>';
        }).join('');

        if (badgeEl) badgeEl.textContent = chaSelectedTipos.size;
    }

    window.chaToggleTipoFiltro = function (tipo) {
        if (!chaSelectedTipos) return;
        if (chaSelectedTipos.has(tipo)) {
            if (chaSelectedTipos.size <= 1) { chaRenderFiltroTipos(chaCatalogoTipos); return; } // nunca deixa ficar com 0 selecionados
            chaSelectedTipos.delete(tipo);
        } else {
            chaSelectedTipos.add(tipo);
        }
        chaRenderFiltroTipos(chaCatalogoTipos);
        if (lastChamados) renderChamados(lastChamados);
    };

    // ── Filtro por Responsável — roster dinâmico (qualquer colaborador que apareça em
    // F_RESP dos chamados visíveis, não uma lista fixa) — default: todos marcados. Pessoas
    // novas que apareçam num recarregamento entram já marcadas (mesma semântica do default:
    // "mostrar todo mundo" se aplica também a quem aparece depois do primeiro carregamento).
    var chaSelectedPessoas   = null;          // Set de bitrixUserId (string) — null = ainda não inicializado
    var chaPessoasConhecidas = new Set();     // uids já vistos em qualquer carregamento anterior

    function chaRenderFiltroPessoas(chamados) {
        var painel  = document.getElementById('cha-dropdown-pessoa-panel');
        var badgeEl = document.getElementById('cha-dropdown-pessoa-count');
        if (!painel) return;

        var porUid = {};
        (chamados || []).forEach(function (c) {
            (c.responsaveis || []).forEach(function (r) { porUid[String(r.bitrixUserId)] = r.nome; });
        });

        if (chaSelectedPessoas === null) chaSelectedPessoas = new Set();

        // Uid genuinamente novo (nunca visto antes) entra marcado por padrão — "mostrar todo
        // mundo" também vale pra quem aparece só depois do primeiro carregamento; uid que o
        // usuário desmarcou manualmente não volta a ser marcado nos recarregamentos seguintes.
        Object.keys(porUid).forEach(function (uid) {
            if (!chaPessoasConhecidas.has(uid)) {
                chaPessoasConhecidas.add(uid);
                chaSelectedPessoas.add(uid);
            }
        });

        var pessoas = Object.keys(porUid)
            .map(function (uid) { return { uid: uid, nome: porUid[uid] }; })
            .sort(function (a, b) { return a.nome.localeCompare(b.nome); });

        painel.innerHTML = pessoas.length
            ? pessoas.map(function (p) {
                var ativo = chaSelectedPessoas.has(p.uid);
                return '<label class="cha-dropdown-item">'
                    + '<input type="checkbox"' + (ativo ? ' checked' : '') + ' onchange="chaTogglePessoaFiltro(\'' + p.uid + '\')">'
                    + escHtml(p.nome)
                    + '</label>';
            }).join('')
            : '<div class="cha-dropdown-empty">Nenhum responsável nos chamados visíveis.</div>';

        if (badgeEl) badgeEl.textContent = chaSelectedPessoas.size;
    }

    window.chaTogglePessoaFiltro = function (uid) {
        if (!chaSelectedPessoas) return;
        if (chaSelectedPessoas.has(uid)) {
            if (chaSelectedPessoas.size <= 1) { chaRenderFiltroPessoas(lastChamados && lastChamados.chamados); return; }
            chaSelectedPessoas.delete(uid);
        } else {
            chaSelectedPessoas.add(uid);
        }
        chaRenderFiltroPessoas(lastChamados && lastChamados.chamados);
        if (lastChamados) renderChamados(lastChamados);
    };

    function chaPassaFiltroPessoa(c) {
        if (!chaSelectedPessoas || !chaSelectedPessoas.size) return true;
        var resp = c.responsaveis || [];
        if (!resp.length) return true; // sem responsável nenhum — não é escondido por este filtro
        return resp.some(function (r) { return chaSelectedPessoas.has(String(r.bitrixUserId)); });
    }

    // ── Ordenação por coluna (todas exceto Chamado) ───────────────────────────────
    var chaSortColuna = null; // 'data' | 'empresa' | 'tipo' | 'prioridade' | 'etapa' | 'solicitante' | 'responsavel' | null
    var chaSortAsc    = true;

    // Ordena por urgência real (Urgente primeiro), não alfabética — "Alta" não pode vir antes
    // de "Urgente" só porque a letra A é menor que U.
    var CHA_PRIORIDADE_RANK = { 'Urgente': 1, 'Alta': 2, 'Média': 3, 'Baixa': 4 };

    function chaValorOrdenacao(c, coluna) {
        if (coluna === 'data')        return c.createdTime || '';
        if (coluna === 'empresa')     return c.empresaNome || '';
        if (coluna === 'tipo')        return c.tipoLabel  || '';
        // etapaOrdem = posição real no pipeline do Funil 208 (ver MonitoramentoChamadosService)
        // — não a ordem alfabética do label. Zero-padded pra comparação lexicográfica correta.
        if (coluna === 'etapa')       return String(c.etapaOrdem != null ? c.etapaOrdem : 999).padStart(3, '0');
        if (coluna === 'prioridade')  return String(CHA_PRIORIDADE_RANK[c.prioridadeLabel] || 9);
        if (coluna === 'solicitante') return c.solicitante || '';
        if (coluna === 'responsavel') return (c.responsaveis && c.responsaveis[0] && c.responsaveis[0].nome) || '';
        return '';
    }

    function chaAtualizarIconesOrdenacao() {
        document.querySelectorAll('#mon-tab-content-cha .cha-th-sort').forEach(function (el) {
            var icon = el.querySelector('.mon-sort-icon');
            if (!icon) return;
            if (chaSortColuna === el.getAttribute('data-col')) {
                icon.className = 'fas ' + (chaSortAsc ? 'fa-sort-up' : 'fa-sort-down') + ' mon-sort-icon active';
            } else {
                icon.className = 'fas fa-sort mon-sort-icon';
            }
        });
    }

    window.chaOrdenarPor = function (coluna) {
        if (chaSortColuna === coluna) {
            chaSortAsc = !chaSortAsc;
        } else {
            chaSortColuna = coluna;
            chaSortAsc = true;
        }
        chaAtualizarIconesOrdenacao();
        if (lastChamados) renderChamados(lastChamados);
    };

    function chaAplicarOrdenacao(chamados) {
        if (!chaSortColuna) return chamados; // padrão: mantém a ordem original (mais antigo primeiro)

        return chamados.slice().sort(function (a, b) {
            var va = chaValorOrdenacao(a, chaSortColuna).toLowerCase();
            var vb = chaValorOrdenacao(b, chaSortColuna).toLowerCase();
            var cmp = va < vb ? -1 : (va > vb ? 1 : 0);
            return chaSortAsc ? cmp : -cmp;
        });
    }

    function renderChamados(data) {
        var listEl  = document.getElementById('cha-list');
        var countEl = document.getElementById('cha-count');

        if (data.aviso) {
            listEl.innerHTML = '<div class="mon-empty"><i class="fas fa-plug"></i><div>' + escHtml(data.aviso) + '</div></div>';
            countEl.textContent = '';
            return;
        }

        var todos = data.chamados || [];
        chaRenderFiltroTipos(data.catalogoTipos);
        chaRenderFiltroPessoas(todos);

        var visiveis = todos.filter(function (c) {
            return chaSelectedTipos.has(chaChaveTipo(c)) && chaPassaFiltroPessoa(c) && monPassaFiltroEtapa(c);
        });
        visiveis = chaAplicarOrdenacao(visiveis);

        countEl.textContent = visiveis.length + ' em aberto';

        if (!visiveis.length) {
            listEl.innerHTML = '<div class="mon-empty"><i class="fas fa-check-circle"></i><div>Nenhum chamado em aberto.</div></div>';
            return;
        }

        listEl.innerHTML = visiveis.map(chaRowHtml).join('');
    }

    function carregarChamados() {
        return fetch('/api/monitoramento-chamados-cards.php', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.erro) {
                    document.getElementById('cha-list').innerHTML =
                        '<div class="mon-empty" style="color:#fc8181"><i class="fas fa-exclamation-circle"></i><div>'
                        + escHtml(data.erro) + '</div></div>';
                    return;
                }
                lastChamados = data;
                renderChamados(data);
            })
            .catch(function () {
                document.getElementById('cha-list').innerHTML =
                    '<div class="mon-empty" style="color:#fc8181"><i class="fas fa-exclamation-circle"></i><div>Erro de comunicação.</div></div>';
            });
    }

    // ── Painel Funil (SPA 1054 / Funil 208 — volume de criados/finalizados; sem Tarefas) ──
    function funStatHtml(valor, label) {
        return '<div class="fun-stat"><span class="fun-stat-value">' + (valor != null ? valor : '—') + '</span><span class="fun-stat-label">' + escHtml(label) + '</span></div>';
    }

    function fmtDiaMes(iso) {
        var p = (iso || '').split('-');
        return p.length === 3 ? (p[2] + '/' + p[1]) : '';
    }

    // Data de hoje em "YYYY-MM-DD" local (não UTC) — comparável por string com createdTime/
    // previsao (mesmo formato), usado pelo highlight da coluna Criado/Prev. em chaRowHtml().
    function chaHojeIso() {
        var d = new Date();
        return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    }
    function chaSubtrairDias(iso, dias) {
        var d = new Date(iso + 'T00:00:00');
        d.setDate(d.getDate() - dias);
        return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    }

    var FUN_DIST_CORES = {
        'Fila - Desenvolvimento':     '#b794f4',
        'Fila - Suporte':             '#0DC2FF',
        'Fila - Demandas Programadas':'#26FF93',
        'Demandas em Execução':       '#f6ad55',
    };
    var FUN_DIST_COR_SUBITEM = '#a0aec0'; // sub-itens de "Demandas em Execução" — neutro, secundário

    // ── Clique-pra-filtrar o Chamados abertos a partir de um bucket/sub-item do Funil ──
    // { chave, stages } | null — chave identifica QUEM está filtrando agora (pra saber se um
    // clique é "no mesmo de novo" e pra destacar visualmente o item ativo, ver funDistRowHtml()).
    var monEtapaFiltro         = null;
    var monExecucaoExpandida   = false;
    var lastFunilBuckets       = [];

    function monPassaFiltroEtapa(c) {
        if (!monEtapaFiltro) return true;
        return monEtapaFiltro.stages.indexOf(c.etapa) !== -1;
    }

    // Clique num bucket de nível 1 (individual OU "Demandas em Execução" recolhido/como um
    // todo) — clicar de novo no mesmo limpa o filtro (mostra tudo de novo).
    window.monFiltrarPorEtapaBucket = function (idx) {
        var bucket = lastFunilBuckets[idx];
        if (!bucket) return;
        var chave = 'b' + idx;
        monEtapaFiltro = (monEtapaFiltro && monEtapaFiltro.chave === chave)
            ? null
            : { chave: chave, stages: bucket.stages };
        renderFunilDistribuicao(lastFunilBuckets);
        if (lastChamados) renderChamados(lastChamados);
    };

    // Clique num sub-item (só existe quando "Demandas em Execução" está expandido) — clicar de
    // novo no mesmo sub-item NÃO limpa pra "mostrar tudo", volta 1 nível: pro filtro agregado
    // dos 6 juntos (não é "de ninguém" — o usuário ainda estava dentro do agregado).
    window.monFiltrarPorSubItem = function (idxAgregado, idxSub) {
        var agregado = lastFunilBuckets[idxAgregado];
        if (!agregado || !agregado.subItens) return;
        var sub = agregado.subItens[idxSub];
        if (!sub) return;
        var chave         = 'b' + idxAgregado + 's' + idxSub;
        var chaveAgregado = 'b' + idxAgregado;
        monEtapaFiltro = (monEtapaFiltro && monEtapaFiltro.chave === chave)
            ? { chave: chaveAgregado, stages: agregado.stages }
            : { chave: chave, stages: sub.stages };
        renderFunilDistribuicao(lastFunilBuckets);
        if (lastChamados) renderChamados(lastChamados);
    };

    // Só expande/recolhe — não filtra nada (clique separado do clique-pra-filtrar do resto da
    // linha, ver stopPropagation() no HTML do chevron).
    window.monToggleExecucaoExpandida = function () {
        monExecucaoExpandida = !monExecucaoExpandida;
        renderFunilDistribuicao(lastFunilBuckets);
    };

    function funDistRowHtml(bucket, idx, maxTotal) {
        var cor     = FUN_DIST_CORES[bucket.label] || '#a0aec0';
        var largura = maxTotal > 0 ? Math.round((bucket.total / maxTotal) * 100) : 0;
        var chave   = 'b' + idx;
        var ativo   = monEtapaFiltro && monEtapaFiltro.chave === chave;
        var temSub  = !!(bucket.subItens && bucket.subItens.length);
        var chevron = temSub
            ? '<i class="fas fa-chevron-' + (monExecucaoExpandida ? 'down' : 'right') + ' fun-dist-chevron" onclick="event.stopPropagation();monToggleExecucaoExpandida()"></i>'
            : '<span class="fun-dist-chevron-vazio"></span>';

        var linha = '<div class="fun-dist-row' + (ativo ? ' ativo' : '') + '" onclick="monFiltrarPorEtapaBucket(' + idx + ')">'
            + chevron
            + '<span class="fun-dist-label" title="' + escHtml(bucket.label) + '">' + escHtml(bucket.label) + '</span>'
            + '<span class="fun-dist-track"><span class="fun-dist-fill" style="width:' + largura + '%;background:' + cor + '"></span></span>'
            + '<span class="fun-dist-value">' + bucket.total + '</span>'
            + '</div>';

        if (temSub && monExecucaoExpandida) {
            var maxSub = bucket.subItens.reduce(function (m, s) { return Math.max(m, s.total); }, 0);
            linha += bucket.subItens.map(function (sub, idxSub) {
                var larguraSub = maxSub > 0 ? Math.round((sub.total / maxSub) * 100) : 0;
                var chaveSub   = 'b' + idx + 's' + idxSub;
                var ativoSub   = monEtapaFiltro && monEtapaFiltro.chave === chaveSub;
                return '<div class="fun-dist-row fun-dist-subitem' + (ativoSub ? ' ativo' : '') + '" onclick="monFiltrarPorSubItem(' + idx + ',' + idxSub + ')">'
                    + '<span class="fun-dist-chevron-vazio"></span>'
                    + '<span class="fun-dist-label" title="' + escHtml(sub.label) + '">' + escHtml(sub.label) + '</span>'
                    + '<span class="fun-dist-track"><span class="fun-dist-fill" style="width:' + larguraSub + '%;background:' + FUN_DIST_COR_SUBITEM + '"></span></span>'
                    + '<span class="fun-dist-value">' + sub.total + '</span>'
                    + '</div>';
            }).join('');
        }

        return linha;
    }

    function renderFunilDistribuicao(buckets) {
        var el = document.getElementById('fun-dist-rows');
        if (!el) return;

        lastFunilBuckets = buckets || [];
        var maxTotal = lastFunilBuckets.reduce(function (m, b) { return Math.max(m, b.total); }, 0);

        el.innerHTML = lastFunilBuckets.map(function (b, idx) { return funDistRowHtml(b, idx, maxTotal); }).join('');
    }

    function renderFunil(data) {
        var cicloEl          = document.getElementById('fun-ciclo');
        var criadosBody     = document.getElementById('fun-criados-body');
        var finalizadosBody = document.getElementById('fun-finalizados-body');
        if (!criadosBody || !finalizadosBody) return;

        if (data.aviso) {
            if (cicloEl) cicloEl.textContent = '';
            var avisoHtml = '<div class="mon-empty"><i class="fas fa-plug"></i><div>' + escHtml(data.aviso) + '</div></div>';
            criadosBody.innerHTML     = avisoHtml;
            finalizadosBody.innerHTML = avisoHtml;
            renderFunilDistribuicao([]);
            return;
        }

        if (cicloEl && data.periodo) {
            cicloEl.textContent = 'ciclo ' + fmtDiaMes(data.periodo.inicio) + '-' + fmtDiaMes(data.periodo.fim);
        }

        var criados     = data.chamadosCriados     || {};
        var finalizados = data.chamadosFinalizados || {};

        criadosBody.innerHTML =
            funStatHtml(criados.semana, 'Nesta semana') + funStatHtml(criados.periodo, 'No período');
        finalizadosBody.innerHTML =
            funStatHtml(finalizados.semana, 'Nesta semana') + funStatHtml(finalizados.periodo, 'No período');

        renderFunilDistribuicao(data.distribuicaoAbertos || []);
    }

    function carregarFunil() {
        return fetch('/api/monitoramento-funil-cards.php', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.erro) {
                    var erroHtml = '<div class="mon-empty" style="color:#fc8181"><i class="fas fa-exclamation-circle"></i><div>'
                        + escHtml(data.erro) + '</div></div>';
                    document.getElementById('fun-criados-body').innerHTML     = erroHtml;
                    document.getElementById('fun-finalizados-body').innerHTML = erroHtml;
                    return;
                }
                renderFunil(data);
            })
            .catch(function () {
                var erroHtml = '<div class="mon-empty" style="color:#fc8181"><i class="fas fa-exclamation-circle"></i><div>Erro de comunicação.</div></div>';
                document.getElementById('fun-criados-body').innerHTML     = erroHtml;
                document.getElementById('fun-finalizados-body').innerHTML = erroHtml;
            });
    }

    // ── Painel Atendimento (Contact Center / Open Lines — fila "Geral KW24 - Suporte") ──
    function fmtMinutos(min) {
        if (min == null) return '';
        if (min < 60) return min + 'min';
        var h = Math.floor(min / 60);
        var m = min % 60;
        return h + 'h' + (m ? ' ' + m + 'min' : '');
    }

    // Colunas alinhadas (Conversa/Responsável/Tempo, ver .ate-thead) — Responsável sempre
    // renderizado (com "—" quando não há, mesmo padrão de .cha-sem-resp em Chamados abertos),
    // pra não quebrar o alinhamento entre linhas.
    function ateRowHtml(c) {
        var dotClasse   = c.aguardando ? 'aguardando' : 'respondido';
        var tempoClasse = c.aguardando ? ' aguardando' : '';
        var tempoTexto  = c.minutosDesdeUltimaAtividade != null
            ? (c.aguardando ? 'aguardando há ' : 'há ') + fmtMinutos(c.minutosDesdeUltimaAtividade)
            : '';
        var responsavelHtml = c.reclamadaPor
            ? '<span class="ate-row-responsavel">' + escHtml(c.reclamadaPor) + '</span>'
            : '<span class="ate-row-responsavel vazio">—</span>';

        // Só o nome/título da conversa — sem a prévia da última mensagem (removida: encurta a
        // linha, ajuda mais linhas caberem na altura fixa do card, ver monSincronizarAlturaAtendimento()).
        var body = '<span class="ate-dot ' + dotClasse + '"></span>'
            + '<span class="ate-row-titulo" title="' + escHtml(c.titulo) + '">' + escHtml(c.titulo) + '</span>'
            + responsavelHtml
            + '<span class="ate-row-tempo' + tempoClasse + '">' + escHtml(tempoTexto) + '</span>';

        return c.urlBitrix24
            ? '<a class="ate-row" href="' + escHtml(c.urlBitrix24) + '" target="_blank" rel="noopener">' + body + '</a>'
            : '<div class="ate-row">' + body + '</div>';
    }

    // Abas "Conversas" / "Grupos" — mesmo padrão de monTrocarAba(), independente dele.
    var ateAbaAtiva = 'conv';

    function ateAtualizarAbas() {
        var tabConv  = document.getElementById('mon-tab-ate-conv');
        var tabGrupo = document.getElementById('mon-tab-ate-grupo');
        var contConv  = document.getElementById('ate-tab-content-conv');
        var contGrupo = document.getElementById('ate-tab-content-grupo');
        var filtros   = document.getElementById('ate-filtros');
        if (!tabConv || !tabGrupo || !contConv || !contGrupo) return;

        tabConv.classList.toggle('active', ateAbaAtiva === 'conv');
        tabGrupo.classList.toggle('active', ateAbaAtiva === 'grupo');
        contConv.style.display  = ateAbaAtiva === 'conv'  ? 'flex' : 'none';
        contGrupo.style.display = ateAbaAtiva === 'grupo' ? 'flex' : 'none';
        // Filtro por Responsável só existe na aba Conversas — Grupos não tem responsável
        // único (sempre abertos pra fila toda), ver relatório da tarefa.
        if (filtros) filtros.style.display = ateAbaAtiva === 'conv' ? 'flex' : 'none';
    }

    window.ateTrocarAba = function (aba) {
        if (ateAbaAtiva === aba) return;
        ateAbaAtiva = aba;
        ateAtualizarAbas();
    };

    ateAtualizarAbas();

    // ── Filtro por Responsável (Conversas) — dropdown-checklist, mesmo padrão de
    // Chamados abertos (ver .cha-dropdown-*), roster fixo = identidades cadastradas
    // (data.identidades), default todos marcados. "aguardando_atendimento" (não reclamada)
    // e status indeterminado (fallback com < 2 webhooks) sempre passam — não são de uma
    // pessoa só pra filtrar. ──────────────────────────────────────────────────────────
    var ateSelectedPessoas   = null;      // Set de nomes (string) — null = não inicializado
    var atePessoasConhecidas = new Set();
    var lastAtendimento      = null;

    window.ateToggleDropdown = function () {
        var el = document.getElementById('ate-dropdown-pessoa');
        if (el) el.classList.toggle('open');
    };

    function ateRenderFiltroPessoas(identidades) {
        var painel  = document.getElementById('ate-dropdown-pessoa-panel');
        var badgeEl = document.getElementById('ate-dropdown-pessoa-count');
        if (!painel) return;

        identidades = identidades || [];
        if (ateSelectedPessoas === null) ateSelectedPessoas = new Set();

        // Nome genuinamente novo (nunca visto) entra marcado por padrão — "mostrar todo
        // mundo" também vale pra identidade cadastrada depois do primeiro carregamento.
        identidades.forEach(function (nome) {
            if (!atePessoasConhecidas.has(nome)) {
                atePessoasConhecidas.add(nome);
                ateSelectedPessoas.add(nome);
            }
        });

        painel.innerHTML = identidades.length
            ? identidades.map(function (nome) {
                var ativo = ateSelectedPessoas.has(nome);
                var nomeEscapado = escHtml(nome).replace(/'/g, "\\'");
                return '<label class="cha-dropdown-item">'
                    + '<input type="checkbox"' + (ativo ? ' checked' : '') + ' onchange="ateTogglePessoaFiltro(\'' + nomeEscapado + '\')">'
                    + escHtml(nome)
                    + '</label>';
            }).join('')
            : '<div class="cha-dropdown-empty">Nenhum webhook pessoal cadastrado.</div>';

        if (badgeEl) badgeEl.textContent = ateSelectedPessoas.size;
    }

    window.ateTogglePessoaFiltro = function (nome) {
        if (!ateSelectedPessoas) return;
        var identidades = lastAtendimento ? lastAtendimento.identidades : [];
        if (ateSelectedPessoas.has(nome)) {
            if (ateSelectedPessoas.size <= 1) { ateRenderFiltroPessoas(identidades); return; } // nunca deixa ficar com 0 selecionados
            ateSelectedPessoas.delete(nome);
        } else {
            ateSelectedPessoas.add(nome);
        }
        ateRenderFiltroPessoas(identidades);
        if (lastAtendimento) renderAtendimento(lastAtendimento);
    };

    // Passa se: não reclamada (visível pra fila toda, não é de ninguém pra filtrar),
    // indeterminada (statusFila null — modo com < 2 webhooks, filtro não é confiável aqui),
    // ou reclamada por alguém marcado no filtro.
    function atePassaFiltroPessoa(c) {
        if (c.statusFila !== 'em_atendimento') return true;
        if (!ateSelectedPessoas || !ateSelectedPessoas.size) return true;
        return ateSelectedPessoas.has(c.reclamadaPor);
    }

    function renderAtendimento(data) {
        lastAtendimento = data;
        var kpisEl      = document.getElementById('ate-kpis');
        var listEl      = document.getElementById('ate-list');
        var grupoListEl = document.getElementById('ate-grupo-list');
        var grupoCntEl  = document.getElementById('ate-grupo-count');
        if (!kpisEl || !listEl) return;

        if (data.aviso) {
            kpisEl.innerHTML = '';
            listEl.innerHTML = '<div class="mon-empty"><i class="fas fa-plug"></i><div>' + escHtml(data.aviso) + '</div></div>';
            if (grupoListEl) grupoListEl.innerHTML = '';
            if (grupoCntEl) grupoCntEl.textContent = '0';
            return;
        }

        ateRenderFiltroPessoas(data.identidades);

        var grupos = data.grupos || [];
        if (grupoCntEl) grupoCntEl.textContent = grupos.length;
        if (grupoListEl) {
            grupoListEl.innerHTML = grupos.length
                ? grupos.map(ateRowHtml).join('')
                : '<div class="mon-empty"><i class="fas fa-check-circle"></i><div>Nenhum grupo de WhatsApp ativo no momento.</div></div>';
        }

        var ativas   = data.conversasAtivas || { total: 0, aguardando: 0 };
        var tempoTxt = data.tempoMedioRespostaMinutos != null
            ? fmtMinutos(data.tempoMedioRespostaMinutos)
            : 'sem dados suficientes';

        kpisEl.innerHTML =
            '<div class="ate-kpi"><span class="ate-kpi-value">' + ativas.total + '</span><span class="ate-kpi-label">Conversas ativas</span></div>'
            + '<div class="ate-kpi"><span class="ate-kpi-value' + (ativas.aguardando ? ' alerta' : '') + '">' + ativas.aguardando + '</span><span class="ate-kpi-label">Aguardando resposta</span></div>'
            + '<div class="ate-kpi"><span class="ate-kpi-value">' + escHtml(tempoTxt) + '</span><span class="ate-kpi-label">Tempo médio de resposta</span></div>';

        var conversas = (data.conversas || []).filter(atePassaFiltroPessoa);
        if (!conversas.length) {
            listEl.innerHTML = '<div class="mon-empty"><i class="fas fa-check-circle"></i><div>Nenhuma conversa ativa no momento.</div></div>';
            return;
        }

        // Agrupamento "Aguardando atendimento" × "Sendo atendida" só é confiável com 2+
        // webhooks pessoais cadastrados (ver agrupamentoPorResponsavel/statusFila no
        // service) — sem isso, mantém a lista plana de sempre.
        if (!data.agrupamentoPorResponsavel) {
            listEl.innerHTML = conversas.map(ateRowHtml).join('');
            return;
        }

        var semDono = conversas.filter(function (c) { return c.statusFila === 'aguardando_atendimento'; });
        var comDono = conversas.filter(function (c) { return c.statusFila === 'em_atendimento'; });

        var html = '';
        if (semDono.length) {
            html += '<div class="ate-group-header alerta">Aguardando atendimento (' + semDono.length + ')</div>'
                + semDono.map(ateRowHtml).join('');
        }
        if (comDono.length) {
            html += '<div class="ate-group-header">Sendo atendida (' + comDono.length + ')</div>'
                + comDono.map(ateRowHtml).join('');
        }
        listEl.innerHTML = html;
    }

    function carregarAtendimento() {
        return fetch('/api/monitoramento-atendimento-cards.php', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.erro) {
                    document.getElementById('ate-kpis').innerHTML = '';
                    document.getElementById('ate-list').innerHTML =
                        '<div class="mon-empty" style="color:#fc8181"><i class="fas fa-exclamation-circle"></i><div>'
                        + escHtml(data.erro) + '</div></div>';
                    document.getElementById('ate-grupo-list').innerHTML = '';
                    return;
                }
                renderAtendimento(data);
            })
            .catch(function () {
                document.getElementById('ate-kpis').innerHTML = '';
                document.getElementById('ate-list').innerHTML =
                    '<div class="mon-empty" style="color:#fc8181"><i class="fas fa-exclamation-circle"></i><div>Erro de comunicação.</div></div>';
                document.getElementById('ate-grupo-list').innerHTML = '';
            });
    }

    // Atendimento precisa ter a MESMA altura do Funil (vizinho de linha), nas duas abas
    // (Conversas/Grupos), sem depender da quantidade de itens da lista — align-items:stretch
    // "de graça" não funciona aqui porque nenhum dos dois tem altura imposta de fora, então
    // .topo-row sempre usa o MAIOR conteúdo natural dos dois lados como referência (com uma
    // lista de conversas mais alta que o Funil, era ela que dava a altura da linha, e o
    // Funil só esticava pra acompanhar — vão vazio embaixo de "Outros", bug relatado ao vivo,
    // reproduzido e confirmado num teste visual local com Chrome headless antes deste fix).
    //
    // Addendum de Gabriel (confirmado ao vivo): igualar exatamente à altura natural do Funil
    // não bastava — a lista de Conversas/Grupos ainda precisava de scroll próprio e a linha
    // ficava "cramped" com "Demandas em Execução" expandido. A altura fixa das duas caixas é
    // a altura natural do Funil TOTALMENTE EXPANDIDO — não a altura no estado atual (colapsado
    // ou expandido) — com uma pequena margem (fator ajustado ao vivo por Gabriel via DevTools:
    // 425px -> 325px foi o encaixe correto, caixa terminando bem depois da última linha visível
    // "Treinamento/Validação", só um respiro pequeno — 1.34 tinha margem grande demais).
    var FUN_ALTURA_FATOR = 1.025;

    // Mede a altura natural do Funil com "Demandas em Execução" forçado a expandido (mesmo que
    // o estado real no momento seja colapsado), restaurando o estado real depois de medir — só
    // um "flicker" de render síncrono, invisível pro usuário (sem repaint entre as duas chamadas).
    function monAlturaFunilExpandido() {
        var fun = document.getElementById('fun-section');
        if (!fun || !lastFunilBuckets.length) return 0;

        var estadoReal = monExecucaoExpandida;
        if (!estadoReal) {
            monExecucaoExpandida = true;
            renderFunilDistribuicao(lastFunilBuckets);
        }
        var altura = fun.offsetHeight;
        if (!estadoReal) {
            monExecucaoExpandida = false;
            renderFunilDistribuicao(lastFunilBuckets);
        }
        return altura;
    }

    function monSincronizarAlturaAtendimento() {
        var ate = document.getElementById('ate-section');
        var fun = document.getElementById('fun-section');
        if (!ate || !fun) return;

        fun.style.height = ''; // solta a altura fixa antes de medir o conteúdo natural de novo
        var alturaExpandida = monAlturaFunilExpandido();
        if (!alturaExpandida) return;

        var alturaFixa = Math.round(alturaExpandida * FUN_ALTURA_FATOR) + 'px';
        fun.style.height = alturaFixa;
        ate.style.height = alturaFixa;
    }

    // ── Carregamento geral (Equipe + Chamados abertos + Tarefas + Funil + Atendimento) ──
    function carregar() {
        var icon = document.getElementById('mon-refresh-icon');
        if (icon) icon.classList.add('fa-spin');

        var pEquipe = fetch('/api/monitoramento-cards.php', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.erro) {
                    document.getElementById('mon-equipe-grid').innerHTML =
                        '<div class="mon-empty" style="color:#fc8181"><i class="fas fa-exclamation-circle"></i><div>'
                        + escHtml(data.erro) + '</div></div>';
                    return;
                }
                lastData = data;
                render(data);
            })
            .catch(function () {
                document.getElementById('mon-equipe-grid').innerHTML =
                    '<div class="mon-empty" style="color:#fc8181"><i class="fas fa-exclamation-circle"></i><div>Erro de comunicação.</div></div>';
            });

        Promise.all([pEquipe, carregarChamados(), carregarTarefas(), carregarFunil(), carregarAtendimento()]).then(function () {
            if (icon) icon.classList.remove('fa-spin');
            var upd = document.getElementById('mon-updated');
            if (upd) upd.textContent = 'Atualizado às ' + new Date().toLocaleTimeString('pt-BR');
            monSincronizarAlturaAtendimento();
        });
    }

    window.monAtualizar = carregar;

    carregar();
    setInterval(carregar, AUTO_REFRESH_MS);

    // Largura fluida (clamp) muda fonte/padding do Funil ao redimensionar — re-sincroniza pra
    // Atendimento não ficar com uma altura desatualizada.
    var monResizeTimer = null;
    window.addEventListener('resize', function () {
        clearTimeout(monResizeTimer);
        monResizeTimer = setTimeout(monSincronizarAlturaAtendimento, 150);
    });

})();
